<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

/**
 * The module settings: release defaults (config/settings.php) with what the panel saved on top.
 */
final readonly class SurveySettings
{
    public const int DEFAULT_MIN_RESPONSES = 3;

    public const int DEFAULT_CLOSE_DAYS = 7;

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
}
