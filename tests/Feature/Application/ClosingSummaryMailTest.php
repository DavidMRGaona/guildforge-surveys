<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Surveys\Application\Contracts\ClosingSummaryNotifierInterface;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Console\Commands\CloseDueSurveysCommand;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Notifications\SurveyClosingSummary;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use RuntimeException;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Closing a survey that asked for it mails a summary of its results to whoever organizes its
 * subject, to whoever created it when they may still see the results and, when the setting
 * says so, to everyone who may see results in the panel.
 */
final class ClosingSummaryMailTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private const string VIEW_RESULTS = 'surveys:surveys.view_results';

    private UserModel $organizer;

    /** @var list<UserModel> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);

        $this->organizer = UserModel::factory()->create();
        $this->players = [UserModel::factory()->create(), UserModel::factory()->create(), UserModel::factory()->create()];
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO(array_map(static fn (UserModel $user): string => $user->id, $this->players));

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->travelTo(new DateTimeImmutable('2030-01-04T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_closing_by_date_mails_the_organizers_a_link_to_the_results_on_the_site(): void
    {
        $id = $this->openSubjectSurvey();
        $this->travel(8)->days();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(CloseDueSurveysCommand::class));
        $this->artisanCommand('surveys:close-due')->assertSuccessful();

        Notification::assertSentTo($this->organizer, SurveyClosingSummary::class, fn (SurveyClosingSummary $mail): bool => $mail->url() === route('surveys.manage.results', $id->value));
        Notification::assertNotSentTo($this->players[0], SurveyClosingSummary::class);
    }

    public function test_closing_by_hand_on_the_site_or_in_the_panel_mails_it_too(): void
    {
        $byOrganizer = $this->openSubjectSurvey();
        $this->app->make(OrganizerSurveyService::class)->close($byOrganizer, $this->organizer->id);

        $inPanel = $this->openSubjectSurvey();
        $this->app->make(SurveyLifecycleService::class)->close($inPanel);

        Notification::assertSentToTimes($this->organizer, SurveyClosingSummary::class, 2);
    }

    public function test_a_survey_that_did_not_ask_for_it_mails_nothing(): void
    {
        $id = $this->openSubjectSurvey(closingSummary: false);

        $this->app->make(SurveyLifecycleService::class)->close($id);

        Notification::assertNothingSent();
    }

    public function test_organizers_who_no_longer_manage_the_subject_or_whose_account_is_gone_get_nothing(): void
    {
        $former = UserModel::factory()->create();
        $anonymized = UserModel::factory()->create(['anonymized_at' => now()]);
        ExampleThingSource::$organizerIds = [$this->organizer->id, $former->id, $anonymized->id];
        ExampleThingSource::$managers = [$this->organizer->id, $anonymized->id];
        $id = $this->openSubjectSurvey();

        $this->app->make(SurveyLifecycleService::class)->close($id);

        Notification::assertSentTo($this->organizer, SurveyClosingSummary::class);
        Notification::assertNotSentTo($former, SurveyClosingSummary::class);
        Notification::assertNotSentTo($anonymized, SurveyClosingSummary::class);
    }

    public function test_the_creator_gets_it_where_they_may_see_the_results_and_only_once(): void
    {
        $manager = UserModel::factory()->create();
        ExampleThingSource::$managers = [$this->organizer->id, $manager->id];
        $byOrganizer = $this->openSubjectSurvey(createdBy: $this->organizer->id);
        $byManager = $this->openSubjectSurvey(createdBy: $manager->id);
        $viewer = $this->userWith([self::VIEW_RESULTS]);
        $general = $this->openGeneralSurvey(createdBy: $viewer->id);
        $editor = $this->userWith([]);
        $withoutResults = $this->openGeneralSurvey(createdBy: $editor->id);

        foreach ([$byOrganizer, $byManager, $general, $withoutResults] as $id) {
            $this->app->make(SurveyLifecycleService::class)->close($id);
        }

        Notification::assertSentToTimes($this->organizer, SurveyClosingSummary::class, 2);
        Notification::assertSentTo($manager, SurveyClosingSummary::class, fn (SurveyClosingSummary $mail): bool => $mail->url() === route('surveys.manage.results', $byManager->value));
        Notification::assertSentTo($viewer, SurveyClosingSummary::class, fn (SurveyClosingSummary $mail): bool => $mail->url() === SurveyResource::getUrl('results', ['record' => $general->value]));
        Notification::assertNotSentTo($editor, SurveyClosingSummary::class);
    }

    public function test_results_viewers_get_it_only_when_the_setting_says_so(): void
    {
        $viewer = $this->userWith([self::VIEW_RESULTS]);
        $first = $this->openSubjectSurvey();
        $this->app->make(SurveyLifecycleService::class)->close($first);
        Notification::assertNotSentTo($viewer, SurveyClosingSummary::class);

        config()->set('modules.settings.surveys.closing_summary_to_results_viewers', true);
        $second = $this->openSubjectSurvey();
        $this->app->make(SurveyLifecycleService::class)->close($second);

        Notification::assertSentTo($viewer, SurveyClosingSummary::class, fn (SurveyClosingSummary $mail): bool => $mail->url() === SurveyResource::getUrl('results', ['record' => $second->value]));
        Notification::assertSentToTimes($this->organizer, SurveyClosingSummary::class, 2);
    }

    public function test_closing_twice_mails_once(): void
    {
        $id = $this->openSubjectSurvey();
        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $lifecycle->close($id);

        try {
            $lifecycle->close($id);
            $this->fail('A closed survey cannot close again.');
        } catch (InvalidSurveyStateException) {
            // Expected
        }

        Notification::assertSentToTimes($this->organizer, SurveyClosingSummary::class, 1);
    }

    public function test_a_failing_summary_does_not_undo_the_close(): void
    {
        Exceptions::fake();
        $this->app->bind(ClosingSummaryNotifierInterface::class, static fn (): ClosingSummaryNotifierInterface => new class implements ClosingSummaryNotifierInterface
        {
            public function surveyClosed(Survey $survey): void
            {
                throw new RuntimeException('Mail server down');
            }
        });
        $id = $this->openSubjectSurvey();

        $this->app->make(SurveyLifecycleService::class)->close($id);

        $this->assertSame(SurveyStatus::Closed, $this->app->make(SurveyRepositoryInterface::class)->findOrFail($id)->status());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_mail_names_the_site_sums_up_the_results_and_escapes_what_people_wrote(): void
    {
        $this->mock(SiteBrandingServiceInterface::class)->shouldReceive('siteName')->andReturn('Asociación de ejemplo');
        ExampleThingSource::$title = 'Mesa *especial*';
        $id = $this->openSubjectSurvey(title: 'Valora la [partida]', anonymous: false);
        $this->answer($id, 0, ['q-day' => 'o-fri', 'q-fun' => 4, 'q-notes' => 'Texto que nunca sale']);
        $this->answer($id, 1, ['q-day' => 'o-fri', 'q-fun' => 5, 'q-notes' => 'Tampoco este']);
        $this->app->make(SurveyLifecycleService::class)->close($id);

        Notification::assertSentTo($this->organizer, SurveyClosingSummary::class, function (SurveyClosingSummary $mail): bool {
            $message = $mail->toMail($this->organizer);
            $text = implode("\n", [...$message->introLines, ...$message->outroLines]);

            $this->assertSame('Resultados de la encuesta «Valora la [partida]»', $message->subject);
            $this->assertStringContainsString('Valora la \[partida\]', $text);
            $this->assertStringContainsString('Mesa \*especial\*', $text);
            $this->assertStringContainsString('Asociación de ejemplo', $text);
            $this->assertStringContainsString('Han respondido 2 de 3 personas (67 %)', $text);
            $this->assertStringContainsString('«Viernes \(tarde\)» (2 de 2)', $text);
            $this->assertStringContainsString('media de 4,5, de 1 a 5 (2 respuestas)', $text);
            $this->assertStringContainsString('1 pregunta más', $text);
            $this->assertStringNotContainsString('Texto que nunca sale', $text);
            $this->assertSame(route('surveys.manage.results', $mail->surveyId), $message->actionUrl);

            return true;
        });
    }

    public function test_an_anonymous_survey_below_the_minimum_says_so_and_gives_no_figure(): void
    {
        $id = $this->openSubjectSurvey(anonymous: true);
        $this->answer($id, 0, ['q-day' => 'o-fri', 'q-fun' => 4, 'q-notes' => 'Algo']);
        $this->app->make(SurveyLifecycleService::class)->close($id);

        Notification::assertSentTo($this->organizer, SurveyClosingSummary::class, function (SurveyClosingSummary $mail): bool {
            $text = implode("\n", $mail->toMail($this->organizer)->introLines);

            $this->assertStringContainsString('no ha llegado al mínimo', $text);
            $this->assertStringNotContainsString('respondido 1', $text);
            $this->assertStringNotContainsString('Viernes', $text);

            return true;
        });
    }

    private function openSubjectSurvey(bool $closingSummary = true, ?string $createdBy = null, string $title = 'Encuesta de ejemplo', bool $anonymous = false): SurveyId
    {
        return $this->open(new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: $title,
            description: null,
            definition: $this->definition(),
            anonymous: $anonymous,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: SubjectSnapshotFactory::fromDto((new ExampleThingSource)->find('thing-1') ?? throw new RuntimeException('The example thing exists.')),
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
            createdBy: $createdBy,
            closingSummary: $closingSummary,
        ));
    }

    private function openGeneralSurvey(string $createdBy): SurveyId
    {
        return $this->open(new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta general',
            description: null,
            definition: $this->definition(),
            anonymous: false,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
            createdBy: $createdBy,
            closingSummary: true,
        ));
    }

    private function open(Survey $survey): SurveyId
    {
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);
        $this->app->make(SurveyLifecycleService::class)->open($survey->id(), notify: false);

        return $survey->id();
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function answer(SurveyId $id, int $player, array $answers): void
    {
        $this->app->make(SubmissionService::class)->submit($id, Respondent::account($this->players[$player]->id), $answers);
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [
            ['id' => 'q-day', 'type' => 'single_choice', 'title' => '¿Qué día?', 'required' => true, 'options' => [
                ['id' => 'o-fri', 'label' => 'Viernes (tarde)'], ['id' => 'o-sat', 'label' => 'Sábado'],
            ]],
            ['id' => 'q-fun', 'type' => 'linear_scale', 'title' => '¿Cuánto te divertiste?', 'required' => true, 'scale' => ['min' => 1, 'max' => 5]],
            ['id' => 'q-notes', 'type' => 'paragraph', 'title' => 'Comentarios', 'required' => true],
        ]]]]);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'surveys', 'action' => Str::afterLast($key, '.'), 'module' => 'surveys'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
