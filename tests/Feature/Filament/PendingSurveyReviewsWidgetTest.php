<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Livewire\Livewire;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Widgets\PendingSurveyReviewsWidget;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\SurveysServiceProvider;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * «Encuestas pendientes de revisión» on the dashboard: only while there is one, and only for
 * whoever may review it.
 */
final class PendingSurveyReviewsWidgetTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    protected function setUp(): void
    {
        parent::setUp();

        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->organizer = UserModel::factory()->create(['name' => 'Persona organizadora']);
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO([UserModel::factory()->create()->id]);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_provider_registers_it(): void
    {
        $this->assertContains(PendingSurveyReviewsWidget::class, (new SurveysServiceProvider($this->app))->registerFilamentWidgets());
    }

    public function test_it_only_shows_while_something_waits(): void
    {
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));
        $this->assertFalse(PendingSurveyReviewsWidget::canView());

        $this->pending('Valoración de ejemplo');

        $this->assertTrue(PendingSurveyReviewsWidget::canView());
    }

    public function test_it_only_shows_to_moderators(): void
    {
        $this->pending('Valoración de ejemplo');

        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.manage']));

        $this->assertFalse(PendingSurveyReviewsWidget::canView());
    }

    public function test_it_lists_what_waits_with_who_sent_it(): void
    {
        $survey = $this->pending('Valoración de ejemplo');
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));

        Livewire::test(PendingSurveyReviewsWidget::class)
            ->assertOk()
            ->assertCanSeeTableRecords([SurveyModel::query()->findOrFail($survey->id()->value)])
            ->assertSee('Valoración de ejemplo')
            ->assertSee('Partida de ejemplo')
            ->assertSee('Persona organizadora');
    }

    private function pending(string $title): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            $title,
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        $survey = $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: null);
        $survey->markUnreviewedChanges($this->organizer->id);
        $survey->submitForReview($this->organizer->id, new DateTimeImmutable);
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return $survey;
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        foreach ($permissionKeys as $key) {
            [$resource, $action, $module] = $key === 'admin.access' ? ['admin', 'access', null] : ['surveys', substr($key, strrpos($key, '.') + 1), 'surveys'];
            $permission = PermissionModel::query()->firstOrCreate(['key' => $key], ['label' => $key, 'resource' => $resource, 'action' => $action, 'module' => $module]);
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
