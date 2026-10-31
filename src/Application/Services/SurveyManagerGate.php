<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;

/**
 * Who may manage a subject's survey on the public site: whoever its source lets manage the
 * subject, asked live. Without the module that offered the subject, nobody may.
 */
final readonly class SurveyManagerGate
{
    public function __construct(private SurveySubjectSourceRegistryInterface $sources) {}

    public function canManage(string $userId, Survey $survey): bool
    {
        $subject = $survey->subject();
        if (! $subject instanceof SubjectSnapshot) {
            return false;
        }

        $source = $this->sources->source($subject->sourceType);

        return $source instanceof SurveySubjectSourceInterface && $source->canManage($userId, $subject->subjectId);
    }

    /**
     * The snapshot's organizers who still manage the subject.
     *
     * @return list<string>
     */
    public function managingOrganizers(Survey $survey): array
    {
        $organizers = $survey->subject()->organizerIds ?? [];

        return array_values(array_filter($organizers, fn (string $userId): bool => $this->canManage($userId, $survey)));
    }
}
