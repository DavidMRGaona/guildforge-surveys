<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Console;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Console\Commands\SendSurveyRemindersCommand;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

final class SendSurveyRemindersCommandTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $answered;

    private UserModel $pending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(SendSurveyRemindersCommand::class));
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->answered = UserModel::factory()->create();
        $this->pending = UserModel::factory()->create();
        ExampleThingSource::$audience = new SurveyAudienceDTO(
            [$this->answered->id, $this->pending->id],
            [new SurveyGuestDTO('guest-1', 'Invitada', 'invitada@example.test')],
        );
    }

    public function test_reminds_only_who_has_not_answered_once_in_the_window(): void
    {
        [$id, $opened] = $this->openSurvey(closesInHours: 10, reminderHours: 24);
        $this->app->make(SubmissionService::class)->submit($id, Respondent::account($this->answered->id), ['q-1' => 'o-1']);
        Notification::fake();

        $this->artisanCommand('surveys:send-reminders')->assertSuccessful();
        $this->artisanCommand('surveys:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->pending, SurveyInvitation::class, 1);
        Notification::assertSentTo($this->pending, SurveyInvitation::class, static fn (SurveyInvitation $mail): bool => $mail->reminder);
        Notification::assertNotSentTo($this->answered, SurveyInvitation::class);
        Notification::assertSentOnDemand(SurveyInvitation::class, static fn (SurveyInvitation $mail, array $channels, AnonymousNotifiable $guest): bool => $guest->routes['mail'] === 'invitada@example.test'
            && $mail->url === route('surveys.guest', [$id->value, $opened->guestTokens['guest-1']]));
    }

    public function test_nothing_before_the_window_or_without_a_reminder(): void
    {
        $this->openSurvey(closesInHours: 72, reminderHours: 24);
        $this->openSurvey(closesInHours: 10, reminderHours: null);
        Notification::fake();

        $this->artisanCommand('surveys:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_runs_every_hour(): void
    {
        $events = array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'surveys:send-reminders'),
        );

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', array_first($events)->expression);
    }

    /**
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openSurvey(int $closesInHours, ?int $reminderHours): array
    {
        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $id = $lifecycle->createForSubject(
            ExampleThingSource::TYPE,
            'thing-1',
            'Valora la partida',
            SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [[
                'id' => 'q-1', 'type' => 'single_choice', 'title' => '¿Qué tal?', 'required' => true,
                'options' => [['id' => 'o-1', 'label' => 'Bien'], ['id' => 'o-2', 'label' => 'Muy bien']],
            ]]]]]),
            anonymous: true,
            closesAt: now()->addHours($closesInHours)->toDateTimeImmutable(),
        );
        $surveys = $this->app->make(SurveyRepositoryInterface::class);
        $survey = $surveys->findOrFail($id);
        $survey->reschedule($survey->closesAt(), $reminderHours, now()->toDateTimeImmutable());
        $surveys->save($survey);

        return [$id, $lifecycle->open($id, notify: false)];
    }
}
