<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

use Modules\Surveys\Domain\Enums\QuestionType;

/**
 * One question. The id is stable within its survey (answers point at it); the key is only
 * set on questions coming from a template and stays the same across every survey made from
 * it, so trends can compare them over time. $nextByOption sends
 * the respondent elsewhere after this question's section depending on the option chosen;
 * only types that canBranch() use it. $slots are the dates an availability question proposes.
 */
final readonly class Question
{
    /**
     * @param  list<Option>  $options
     * @param  list<OptionJump>  $nextByOption
     * @param  list<Slot>  $slots
     */
    public function __construct(
        public string $id,
        public ?string $key,
        public QuestionType $type,
        public string $title,
        public ?string $help,
        public bool $required,
        public array $options = [],
        public bool $allowOther = false,
        public ?ScaleSettings $scale = null,
        public ?int $stars = null,
        public ?GridSettings $grid = null,
        public array $nextByOption = [],
        public array $slots = [],
    ) {}

    /**
     * Where the given option leads, or null when it follows its section.
     */
    public function nextFor(string $optionId): ?string
    {
        foreach ($this->nextByOption as $jump) {
            if ($jump->optionId === $optionId) {
                return $jump->next;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'type' => $this->type->value,
            'title' => $this->title,
            'help' => $this->help,
            'required' => $this->required,
            'options' => array_map(static fn (Option $option): array => $option->toArray(), $this->options),
            'allow_other' => $this->allowOther,
            'scale' => $this->scale?->toArray(),
            'stars' => $this->stars,
            'grid' => $this->grid?->toArray(),
            'next_by_option' => array_map(static fn (OptionJump $jump): array => $jump->toArray(), $this->nextByOption),
            'slots' => array_map(static fn (Slot $slot): array => $slot->toArray(), $this->slots),
        ];
    }
}
