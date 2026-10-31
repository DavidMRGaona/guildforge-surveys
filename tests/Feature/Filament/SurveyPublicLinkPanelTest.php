<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\EditSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurvey;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyPublicLinkPanelTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyRepositoryInterface $surveys;

    private SurveyLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->lifecycle = $this->app->make(SurveyLifecycleService::class);
    }

    public function test_the_edit_page_shows_the_link_to_copy_and_its_mode(): void
    {
        $this->actingAsPanelAdmin();
        [$id, $opened] = $this->openSurvey(PublicLinkMode::Open);

        Livewire::test(EditSurvey::class, ['record' => $id->value])
            ->assertSee(__('surveys::filament.survey.fields.public_link_url'))
            ->assertSee($this->url($id, $opened))
            ->assertSeeHtml('navigator.clipboard.writeText')
            ->assertSee(__('surveys::filament.survey.public_link_modes.open'))
            ->assertSee(__('surveys::filament.survey.public_link_modes.open_help'));
    }

    public function test_whoever_may_only_read_the_survey_sees_the_link_too(): void
    {
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));
        [$id, $opened] = $this->openSurvey(PublicLinkMode::VerifiedEmail);

        Livewire::test(ViewSurvey::class, ['record' => $id->value])
            ->assertSee($this->url($id, $opened))
            ->assertSeeHtml('navigator.clipboard.writeText')
            ->assertSee(__('surveys::filament.survey.public_link_modes.verified_email'))
            ->assertActionHidden('renew_public_link');
    }

    public function test_someone_who_may_not_see_the_survey_never_gets_the_link(): void
    {
        [$id, $opened] = $this->openSurvey(PublicLinkMode::Open);
        $this->actingAs($this->userWith(['admin.access']));

        $this->get(SurveyResource::getUrl('view', ['record' => $id->value]))
            ->assertForbidden()
            ->assertDontSee($this->url($id, $opened));
    }

    public function test_no_link_while_the_survey_takes_no_responses(): void
    {
        $this->actingAsPanelAdmin();
        $draft = $this->survey(publicLink: true);

        Livewire::test(EditSurvey::class, ['record' => $draft->id()->value])
            ->assertDontSee(__('surveys::filament.survey.fields.public_link_url'))
            ->assertDontSee('/abierta/')
            ->assertActionHidden('renew_public_link');

        [$id, $opened] = $this->openSurvey(PublicLinkMode::Open);
        $this->lifecycle->close($id);

        Livewire::test(EditSurvey::class, ['record' => $id->value])
            ->assertDontSee($this->url($id, $opened))
            ->assertActionHidden('renew_public_link');
    }

    public function test_no_link_on_a_survey_without_one(): void
    {
        $this->actingAsPanelAdmin();
        $survey = $this->survey(publicLink: false);
        $this->lifecycle->open($survey->id(), notify: false);

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->assertDontSee(__('surveys::filament.survey.fields.public_link_url'))
            ->assertDontSee('/abierta/')
            ->assertActionHidden('renew_public_link');
    }

    public function test_renewing_the_link_needs_the_second_confirmation(): void
    {
        $this->actingAsPanelAdmin();
        [$id, $opened] = $this->openSurvey(PublicLinkMode::Open);
        $old = $this->url($id, $opened);

        Livewire::test(EditSurvey::class, ['record' => $id->value])
            ->callAction('renew_public_link', data: ['confirmation' => false])
            ->assertHasActionErrors(['confirmation']);
        $this->assertSame($opened->publicToken, $this->lifecycle->publicLink($id)?->token);

        $page = Livewire::test(EditSurvey::class, ['record' => $id->value])
            ->callAction('renew_public_link', data: ['confirmation' => true])
            ->assertHasNoActionErrors()
            ->assertNotified(__('surveys::filament.actions.renew_public_link_done'));

        $token = $this->lifecycle->publicLink($id)?->token;
        $this->assertIsString($token);
        $this->assertNotSame($opened->publicToken, $token);
        $page->assertSee(route('surveys.public', [$id->value, $token]))
            ->assertDontSee($old);
    }

    /**
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openSurvey(PublicLinkMode $mode): array
    {
        $survey = $this->survey(publicLink: true, mode: $mode);

        return [$survey->id(), $this->lifecycle->open($survey->id(), notify: false)];
    }

    private function survey(bool $publicLink, PublicLinkMode $mode = PublicLinkMode::VerifiedEmail): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
            publicLinkEnabled: $publicLink,
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
            publicLinkMode: $mode,
        );
        $this->surveys->save($survey);

        return $survey;
    }

    private function url(SurveyId $id, OpenedSurveyDTO $opened): string
    {
        $this->assertIsString($opened->publicToken);

        return route('surveys.public', [$id->value, $opened->publicToken]);
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
