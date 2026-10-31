<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Infrastructure;

use App\Application\Surveys\Contracts\SurveySubjectLinksInterface;
use App\Application\Surveys\DTOs\SurveyLinkDTO;
use App\Application\Surveys\Enums\SurveyLinkAction;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What a subject's page links to: its surveys page for whoever manages the subject, and each
 * participant's own surveys, to answer or, when the survey shares them, to see the results.
 */
final class SurveySubjectLinksTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveySubjectLinksInterface $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        $this->createExamplePeople();
        $this->links = $this->app->make(SurveySubjectLinksInterface::class);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_a_visitor_gets_no_links(): void
    {
        $this->openPoll(anonymous: false);

        $this->assertTrue($this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', null)->isEmpty());
    }

    public function test_whoever_manages_the_subject_gets_its_surveys_page(): void
    {
        $organizer = UserModel::factory()->create();
        ExampleThingSource::$managers = [$organizer->id];

        $links = $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $organizer->id);

        $this->assertSame(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']), $links->manageUrl);
        $this->assertSame([], $links->surveys);
    }

    public function test_a_participant_does_not_get_the_surveys_page(): void
    {
        $this->openPoll(anonymous: false);

        $this->assertNull($this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->people[0]->id)->manageUrl);
    }

    public function test_a_participant_answers_an_open_survey(): void
    {
        [$id] = $this->openPoll(anonymous: false);

        $links = $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->people[0]->id);

        $this->assertEquals([new SurveyLinkDTO('Encuesta de ejemplo', route('surveys.respond', $id->value), SurveyLinkAction::Respond)], $links->surveys);
    }

    public function test_after_answering_a_survey_that_shares_its_results_they_are_linked(): void
    {
        [$id] = $this->openPoll(anonymous: false);
        $this->app->make(SubmissionService::class)->submit($id, Respondent::account($this->people[0]->id), ['q-1' => 'o-1']);

        $links = $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->people[0]->id);

        $this->assertEquals([new SurveyLinkDTO('Encuesta de ejemplo', route('surveys.respond', $id->value), SurveyLinkAction::ViewResults)], $links->surveys);
    }

    public function test_after_answering_a_survey_that_keeps_its_results_there_is_nothing_to_link(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0);

        $this->assertSame([], $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->people[0]->id)->surveys);
    }

    public function test_an_anonymous_survey_links_its_results_only_once_they_show(): void
    {
        [$id] = $this->openForm(anonymous: true, results: new ResultsSettings(visibleToRespondents: true));
        $this->answer($id, 0);

        // Open and below the minimum: nothing to see yet
        $this->assertSame([], $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->people[0]->id)->surveys);

        $this->answer($id, 1);
        $this->answer($id, 2);
        $this->close($id);

        $links = $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->people[0]->id);
        $this->assertSame(SurveyLinkAction::ViewResults, $links->surveys[0]->action ?? null);
    }

    public function test_someone_outside_the_audience_gets_no_survey(): void
    {
        $this->openPoll(anonymous: false);

        $this->assertSame([], $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', UserModel::factory()->create()->id)->surveys);
    }

    public function test_a_survey_that_never_opened_is_not_linked(): void
    {
        $this->app->make(SurveyRepositoryInterface::class)->save(new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta preparada',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: SubjectSnapshotFactory::fromDto((new ExampleThingSource)->find('thing-1') ?? self::fail('The example thing exists.')),
            subjectEndKey: ExampleThingSource::TYPE.':thing-1',
        ));

        $this->assertSame([], $this->links->forSubject(ExampleThingSource::TYPE, 'thing-1', $this->people[0]->id)->surveys);
    }

    public function test_an_unknown_subject_or_type_has_no_links(): void
    {
        $organizer = UserModel::factory()->create();
        ExampleThingSource::$managers = [$organizer->id];

        $this->assertTrue($this->links->forSubject(ExampleThingSource::TYPE, 'missing', $organizer->id)->isEmpty());
        $this->assertTrue($this->links->forSubject('other-thing', 'thing-1', $organizer->id)->isEmpty());
    }

    private function close(SurveyId $id): void
    {
        $surveys = $this->app->make(SurveyRepositoryInterface::class);
        $survey = $surveys->findOrFail($id);
        $survey->close(new DateTimeImmutable);
        $surveys->save($survey);
    }
}
