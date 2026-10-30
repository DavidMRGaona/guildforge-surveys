<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

final readonly class Option
{
    public function __construct(
        public string $id,
        public string $label,
    ) {}

    /**
     * @return array{id: string, label: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label];
    }
}
