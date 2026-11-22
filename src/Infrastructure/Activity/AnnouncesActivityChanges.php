<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Activity;

use App\Application\Activity\Services\ActivityPublisherInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Surveys\Infrastructure\Jobs\PublishResponseActivityJob;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Throwable;

/**
 * Publishes, through the core's ActivityPublisherInterface, every change to what the response
 * source reports (core#774), whatever made it: a response sent or taken away, a survey that starts
 * or stops counting (paused for review, approved, turned anonymous, sent to another audience), a
 * survey deleted and an account that is gone. Surveys and responses are saved from the services,
 * the panel and plain models, so the provider wires this to the models rather than to any one path.
 *
 * Something that does not count says nothing: a response to an anonymous survey is never
 * announced, not even its id. Publishing for a save that changed nothing would be harmless, but it
 * is noise a consumer would pay for.
 *
 * A failure here never costs the save that caused it (a response taken must not be lost because a
 * queue is down): it is logged by class only and the consumer's recalculation (`history()`)
 * corrects what was missed. Every query that can fail runs in a savepoint, so PostgreSQL keeps
 * the surrounding transaction usable. Writes made with a query builder raise no model event and
 * are not published; the module makes none on these tables.
 */
final readonly class AnnouncesActivityChanges
{
    public function __construct(
        private CountedResponses $counted,
        private ActivityPublisherInterface $activity,
    ) {}

    /**
     * A response sent counts when its survey does, so one to an anonymous survey (which never has a
     * person) or to a restricted one says nothing, not even its id.
     */
    public function responseCreated(SurveyResponseModel $response): void
    {
        $this->attempt('response created', function () use ($response): void {
            if ($response->user_id !== null && $this->surveyCounts($response->survey_id)) {
                $this->activity->recorded(ResponseActivitySource::TYPE, $response->id);
            }
        });
    }

    /**
     * A response moved to another account or survey, or taken from its account, has other facts now.
     * Inside `updated` the model still holds the values it had before the save, and `wasChanged()`
     * tells what this save changed.
     */
    public function responseUpdated(SurveyResponseModel $response): void
    {
        $this->attempt('response updated', function () use ($response): void {
            if (! $response->wasChanged(['user_id', 'survey_id'])) {
                return;
            }

            // Only if it counted before or counts now: a response moved between surveys that never
            // count has nothing to say
            $before = $response->getOriginal('user_id') !== null && $this->surveyCounts((string) $response->getOriginal('survey_id'));
            $now = $response->user_id !== null && $this->surveyCounts($response->survey_id);

            if ($before || $now) {
                $this->activity->recorded(ResponseActivitySource::TYPE, $response->id);
            }
        });
    }

    /**
     * A response of a counted survey is taken away. When the survey itself is going, its
     * responses go through the foreign key without a model event: see surveyDeleting().
     */
    public function responseDeleted(SurveyResponseModel $response): void
    {
        $this->attempt('response deleted', function () use ($response): void {
            if ($response->user_id !== null && $this->surveyCounts($response->survey_id)) {
                $this->activity->recorded(ResponseActivitySource::TYPE, $response->id);
            }
        });
    }

    /**
     * Only a survey that starts or stops counting moves its responses: closing it, renaming it or
     * anything else about it leaves them as they were. A new survey has no responses yet.
     */
    public function surveyUpdated(SurveyModel $survey): void
    {
        $this->attempt('survey updated', function () use ($survey): void {
            if (! $survey->wasChanged(['status', 'audience_type', 'anonymous'])) {
                return;
            }

            $was = $this->counted->surveyCounts(
                (string) $survey->getOriginal('status'),
                (string) $survey->getOriginal('audience_type'),
                (bool) $survey->getOriginal('anonymous'),
            );

            if ($was !== $this->counted->surveyCounts($survey->status, $survey->audience_type, $survey->anonymous)) {
                $this->announceInBatches($this->namedResponseIds($survey->id));
            }
        });
    }

    /**
     * The named responses of a survey that counts, asked before it goes (`deleting`): they go with
     * it through a foreign key, without model events, and nothing could be asked afterwards.
     *
     * @return list<string>
     */
    public function responsesOfDeletedSurvey(SurveyModel $survey): array
    {
        $ids = [];

        $this->attempt('survey deleting', function () use ($survey, &$ids): void {
            if ($this->counted->surveyCounts($survey->status, $survey->audience_type, $survey->anonymous)) {
                $ids = $this->namedResponseIds($survey->id);
            }
        });

        return $ids;
    }

    /**
     * Once the survey is gone (`deleted`): the facts are empty by the time the consumer reads them.
     *
     * @param  list<string>  $responseIds
     */
    public function surveyRemoved(array $responseIds): void
    {
        $this->attempt('survey deleted', fn () => $this->announceInBatches($responseIds));
    }

    /**
     * The responses an account gave that count, asked before it is removed for good
     * (`forceDeleting`), while it still exists: afterwards no response would count.
     *
     * @return list<string>
     */
    public function responsesOf(UserModel $user): array
    {
        $ids = [];

        $this->attempt('account deleting', function () use ($user, &$ids): void {
            $ids = $this->responseIdsOf((string) $user->getKey());
        });

        return $ids;
    }

    /**
     * Once the account is gone (`forceDeleted`): a forced deletion opens no transaction, so
     * publishing earlier would let the consumer read the responses still counting. Deactivating an
     * account keeps its responses counting, and says nothing.
     *
     * @param  list<string>  $responseIds
     */
    public function accountRemoved(array $responseIds): void
    {
        $this->attempt('account deleted', function () use ($responseIds): void {
            foreach ($responseIds as $responseId) {
                $this->activity->recorded(ResponseActivitySource::TYPE, $responseId);
            }
        });
    }

    /**
     * An account was anonymized: the core's reader drops its facts, so what it gave stops counting.
     */
    public function accountAnonymized(string $userId): void
    {
        $this->attempt('account anonymized', function () use ($userId): void {
            foreach ($this->responseIdsOf($userId) as $responseId) {
                $this->activity->recorded(ResponseActivitySource::TYPE, $responseId);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function responseIdsOf(string $userId): array
    {
        return DB::transaction(fn (): array => array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $this->counted->responses()->where('user_id', $userId)->orderBy('id')->pluck('id')->all(),
        )));
    }

    /**
     * @return list<string>
     */
    private function namedResponseIds(string $surveyId): array
    {
        return DB::transaction(fn (): array => array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            SurveyResponseModel::query()->where('survey_id', $surveyId)->whereNotNull('user_id')->orderBy('id')->pluck('id')->all(),
        )));
    }

    /**
     * @param  list<string>  $responseIds
     */
    private function announceInBatches(array $responseIds): void
    {
        foreach (array_chunk($responseIds, PublishResponseActivityJob::BATCH) as $batch) {
            // Queued once the change commits, and guarded like the rest: a queue that is down
            // must not turn a save that already happened into an error
            DB::afterCommit(function () use ($batch): void {
                $this->attempt('queue batch', static function () use ($batch): void {
                    Bus::dispatch(new PublishResponseActivityJob($batch));
                });
            });
        }
    }

    /**
     * Whether the survey counts, asked in a savepoint. A failure answers «yes»: publishing a
     * response that did not count is harmless (the consumer reads its facts and finds none),
     * missing one that did is not.
     */
    private function surveyCounts(string $surveyId): bool
    {
        try {
            return DB::transaction(fn (): bool => $this->counted->surveys()->whereKey($surveyId)->exists());
        } catch (Throwable $exception) {
            $this->warn('survey counts', $exception);

            return true;
        }
    }

    private function attempt(string $what, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->warn($what, $exception);
        }
    }

    private function warn(string $what, Throwable $exception): void
    {
        Log::warning('The surveys module could not publish activity.', ['what' => $what, 'exception' => $exception::class]);
    }
}
