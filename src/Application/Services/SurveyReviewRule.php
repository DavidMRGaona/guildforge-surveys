<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Domain\Entities\Survey;

/**
 * The one rule on what waits for a moderator: content changed outside the panel while the
 * review setting is on. A template sent untouched never waits, nor do changes of dates,
 * anonymity or results settings, which are not content.
 */
final readonly class SurveyReviewRule
{
    public function __construct(private SurveySettingsInterface $settings) {}

    public function needsReview(Survey $survey): bool
    {
        return $this->settings->reviewRequired() && $survey->hasUnreviewedChanges();
    }
}
