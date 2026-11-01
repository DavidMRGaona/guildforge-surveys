<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use Illuminate\Support\Facades\Log;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Repositories\SubjectEndChoiceRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SubjectEndChoice;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

/**
 * The template a subject's end survey is made from, when no survey of its own was prepared:
 * the one chosen in the subject's form, else the one set for its game system (recognised by the
 * game_system attribute's value id, never by its name), else its type's.
 */
final readonly class EndSurveyTemplateResolver
{
    public function __construct(
        private SubjectEndChoiceRepositoryInterface $choices,
        private SurveySettingsInterface $settings,
        private SurveyTemplateRepositoryInterface $templates,
        private SurveySubjectSourceRegistryInterface $sources,
    ) {}

    /**
     * Null when the subject was chosen to have no survey, or nothing gives it a template. A
     * chosen template deleted since falls back to its default. The subject is looked up unless
     * the caller already has it.
     */
    public function templateFor(string $sourceType, string $subjectId, ?SurveySubjectDTO $subject = null): ?SurveyTemplate
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

        return $this->defaultTemplate($sourceType, $subject ?? $this->subject($sourceType, $subjectId));
    }

    /**
     * What a subject gets unless its form says otherwise: its game system's template, else its
     * type's. Without the subject (being created, or not offered yet) only the type's is known.
     */
    public function defaultTemplate(string $sourceType, ?SurveySubjectDTO $subject): ?SurveyTemplate
    {
        return $this->gameSystemTemplate($sourceType, $subject) ?? $this->typeTemplate($sourceType);
    }

    /**
     * The template set for the subject's game system, while it exists.
     */
    public function gameSystemTemplate(string $sourceType, ?SurveySubjectDTO $subject): ?SurveyTemplate
    {
        $templateId = $this->settings->forSubjectType($sourceType)->gameSystemTemplateId($subject?->attribute('game_system')?->valueId);
        if ($templateId === null) {
            return null;
        }

        $template = $this->templates->find(SurveyTemplateId::fromString($templateId));
        if (! $template instanceof SurveyTemplate) {
            Log::warning('surveys: the template set for a game system no longer exists; using its type\'s', [
                'source_type' => $sourceType,
                'template_id' => $templateId,
            ]);
        }

        return $template;
    }

    /**
     * Whether some game system of this kind of subject has a template of its own.
     */
    public function hasGameSystemTemplates(string $sourceType): bool
    {
        return $this->settings->forSubjectType($sourceType)->gameSystemTemplates !== [];
    }

    public function typeTemplate(string $sourceType): ?SurveyTemplate
    {
        $templateId = $this->settings->forSubjectType($sourceType)->templateId;

        return $templateId === null ? null : $this->templates->find(SurveyTemplateId::fromString($templateId));
    }

    private function subject(string $sourceType, string $subjectId): ?SurveySubjectDTO
    {
        $source = $this->sources->source($sourceType);

        return $source instanceof SurveySubjectSourceInterface ? $source->find($subjectId) : null;
    }
}
