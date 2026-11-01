<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Domain\Surveys\Events\GeneralSurveyOpened;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;
use Modules\Surveys\Application\Contracts\SurveyRecipientStoreInterface;
use Modules\Surveys\Application\DTOs\AccessLinkRecipient;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Opening a general survey that asked for it announces it to whoever posts on channels,
 * through the core's GeneralSurveyOpened event.
 */
final class SurveyChannelAnnouncementTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = $this->app->make(SurveyLifecycleService::class);
        Event::fake([GeneralSurveyOpened::class]);
    }

    public function test_opening_an_announced_general_survey_dispatches_the_event(): void
    {
        $closesAt = new DateTimeImmutable('+7 days');
        $survey = $this->draft(announce: true, closesAt: $closesAt, description: 'Queremos saber qué días os viene mejor.');

        $this->lifecycle->open($survey->id());

        Event::assertDispatchedTimes(GeneralSurveyOpened::class, 1);
        Event::assertDispatched(GeneralSurveyOpened::class, fn (GeneralSurveyOpened $event): bool => $event->surveyId === $survey->id()->value
            && $event->title === 'Horario del local'
            && $event->description === 'Queremos saber qué días os viene mejor.'
            && $event->isPoll === false
            && $event->closesAt?->getTimestamp() === $closesAt->getTimestamp()
            && $event->url === route('surveys.respond', $survey->id()->value));
    }

    public function test_a_poll_is_announced_as_a_poll(): void
    {
        $survey = $this->draft(announce: true, kind: SurveyKind::Poll);

        $this->lifecycle->open($survey->id());

        Event::assertDispatched(GeneralSurveyOpened::class, fn (GeneralSurveyOpened $event): bool => $event->isPoll);
    }

    public function test_it_links_the_public_link_when_the_survey_has_one(): void
    {
        $survey = $this->draft(announce: true, publicLink: true);

        $opened = $this->lifecycle->open($survey->id());

        $this->assertNotNull($opened->publicToken);
        Event::assertDispatched(GeneralSurveyOpened::class, fn (GeneralSurveyOpened $event): bool => $event->url === route('surveys.public', [$survey->id()->value, $opened->publicToken]));
    }

    public function test_a_survey_without_closing_date_is_announced_without_one(): void
    {
        $survey = $this->draft(announce: true, closesAt: null);

        $this->lifecycle->open($survey->id());

        Event::assertDispatched(GeneralSurveyOpened::class, fn (GeneralSurveyOpened $event): bool => ! $event->closesAt instanceof DateTimeImmutable);
    }

    public function test_it_does_not_announce_when_the_box_is_off(): void
    {
        $survey = $this->draft(announce: false);

        $this->lifecycle->open($survey->id());

        Event::assertNotDispatched(GeneralSurveyOpened::class);
    }

    public function test_it_never_announces_a_subject_survey(): void
    {
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $id = $this->lifecycle->createForSubject(ExampleThingSource::TYPE, 'thing-1', 'Valora la partida', SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()), anonymous: true, closesAt: null);
        // Forced in the database: the panel never offers the box on a subject's survey
        SurveyModel::query()->whereKey($id->value)->update(['announce_in_channels' => true]);

        $this->lifecycle->open($id);

        Event::assertNotDispatched(GeneralSurveyOpened::class);
    }

    public function test_reopening_after_a_review_does_not_announce_again(): void
    {
        $survey = $this->draft(announce: true);
        $this->lifecycle->open($survey->id());

        $repository = $this->app->make(SurveyRepositoryInterface::class);
        $reviewed = $repository->findOrFail($survey->id());
        $reviewed->submitForReview(UserModel::factory()->create()->id, new DateTimeImmutable);
        $reviewed->reject(UserModel::factory()->create()->id, 'Falta una opción', new DateTimeImmutable);
        $repository->save($reviewed);

        $this->lifecycle->open($survey->id());

        Event::assertDispatchedTimes(GeneralSurveyOpened::class, 1);
    }

    public function test_a_failed_opening_announces_nothing(): void
    {
        $survey = $this->draft(announce: true);
        $recipients = $this->app->make(SurveyRecipientStoreInterface::class);
        $this->app->instance(SurveyRecipientStoreInterface::class, new readonly class($recipients) implements SurveyRecipientStoreInterface
        {
            public function __construct(private SurveyRecipientStoreInterface $inner) {}

            public function fix(SurveyId $surveyId, SurveyAudienceDTO $audience, array $guestTokens): void
            {
                throw new RuntimeException('The database went away.');
            }

            public function forgetGuestEmails(SurveyId $surveyId): void
            {
                $this->inner->forgetGuestEmails($surveyId);
            }

            public function audienceMemberWith(SurveyId $surveyId, string $normalizedEmail): ?AccessLinkRecipient
            {
                return $this->inner->audienceMemberWith($surveyId, $normalizedEmail);
            }

            public function publicLinkRecipient(SurveyId $surveyId, string $reference, string $email): AccessLinkRecipient
            {
                return $this->inner->publicLinkRecipient($surveyId, $reference, $email);
            }
        });

        try {
            $this->app->make(SurveyLifecycleService::class)->open($survey->id());
            $this->fail('The opening should have failed.');
        } catch (RuntimeException) {
            Event::assertNotDispatched(GeneralSurveyOpened::class);
        }
    }

    public function test_a_failing_listener_does_not_break_the_opening(): void
    {
        // Back to the real dispatcher, so the listener runs
        $fake = Event::getFacadeRoot();
        $this->assertInstanceOf(EventFake::class, $fake);
        Event::swap($fake->dispatcher);
        Event::listen(GeneralSurveyOpened::class, static function (): void {
            throw new RuntimeException('The channel settings could not be read.');
        });
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(static function (RuntimeException $e) use (&$reported): bool {
            $reported[] = $e->getMessage();

            return false;
        });
        $survey = $this->draft(announce: true);

        $this->lifecycle->open($survey->id());

        $this->assertSame('open', SurveyModel::query()->findOrFail($survey->id()->value)->status);
        $this->assertSame(['The channel settings could not be read.'], $reported);
    }

    private function draft(bool $announce, SurveyKind $kind = SurveyKind::Form, bool $publicLink = false, ?DateTimeImmutable $closesAt = new DateTimeImmutable('+7 days'), ?string $description = null): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: $kind,
            title: 'Horario del local',
            description: $description,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor($kind),
            audienceType: AudienceType::AllUsers,
            publicLinkEnabled: $publicLink,
            closesAt: $closesAt,
        );
        $survey->chooseChannelAnnouncement($announce);
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return $survey;
    }
}
