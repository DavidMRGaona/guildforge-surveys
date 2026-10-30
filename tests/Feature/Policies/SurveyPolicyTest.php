<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Policies;

use App\Application\Authorization\Services\PermissionRegistryInterface;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\SurveysServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyPolicyTest extends ModuleTestCase
{
    private const string MANAGE = 'surveys:surveys.manage';

    private const string VIEW_RESULTS = 'surveys:surveys.view_results';

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_registers_the_three_permissions_under_the_module_prefix(): void
    {
        $registry = $this->app->make(PermissionRegistryInterface::class);

        foreach (['manage', 'view_results', 'moderate'] as $action) {
            $this->assertTrue($registry->has("surveys:surveys.{$action}"), $action);
        }
    }

    public function test_no_permission_is_granted_or_suggested_to_any_role(): void
    {
        $withRoles = array_filter(
            (new SurveysServiceProvider($this->app))->registerPermissions(),
            static fn (PermissionDTO $permission): bool => $permission->roles !== [],
        );

        $this->assertSame([], $withRoles);
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function surveyAbilities(): iterable
    {
        yield 'list with manage' => ['viewAny', [self::MANAGE], true];
        yield 'list with results' => ['viewAny', [self::VIEW_RESULTS], true];
        yield 'list without either' => ['viewAny', [], false];
        yield 'view with results' => ['view', [self::VIEW_RESULTS], true];
        yield 'create with manage' => ['create', [self::MANAGE], true];
        yield 'create with results only' => ['create', [self::VIEW_RESULTS], false];
        yield 'update with manage' => ['update', [self::MANAGE], true];
        yield 'update with results only' => ['update', [self::VIEW_RESULTS], false];
        yield 'delete with manage' => ['delete', [self::MANAGE], true];
        yield 'results with results' => ['viewResults', [self::VIEW_RESULTS], true];
        yield 'results with manage only' => ['viewResults', [self::MANAGE], false];
        yield 'export with results' => ['export', [self::VIEW_RESULTS], true];
        yield 'export with manage only' => ['export', [self::MANAGE], false];
    }

    /**
     * @param  list<string>  $permissions
     */
    #[DataProvider('surveyAbilities')]
    public function test_survey_abilities_follow_the_permissions(string $ability, array $permissions, bool $allowed): void
    {
        $arguments = in_array($ability, ['viewAny', 'create'], true) ? [SurveyModel::class] : [$this->survey()];

        $this->assertSame($allowed, $this->userWith($permissions)->can($ability, $arguments));
    }

    public function test_templates_need_manage(): void
    {
        $template = SurveyTemplateModel::query()->create(['name' => 'Plantilla', 'kind' => 'form', 'definition' => ['sections' => []]]);

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue($this->userWith([self::MANAGE])->can($ability, SurveyTemplateModel::class));
            $this->assertFalse($this->userWith([self::VIEW_RESULTS])->can($ability, SurveyTemplateModel::class));
        }
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($this->userWith([self::MANAGE])->can($ability, $template));
            $this->assertFalse($this->userWith([self::VIEW_RESULTS])->can($ability, $template));
        }
    }

    /**
     * An editor holds no survey permission until someone grants it in the role editor.
     */
    public function test_an_editor_without_grants_sees_nothing(): void
    {
        $editor = UserModel::factory()->editor()->create();

        $this->assertFalse($editor->can('viewAny', SurveyModel::class));
        $this->assertFalse($editor->can('viewAny', SurveyTemplateModel::class));
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

    private function survey(): SurveyModel
    {
        return SurveyModel::query()->create([
            'kind' => 'form',
            'title' => 'Encuesta de ejemplo',
            'definition' => ['sections' => []],
            'status' => 'draft',
            'anonymous' => true,
            'results_settings' => [],
            'audience_type' => 'all_users',
            'audience_roles' => [],
        ]);
    }
}
