<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Application\Services\SurveyReviewService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Notifications\SurveyReviewApproved;
use Modules\Surveys\Notifications\SurveyReviewRejected;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyReviewServiceTest extends ModuleTestCase
{
    private const string MODERATOR = '00000000-0000-4000-8000-0000000000aa';

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private UserModel $player;

    private SurveyRepositoryInterface $surveys;

    private SurveyReviewService $review;

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
        $this->player = UserModel::factory()->create();
        ExampleThingSource::$audience = new SurveyAudienceDTO(
            [$this->player->id],
            [new SurveyGuestDTO('guest-1', 'Invitada de ejemplo', 'invitada@example.test')],
        );

        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->review = $this->app->make(SurveyReviewService::class);
        $this->now = new DateTimeImmutable('2030-01-05T20:00:00+00:00');
        $this->travelTo($this->now);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_approving_a_survey_that_never_opened_sends_it_counting_from_now(): void
    {
        $survey = $this->pending(opened: false);
        $approvedAt = $this->now->modify('+2 days');

        $this->review->approve($survey->id(), self::MODERATOR, $approvedAt);

        $opened = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Open, $opened->status());
        $this->assertEquals($approvedAt->modify('+7 days'), $opened->closesAt());
        $this->assertSame(self::MODERATOR, $opened->reviewedBy());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
        Notification::assertSentTo($this->organizer, SurveyReviewApproved::class);
    }

    public function test_approving_a_survey_that_had_opened_mails_nobody_again(): void
    {
        $survey = $this->pending(opened: true);
        Notification::fake();

        $this->review->approve($survey->id(), self::MODERATOR, $this->now);

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
        Notification::assertSentTimes(SurveyInvitation::class, 0);
        Notification::assertSentTo($this->organizer, SurveyReviewApproved::class);
    }

    public function test_rejecting_tells_the_organizer_why(): void
    {
        $survey = $this->pending(opened: false);

        $this->review->reject($survey->id(), self::MODERATOR, 'Falta *una* pregunta', $this->now);

        $rejected = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Draft, $rejected->status());
        $this->assertSame('Falta *una* pregunta', $rejected->rejectionReason());
        Notification::assertSentTo($this->organizer, SurveyReviewRejected::class, function (SurveyReviewRejected $mail): bool {
            $text = implode("\n", $mail->toMail($this->organizer)->introLines);

            return $mail->reason === 'Falta *una* pregunta' && str_contains($text, 'Falta \*una\* pregunta');
        });
        Notification::assertSentTimes(SurveyInvitation::class, 0);
    }

    public function test_turning_review_off_approves_nothing(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->pending(opened: true);
        config()->set('modules.settings.surveys.review_required', false);

        $stillPending = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::PendingReview, $stillPending->status());
        $this->assertFalse($stillPending->isAcceptingResponses($this->now));
        $this->assertSame(
            SubmissionService::STATE_CLOSED,
            $this->app->make(SubmissionService::class)->stateFor($survey->id(), Respondent::account($this->player->id)),
        );

        $this->review->approve($survey->id(), self::MODERATOR, $this->now);
        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_a_survey_reviewed_meanwhile_is_not_reviewed_twice(): void
    {
        $survey = $this->pending(opened: false);
        $this->review->approve($survey->id(), self::MODERATOR, $this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $this->review->reject($survey->id(), self::MODERATOR, 'Tarde', $this->now);
    }

    private function pending(bool $opened): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $survey = $lifecycle->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: null);
        if ($opened) {
            $lifecycle->open($survey->id(), $this->now);
            $survey = $this->surveys->findOrFail($survey->id());
        }

        $survey->markUnreviewedChanges($this->organizer->id);
        $survey->submitForReview($this->organizer->id, $this->now);
        $this->surveys->save($survey);

        return $survey;
    }
}
