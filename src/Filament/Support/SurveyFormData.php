<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;

/**
 * Reads the survey form's validated data into domain values.
 */
final class SurveyFormData
{
    /**
     * The stored survey as the form shows it: the definition as editor state and the two results
     * switches on their own fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forFill(array $data): array
    {
        $definition = is_array($data['definition'] ?? null) ? $data['definition'] : ['sections' => []];
        $results = ResultsSettings::fromArray(is_array($data['results_settings'] ?? null) ? $data['results_settings'] : []);

        $data['sections'] = DefinitionFormState::fromDefinition(SurveyDefinition::fromArray($definition));
        $data['live_results'] = $results->liveResults;
        $data['visible_to_respondents'] = $results->visibleToRespondents;

        return $data;
    }

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
     * A public link asks for an email unless the form makes it open.
     *
     * @param  array<string, mixed>  $data
     */
    public static function publicLinkMode(array $data): PublicLinkMode
    {
        return PublicLinkMode::tryFrom(is_string($data['public_link_mode'] ?? null) ? $data['public_link_mode'] : '') ?? PublicLinkMode::VerifiedEmail;
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
