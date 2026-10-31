<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Surveys\Application\Contracts\OrganizerNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

/**
 * Makes a subject's survey when the subject ends, from the template of its kind: sent by itself
 * (at once or after its delay, counted from the end) or offered to its organizers. Kinds without
 * a template get nothing, and a subject that ends twice gets one survey.
 */
final readonly class SubjectEndedService
{
    public function __construct(
        private SurveySubjectSourceRegistryInterface $sources,
        private SurveySettingsInterface $settings,
        private SurveyTemplateRepositoryInterface $templates,
        private SurveyRepositoryInterface $surveys,
        private SurveyLifecycleService $lifecycle,
        private OrganizerNotifierInterface $organizers,
    ) {}

    public function handle(string $sourceType, string $subjectId, DateTimeImmutable $endedAt, ?DateTimeImmutable $now = null): void
    {
        $now ??= now()->toDateTimeImmutable();
        $type = $this->settings->forSubjectType($sourceType);
        $template = $type->templateId === null ? null : $this->templates->find(SurveyTemplateId::fromString($type->templateId));
        $source = $this->sources->source($sourceType);
        $endKey = "{$sourceType}:{$subjectId}";

        if (! $template instanceof SurveyTemplate
            || ! $source instanceof SurveySubjectSourceInterface
            || ! $source->find($subjectId) instanceof SurveySubjectDTO
            || $this->surveys->existsForSubjectEnd($endKey)) {
            return;
        }

        try {
            $survey = $this->lifecycle->createFromTemplateForSubject($sourceType, $subjectId, $template, $type->anonymous, $type->closeDays, $endKey);
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
}
