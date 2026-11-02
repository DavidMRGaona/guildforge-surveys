<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Services;

use Modules\Surveys\Domain\Enums\FileKind;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;

/**
 * What file questions need from the survey around them. An anonymous survey takes no PDF: its
 * properties may name whoever wrote it, and nothing can strip them as images are stripped. The
 * open public link takes no file at all: anyone could fill the storage with it.
 */
final class FileQuestionRules
{
    /**
     * @return list<DefinitionViolation>
     */
    public function violations(SurveyDefinition $definition, bool $anonymous, bool $publicLinkEnabled, PublicLinkMode $publicLinkMode): array
    {
        $violations = [];
        $hasFiles = false;
        foreach ($definition->sections as $s => $section) {
            foreach ($section->questions as $q => $question) {
                if ($question->type !== QuestionType::File) {
                    continue;
                }
                $hasFiles = true;
                if ($anonymous && $question->file?->accepts(FileKind::Pdf) === true) {
                    $violations[] = new DefinitionViolation("sections.{$s}.questions.{$q}.file.accept", 'pdf_not_allowed_anonymous');
                }
            }
        }

        if ($hasFiles && $publicLinkEnabled && $publicLinkMode === PublicLinkMode::Open) {
            $violations[] = new DefinitionViolation('public_link_mode', 'file_needs_verified_link');
        }

        return $violations;
    }
}
