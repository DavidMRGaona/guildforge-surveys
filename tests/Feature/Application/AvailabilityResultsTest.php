<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Modules\Surveys\Application\DTOs\Results\AvailabilitySlotResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Application\Services\ClosingSummaryService;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Notifications\SurveyClosingSummary;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

final class AvailabilityResultsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    use AnswersExampleSurveys;

    private ResultsService $results;

    protected function setUp(): void
    {
        parent::setUp();

        $this->results = $this->app->make(ResultsService::class);
        $this->createExamplePeople();
    }

    public function test_availability_surveys_share_live_results_as_polls_do(): void
    {
        $defaults = ResultsSettings::defaultsFor(SurveyKind::Availability);

        $this->assertTrue($defaults->liveResults);
        $this->assertTrue($defaults->visibleToRespondents);
    }

    public function test_counts_each_slot_and_marks_the_best_by_yes_then_if_needed(): void
    {
        $id = $this->openAvailability(anonymous: false);
        $this->answerSlots($id, 0, ['d-1' => 'yes', 'd-2' => 'yes', 'd-3' => 'no']);
        $this->answerSlots($id, 1, ['d-1' => 'yes', 'd-2' => 'if_needed']);
        $this->answerSlots($id, 2, ['d-1' => 'if_needed', 'd-2' => 'yes', 'd-3' => 'yes']);

        $question = $this->question($this->results->forPanel($id));
        $slots = $question->availability->slots ?? [];

        $this->assertSame(ChartType::StackedBar, $question->chartType);
        $this->assertSame(3, $question->answered);
        $this->assertSame(
            [['d-1', 2, 1, 0, true], ['d-2', 2, 1, 0, true], ['d-3', 1, 0, 2, false]],
            array_map(static fn (AvailabilitySlotResultDTO $slot): array => [$slot->slotId, $slot->yes, $slot->ifNeeded, $slot->no, $slot->best], $slots),
        );
    }

    public function test_more_if_needed_breaks_a_tie_on_yes(): void
    {
        $id = $this->openAvailability(anonymous: false);
        $this->answerSlots($id, 0, ['d-1' => 'yes', 'd-2' => 'yes']);
        $this->answerSlots($id, 1, ['d-2' => 'if_needed']);

        $slots = $this->question($this->results->forPanel($id))->availability->slots ?? [];

        $this->assertSame([false, true, false], array_map(static fn (AvailabilitySlotResultDTO $slot): bool => $slot->best, $slots));
    }

    public function test_nothing_is_best_while_nobody_can(): void
    {
        $id = $this->openAvailability(anonymous: false);
        $this->answerSlots($id, 0, []);

        $slots = $this->question($this->results->forPanel($id))->availability->slots ?? [];

        $this->assertSame([false, false, false], array_map(static fn (AvailabilitySlotResultDTO $slot): bool => $slot->best, $slots));
    }

    public function test_slots_read_in_the_display_zone(): void
    {
        $id = $this->openAvailability(anonymous: false);

        $slots = $this->question($this->results->forPanel($id))->availability->slots ?? [];

        $this->assertSame(['2026-12-05', null, null, 'sábado, 5 de diciembre de 2026'], [$slots[0]->day, $slots[0]->start, $slots[0]->end, $slots[0]->label]);
        $this->assertSame(['2026-12-11', '17:00', '21:00', 'viernes, 11 de diciembre de 2026, de 17:00 a 21:00'], [$slots[1]->day, $slots[1]->start, $slots[1]->end, $slots[1]->label]);
        $this->assertSame(['2026-12-12', '11:00', null, 'sábado, 12 de diciembre de 2026, a las 11:00'], [$slots[2]->day, $slots[2]->start, $slots[2]->end, $slots[2]->label]);
    }

    public function test_a_named_survey_says_who_can_each_slot_and_an_anonymous_one_does_not(): void
    {
        $named = $this->openAvailability(anonymous: false);
        $this->answerSlots($named, 1, ['d-1' => 'yes']);
        $this->answerSlots($named, 0, ['d-1' => 'yes', 'd-2' => 'if_needed']);

        $slots = $this->question($this->results->forRespondents($named))->availability->slots ?? [];
        $this->assertSame([['Persona A', 'Persona B'], [], []], [$slots[0]->yesNames, $slots[0]->ifNeededNames, $slots[0]->noNames]);
        $this->assertSame([[], ['Persona A'], ['Persona B']], [$slots[1]->yesNames, $slots[1]->ifNeededNames, $slots[1]->noNames]);

        $anonymous = $this->openAvailability(anonymous: true);
        foreach ([0, 1, 2] as $person) {
            $this->answerSlots($anonymous, $person, ['d-1' => 'yes']);
        }

        $slots = $this->question($this->results->forRespondents($anonymous))->availability->slots ?? [];
        $this->assertSame(3, $slots[0]->yes);
        $this->assertSame([], $slots[0]->yesNames);
    }

    public function test_the_closing_summary_names_the_best_slots(): void
    {
        $id = $this->openAvailability(anonymous: false);
        $this->answerSlots($id, 0, ['d-1' => 'yes', 'd-2' => 'yes']);
        $this->answerSlots($id, 1, ['d-1' => 'if_needed', 'd-2' => 'if_needed']);
        $this->app->make(SurveyLifecycleService::class)->close($id);

        $question = $this->app->make(ClosingSummaryService::class)->for($id)->questions[0];

        $this->assertTrue($question->availability);
        $this->assertSame(['sábado, 5 de diciembre de 2026', 'viernes, 11 de diciembre de 2026, de 17:00 a 21:00'], $question->topOptions);
        $this->assertSame([1, 1, 2], [$question->topCount, $question->topIfNeeded, $question->answered]);

        $mail = new SurveyClosingSummary($this->app->make(ClosingSummaryService::class)->for($id), $id->value, inPanel: false, siteName: 'Asociación de ejemplo');
        $text = implode("\n", $mail->toMail($this->people[0])->introLines);
        $this->assertStringContainsString('**¿Qué día os viene bien?**: las fechas que más gente puede son «sábado, 5 de diciembre de 2026» y «viernes, 11 de diciembre de 2026, de 17:00 a 21:00» (1 sí y 1 si hace falta de 2 cada una).', $text);
    }

    private function openAvailability(bool $anonymous): SurveyId
    {
        ExampleThingSource::reset();

        return $this->openExample(SurveyKind::Availability, SurveyDefinition::fromArray(self::definition()), $anonymous, ResultsSettings::defaultsFor(SurveyKind::Availability))[0];
    }

    /**
     * @param  array<string, string>  $answers
     */
    private function answerSlots(SurveyId $id, int $person, array $answers): void
    {
        $this->answer($id, $person, ['q-1' => $answers]);
    }

    private function question(SurveyResultsDTO $results): QuestionResultDTO
    {
        $this->assertTrue($results->visible);

        return $results->questions[0];
    }

    /**
     * A whole Saturday, a Friday evening (17:00-21:00 in Madrid) and a Saturday morning start.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return ['sections' => [['id' => 's-1', 'questions' => [[
            'id' => 'q-1', 'type' => 'availability', 'title' => '¿Qué día os viene bien?', 'required' => true,
            'slots' => [
                ['id' => 'd-1', 'date' => '2026-12-05'],
                ['id' => 'd-2', 'starts_at' => '2026-12-11T16:00:00+00:00', 'ends_at' => '2026-12-11T20:00:00+00:00'],
                ['id' => 'd-3', 'starts_at' => '2026-12-12T10:00:00+00:00'],
            ],
        ]]]]];
    }
}
