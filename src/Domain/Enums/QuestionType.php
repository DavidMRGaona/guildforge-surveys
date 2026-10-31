<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

enum QuestionType: string
{
    case ShortText = 'short_text';
    case Paragraph = 'paragraph';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Dropdown = 'dropdown';
    case LinearScale = 'linear_scale';
    case StarRating = 'star_rating';
    case Date = 'date';
    case Time = 'time';
    case Grid = 'grid';

    /**
     * Whether the question offers a list of options to pick from.
     */
    public function hasOptions(): bool
    {
        return in_array($this, [self::SingleChoice, self::MultipleChoice, self::Dropdown], true);
    }

    /**
     * Whether the question may offer an "Other" option with free text.
     */
    public function allowsOther(): bool
    {
        return $this->hasOptions();
    }

    public function isFreeText(): bool
    {
        return $this === self::ShortText || $this === self::Paragraph;
    }

    /**
     * Charts that fit the question's answers, the default first.
     *
     * @return non-empty-list<ChartType>
     */
    public function chartTypes(): array
    {
        return match ($this) {
            self::SingleChoice, self::Dropdown => [ChartType::Bar, ChartType::Pie],
            self::Grid => [ChartType::StackedBar],
            self::ShortText, self::Paragraph => [ChartType::List],
            default => [ChartType::Bar],
        };
    }

    public function defaultChartType(): ChartType
    {
        return $this->chartTypes()[0];
    }
}
