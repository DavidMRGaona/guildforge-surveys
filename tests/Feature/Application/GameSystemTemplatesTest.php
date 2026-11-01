<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\Contracts\SubjectEndSurveyChoiceInterface;
use App\Application\Surveys\DTOs\EndSurveyChoiceDTO;
use App\Application\Surveys\DTOs\EndSurveyOptionDTO;
use App\Application\Surveys\DTOs\EndSurveySelection;
use App\Application\Surveys\DTOs\SurveySubjectAttributeDTO;
use App\Application\Surveys\Enums\EndSurveyChoiceOutcome;
use App\Application\Surveys\Enums\SurveySurface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Services\EndSurveyTemplateResolver;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SubjectEndedService;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SubjectEndChoiceModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The template a subject's survey is made from follows the rule for its game system, recognised
 * by the system's id, before its type's template: when it ends, when it is prepared ahead and as
 * the default of the choice in its form. What is chosen in the form still wins.
 */
final class GameSystemTemplatesTest extends ModuleTestCase
{
    private const string KEY = ExampleThingSource::TYPE.':thing-1';

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private SurveyTemplate $typeTemplate;

    private SurveyTemplate $systemTemplate;

    private SurveyTemplate $otherTemplate;

    private SubjectEndSurveyChoiceInterface $choice;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);

        $this->organizer = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];

        $this->typeTemplate = $this->template('Torneo de ejemplo');
        $this->systemTemplate = $this->template('Torneo de juego de ejemplo');
        $this->otherTemplate = $this->template('Otra plantilla de ejemplo');
        $this->configure($this->typeTemplate->id()->value, ['system-1' => $this->systemTemplate->id()->value]);

        $this->now = new DateTimeImmutable('2030-01-04T10:00:00+00:00');
        $this->travelTo($this->now);

        $this->choice = $this->app->make(SubjectEndSurveyChoiceInterface::class);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_a_subject_of_a_game_system_with_a_template_gets_that_one_when_it_ends(): void
    {
        $this->playing('system-1');

        $this->end();

        $this->assertSame('Torneo de juego de ejemplo', $this->endSurveyTitle());
    }

    public function test_a_subject_of_another_game_system_gets_its_types_template(): void
    {
        $this->playing('system-2');

        $this->end();

        $this->assertSame('Torneo de ejemplo', $this->endSurveyTitle());
    }

    public function test_a_subject_without_a_game_system_gets_its_types_template(): void
    {
        $this->end();

        $this->assertSame('Torneo de ejemplo', $this->endSurveyTitle());
    }

    public function test_the_game_system_is_recognised_by_its_id_not_by_its_name(): void
    {
        ExampleThingSource::$gameSystem = new SurveySubjectAttributeDTO('game_system', 'Sistema de juego', 'system-1');

        $this->end();

        $this->assertSame('Torneo de ejemplo', $this->endSurveyTitle());
    }

    public function test_a_game_system_template_deleted_since_falls_back_to_the_types(): void
    {
        $this->playing('system-1');
        $this->app->make(SurveyTemplateRepositoryInterface::class)->delete($this->systemTemplate->id());

        $this->end();

        $this->assertSame('Torneo de ejemplo', $this->endSurveyTitle());
    }

    public function test_a_game_system_template_goes_out_even_when_the_type_has_none(): void
    {
        $this->configure(null, ['system-1' => $this->systemTemplate->id()->value]);
        $this->playing('system-1');

        $this->end();

        $this->assertSame('Torneo de juego de ejemplo', $this->endSurveyTitle());
    }

    public function test_the_template_chosen_in_the_subjects_form_wins(): void
    {
        $this->playing('system-1');
        $this->choose('template:'.$this->otherTemplate->id()->value);

        $this->end();

        $this->assertSame('Otra plantilla de ejemplo', $this->endSurveyTitle());
    }

    public function test_choosing_the_types_template_by_hand_keeps_it_over_the_game_systems(): void
    {
        $this->playing('system-1');

        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('template:'.$this->typeTemplate->id()->value));
        $this->assertSame('template:'.$this->typeTemplate->id()->value, $this->forForm()->selected);

        $this->end();

        $this->assertSame('Torneo de ejemplo', $this->endSurveyTitle());
    }

    public function test_choosing_the_game_systems_template_by_hand_stores_nothing(): void
    {
        $this->playing('system-1');
        $this->choose('none');

        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('template:'.$this->systemTemplate->id()->value));

        $this->assertSame(0, SubjectEndChoiceModel::query()->count());
        $this->assertSame('type-default', $this->forForm()->selected);
    }

    public function test_preparing_ahead_follows_the_game_system(): void
    {
        $this->playing('system-1');

        $this->assertSame('Torneo de juego de ejemplo', $this->app->make(EndSurveyTemplateResolver::class)->templateFor(ExampleThingSource::TYPE, 'thing-1')?->name());
        $this->assertSame('Torneo de juego de ejemplo', $this->app->make(OrganizerSurveyService::class)->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id)->title());
    }

    public function test_preparing_ahead_is_offered_with_only_a_game_system_template(): void
    {
        $this->configure(null, ['system-1' => $this->systemTemplate->id()->value]);
        $service = $this->app->make(OrganizerSurveyService::class);

        $subject = (new ExampleThingSource)->find('thing-1');
        $this->assertNotNull($subject);
        $this->assertFalse($service->preparationAvailable($subject));

        $this->playing('system-1');
        $subject = (new ExampleThingSource)->find('thing-1');
        $this->assertNotNull($subject);
        $this->assertTrue($service->preparationAvailable($subject));
    }

    public function test_the_form_offers_the_game_systems_template_by_default(): void
    {
        $this->playing('system-1');

        $choice = $this->forForm();

        $this->assertSame('type-default', $choice->selected);
        $this->assertSame([
            'type-default' => 'Plantilla de su sistema de juego: «Torneo de juego de ejemplo»',
            'template:'.$this->otherTemplate->id()->value => 'Otra plantilla de ejemplo',
            'template:'.$this->typeTemplate->id()->value => 'Torneo de ejemplo',
            'own' => 'Una encuesta propia',
            'none' => 'Ninguna',
        ], $this->labels($choice->options));
        $this->assertSame('template:'.$this->systemTemplate->id()->value, $choice->baseTemplates[0]->value);
    }

    public function test_the_form_of_a_subject_without_a_rule_offers_its_types_template(): void
    {
        $this->playing('system-2');

        $this->assertSame('Plantilla del tipo: «Torneo de ejemplo»', $this->labels($this->forForm()->options)['type-default']);
    }

    public function test_the_form_of_a_subject_being_created_says_the_game_system_decides(): void
    {
        $choice = $this->choice->forForm(ExampleThingSource::TYPE, null, $this->organizer->id, SurveySurface::Panel);

        $this->assertInstanceOf(EndSurveyChoiceDTO::class, $choice);
        $this->assertSame(
            'La de su sistema de juego o, si no tiene, la del tipo: «Torneo de ejemplo»',
            $this->labels($choice->options)['type-default'],
        );
    }

    public function test_the_form_of_a_subject_being_created_without_a_types_template(): void
    {
        $this->configure(null, ['system-1' => $this->systemTemplate->id()->value]);

        $choice = $this->choice->forForm(ExampleThingSource::TYPE, null, $this->organizer->id, SurveySurface::Panel);

        $this->assertInstanceOf(EndSurveyChoiceDTO::class, $choice);
        $this->assertSame(
            'La de su sistema de juego (sin plantilla para el suyo, no saldrá ninguna)',
            $this->labels($choice->options)['type-default'],
        );
    }

    /**
     * @param  array<string, string>  $gameSystemTemplates
     */
    private function configure(?string $typeTemplateId, array $gameSystemTemplates): void
    {
        $rows = [];
        foreach ($gameSystemTemplates as $gameSystem => $templateId) {
            $rows[] = ['game_system' => $gameSystem, 'template_id' => $templateId];
        }

        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => [
                'template_id' => $typeTemplateId,
                'auto_send' => true,
                'send_delay_hours' => 0,
                'game_system_templates' => $rows,
            ],
        ]);
    }

    private function playing(string $gameSystemId): void
    {
        ExampleThingSource::$gameSystem = new SurveySubjectAttributeDTO('game_system', 'Sistema de juego', "Juego {$gameSystemId}", $gameSystemId);
    }

    private function end(): void
    {
        $this->app->make(SubjectEndedService::class)->handle(ExampleThingSource::TYPE, 'thing-1', $this->now, $this->now);
    }

    private function endSurveyTitle(): ?string
    {
        return $this->app->make(SurveyRepositoryInterface::class)->findBySubjectEnd(self::KEY)?->title();
    }

    private function forForm(): EndSurveyChoiceDTO
    {
        $choice = $this->choice->forForm(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveySurface::Panel);
        $this->assertInstanceOf(EndSurveyChoiceDTO::class, $choice);

        return $choice;
    }

    private function choose(string $value): EndSurveyChoiceOutcome
    {
        return $this->choice->choose(ExampleThingSource::TYPE, 'thing-1', new EndSurveySelection($value), $this->organizer->id);
    }

    /**
     * @param  list<EndSurveyOptionDTO>  $options
     * @return array<string, string>
     */
    private function labels(array $options): array
    {
        $labels = [];
        foreach ($options as $option) {
            $labels[$option->value] = $option->label;
        }

        return $labels;
    }

    private function template(string $name): SurveyTemplate
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            $name,
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $template;
    }
}
