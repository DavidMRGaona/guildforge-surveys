<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Enums;

use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use PHPUnit\Framework\TestCase;

final class ChartTypeTest extends TestCase
{
    public function test_each_question_type_offers_its_charts_and_a_default(): void
    {
        $this->assertSame([ChartType::Bar, ChartType::Pie], QuestionType::SingleChoice->chartTypes());
        $this->assertSame([ChartType::Bar, ChartType::Pie], QuestionType::Dropdown->chartTypes());
        $this->assertSame([ChartType::Bar], QuestionType::MultipleChoice->chartTypes());
        $this->assertSame([ChartType::StackedBar], QuestionType::Grid->chartTypes());
        $this->assertSame([ChartType::List], QuestionType::Paragraph->chartTypes());
        $this->assertSame([ChartType::Bar], QuestionType::StarRating->chartTypes());
        $this->assertSame([ChartType::Bar], QuestionType::Time->chartTypes());
        $this->assertSame([ChartType::Bar, ChartType::StackedBar], QuestionType::Ranking->chartTypes());

        foreach (QuestionType::cases() as $type) {
            $this->assertSame($type->chartTypes()[0], $type->defaultChartType());
        }
    }

    public function test_results_settings_keep_a_valid_chart_and_fall_back_otherwise(): void
    {
        $single = $this->question('q-1', QuestionType::SingleChoice);
        $grid = $this->question('q-2', QuestionType::Grid);
        $settings = new ResultsSettings(chartTypes: ['q-1' => 'pie', 'q-2' => 'pie'], hiddenQuestionIds: ['q-2']);

        $this->assertSame(ChartType::Pie, $settings->chartTypeFor($single));
        $this->assertSame(ChartType::StackedBar, $settings->chartTypeFor($grid));
        $this->assertSame(ChartType::Bar, (new ResultsSettings(chartTypes: ['q-1' => 'nonsense']))->chartTypeFor($single));
        $this->assertTrue($settings->isHidden('q-2'));
        $this->assertFalse($settings->isHidden('q-1'));
    }

    private function question(string $id, QuestionType $type): Question
    {
        return new Question($id, null, $type, 'Pregunta', null, true, $type->hasOptions() ? [new Option('o-1', 'Uno'), new Option('o-2', 'Dos')] : []);
    }
}
