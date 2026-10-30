<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Factories;

use App\Application\Surveys\DTOs\SurveySubjectAttributeDTO;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;

/**
 * Freezes what a survey subject source returns into the survey's own snapshot.
 */
final class SubjectSnapshotFactory
{
    public static function fromDto(SurveySubjectDTO $subject): SubjectSnapshot
    {
        return new SubjectSnapshot(
            sourceType: $subject->sourceType,
            subjectId: $subject->subjectId,
            title: $subject->title,
            startsAt: $subject->startsAt,
            endsAt: $subject->endsAt,
            organizerIds: $subject->organizerIds,
            attributes: array_map(
                static fn (SurveySubjectAttributeDTO $attribute): array => ['key' => $attribute->key, 'label' => $attribute->label, 'value' => $attribute->value],
                $subject->attributes,
            ),
            url: $subject->url,
        );
    }
}
