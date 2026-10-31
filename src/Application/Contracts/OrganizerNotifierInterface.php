<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Domain\Entities\Survey;

/**
 * Mails about a subject's survey to the people who organize the subject.
 */
interface OrganizerNotifierInterface
{
    /**
     * The subject ended and its survey waits for an organizer to send it.
     */
    public function readyToSend(Survey $survey): void;

    /**
     * The subject ended and its survey will go out by itself at its scheduled time.
     */
    public function scheduled(Survey $survey): void;

    /**
     * The subject was cancelled before it ended, so its survey will not go out.
     */
    public function withdrawn(Survey $survey): void;
}
