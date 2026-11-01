<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\Contracts\SubjectEndSurveyChoiceInterface;
use App\Application\Surveys\DTOs\EndSurveyChoiceDTO;
use App\Application\Surveys\DTOs\EndSurveyOptionDTO;
use App\Application\Surveys\DTOs\EndSurveySelection;
use App\Application\Surveys\Enums\EndSurveyChoiceOutcome;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Enums\SurveySurface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Modules\Surveys\Application\DTOs\OrganizerSurveyInput;
use Modules\Surveys\Application\Services\EndSurveyTemplateResolver;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SubjectCancelledService;
use Modules\Surveys\Application\Services\SubjectEndedService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SubjectEndChoiceModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The survey that goes out when a subject ends, chosen from the subject's own form: its type's
 * template, another one, a survey of its own, or none. Whoever manages the subject or the
 * surveys chooses, until the subject ends.
 */
final class SubjectEndSurveyChoiceTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    private const string KEY = ExampleThingSource::TYPE.':thing-1';

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private UserModel $stranger;

    private SurveyTemplate $typeTemplate;

    private SurveyTemplate $otherTemplate;

    private SubjectEndSurveyChoiceInterface $choice;

    private SurveyRepositoryInterface $surveys;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);

        $this->organizer = UserModel::factory()->create();
        $this->stranger = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];

        $this->typeTemplate = $this->template('Valoración de ejemplo');
        $this->otherTemplate = $this->template('Torneo de ejemplo');
        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => ['template_id' => $this->typeTemplate->id()->value, 'auto_send' => true, 'send_delay_hours' => 0],
        ]);

        $this->now = new DateTimeImmutable('2030-01-04T10:00:00+00:00');
        $this->travelTo($this->now);

        $this->choice = $this->app->make(SubjectEndSurveyChoiceInterface::class);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_form_offers_the_types_template_by_default_and_the_others(): void
    {
        $choice = $this->forForm();

        $this->assertSame('type-default', $choice->selected);
        $this->assertFalse($choice->locked);
        $this->assertNull($choice->editUrl);
        $this->assertSame([
            'type-default' => 'Plantilla del tipo: «Valoración de ejemplo»',
            'template:'.$this->otherTemplate->id()->value => 'Torneo de ejemplo',
            'own' => 'Una encuesta propia',
            'none' => 'Ninguna',
        ], $this->labels($choice->options));
        $this->assertSame(__('surveys::messages.end_choice.templates'), $choice->options[1]->group);
        $this->assertSame('own', $choice->ownValue);
        $this->assertSame([
            'template:'.$this->typeTemplate->id()->value => 'Valoración de ejemplo',
            'template:'.$this->otherTemplate->id()->value => 'Torneo de ejemplo',
        ], $this->labels($choice->baseTemplates));
    }

    public function test_a_subject_being_created_gets_the_same_offer(): void
    {
        $choice = $this->choice->forForm(ExampleThingSource::TYPE, null, $this->stranger->id, SurveySurface::Panel);

        $this->assertInstanceOf(EndSurveyChoiceDTO::class, $choice);
        $this->assertSame('type-default', $choice->selected);
    }

    public function test_nothing_is_offered_to_a_stranger_or_for_an_unknown_subject_or_type(): void
    {
        $this->assertNull($this->choice->forForm(ExampleThingSource::TYPE, 'thing-1', $this->stranger->id, SurveySurface::Panel));
        $this->assertNull($this->choice->forForm(ExampleThingSource::TYPE, 'thing-404', $this->organizer->id, SurveySurface::Panel));
        $this->assertNull($this->choice->forForm('unknown-thing', null, $this->organizer->id, SurveySurface::Panel));
    }

    public function test_whoever_manages_the_surveys_may_choose_for_any_subject(): void
    {
        $manager = $this->userWith('surveys:surveys.manage');

        $this->assertInstanceOf(EndSurveyChoiceDTO::class, $this->choice->forForm(ExampleThingSource::TYPE, 'thing-1', $manager->id, SurveySurface::Panel));
        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('none', userId: $manager->id));
    }

    public function test_a_stranger_may_not_choose(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->choose('none', userId: $this->stranger->id);
    }

    public function test_another_template_goes_out_when_the_subject_ends(): void
    {
        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('template:'.$this->otherTemplate->id()->value));
        $this->assertSame('template:'.$this->otherTemplate->id()->value, $this->forForm()->selected);

        $this->end();

        $survey = $this->surveys->findBySubjectEnd(self::KEY);
        $this->assertInstanceOf(Survey::class, $survey);
        $this->assertSame('Torneo de ejemplo', $survey->title());
        $this->assertSame($this->otherTemplate->id()->value, $survey->templateId()?->value);
    }

    public function test_no_survey_goes_out_when_none_was_chosen(): void
    {
        $this->choose('none');

        $this->assertSame('none', $this->forForm()->selected);
        $this->end();

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_going_back_to_the_types_template_forgets_the_choice(): void
    {
        $this->choose('none');

        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('type-default'));

        $this->assertSame(0, SubjectEndChoiceModel::query()->count());
        $this->end();
        $this->assertSame('Valoración de ejemplo', $this->surveys->findBySubjectEnd(self::KEY)?->title());
    }

    public function test_choosing_what_is_already_chosen_changes_nothing(): void
    {
        $this->assertSame(EndSurveyChoiceOutcome::Unchanged, $this->choose('type-default'));

        $this->choose('none');

        $this->assertSame(EndSurveyChoiceOutcome::Unchanged, $this->choose('none'));
    }

    public function test_a_chosen_template_deleted_since_falls_back_to_the_types(): void
    {
        $this->choose('template:'.$this->otherTemplate->id()->value);
        $this->app->make(SurveyTemplateRepositoryInterface::class)->delete($this->otherTemplate->id());

        $this->assertSame('type-default', $this->forForm()->selected);
        $this->end();

        $this->assertSame('Valoración de ejemplo', $this->surveys->findBySubjectEnd(self::KEY)?->title());
    }

    public function test_an_own_survey_is_prepared_from_the_template_to_start_from(): void
    {
        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('own', 'template:'.$this->otherTemplate->id()->value));

        $survey = $this->surveys->findBySubjectEnd(self::KEY);
        $this->assertInstanceOf(Survey::class, $survey);
        $this->assertTrue($survey->isAwaitingSubjectEnd());
        $this->assertSame('Torneo de ejemplo', $survey->title());
        $this->assertSame($this->organizer->id, $survey->createdBy());

        $choice = $this->forForm(SurveySurface::Web);
        $this->assertSame('survey:'.$survey->id()->value, $choice->selected);
        $this->assertSame('La encuesta «Torneo de ejemplo»', $this->labels($choice->options)['survey:'.$survey->id()->value]);
        $this->assertSame(route('surveys.manage.edit', $survey->id()->value), $choice->editUrl);
    }

    public function test_an_own_survey_needs_an_existing_template_to_start_from(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->choose('own', 'template:'.SurveyTemplateId::generate()->value);
    }

    public function test_an_untouched_prepared_survey_is_dropped_when_the_choice_changes(): void
    {
        $this->choose('own', 'template:'.$this->otherTemplate->id()->value);

        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('none'));

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_an_edited_prepared_survey_is_kept_as_a_draft_of_the_subject(): void
    {
        $this->choose('own', 'template:'.$this->otherTemplate->id()->value);
        $prepared = $this->surveys->findBySubjectEnd(self::KEY);
        $this->assertInstanceOf(Survey::class, $prepared);
        $prepared->editDetails('Torneo de ejemplo, versión del club', null, false);
        $this->surveys->save($prepared);

        $this->assertSame(EndSurveyChoiceOutcome::ReleasedAsDraft, $this->choose('type-default'));

        $kept = $this->surveys->findOrFail($prepared->id());
        $this->assertNull($kept->subjectEndKey());
        $this->assertSame('Torneo de ejemplo, versión del club', $kept->title());
        $this->assertSame([
            'type-default', 'template:'.$this->otherTemplate->id()->value, 'own', 'draft:'.$kept->id()->value, 'none',
        ], array_keys($this->labels($this->forForm()->options)));
    }

    public function test_a_draft_of_the_subject_becomes_its_end_survey(): void
    {
        $draft = $this->app->make(OrganizerSurveyService::class)->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Form, $this->ownInput());

        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choose('draft:'.$draft->id()->value));

        $promoted = $this->surveys->findBySubjectEnd(self::KEY);
        $this->assertInstanceOf(Survey::class, $promoted);
        $this->assertSame($draft->id()->value, $promoted->id()->value);
        $this->assertTrue($promoted->isAwaitingSubjectEnd());
        $this->assertTrue($promoted->sendsOnEnd());
        $this->assertSame('survey:'.$draft->id()->value, $this->forForm()->selected);
    }

    public function test_an_unknown_draft_cannot_be_chosen(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->choose('draft:'.SurveyTemplateId::generate()->value);
    }

    public function test_the_choice_is_locked_once_the_subject_ended(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Ended;

        $choice = $this->forForm();

        $this->assertTrue($choice->locked);
        $this->assertSame(__('surveys::messages.end_choice.locked.ended'), $choice->lockedReason);
        $this->assertSame(EndSurveyChoiceOutcome::Locked, $this->choose('none'));
        $this->assertSame(0, SubjectEndChoiceModel::query()->count());
    }

    public function test_the_choice_is_locked_once_the_survey_went_out(): void
    {
        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->end();
        ExampleThingSource::$status = SurveySubjectStatus::Ended;
        $survey = $this->surveys->findBySubjectEnd(self::KEY);
        $this->assertInstanceOf(Survey::class, $survey);
        $this->assertTrue($survey->wasOpened());

        $choice = $this->forForm(SurveySurface::Panel, $this->userWith('surveys:surveys.manage'));

        $this->assertTrue($choice->locked);
        $this->assertSame(__('surveys::messages.end_choice.locked.sent'), $choice->lockedReason);
        $this->assertSame('survey:'.$survey->id()->value, $choice->selected);
        $this->assertSame(SurveyResource::getUrl('view', ['record' => $survey->id()->value]), $choice->editUrl);
    }

    public function test_a_cancelled_subject_forgets_its_choice_and_locks_it(): void
    {
        $this->choose('none');
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        $this->app->make(SubjectCancelledService::class)->handle(ExampleThingSource::TYPE, 'thing-1', $this->now);

        $this->assertSame(0, SubjectEndChoiceModel::query()->count());
        $this->assertSame(__('surveys::messages.end_choice.locked.cancelled'), $this->forForm()->lockedReason);
    }

    public function test_a_prepared_survey_follows_the_template_chosen_for_the_subject(): void
    {
        $this->choose('template:'.$this->otherTemplate->id()->value);

        $prepared = $this->app->make(OrganizerSurveyService::class)->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        $this->assertSame('Torneo de ejemplo', $prepared->title());
    }

    public function test_a_subject_not_offered_yet_can_keep_a_template_or_none(): void
    {
        $manager = $this->userWith('surveys:surveys.manage');

        $choice = $this->choice->forForm(ExampleThingSource::TYPE, 'thing-2', $manager->id, SurveySurface::Panel);

        $this->assertInstanceOf(EndSurveyChoiceDTO::class, $choice);
        $this->assertSame(['type-default', 'template:'.$this->otherTemplate->id()->value, 'none'], array_keys($this->labels($choice->options)));
        $this->assertNull($choice->ownValue);
        $this->assertFalse($choice->locked);

        $this->assertSame(EndSurveyChoiceOutcome::Saved, $this->choice->choose(ExampleThingSource::TYPE, 'thing-2', new EndSurveySelection('none'), $manager->id));
        $this->assertSame('none', $this->choice->forForm(ExampleThingSource::TYPE, 'thing-2', $manager->id, SurveySurface::Panel)?->selected);
        $this->assertNull($this->app->make(EndSurveyTemplateResolver::class)->templateFor(ExampleThingSource::TYPE, 'thing-2'));
    }

    public function test_an_own_survey_for_a_subject_not_offered_yet_keeps_its_template_to_start_from(): void
    {
        $manager = $this->userWith('surveys:surveys.manage');

        $outcome = $this->choice->choose(ExampleThingSource::TYPE, 'thing-2', new EndSurveySelection('own', 'template:'.$this->otherTemplate->id()->value), $manager->id);

        $this->assertSame(EndSurveyChoiceOutcome::Deferred, $outcome);
        $this->assertSame(0, SurveyModel::query()->count());
        $this->assertSame('Torneo de ejemplo', $this->app->make(EndSurveyTemplateResolver::class)->templateFor(ExampleThingSource::TYPE, 'thing-2')?->name());
    }

    public function test_an_own_survey_from_the_types_template_for_a_subject_not_offered_yet_stores_nothing(): void
    {
        $manager = $this->userWith('surveys:surveys.manage');

        $outcome = $this->choice->choose(ExampleThingSource::TYPE, 'thing-2', new EndSurveySelection('own', 'template:'.$this->typeTemplate->id()->value), $manager->id);

        $this->assertSame(EndSurveyChoiceOutcome::Deferred, $outcome);
        $this->assertSame(0, SubjectEndChoiceModel::query()->count());
    }

    public function test_only_whoever_manages_the_surveys_chooses_for_a_subject_not_offered_yet(): void
    {
        ExampleThingSource::$managers = [$this->organizer->id];

        $this->assertNull($this->choice->forForm(ExampleThingSource::TYPE, 'thing-2', $this->organizer->id, SurveySurface::Panel));

        $this->expectException(AuthorizationException::class);

        $this->choice->choose(ExampleThingSource::TYPE, 'thing-2', new EndSurveySelection('none'), $this->organizer->id);
    }

    private function forForm(SurveySurface $surface = SurveySurface::Panel, ?UserModel $user = null): EndSurveyChoiceDTO
    {
        $choice = $this->choice->forForm(ExampleThingSource::TYPE, 'thing-1', ($user ?? $this->organizer)->id, $surface);
        $this->assertInstanceOf(EndSurveyChoiceDTO::class, $choice);

        return $choice;
    }

    private function choose(string $value, ?string $base = null, ?string $userId = null): EndSurveyChoiceOutcome
    {
        return $this->choice->choose(ExampleThingSource::TYPE, 'thing-1', new EndSurveySelection($value, $base), $userId ?? $this->organizer->id);
    }

    private function end(): void
    {
        $this->app->make(SubjectEndedService::class)->handle(ExampleThingSource::TYPE, 'thing-1', $this->now, $this->now);
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

    private function ownInput(): OrganizerSurveyInput
    {
        return new OrganizerSurveyInput(
            title: 'Qué jugar después',
            description: null,
            definition: SurveyDefinition::fromArray(['sections' => [[
                'id' => 'section-1',
                'title' => null,
                'description' => null,
                'questions' => [[
                    'id' => 'question-1',
                    'key' => null,
                    'type' => 'short_text',
                    'title' => '¿Qué te gustaría jugar?',
                    'help' => null,
                    'required' => true,
                ]],
            ]]]),
            anonymous: false,
            durationDays: 3,
            closesAt: null,
            reminderHoursBefore: null,
            liveResults: false,
            visibleToRespondents: true,
        );
    }

    private function userWith(string $permissionKey): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        $permission = PermissionModel::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['label' => $permissionKey, 'resource' => 'surveys', 'action' => substr($permissionKey, strrpos($permissionKey, '.') + 1), 'module' => 'surveys'],
        );
        $role->permissions()->attach($permission->id);

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
