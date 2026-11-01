<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\OrganizerNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;

/**
 * What a subject's end does to its survey. A survey its organizers prepared ahead is taken as it
 * is now (the subject refreshed) and goes out or is offered as they chose; otherwise one is made
 * from the template of its kind, sent by itself (at once or after its delay, counted from the
 * end) or offered to its organizers. Kinds without a template get nothing, and a subject that
 * ends twice gets one survey, sent once.
 */
final readonly class SubjectEndedService
{
    public function __construct(
        private SurveySubjectSourceRegistryInterface $sources,
        private SurveySettingsInterface $settings,
        private EndSurveyTemplateResolver $endTemplates,
        private SurveyRepositoryInterface $surveys,
        private SurveyLifecycleService $lifecycle,
        private OrganizerNotifierInterface $organizers,
        private OrganizerSurveyService $organizerSurveys,
    ) {}

    public function handle(string $sourceType, string $subjectId, DateTimeImmutable $endedAt, ?DateTimeImmutable $now = null): void
    {
        $now ??= now()->toDateTimeImmutable();
        $source = $this->sources->source($sourceType);
        $subject = $source instanceof SurveySubjectSourceInterface ? $source->find($subjectId) : null;
        if (! $subject instanceof SurveySubjectDTO) {
            return;
        }

        $endKey = "{$sourceType}:{$subjectId}";
        if ($this->surveys->existsForSubjectEnd($endKey)) {
            $this->endPrepared($endKey, $subject, $endedAt, $now);

            return;
        }

        $type = $this->settings->forSubjectType($sourceType);
        $template = $this->endTemplates->templateFor($sourceType, $subjectId, $subject);
        if (! $template instanceof SurveyTemplate) {
            return;
        }

        try {
            $survey = $this->lifecycle->createFromTemplateForSubject(
                $sourceType,
                $subjectId,
                $template,
                $type->anonymous,
                $type->closeDays,
                $endKey,
                subjectEndedAt: $endedAt,
            );
        } catch (UniqueConstraintViolationException) {
            // The same end, handled at the same time by another worker
            return;
        }

        if (! $type->autoSend) {
            $this->organizers->readyToSend($survey);

            return;
        }

        $sendAt = $endedAt->modify("+{$type->sendDelayHours} hours");
        if ($sendAt <= $now) {
            $this->lifecycle->open($survey->id(), $now);

            return;
        }

        $survey->scheduleSending($sendAt);
        $this->surveys->save($survey);
        $this->organizers->scheduled($survey);
    }

    /**
     * The end of a subject whose end survey already exists: only one prepared ahead and still
     * waiting moves; a repeated end, a dismissed survey or one opened from the panel stay as they
     * are. Its content may have changed, so going out at once follows the review rule.
     */
    private function endPrepared(string $endKey, SurveySubjectDTO $subject, DateTimeImmutable $endedAt, DateTimeImmutable $now): void
    {
        $next = DB::transaction(function () use ($endKey, $subject, $endedAt, $now): ?string {
            $survey = $this->surveys->findBySubjectEndForUpdate($endKey);
            if (! $survey instanceof Survey || ! $survey->isAwaitingSubjectEnd()) {
                return null;
            }

            $survey->markSubjectEnded($endedAt);
            $survey->refreshSubject(SubjectSnapshotFactory::fromDto($subject));

            if ($survey->dismissedAt() instanceof DateTimeImmutable) {
                $this->surveys->save($survey);

                return null;
            }

            if (! $survey->sendsOnEnd()) {
                $this->surveys->save($survey);

                return 'offer';
            }

            $sendAt = $endedAt->modify("+{$survey->sendDelayHours()} hours");
            $survey->scheduleSending($sendAt <= $now ? $now : $sendAt);
            $this->surveys->save($survey);

            return $sendAt <= $now ? 'send' : 'scheduled';
        });

        $survey = $this->surveys->findBySubjectEnd($endKey);
        if (! $survey instanceof Survey) {
            return;
        }

        match ($next) {
            'offer' => $this->organizers->readyToSend($survey),
            'scheduled' => $this->organizers->scheduled($survey),
            'send' => $this->organizerSurveys->sendScheduled($survey->id(), $now),
            null => null,
        };
    }
}
