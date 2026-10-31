<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

/**
 * How the surveys of one kind of subject (game tables, tournaments…) are made when a subject ends.
 * Without a template, subjects of that kind get no survey.
 */
final readonly class SubjectTypeSettings
{
    public function __construct(
        public ?string $templateId,
        public bool $autoSend,
        // Hours after the subject ends before an automatic survey goes out; 0 sends it at once
        public int $sendDelayHours,
        public int $closeDays,
        public bool $anonymous,
    ) {}
}
