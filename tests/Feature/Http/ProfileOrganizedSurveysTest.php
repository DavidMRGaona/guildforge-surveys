<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The profile's surveys tab also lists the surveys of what the person organizes.
 */
final class ProfileOrganizedSurveysTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private UserModel $player;

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
        $this->player = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO([$this->player->id, UserModel::factory()->create()->id]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->now = new DateTimeImmutable('2030-01-05T20:00:00+00:00');
        $this->travelTo($this->now);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_profile_lists_each_survey_with_its_state(): void
    {
        $toSend = $this->offered('Por enviar', ExampleThingSource::TYPE.':thing-1');
        $scheduled = $this->offered('Programada');
        $scheduled->scheduleSending($this->now->modify('+12 hours'));
        $this->surveys->save($scheduled);
        $open = $this->offered('Abierta');
        $this->app->make(SurveyLifecycleService::class)->open($open->id(), $this->now);
        $this->app->make(SubmissionService::class)->submit($open->id(), Respondent::account($this->player->id), ['q-short' => 'Hola']);
        $rejected = $this->offered('Rechazada');
        $rejected->submitForReview($this->organizer->id, $this->now);
        $rejected->reject('00000000-0000-4000-8000-0000000000aa', 'Falta una pregunta', $this->now);
        $this->surveys->save($rejected);
        $dismissed = $this->offered('Descartada');
        $dismissed->dismiss($this->now);
        $this->surveys->save($dismissed);

        $this->actingAs($this->organizer)->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($toSend, $scheduled, $open, $rejected): Assert {
                /** @var list<array{id: string, status: string, subjectTitle: ?string, scheduledSendAt: ?string, rejectionReason: ?string, responses: int, audienceSize: int, sendUrl: ?string, resultsUrl: ?string}> $rows */
                $rows = $page->toArray()['props']['profileOrganizedSurveys'];
                $surveys = array_column($rows, null, 'id');

                $this->assertCount(4, $surveys);
                $this->assertSame('to_send', $surveys[$toSend->id()->value]['status']);
                $this->assertSame('scheduled', $surveys[$scheduled->id()->value]['status']);
                $this->assertNotNull($surveys[$scheduled->id()->value]['scheduledSendAt']);
                $this->assertSame('open', $surveys[$open->id()->value]['status']);
                $this->assertSame(1, $surveys[$open->id()->value]['responses']);
                $this->assertSame(2, $surveys[$open->id()->value]['audienceSize']);
                $this->assertNull($surveys[$open->id()->value]['sendUrl']);
                // Results only once it opened
                $this->assertSame(route('surveys.manage.results', $open->id()->value), $surveys[$open->id()->value]['resultsUrl']);
                $this->assertNull($surveys[$toSend->id()->value]['resultsUrl']);
                $this->assertSame('rejected', $surveys[$rejected->id()->value]['status']);
                $this->assertSame('Falta una pregunta', $surveys[$rejected->id()->value]['rejectionReason']);
                $this->assertSame('Partida de ejemplo', $surveys[$toSend->id()->value]['subjectTitle']);

                // The badge counts what waits for the organizer: one to send and one rejected
                return $page->where('profilePendingSurveysTotal', 2);
            });
    }

    public function test_someone_who_no_longer_manages_the_subject_sees_none(): void
    {
        $this->offered('Por enviar');
        ExampleThingSource::$managers = [];

        $this->actingAs($this->organizer)->get(route('profile.show'))
            ->assertInertia(fn (Assert $page) => $page->has('profileOrganizedSurveys', 0)->where('profilePendingSurveysTotal', 0));
    }

    public function test_players_do_not_see_them_as_organized(): void
    {
        $this->offered('Por enviar');

        $this->actingAs($this->player)->get(route('profile.show'))
            ->assertInertia(fn (Assert $page) => $page->has('profileOrganizedSurveys', 0));
    }

    public function test_other_pages_do_not_load_it(): void
    {
        $this->offered('Por enviar');

        $this->actingAs($this->organizer)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('profileOrganizedSurveys', null));
    }

    /**
     * @param  string|null  $endKey  given, the subject's end survey offered after its end
     */
    private function offered(string $title, ?string $endKey = null): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            $title,
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(['sections' => [[
                'id' => 's-1',
                'title' => null,
                'description' => null,
                'questions' => [[
                    'id' => 'q-short', 'key' => null, 'type' => 'short_text', 'title' => 'Pregunta', 'help' => null,
                    'required' => false, 'options' => [], 'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null,
                ]],
            ]]]),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: $endKey, subjectEndedAt: $endKey === null ? null : $this->now);
    }
}
