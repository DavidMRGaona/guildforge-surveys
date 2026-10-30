<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

final readonly class ScaleSettings
{
    public function __construct(
        public int $min,
        public int $max,
        public ?string $minLabel = null,
        public ?string $maxLabel = null,
    ) {}

    /**
     * @return array{min: int, max: int, min_label: ?string, max_label: ?string}
     */
    public function toArray(): array
    {
        return ['min' => $this->min, 'max' => $this->max, 'min_label' => $this->minLabel, 'max_label' => $this->maxLabel];
    }
}
