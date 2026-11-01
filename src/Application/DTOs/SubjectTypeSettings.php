<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

/**
 * How the surveys of one kind of subject (game tables, tournaments…) are made when a subject ends.
 * Without a template, subjects of that kind get no survey, unless their game system has one.
 */
final readonly class SubjectTypeSettings
{
    /**
     * @param  array<string, string>  $gameSystemTemplates  template id by game system id (the
     *                                                      game_system attribute's value id)
     */
    public function __construct(
        public ?string $templateId,
        public bool $autoSend,
        // Hours after the subject ends before an automatic survey goes out; 0 sends it at once
        public int $sendDelayHours,
        public int $closeDays,
        public bool $anonymous,
        public array $gameSystemTemplates = [],
    ) {}

    /**
     * The template set for this game system, if any; the type's template is not a fallback here.
     */
    public function gameSystemTemplateId(?string $gameSystemId): ?string
    {
        return $gameSystemId === null ? null : ($this->gameSystemTemplates[$gameSystemId] ?? null);
    }
}
