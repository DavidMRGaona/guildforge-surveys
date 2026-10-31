<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The subject's page and the organizer's actions on the public site, always decided live by the
 * subject's source.
 */
final class OrganizerSurveyControllerTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private SurveyRepositoryInterface $surveys;

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
        ExampleThingSource::$audience = new SurveyAudienceDTO([UserModel::factory()->create()->id, UserModel::factory()->create()->id]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-05T20:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_subject_page_lists_its_surveys_for_its_organizer(): void
    {
        $offered = $this->offered();
        $dismissed = $this->offered();
        $dismissed->dismiss(now()->toDateTimeImmutable());
        $this->surveys->save($dismissed);

        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Subject', false)
                ->where('subject.title', 'Partida de ejemplo')
                ->has('surveys', 1)
                ->where('surveys.0.id', $offered->id()->value)
                ->where('surveys.0.status', 'to_send')
                ->where('surveys.0.sendUrl', route('surveys.manage.send', $offered->id()->value))
                ->where('surveys.0.dismissUrl', route('surveys.manage.dismiss', $offered->id()->value)));
    }

    public function test_others_cannot_see_the_subject_page(): void
    {
        $this->offered();

        $this->actingAs(UserModel::factory()->create())
            ->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertForbidden();
    }

    public function test_an_unknown_subject_or_kind_is_not_found(): void
    {
        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'missing']))->assertNotFound();
        $this->actingAs($this->organizer)->get(route('surveys.subject', ['other-thing', 'thing-1']))->assertNotFound();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))->assertRedirect();
    }

    public function test_sending_opens_the_survey_and_says_so(): void
    {
        $survey = $this->offered();

        $this->actingAs($this->organizer)->from(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->post(route('surveys.manage.send', $survey->id()->value))
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertSessionHas('success', __('surveys::messages.manage.sent'));

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_sending_to_review_says_so(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->offered();
        $survey->markUnreviewedChanges($this->organizer->id);
        $this->surveys->save($survey);

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.send', $survey->id()->value))
            ->assertSessionHas('success', __('surveys::messages.manage.sent_for_review'));
    }

    public function test_sending_twice_says_it_is_already_sent(): void
    {
        $survey = $this->offered();
        $this->actingAs($this->organizer)->post(route('surveys.manage.send', $survey->id()->value));

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.send', $survey->id()->value))
            ->assertSessionHas('error', __('surveys::messages.manage.not_sendable'));
    }

    public function test_dismissing_hides_it(): void
    {
        $survey = $this->offered();

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.dismiss', $survey->id()->value))
            ->assertSessionHas('success', __('surveys::messages.manage.dismissed'));

        $this->assertNotNull($this->surveys->findOrFail($survey->id())->dismissedAt());
    }

    public function test_strangers_and_lost_managers_are_refused(): void
    {
        $survey = $this->offered();
        $stranger = UserModel::factory()->create();

        $this->actingAs($stranger)->post(route('surveys.manage.send', $survey->id()->value))->assertForbidden();
        $this->actingAs($stranger)->post(route('surveys.manage.dismiss', $survey->id()->value))->assertForbidden();

        ExampleThingSource::$managers = [];
        $this->actingAs($this->organizer)->post(route('surveys.manage.send', $survey->id()->value))->assertForbidden();
        $this->assertSame(SurveyStatus::Draft, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_a_disabled_source_closes_the_door(): void
    {
        $survey = $this->offered();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->clear();

        $this->actingAs($this->organizer)->post(route('surveys.manage.send', $survey->id()->value))->assertForbidden();
    }

    public function test_an_unknown_survey_is_not_found(): void
    {
        $this->actingAs($this->organizer)->post(route('surveys.manage.send', SurveyTemplateId::generate()->value))->assertNotFound();
    }

    private function offered(): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: null);
    }
}
