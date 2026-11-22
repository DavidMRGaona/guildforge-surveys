<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Application\Activity\DTOs\ActivitySubjectFactsDTO;
use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityKind;
use App\Domain\Activity\ActivityRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;

/**
 * «Encuestas respondidas» for the activity contract (core#774): every response an account gave to a
 * survey that counts (see CountedResponses: named, sent to every account, open or closed) is a
 * subject of its own with one `respondent` fact. Who answered is only ever shown to others as a
 * total; the source never reports anything of an anonymous survey.
 *
 * A response keeps only the display day it was sent (and no time, so that an anonymous one cannot
 * be told apart by when it came). The fact is dated at the start of that day, the earliest instant
 * the response can have been sent: never the survey's or the account's dates.
 *
 * The answer is the same for everybody and never reads the signed-in user, the session or the
 * request, since a queued job runs it. Only `describe()` looks at the viewer it is given.
 */
final readonly class ResponseActivitySource implements ActivitySourceInterface
{
    public const string TYPE = 'surveys.response';

    private const string CURSOR_PREFIX = 'sv1|';

    public function __construct(
        private CountedResponses $counted,
        private TimezoneServiceInterface $timezone,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function kind(): ActivityKind
    {
        return ActivityKind::SurveyResponse;
    }

    public function label(): string
    {
        return __('surveys::messages.activity.response_label');
    }

    public function facts(string $subjectId): array
    {
        $id = strtolower($subjectId);

        if (! Str::isUuid($id)) {
            return [];
        }

        $response = $this->counted->responses()->whereKey($id)->first(['id', 'user_id', 'submitted_on']);

        return $response instanceof SurveyResponseModel ? [$this->fact($response)] : [];
    }

    public function history(?string $cursor, int $limit): ActivityHistoryPageDTO
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The limit of a history page must be at least 1.');
        }

        // The most the core ever asks for: a page is one query and never longer than that
        $limit = min($limit, ActivityHistoryPageDTO::MAX_LIMIT);
        $after = $cursor === null ? null : $this->decode($cursor);

        $rows = $this->counted->responses()
            ->when($after !== null, static fn ($query) => $query->where('id', '>', $after))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'user_id', 'submitted_on']);

        $page = $rows->take($limit);
        $last = $page->last();

        return new ActivityHistoryPageDTO(
            subjects: array_values($page->map(fn (SurveyResponseModel $response): ActivitySubjectFactsDTO => new ActivitySubjectFactsDTO($response->id, [$this->fact($response)]))->all()),
            nextCursor: $rows->count() > $limit && $last instanceof SurveyResponseModel ? $this->encode($last->id) : null,
        );
    }

    /**
     * A response says that this person answered that survey, which the module never shows to the
     * people a survey went to (not even who answered a form): so it is described only to the person
     * who gave it (title and link to the survey) and to whoever may read the survey's results in the
     * panel (the title), with the module's own rule for that. Nobody else, even with a role that
     * manages surveys but cannot read their results, and a response that does not count is never
     * described, whoever asks.
     */
    public function describe(array $subjectIds, ?Authenticatable $viewer): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(strtolower(...), $subjectIds),
            static fn (string $id): bool => Str::isUuid($id),
        )));

        if (! $viewer instanceof Authenticatable || $ids === []) {
            return [];
        }

        $responses = $this->counted->responses()->whereIn('id', $ids)->get(['id', 'survey_id', 'user_id']);

        if ($responses->isEmpty()) {
            return [];
        }

        $surveys = SurveyModel::query()->whereIn('id', $responses->pluck('survey_id')->unique()->all())->get(['id', 'title'])->keyBy('id');
        $viewerId = (string) $viewer->getAuthIdentifier();
        /** @var array<string, bool> $mayReadResults asked once per survey, however many responses it has */
        $mayReadResults = [];
        $described = [];

        foreach ($responses as $response) {
            $survey = $surveys->get($response->survey_id);

            if (! $survey instanceof SurveyModel) {
                continue;
            }

            if ((string) $response->user_id === $viewerId) {
                $described[$response->id] = new ActivitySubjectDTO($response->id, $survey->title, route('surveys.respond', $survey->id, false));

                continue;
            }

            $mayReadResults[$survey->id] ??= Gate::forUser($viewer)->allows('viewResults', $survey);

            if ($mayReadResults[$survey->id]) {
                $described[$response->id] = new ActivitySubjectDTO($response->id, $survey->title);
            }
        }

        return $described;
    }

    private function fact(SurveyResponseModel $response): ActivityFactDTO
    {
        return new ActivityFactDTO(
            userId: (string) $response->user_id,
            role: ActivityRole::Respondent,
            occurredAt: $this->timezone->displayDayRange($response->submitted_on->format('Y-m-d'))[0],
        );
    }

    private function encode(string $responseId): string
    {
        return rtrim(strtr(base64_encode(self::CURSOR_PREFIX.$responseId), '+/', '-_'), '=');
    }

    private function decode(string $cursor): string
    {
        $decoded = preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) === 1
            ? base64_decode(strtr($cursor, '-_', '+/'), true)
            : false;

        if (! is_string($decoded) || ! str_starts_with($decoded, self::CURSOR_PREFIX)) {
            throw new InvalidArgumentException('The history cursor cannot be read.');
        }

        $id = strtolower(substr($decoded, strlen(self::CURSOR_PREFIX)));

        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('The history cursor cannot be read.');
        }

        return $id;
    }
}
