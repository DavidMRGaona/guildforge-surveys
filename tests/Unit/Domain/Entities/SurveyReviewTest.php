<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

final class SurveyReviewTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-11-10T18:00:00+00:00');
    }

    public function test_submitting_an_open_survey_stops_responses(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);

        $survey->submitForReview('organizer-1', $this->now->modify('+1 hour'));

        $this->assertSame(SurveyStatus::PendingReview, $survey->status());
        $this->assertFalse($survey->isAcceptingResponses($this->now->modify('+1 hour')));
        $this->assertSame('organizer-1', $survey->submittedBy());
        $this->assertEquals($this->now->modify('+1 hour'), $survey->submittedForReviewAt());
    }

    public function test_approving_a_never_opened_survey_returns_it_to_draft(): void
    {
        $survey = $this->survey();
        $survey->changeDuration(7);
        $survey->markUnreviewedChanges('organizer-1');
        $survey->submitForReview('organizer-1', $this->now);

        $survey->approve('moderator-1', $this->now->modify('+1 hour'));

        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertSame('moderator-1', $survey->reviewedBy());
        $this->assertEquals($this->now->modify('+1 hour'), $survey->reviewedAt());
        $this->assertFalse($survey->hasUnreviewedChanges());
    }

    public function test_opening_counts_the_duration_from_now(): void
    {
        $survey = $this->survey();
        $survey->changeDuration(7);

        $survey->open($this->now);

        $this->assertEquals($this->now->modify('+7 days'), $survey->closesAt());
    }

    public function test_approving_a_reopened_survey_restarts_its_duration(): void
    {
        $survey = $this->survey();
        $survey->changeDuration(7);
        $survey->open($this->now);
        $survey->submitForReview('organizer-1', $this->now->modify('+1 day'));

        $approvedAt = $this->now->modify('+2 days');
        $survey->approve('moderator-1', $approvedAt);

        $this->assertSame(SurveyStatus::Open, $survey->status());
        $this->assertEquals($approvedAt->modify('+7 days'), $survey->closesAt());
        $this->assertEquals($this->now, $survey->opensAt());
    }

    public function test_reopening_after_a_rejection_keeps_the_first_opening(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);
        $survey->submitForReview('organizer-1', $this->now);
        $survey->reject('moderator-1', 'Falta una pregunta', $this->now);

        $survey->open($this->now->modify('+1 day'));

        $this->assertSame(SurveyStatus::Open, $survey->status());
        $this->assertEquals($this->now, $survey->opensAt());
    }

    public function test_rejecting_keeps_the_reason_until_it_is_sent_again(): void
    {
        $survey = $this->survey();
        $survey->submitForReview('organizer-1', $this->now);

        $survey->reject('moderator-1', 'Falta una pregunta', $this->now);

        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertSame('Falta una pregunta', $survey->rejectionReason());

        $survey->submitForReview('organizer-1', $this->now->modify('+1 hour'));

        $this->assertNull($survey->rejectionReason());
    }

    public function test_rejecting_needs_a_reason(): void
    {
        $survey = $this->survey();
        $survey->submitForReview('organizer-1', $this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->reject('moderator-1', '  ', $this->now);
    }

    public function test_only_pending_surveys_are_approved(): void
    {
        $this->expectException(InvalidSurveyStateException::class);

        $this->survey()->approve('moderator-1', $this->now);
    }

    public function test_only_pending_surveys_are_rejected(): void
    {
        $this->expectException(InvalidSurveyStateException::class);

        $this->survey()->reject('moderator-1', 'Motivo', $this->now);
    }

    public function test_a_closed_survey_is_never_submitted_for_review(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);
        $survey->close($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->submitForReview('organizer-1', $this->now);
    }

    public function test_duration_is_fixed_once_opened(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->changeDuration(3);
    }

    public function test_duration_is_at_least_one_day(): void
    {
        $this->expectException(InvalidSurveyStateException::class);

        $this->survey()->changeDuration(0);
    }

    public function test_only_unopened_drafts_are_dismissed(): void
    {
        $draft = $this->survey();
        $draft->scheduleSending($this->now->modify('+12 hours'));
        $draft->dismiss($this->now);
        $this->assertEquals($this->now, $draft->dismissedAt());
        $this->assertNull($draft->scheduledSendAt());

        $open = $this->survey();
        $open->open($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $open->dismiss($this->now);
    }

    public function test_a_scheduled_draft_is_due_at_its_time(): void
    {
        $survey = $this->survey();
        $survey->scheduleSending($this->now->modify('+12 hours'));

        $this->assertFalse($survey->isDueForSending($this->now->modify('+11 hours')));
        $this->assertTrue($survey->isDueForSending($this->now->modify('+12 hours')));

        $survey->open($this->now->modify('+12 hours'));

        $this->assertNull($survey->scheduledSendAt());
        $this->assertFalse($survey->isDueForSending($this->now->modify('+13 hours')));
    }

    public function test_only_unopened_drafts_are_scheduled(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->scheduleSending($this->now->modify('+1 hour'));
    }

    public function test_marking_changes_remembers_who_made_them(): void
    {
        $survey = $this->survey();

        $survey->markUnreviewedChanges('organizer-1');

        $this->assertTrue($survey->hasUnreviewedChanges());
        $this->assertSame('organizer-1', $survey->lastChangedBy());
    }

    public function test_opening_clears_unreviewed_changes(): void
    {
        $survey = $this->survey();
        $survey->markUnreviewedChanges('organizer-1');

        $survey->open($this->now);

        $this->assertFalse($survey->hasUnreviewedChanges());
    }

    public function test_content_differs_only_on_title_description_and_definition(): void
    {
        $survey = $this->survey();
        $definition = $survey->definition();

        $this->assertFalse($survey->contentDiffers('Encuesta de ejemplo', null, $definition));
        $this->assertTrue($survey->contentDiffers('Otro título', null, $definition));
        $this->assertTrue($survey->contentDiffers('Encuesta de ejemplo', 'Con descripción', $definition));

        $data = $definition->toArray();
        $data['sections'][0]['questions'][0]['help'] = 'Ayuda nueva';
        $this->assertTrue($survey->contentDiffers('Encuesta de ejemplo', null, SurveyDefinition::fromArray($data)));
    }

    private function survey(): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
        );
    }
}
