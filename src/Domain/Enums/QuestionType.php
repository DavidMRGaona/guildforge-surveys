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
    case Ranking = 'ranking';
    case Availability = 'availability';

    /**
     * Whether the question has a list of options: to pick from, or to put in order.
     */
    public function hasOptions(): bool
    {
        return $this->isChoice() || $this === self::Ranking;
    }

    /**
     * Whether the answer picks one or more of the options.
     */
    public function isChoice(): bool
    {
        return in_array($this, [self::SingleChoice, self::MultipleChoice, self::Dropdown], true);
    }

    /**
     * Whether the question may offer an "Other" option with free text.
     */
    public function allowsOther(): bool
    {
        return $this->isChoice();
    }

    /**
     * Whether the chosen option may decide the next section. Only one answer can be chosen,
     * so there is never more than one way to go.
     */
    public function canBranch(): bool
    {
        return $this === self::SingleChoice || $this === self::Dropdown;
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
            // Points per option, or how often each place went to each option
            self::Ranking => [ChartType::Bar, ChartType::StackedBar],
            // How many can, could and cannot make each slot
            self::Availability => [ChartType::StackedBar],
            self::ShortText, self::Paragraph => [ChartType::List],
            default => [ChartType::Bar],
        };
    }

    public function defaultChartType(): ChartType
    {
        return $this->chartTypes()[0];
    }
}
