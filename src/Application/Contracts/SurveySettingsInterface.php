<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Application\DTOs\SubjectTypeSettings;

interface SurveySettingsInterface
{
    /**
     * Responses an anonymous survey needs before its results, free text or export show; 0 means none.
     */
    public function minResponses(): int;

    public function defaultCloseDays(): int;

    public function anonymousByDefault(): bool;

    /**
     * Whether a new general survey is announced on the channels unless unticked.
     */
    public function announceInChannelsByDefault(): bool;

    /**
     * Whether a new survey mails a summary of its results when it closes, unless unticked.
     */
    public function closingSummaryByDefault(): bool;

    /**
     * Whether closing summaries also go to everyone who may see results in the panel.
     */
    public function closingSummaryToResultsViewers(): bool;

    /**
     * Whether content changed outside the panel waits for a moderator before it goes out.
     */
    public function reviewRequired(): bool;

    /**
     * The settings of one kind of subject, with the global defaults where nothing was saved.
     */
    public function forSubjectType(string $sourceType): SubjectTypeSettings;
}
