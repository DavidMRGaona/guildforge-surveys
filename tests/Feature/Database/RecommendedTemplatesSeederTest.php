<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Database;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Database\Seeders\RecommendedTemplatesSeeder;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class RecommendedTemplatesSeederTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        // database/ is outside the module's autoloaded src/
        foreach (glob(dirname(__DIR__, 3).'/database/seeders/*Seeder.php') ?: [] as $seeder) {
            require_once $seeder;
        }

        // Enabling the module above ran it without templates; these tests ask for them
        RecommendedTemplatesSeeder::$seedInTests = true;
    }

    protected function tearDown(): void
    {
        RecommendedTemplatesSeeder::$seedInTests = false;

        parent::tearDown();
    }

    public function test_module_tests_get_no_templates_unless_they_ask(): void
    {
        RecommendedTemplatesSeeder::$seedInTests = false;

        $this->runSeeder();

        $this->assertSame(0, SurveyTemplateModel::query()->count());
    }

    public function test_it_creates_the_twelve_recommended_templates(): void
    {
        $this->runSeeder();

        $this->assertSame(12, SurveyTemplateModel::query()->whereNotNull('catalog_key')->count());

        $template = $this->byKey('game_table');
        $this->assertSame('Partida de rol', $template->name);
        $this->assertSame(SurveyKind::Form->value, $template->kind);
        $this->assertNull($template->created_by);
    }

    public function test_each_recommended_template_says_what_it_is_for(): void
    {
        $this->runSeeder();

        $this->assertSame(
            [
                'activity_interest' => ['general'],
                'association_event' => ['event'],
                'availability' => ['general'],
                'campaign' => ['campaign'],
                'campaign_session' => ['campaign-session'],
                'game_table' => ['game-table'],
                'member_satisfaction' => ['general'],
                'tournament' => ['tournament'],
                'tournament_40k' => ['tournament'],
                'tournament_aos' => ['tournament'],
                'tournament_blood_bowl' => ['tournament'],
                'tournament_kill_team' => ['tournament'],
            ],
            SurveyTemplateModel::query()->orderBy('catalog_key')->pluck('uses', 'catalog_key')->all(),
        );
    }

    public function test_running_it_again_creates_nothing_new(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(12, SurveyTemplateModel::query()->count());
    }

    public function test_it_never_overwrites_an_edited_template(): void
    {
        $this->runSeeder();
        $edited = SurveyDefinitionTest::everyTypeDefinition();
        $this->byKey('game_table')->update(['name' => 'Nuestra partida de rol', 'description' => null, 'definition' => $edited]);

        $this->runSeeder();

        $template = $this->byKey('game_table');
        $this->assertSame('Nuestra partida de rol', $template->name);
        $this->assertNull($template->description);
        $this->assertEquals($edited, $template->definition);
    }

    public function test_it_sets_the_default_template_of_each_kind_of_subject(): void
    {
        $this->runSeeder();

        $settings = $this->app->make(SurveySettingsInterface::class);
        $this->assertSame($this->byKey('game_table')->id, $settings->forSubjectType('game-table')->templateId);
        $this->assertSame($this->byKey('campaign_session')->id, $settings->forSubjectType('campaign-session')->templateId);
        $this->assertSame($this->byKey('campaign')->id, $settings->forSubjectType('campaign')->templateId);
        $this->assertSame($this->byKey('tournament')->id, $settings->forSubjectType('tournament')->templateId);
        $this->assertSame($this->byKey('association_event')->id, $settings->forSubjectType('event')->templateId);
        // Offered to the organizers with a click, as before
        $this->assertFalse($settings->forSubjectType('game-table')->autoSend);
    }

    public function test_it_keeps_a_default_already_chosen_and_the_other_settings(): void
    {
        $own = SurveyTemplateModel::query()->create(['name' => 'Propia', 'kind' => SurveyKind::Form->value, 'definition' => SurveyDefinitionTest::everyTypeDefinition()]);
        $this->app->make(ModuleManagerServiceInterface::class)->updateSettings(new ModuleName('surveys'), [
            'min_responses' => 5,
            'subject_types' => ['tournament' => ['template_id' => $own->id, 'auto_send' => true, 'close_days' => 3]],
        ]);

        $this->runSeeder();

        $settings = $this->app->make(SurveySettingsInterface::class);
        $this->assertSame(5, $settings->minResponses());
        $tournament = $settings->forSubjectType('tournament');
        $this->assertSame($own->id, $tournament->templateId);
        $this->assertTrue($tournament->autoSend);
        $this->assertSame(3, $tournament->closeDays);
        $this->assertSame($this->byKey('game_table')->id, $settings->forSubjectType('game-table')->templateId);
    }

    public function test_it_fills_a_kind_of_subject_saved_without_a_template_and_keeps_its_other_values(): void
    {
        $this->app->make(ModuleManagerServiceInterface::class)->updateSettings(new ModuleName('surveys'), [
            'subject_types' => ['game-table' => ['template_id' => null, 'close_days' => 4]],
        ]);

        $this->runSeeder();

        $stored = $this->app->make(ModuleSettingsStoreInterface::class)->stored('surveys');
        $this->assertSame(['template_id' => $this->byKey('game_table')->id, 'close_days' => 4], $stored['subject_types']['game-table']);
    }

    public function test_it_does_not_bring_back_a_default_for_a_template_that_was_already_there(): void
    {
        $this->runSeeder();
        // The association chose to send nothing for game tables
        $this->app->make(ModuleManagerServiceInterface::class)->updateSettings(new ModuleName('surveys'), [
            'subject_types' => ['game-table' => ['template_id' => null]],
        ]);

        $this->runSeeder();

        $this->assertNull($this->app->make(SurveySettingsInterface::class)->forSubjectType('game-table')->templateId);
    }

    public function test_texts_are_spanish_even_before_the_module_registers_its_translations(): void
    {
        // On install the seeder runs before the module's provider boots
        $this->app->instance('translator', new Translator(new FileLoader(new Filesystem, $this->app->langPath()), 'es'));

        $this->runSeeder();

        $template = $this->byKey('tournament_blood_bowl');
        $this->assertSame('Torneo de Blood Bowl', $template->name);
        $this->assertStringNotContainsString('surveys::', (string) json_encode($template->definition));
    }

    private function runSeeder(): void
    {
        $this->app->make(RecommendedTemplatesSeeder::class)->run();
    }

    private function byKey(string $catalogKey): SurveyTemplateModel
    {
        return SurveyTemplateModel::query()->where('catalog_key', $catalogKey)->firstOrFail();
    }
}
