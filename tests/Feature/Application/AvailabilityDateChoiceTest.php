<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Services\SiteBrandingServiceInterface;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\ChosenDateNotifierInterface;
use Modules\Surveys\Application\Services\AvailabilityDateService;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Notifications\AvailabilityDateChosen;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Once an availability survey closes, its date is fixed among the proposed slots, once, and
 * everyone invited or who answered with an account is told.
 */
final class AvailabilityDateChoiceTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private AvailabilityDateService $dates;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->dates = $this->app->make(AvailabilityDateService::class);
        $this->createExamplePeople();
    }

    public function test_fixing_the_date_of_a_closed_survey_keeps_it_and_tells_everyone_once(): void
    {
        $this->mock(SiteBrandingServiceInterface::class)->shouldReceive('siteName')->andReturn('Asociación de ejemplo');
        $id = $this->closedSurvey(anonymous: false);

        // Built after the site name is set
        $this->app->make(AvailabilityDateService::class)->choose($id, 'd-2');

        $survey = $this->app->make(SurveyRepositoryInterface::class)->findOrFail($id);
        $this->assertSame('d-2', $survey->chosenSlot()?->id);
        $this->assertNotNull($survey->chosenSlotAt());
        foreach ($this->people as $person) {
            Notification::assertSentToTimes($person, AvailabilityDateChosen::class, 1);
        }
        Notification::assertSentTo($this->people[0], AvailabilityDateChosen::class, function (AvailabilityDateChosen $mail): bool {
            $message = $mail->toMail($this->people[0]);
            $text = implode("\n", $message->introLines);

            $this->assertSame('Fecha elegida: «Encuesta de ejemplo»', $message->subject);
            $this->assertStringContainsString('Partida de ejemplo', $text);
            $this->assertStringContainsString('Asociación de ejemplo', $text);
            $this->assertStringContainsString('**Viernes, 11 de diciembre de 2026, de 17:00 a 21:00**', $text);

            return true;
        });
    }

    public function test_the_results_name_the_chosen_slot(): void
    {
        $id = $this->closedSurvey(anonymous: false);
        $this->dates->choose($id, 'd-1');

        $availability = $this->app->make(ResultsService::class)->forPanel($id)->questions[0]->availability;

        $this->assertSame('d-1', $availability?->chosenSlotId);
    }

    public function test_an_anonymous_survey_tells_its_audience_too(): void
    {
        $id = $this->closedSurvey(anonymous: true);

        $this->dates->choose($id, 'd-1');

        Notification::assertSentTo($this->people[2], AvailabilityDateChosen::class);
    }

    public function test_the_date_is_fixed_once_among_the_slots_of_a_closed_survey(): void
    {
        $open = $this->openAvailability();
        $this->expectRefused(fn () => $this->dates->choose($open, 'd-1'));

        $closed = $this->closedSurvey(anonymous: false);
        $this->expectRefused(fn () => $this->dates->choose($closed, 'd-9'));

        $this->dates->choose($closed, 'd-1');
        $this->expectRefused(fn () => $this->dates->choose($closed, 'd-2'));

        [$form] = $this->openForm(anonymous: false);
        $this->app->make(SurveyLifecycleService::class)->close($form);
        $this->expectRefused(fn () => $this->dates->choose($form, 'd-1'));
    }

    public function test_a_failing_notice_does_not_undo_the_date(): void
    {
        $id = $this->closedSurvey(anonymous: false);
        $this->mock(ChosenDateNotifierInterface::class)
            ->shouldReceive('dateChosen')->andThrow(new RuntimeException('Mail down'));

        $this->dates->choose($id, 'd-1');

        $this->assertSame('d-1', $this->app->make(SurveyRepositoryInterface::class)->findOrFail($id)->chosenSlot()?->id);
    }

    /**
     * @param  callable(): void  $choose
     */
    private function expectRefused(callable $choose): void
    {
        try {
            $choose();
            $this->fail('Fixing this date must be refused.');
        } catch (InvalidSurveyStateException) {
            $this->addToAssertionCount(1);
        }
    }

    private function openAvailability(bool $anonymous = false): SurveyId
    {
        ExampleThingSource::reset();

        return $this->openExample(SurveyKind::Availability, SurveyDefinition::fromArray(AvailabilityResultsTest::definition()), $anonymous, ResultsSettings::defaultsFor(SurveyKind::Availability))[0];
    }

    /**
     * Two of the three people answer; the third was invited and did not.
     */
    private function closedSurvey(bool $anonymous): SurveyId
    {
        $id = $this->openAvailability($anonymous);
        $this->answer($id, 0, ['q-1' => ['d-1' => 'yes', 'd-2' => 'yes']]);
        $this->answer($id, 1, ['q-1' => ['d-2' => 'if_needed']]);
        $this->app->make(SurveyLifecycleService::class)->close($id);

        return $id;
    }
}
