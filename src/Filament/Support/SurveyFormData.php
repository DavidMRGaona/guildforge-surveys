<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;

/**
 * Reads the survey form's validated data into domain values.
 */
final class SurveyFormData
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function closesAt(array $data): ?DateTimeImmutable
    {
        $value = $data['closes_at'] ?? null;

        // The picker hands over a wall time in the storage zone (it converts from the display zone)
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toDateTimeImmutable() : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function reminderHoursBefore(array $data): ?int
    {
        $value = $data['reminder_hours_before'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function audienceType(array $data): AudienceType
    {
        return AudienceType::tryFrom(is_string($data['audience_type'] ?? null) ? $data['audience_type'] : '') ?? AudienceType::AllUsers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function audienceRoles(array $data): array
    {
        $roles = $data['audience_roles'] ?? [];

        return is_array($roles) ? array_values(array_filter($roles, is_string(...))) : [];
    }

    /**
     * The form sets the two switches; chart types and hidden questions are kept as they were.
     *
     * @param  array<string, mixed>  $data
     */
    public static function results(array $data, ResultsSettings $current): ResultsSettings
    {
        return new ResultsSettings(
            liveResults: (bool) ($data['live_results'] ?? false),
            visibleToRespondents: (bool) ($data['visible_to_respondents'] ?? false),
            chartTypes: $current->chartTypes,
            hiddenQuestionIds: $current->hiddenQuestionIds,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableText(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
