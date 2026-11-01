<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Support;

use App\Application\Surveys\Contracts\SurveySubjectAttributeValuesInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveySubjectAttributeDTO;
use App\Application\Surveys\DTOs\SurveySubjectAttributeValueDTO;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use DateTimeImmutable;

/**
 * A survey subject source for tests: one example thing whose audience, organizers and managers
 * are set by each test (reset() puts them back), as are its game system and the game systems it
 * lists. A second thing, thing-3, with its own managers and status, is for moving a survey
 * from one subject to another.
 */
final class ExampleThingSource implements SurveySubjectAttributeValuesInterface, SurveySubjectSourceInterface
{
    public const string TYPE = 'example-thing';

    public static ?SurveyAudienceDTO $audience = null;

    /** @var list<string> */
    public static array $organizerIds = [];

    /** @var list<string> users canManage() lets in */
    public static array $managers = [];

    public static string $title = 'Partida de ejemplo';

    public static SurveySubjectStatus $status = SurveySubjectStatus::Active;

    /** @var list<string> users canManage() lets in on thing-3, its organizers too */
    public static array $otherManagers = [];

    public static SurveySubjectStatus $otherStatus = SurveySubjectStatus::Active;

    /** The thing's game_system attribute, when it has one */
    public static ?SurveySubjectAttributeDTO $gameSystem = null;

    /** @var list<SurveySubjectAttributeValueDTO> */
    public static array $gameSystems = [];

    public static function reset(): void
    {
        self::$audience = null;
        self::$organizerIds = [];
        self::$managers = [];
        self::$title = 'Partida de ejemplo';
        self::$status = SurveySubjectStatus::Active;
        self::$otherManagers = [];
        self::$otherStatus = SurveySubjectStatus::Active;
        self::$gameSystem = null;
        self::$gameSystems = [];
    }

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
        if ($subjectId === 'thing-3') {
            return new SurveySubjectDTO(
                sourceType: self::TYPE,
                subjectId: 'thing-3',
                title: 'Otra partida de ejemplo',
                startsAt: new DateTimeImmutable('2030-02-05T16:00:00+00:00'),
                endsAt: new DateTimeImmutable('2030-02-05T20:00:00+00:00'),
                organizerIds: self::$otherManagers,
                status: self::$otherStatus,
            );
        }

        return $subjectId === 'thing-1' ? new SurveySubjectDTO(
            sourceType: self::TYPE,
            subjectId: 'thing-1',
            title: self::$title,
            startsAt: new DateTimeImmutable('2030-01-05T16:00:00+00:00'),
            endsAt: new DateTimeImmutable('2030-01-05T20:00:00+00:00'),
            organizerIds: self::$organizerIds,
            attributes: self::$gameSystem instanceof SurveySubjectAttributeDTO ? [self::$gameSystem] : [],
            status: self::$status,
        ) : null;
    }

    public function audience(string $subjectId): SurveyAudienceDTO
    {
        return $subjectId === 'thing-1' ? (self::$audience ?? SurveyAudienceDTO::empty()) : SurveyAudienceDTO::empty();
    }

    public function canManage(string $userId, string $subjectId): bool
    {
        return match ($subjectId) {
            'thing-1' => in_array($userId, self::$managers, true),
            'thing-3' => in_array($userId, self::$otherManagers, true),
            default => false,
        };
    }

    public function attributeValues(string $key): array
    {
        return $key === 'game_system' ? self::$gameSystems : [];
    }
}
