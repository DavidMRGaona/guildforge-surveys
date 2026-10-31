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
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

/**
 * A subject's end survey prepared before the subject ends: it waits for the end, then goes out
 * by itself or is offered to its organizers, as chosen.
 */
final class SurveyEndPreparationTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2030-01-04T10:00:00+00:00');
    }

    public function test_an_end_survey_made_before_the_end_waits_for_it(): void
    {
        $survey = $this->endSurvey();

        $this->assertTrue($survey->isAwaitingSubjectEnd());
        $this->assertNull($survey->subjectEndedAt());
    }

    public function test_an_end_survey_made_on_the_end_is_not_waiting(): void
    {
        $survey = $this->endSurvey(subjectEndedAt: $this->now);

        $this->assertFalse($survey->isAwaitingSubjectEnd());
        $this->assertEquals($this->now, $survey->subjectEndedAt());
    }

    public function test_a_survey_that_is_not_about_an_end_never_waits(): void
    {
        $survey = $this->endSurvey(endKey: null);

        $this->assertFalse($survey->isAwaitingSubjectEnd());
    }

    public function test_it_keeps_how_it_goes_out_on_the_end(): void
    {
        $survey = $this->endSurvey();

        $survey->chooseEndSending(sendOnEnd: true, sendDelayHours: 3);

        $this->assertTrue($survey->sendsOnEnd());
        $this->assertSame(3, $survey->sendDelayHours());
    }

    public function test_the_delay_cannot_be_negative(): void
    {
        $survey = $this->endSurvey();

        $this->expectException(InvalidSurveyStateException::class);

        $survey->chooseEndSending(sendOnEnd: true, sendDelayHours: -1);
    }

    public function test_how_it_goes_out_is_fixed_once_the_subject_ended(): void
    {
        $survey = $this->endSurvey(subjectEndedAt: $this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->chooseEndSending(sendOnEnd: false, sendDelayHours: 0);
    }

    public function test_the_end_is_recorded_once(): void
    {
        $survey = $this->endSurvey();
        $later = $this->now->modify('+1 day');

        $survey->markSubjectEnded($this->now);
        $survey->markSubjectEnded($later);

        $this->assertFalse($survey->isAwaitingSubjectEnd());
        $this->assertEquals($this->now, $survey->subjectEndedAt());
    }

    public function test_an_opened_survey_is_not_waiting_any_more(): void
    {
        $survey = $this->endSurvey();

        $survey->open($this->now);

        $this->assertFalse($survey->isAwaitingSubjectEnd());
    }

    public function test_the_snapshot_is_refreshed_until_it_opens(): void
    {
        $survey = $this->endSurvey();
        $renamed = $this->snapshot(title: 'Partida renombrada', organizerIds: ['user-2']);

        $survey->refreshSubject($renamed);

        $this->assertSame('Partida renombrada', $survey->subject()?->title);
        $this->assertSame(['user-2'], $survey->subject()->organizerIds);
    }

    public function test_the_snapshot_is_fixed_once_open(): void
    {
        $survey = $this->endSurvey();
        $survey->open($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->refreshSubject($this->snapshot(title: 'Partida renombrada'));
    }

    public function test_a_snapshot_of_another_subject_is_refused(): void
    {
        $survey = $this->endSurvey();

        $this->expectException(InvalidSurveyStateException::class);

        $survey->refreshSubject($this->snapshot(subjectId: 'thing-2'));
    }

    public function test_a_dismissed_survey_that_never_opened_can_be_taken_back(): void
    {
        $survey = $this->endSurvey();
        $survey->dismiss($this->now);

        $survey->reconsider();

        $this->assertNull($survey->dismissedAt());
        $this->assertTrue($survey->isAwaitingSubjectEnd());
    }

    public function test_a_cancelled_subject_withdraws_a_survey_waiting_for_review(): void
    {
        $survey = $this->endSurvey(subjectEndedAt: $this->now);
        $survey->scheduleSending($this->now->modify('+1 hour'));
        $survey->submitForReview('user-1', $this->now);

        $survey->withdrawWithSubject($this->now);

        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertEquals($this->now, $survey->dismissedAt());
        $this->assertNull($survey->scheduledSendAt());
    }

    public function test_an_opened_survey_carries_on_when_its_subject_is_cancelled(): void
    {
        $survey = $this->endSurvey();
        $survey->open($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->withdrawWithSubject($this->now);
    }

    /**
     * @param  list<string>  $organizerIds
     */
    private function snapshot(string $subjectId = 'thing-1', string $title = 'Partida de ejemplo', array $organizerIds = ['user-1']): SubjectSnapshot
    {
        return new SubjectSnapshot(
            sourceType: 'example-thing',
            subjectId: $subjectId,
            title: $title,
            startsAt: new DateTimeImmutable('2030-01-05T16:00:00+00:00'),
            endsAt: new DateTimeImmutable('2030-01-05T20:00:00+00:00'),
            organizerIds: $organizerIds,
            attributes: [],
            url: null,
        );
    }

    private function endSurvey(?string $endKey = 'example-thing:thing-1', ?DateTimeImmutable $subjectEndedAt = null): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Valoración de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: $this->snapshot(),
            durationDays: 7,
            subjectEndKey: $endKey,
            subjectEndedAt: $subjectEndedAt,
        );
    }
}
