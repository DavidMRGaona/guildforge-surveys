<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Support;

use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use DateTimeImmutable;

/**
 * A survey subject source for tests: one example thing whose audience is set by each test.
 */
final class ExampleThingSource implements SurveySubjectSourceInterface
{
    public const string TYPE = 'example-thing';

    public static ?SurveyAudienceDTO $audience = null;

    public function sourceType(): string
    {
        return self::TYPE;
    }

    public function sourceLabel(): string
    {
        return 'Cosa de ejemplo';
    }

    public function find(string $subjectId): ?SurveySubjectDTO
    {
        return $subjectId === 'thing-1' ? new SurveySubjectDTO(
            sourceType: self::TYPE,
            subjectId: 'thing-1',
            title: 'Partida de ejemplo',
            startsAt: new DateTimeImmutable('2030-01-05T16:00:00+00:00'),
            endsAt: new DateTimeImmutable('2030-01-05T20:00:00+00:00'),
            organizerIds: [],
        ) : null;
    }

    public function audience(string $subjectId): SurveyAudienceDTO
    {
        return $subjectId === 'thing-1' ? (self::$audience ?? SurveyAudienceDTO::empty()) : SurveyAudienceDTO::empty();
    }

    public function canManage(string $userId, string $subjectId): bool
    {
        return false;
    }
}
