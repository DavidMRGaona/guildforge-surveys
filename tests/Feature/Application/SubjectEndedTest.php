<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Services\TimezoneServiceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Domain\Surveys\Events\SurveySubjectEnded;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Notifications\SurveyReadyToSend;
use Modules\Surveys\Notifications\SurveyScheduled;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What happens when a module says one of its subjects ended: the subject's survey is made from
 * the template of its kind, then sent (at once or after a delay) or offered to its organizers.
 */
final class SubjectEndedTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private UserModel $stranger;

    private SurveyTemplate $template;

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
        $players = UserModel::factory()->count(2)->create();
        ExampleThingSource::$audience = new SurveyAudienceDTO(array_values($players->map(static fn (UserModel $player): string => $player->id)->all()));
        ExampleThingSource::$organizerIds = [$this->organizer->id, $this->stranger->id];
        // The second organizer no longer runs the subject: the source decides live
        ExampleThingSource::$managers = [$this->organizer->id];

        $this->template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            'Cuéntanos qué tal fue',
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($this->template);

        $this->now = new DateTimeImmutable('2030-01-05T20:00:00+00:00');
        $this->travelTo($this->now);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_automatic_sending_waits_twelve_hours_by_default(): void
    {
        $this->configure(['auto_send' => true]);

        $this->end($this->now);

        $survey = $this->onlySurvey();
        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertEquals(new DateTimeImmutable('2030-01-06T08:00:00+00:00'), $survey->scheduledSendAt());
        $this->assertSame(0, SurveyRecipientModel::query()->count());
        Notification::assertNothingSentTo(UserModel::query()->whereNotIn('id', [$this->organizer->id, $this->stranger->id])->get());
        Notification::assertSentTo($this->organizer, SurveyScheduled::class);
        Notification::assertNotSentTo($this->stranger, SurveyScheduled::class);
    }

    public function test_the_scheduled_mail_tells_the_time_people_see(): void
    {
        $this->configure(['auto_send' => true]);

        $this->end($this->now);

        $expected = app(TimezoneServiceInterface::class)->format(new DateTimeImmutable('2030-01-06T08:00:00+00:00'));
        Notification::assertSentTo($this->organizer, SurveyScheduled::class, function (SurveyScheduled $mail) use ($expected): bool {
            $text = implode("\n", $mail->toMail($this->organizer)->introLines);

            return $mail->sendsAt === $expected
                && str_contains($text, $expected)
                && $mail->url === route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']);
        });
    }

    public function test_a_zero_delay_opens_the_survey_on_end(): void
    {
        $this->configure(['auto_send' => true, 'send_delay_hours' => 0, 'close_days' => 3]);

        $this->end($this->now);

        $survey = $this->onlySurvey();
        $this->assertSame(SurveyStatus::Open, $survey->status());
        $this->assertEquals($this->now->modify('+3 days'), $survey->closesAt());
        $this->assertSame(2, SurveyRecipientModel::query()->count());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
        Notification::assertNotSentTo($this->organizer, SurveyScheduled::class);
    }

    public function test_a_late_event_past_its_delay_opens_at_once(): void
    {
        $this->configure(['auto_send' => true]);

        $this->end($this->now->modify('-13 hours'));

        $this->assertSame(SurveyStatus::Open, $this->onlySurvey()->status());
    }

    public function test_the_delay_counts_from_the_end_not_from_the_event(): void
    {
        $this->configure(['auto_send' => true]);
        $endedAt = $this->now->modify('-1 hour');

        $this->end($endedAt);

        $this->assertEquals($endedAt->modify('+12 hours'), $this->onlySurvey()->scheduledSendAt());
    }

    public function test_one_click_offers_the_survey_to_its_organizers(): void
    {
        $this->configure(['close_days' => 5, 'anonymous' => false]);

        $this->end($this->now);

        $survey = $this->onlySurvey();
        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertNull($survey->scheduledSendAt());
        $this->assertSame(5, $survey->durationDays());
        $this->assertFalse($survey->isAnonymous());
        Notification::assertSentTo($this->organizer, SurveyReadyToSend::class);
        Notification::assertNotSentTo($this->stranger, SurveyReadyToSend::class);
        Notification::assertNotSentTo($this->organizer, SurveyScheduled::class);
    }

    public function test_the_survey_is_a_copy_of_the_template_about_the_subject(): void
    {
        $this->configure([]);

        $this->end($this->now);

        $survey = $this->onlySurvey();
        $this->assertSame('Valoración de ejemplo', $survey->title());
        $this->assertSame('Cuéntanos qué tal fue', $survey->description());
        $this->assertEquals($this->template->definition(), $survey->definition());
        $this->assertEquals($this->template->id(), $survey->templateId());
        $this->assertSame(AudienceType::Subject, $survey->audienceType());
        $this->assertSame('thing-1', $survey->subject()?->subjectId);
        $this->assertSame('example-thing:thing-1', $survey->subjectEndKey());
        $this->assertFalse($survey->hasUnreviewedChanges());
    }

    public function test_nothing_happens_without_a_template(): void
    {
        $this->configure(['template_id' => null, 'auto_send' => true, 'send_delay_hours' => 0]);

        $this->end($this->now);

        $this->assertSame(0, SurveyModel::query()->count());
        Notification::assertNothingSent();
    }

    public function test_nothing_happens_for_an_unknown_source_or_subject(): void
    {
        $this->configure(['auto_send' => true, 'send_delay_hours' => 0]);

        event(new SurveySubjectEnded('other-thing', 'thing-1', $this->now));
        event(new SurveySubjectEnded(ExampleThingSource::TYPE, 'missing', $this->now));

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_nothing_happens_when_the_template_is_gone(): void
    {
        $this->configure(['template_id' => SurveyTemplateId::generate()->value]);

        $this->end($this->now);

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_the_same_end_twice_makes_one_survey(): void
    {
        $this->configure(['auto_send' => true, 'send_delay_hours' => 0]);

        $this->end($this->now);
        $this->end($this->now);

        $this->assertSame(1, SurveyModel::query()->count());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function configure(array $type): void
    {
        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => array_merge(['template_id' => $this->template->id()->value], $type),
        ]);
    }

    private function end(DateTimeImmutable $endedAt): void
    {
        event(new SurveySubjectEnded(ExampleThingSource::TYPE, 'thing-1', $endedAt));
    }

    private function onlySurvey(): Survey
    {
        $this->assertSame(1, SurveyModel::query()->count());
        $id = SurveyModel::query()->value('id');

        return $this->app->make(SurveyRepositoryInterface::class)->findOrFail(SurveyId::fromString((string) $id));
    }
}
