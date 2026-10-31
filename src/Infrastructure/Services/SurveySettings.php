<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\SubjectTypeSettings;

/**
 * The module settings: release defaults (config/settings.php) with what the panel saved on top.
 */
final readonly class SurveySettings implements SurveySettingsInterface
{
    public const int DEFAULT_MIN_RESPONSES = 3;

    public const int DEFAULT_CLOSE_DAYS = 7;

    public const int DEFAULT_SEND_DELAY_HOURS = 12;

    /**
     * Responses an anonymous survey needs before its results, free text or export show.
     * 0 means no minimum; a negative value is read as 0.
     */
    public function minResponses(): int
    {
        $value = config('modules.settings.surveys.min_responses');

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_MIN_RESPONSES;
    }

    public function defaultCloseDays(): int
    {
        $value = config('modules.settings.surveys.default_close_days');

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : self::DEFAULT_CLOSE_DAYS;
    }

    public function anonymousByDefault(): bool
    {
        return (bool) config('modules.settings.surveys.anonymous_by_default', true);
    }

    public function reviewRequired(): bool
    {
        return (bool) config('modules.settings.surveys.review_required', false);
    }

    /**
     * Read by key, not through config's dot notation: subject types are kebab-case keys of their own.
     */
    public function forSubjectType(string $sourceType): SubjectTypeSettings
    {
        $types = config('modules.settings.surveys.subject_types');
        $entry = is_array($types) && is_array($types[$sourceType] ?? null) ? $types[$sourceType] : [];

        $templateId = $entry['template_id'] ?? null;
        $delay = $entry['send_delay_hours'] ?? null;
        $closeDays = $entry['close_days'] ?? null;

        return new SubjectTypeSettings(
            templateId: is_string($templateId) && $templateId !== '' ? $templateId : null,
            autoSend: (bool) ($entry['auto_send'] ?? false),
            sendDelayHours: is_numeric($delay) && (int) $delay >= 0 ? (int) $delay : self::DEFAULT_SEND_DELAY_HOURS,
            closeDays: is_numeric($closeDays) && (int) $closeDays >= 1 ? (int) $closeDays : $this->defaultCloseDays(),
            anonymous: array_key_exists('anonymous', $entry) && $entry['anonymous'] !== null ? (bool) $entry['anonymous'] : $this->anonymousByDefault(),
        );
    }
}
