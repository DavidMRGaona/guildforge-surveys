<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use Illuminate\Support\Facades\Log;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Repositories\SubjectEndChoiceRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SubjectEndChoice;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

/**
 * The template a subject's end survey is made from, when no survey of its own was prepared:
 * the one chosen in the subject's form, else its type's. Rules per game system (#45) would go
 * between the two.
 */
final readonly class EndSurveyTemplateResolver
{
    public function __construct(
        private SubjectEndChoiceRepositoryInterface $choices,
        private SurveySettingsInterface $settings,
        private SurveyTemplateRepositoryInterface $templates,
    ) {}

    /**
     * Null when the subject was chosen to have no survey, or its type has no template. A chosen
     * template deleted since falls back to the type's.
     */
    public function templateFor(string $sourceType, string $subjectId): ?SurveyTemplate
    {
        $choice = $this->choices->find($sourceType, $subjectId);
        if ($choice instanceof SubjectEndChoice && $choice->isNone()) {
            return null;
        }

        if ($choice instanceof SubjectEndChoice && $choice->templateId instanceof SurveyTemplateId) {
            $chosen = $this->templates->find($choice->templateId);
            if ($chosen instanceof SurveyTemplate) {
                return $chosen;
            }

            Log::warning('surveys: the template chosen for a subject no longer exists; using its type\'s', [
                'source_type' => $sourceType,
                'subject_id' => $subjectId,
                'template_id' => $choice->templateId->value,
            ]);
        }

        return $this->typeTemplate($sourceType);
    }

    public function typeTemplate(string $sourceType): ?SurveyTemplate
    {
        $templateId = $this->settings->forSubjectType($sourceType)->templateId;

        return $templateId === null ? null : $this->templates->find(SurveyTemplateId::fromString($templateId));
    }
}
