<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Domain\Surveys\Events\SurveySubjectCancelled;
use App\Domain\Surveys\Events\SurveySubjectEnded;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\OrganizedSurveysQueryInterface;
use Modules\Surveys\Application\DTOs\OrganizerSurveyInput;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Exceptions\SubjectCancelledException;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Whoever manages a subject asks its audience whatever they like, whenever they like: several
 * forms and polls per subject, made on the public site and so reviewed when the setting says.
 */
final class OrganizerOwnSurveysTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private UserModel $stranger;

    private DateTimeImmutable $now;

    private OrganizerSurveyService $service;

    private SurveyRepositoryInterface $surveys;

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
        ExampleThingSource::$audience = new SurveyAudienceDTO($this->players(2));
        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => ['close_days' => 3, 'anonymous' => false],
        ]);

        $this->now = new DateTimeImmutable('2030-01-04T10:00:00+00:00');
        $this->travelTo($this->now);

        $this->service = $this->app->make(OrganizerSurveyService::class);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_a_manager_creates_a_form_about_the_subject(): void
    {
        $survey = $this->createForm();

        $this->assertSame(SurveyKind::Form, $survey->kind());
        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertSame(AudienceType::Subject, $survey->audienceType());
        $this->assertSame(ExampleThingSource::TYPE, $survey->subject()?->sourceType);
        $this->assertSame('thing-1', $survey->subject()->subjectId);
        $this->assertSame([$this->organizer->id], $survey->subject()->organizerIds);
        $this->assertSame($this->organizer->id, $survey->createdBy());
        $this->assertNull($survey->subjectEndKey());
        $this->assertNull($survey->templateId());
        $this->assertFalse($survey->isAwaitingSubjectEnd());
        $this->assertTrue($survey->hasUnreviewedChanges());
        $this->assertSame($this->organizer->id, $survey->lastChangedBy());
        $this->assertSame(3, $survey->durationDays());
        $this->assertSame(0, SurveyRecipientModel::query()->count());
        Notification::assertNothingSent();
    }

    public function test_a_manager_creates_a_poll(): void
    {
        $survey = $this->service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Poll, $this->input($this->pollDefinition(), liveResults: true));

        $stored = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyKind::Poll, $stored->kind());
        $this->assertTrue($stored->results()->liveResults);
        $this->assertTrue($stored->results()->visibleToRespondents);
        $this->assertSame('¿Qué día jugamos?', $stored->title());
    }

    public function test_an_invalid_poll_is_refused(): void
    {
        $this->expectException(InvalidSurveyDefinitionException::class);

        $this->service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Poll, $this->input($this->formDefinition()));
    }

    public function test_own_surveys_take_no_keys(): void
    {
        $this->expectException(InvalidSurveyDefinitionException::class);

        $this->service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Form, $this->input($this->formDefinition(key: 'overall')));
    }

    public function test_a_stranger_cannot_create(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->create(ExampleThingSource::TYPE, 'thing-1', $this->stranger->id, SurveyKind::Form, $this->input($this->formDefinition()));
    }

    public function test_an_unknown_subject_is_not_found(): void
    {
        $this->expectException(SubjectNotFoundException::class);

        $this->service->create(ExampleThingSource::TYPE, 'thing-404', $this->organizer->id, SurveyKind::Form, $this->input($this->formDefinition()));
    }

    public function test_a_cancelled_subject_takes_no_new_surveys(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        $this->expectException(SubjectCancelledException::class);

        $this->createForm();
    }

    public function test_an_ended_subject_takes_new_surveys(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Ended;

        $survey = $this->createForm();

        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertFalse($survey->isAwaitingSubjectEnd());
    }

    public function test_the_defaults_come_from_the_subject_type(): void
    {
        $this->assertSame(
            ['anonymous' => false, 'durationDays' => 3, 'liveResults' => false, 'visibleToRespondents' => false, 'closingSummary' => true],
            $this->service->defaultsFor(ExampleThingSource::TYPE, SurveyKind::Form),
        );
        $this->assertSame(
            ['anonymous' => false, 'durationDays' => 3, 'liveResults' => true, 'visibleToRespondents' => true, 'closingSummary' => true],
            $this->service->defaultsFor(ExampleThingSource::TYPE, SurveyKind::Poll),
        );
    }

    public function test_the_starting_definitions_have_the_shape_of_their_kind(): void
    {
        $form = $this->service->startingDefinition(SurveyKind::Form)->questions();
        $poll = $this->service->startingDefinition(SurveyKind::Poll)->questions();

        $this->assertCount(1, $form);
        $this->assertSame(QuestionType::ShortText, $form[0]->type);
        $this->assertCount(1, $poll);
        $this->assertSame(QuestionType::SingleChoice, $poll[0]->type);
        $this->assertCount(2, $poll[0]->options);
        $this->assertNotSame($poll[0]->options[0]->id, $poll[0]->options[1]->id);
    }

    public function test_several_surveys_live_alongside_the_end_survey(): void
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);
        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => ['template_id' => $template->id()->value, 'auto_send' => false],
        ]);
        $prepared = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $first = $this->createForm();
        $second = $this->createForm();

        event(new SurveySubjectEnded(ExampleThingSource::TYPE, 'thing-1', $this->now));

        $this->assertInstanceOf(DateTimeImmutable::class, $this->surveys->findOrFail($prepared->id())->subjectEndedAt());
        foreach ([$first, $second] as $own) {
            $stored = $this->surveys->findOrFail($own->id());
            $this->assertSame(SurveyStatus::Draft, $stored->status());
            $this->assertFalse($stored->wasOpened());
            $this->assertNull($stored->subjectEndedAt());
        }
        $this->assertCount(3, $this->surveys->findBySubject(ExampleThingSource::TYPE, 'thing-1'));
    }

    public function test_sending_with_review_off_opens_it_to_the_audience_at_that_moment(): void
    {
        $survey = $this->createForm();
        ExampleThingSource::$audience = new SurveyAudienceDTO($this->players(3));

        $outcome = $this->service->send($survey->id(), $this->organizer->id);

        $this->assertSame(SendOutcome::Opened, $outcome);
        $opened = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Open, $opened->status());
        $this->assertSame(3, $opened->audienceSize());
        $this->assertSame(3, SurveyRecipientModel::query()->count());
        $this->assertEquals($this->now->modify('+3 days'), $opened->closesAt());
    }

    public function test_a_survey_without_duration_stays_open_until_closed(): void
    {
        $survey = $this->service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Poll, $this->input($this->pollDefinition(), durationDays: null));

        $this->service->send($survey->id(), $this->organizer->id);

        $this->assertNull($this->surveys->findOrFail($survey->id())->closesAt());
    }

    public function test_sending_with_review_on_waits_for_a_moderator(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Poll, $this->input($this->pollDefinition()));

        $outcome = $this->service->send($survey->id(), $this->organizer->id);

        $this->assertSame(SendOutcome::SentForReview, $outcome);
        $pending = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::PendingReview, $pending->status());
        $this->assertFalse($pending->isAcceptingResponses($this->now));
        $this->assertSame(0, SurveyRecipientModel::query()->count());
    }

    public function test_sending_refreshes_the_snapshot(): void
    {
        $survey = $this->createForm();
        $successor = UserModel::factory()->create();
        ExampleThingSource::$title = 'Partida renombrada';
        ExampleThingSource::$organizerIds = [$successor->id];
        ExampleThingSource::$managers = [$this->organizer->id, $successor->id];

        $this->service->send($survey->id(), $this->organizer->id);

        $opened = $this->surveys->findOrFail($survey->id());
        $this->assertSame('Partida renombrada', $opened->subject()?->title);
        $this->assertSame([$successor->id], $opened->subject()->organizerIds);
    }

    public function test_cancelling_the_subject_withdraws_unsent_own_surveys(): void
    {
        $unsent = $this->createForm();
        $sent = $this->createForm();
        $this->service->send($sent->id(), $this->organizer->id);

        event(new SurveySubjectCancelled(ExampleThingSource::TYPE, 'thing-1', $this->now));

        $this->assertInstanceOf(DateTimeImmutable::class, $this->surveys->findOrFail($unsent->id())->dismissedAt());
        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($sent->id())->status());
    }

    public function test_a_manager_closes_an_open_survey(): void
    {
        $survey = $this->createForm();
        $this->service->send($survey->id(), $this->organizer->id);
        $later = $this->now->modify('+1 hour');

        $this->service->close($survey->id(), $this->organizer->id, $later);

        $closed = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Closed, $closed->status());
        $this->assertEquals($later, $closed->closedAt());
    }

    public function test_a_stranger_cannot_close(): void
    {
        $survey = $this->createForm();
        $this->service->send($survey->id(), $this->organizer->id);

        $this->expectException(AuthorizationException::class);

        $this->service->close($survey->id(), $this->stranger->id);
    }

    public function test_closing_a_survey_that_is_not_open_is_refused(): void
    {
        $survey = $this->createForm();

        $this->expectException(InvalidSurveyStateException::class);

        $this->service->close($survey->id(), $this->organizer->id);
    }

    public function test_the_list_shows_own_drafts_as_drafts_and_lets_open_ones_close(): void
    {
        $draft = $this->createForm();
        $open = $this->createForm();
        $this->service->send($open->id(), $this->organizer->id);

        $rows = [];
        foreach ($this->app->make(OrganizedSurveysQueryInterface::class)->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id) as $row) {
            $rows[$row['id']] = $row;
        }

        $this->assertSame('draft', $rows[$draft->id()->value]['status']);
        $this->assertSame(route('surveys.manage.send', $draft->id()->value), $rows[$draft->id()->value]['sendUrl']);
        $this->assertSame(route('surveys.manage.dismiss', $draft->id()->value), $rows[$draft->id()->value]['dismissUrl']);
        $this->assertNull($rows[$draft->id()->value]['closeUrl']);
        $this->assertSame('open', $rows[$open->id()->value]['status']);
        $this->assertSame(route('surveys.manage.close', $open->id()->value), $rows[$open->id()->value]['closeUrl']);
    }

    private function createForm(): Survey
    {
        return $this->service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Form, $this->input($this->formDefinition()));
    }

    private function input(SurveyDefinition $definition, ?int $durationDays = 3, bool $liveResults = false): OrganizerSurveyInput
    {
        return new OrganizerSurveyInput(
            title: $definition->questions()[0]->title,
            description: null,
            definition: $definition,
            anonymous: false,
            durationDays: $durationDays,
            closesAt: null,
            reminderHoursBefore: null,
            liveResults: $liveResults,
            visibleToRespondents: true,
        );
    }

    private function formDefinition(?string $key = null): SurveyDefinition
    {
        return SurveyDefinition::fromArray(['sections' => [[
            'id' => 'section-1',
            'title' => null,
            'description' => null,
            'questions' => [[
                'id' => 'question-1',
                'key' => $key,
                'type' => 'short_text',
                'title' => '¿Qué te gustaría jugar?',
                'help' => null,
                'required' => true,
            ]],
        ]]]);
    }

    private function pollDefinition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(['sections' => [[
            'id' => 'section-1',
            'title' => null,
            'description' => null,
            'questions' => [[
                'id' => 'question-1',
                'key' => null,
                'type' => 'single_choice',
                'title' => '¿Qué día jugamos?',
                'help' => null,
                'required' => true,
                'options' => [['id' => 'option-1', 'label' => 'Viernes'], ['id' => 'option-2', 'label' => 'Sábado']],
            ]],
        ]]]);
    }

    /**
     * @return list<string>
     */
    private function players(int $count): array
    {
        return array_values(UserModel::factory()->count($count)->create()->map(static fn (UserModel $player): string => $player->id)->all());
    }
}
