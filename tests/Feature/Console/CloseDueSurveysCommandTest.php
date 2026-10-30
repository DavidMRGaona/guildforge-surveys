<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Console;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Console\Commands\CloseDueSurveysCommand;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class CloseDueSurveysCommandTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        // The module boots after the console kernel resolved its commands
        $this->app->make(Kernel::class)->registerCommand($this->app->make(CloseDueSurveysCommand::class));
    }

    public function test_closes_what_is_due_and_forgets_guest_emails(): void
    {
        $due = $this->openSurvey(closesInHours: 1);
        $later = $this->openSurvey(closesInHours: 48);
        $this->travel(2)->hours();

        $this->artisanCommand('surveys:close-due')->assertSuccessful();
        $this->artisanCommand('surveys:close-due')->assertSuccessful();

        $surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->assertSame(SurveyStatus::Closed, $surveys->findOrFail($due)->status());
        $this->assertSame(SurveyStatus::Open, $surveys->findOrFail($later)->status());
        $this->assertNull(SurveyRecipientModel::query()->where('survey_id', $due->value)->sole()->guest_email);
        $this->assertSame('invitada@example.test', SurveyRecipientModel::query()->where('survey_id', $later->value)->sole()->guest_email);
    }

    public function test_runs_every_five_minutes(): void
    {
        $events = array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'surveys:close-due'),
        );

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', array_first($events)->expression);
    }

    private function openSurvey(int $closesInHours): SurveyId
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        ExampleThingSource::$audience = new SurveyAudienceDTO([], [new SurveyGuestDTO('guest-1', 'Invitada', 'invitada@example.test')]);
        $lifecycle = $this->app->make(SurveyLifecycleService::class);

        $id = $lifecycle->createForSubject(
            ExampleThingSource::TYPE,
            'thing-1',
            'Valora la partida',
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            closesAt: now()->addHours($closesInHours)->toDateTimeImmutable(),
        );
        $lifecycle->open($id);

        return $id;
    }
}
