<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

/**
 * Which survey of a subject goes out on its end can change while the subject has not ended: a
 * prepared one steps down to an ordinary draft, and a draft of the subject's own steps up.
 */
final class SurveyEndKeyTest extends TestCase
{
    private const string KEY = 'example-thing:thing-1';

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2030-01-04T10:00:00+00:00');
    }

    public function test_a_prepared_survey_steps_down_to_a_draft_of_the_subject(): void
    {
        $survey = $this->survey(self::KEY);
        $survey->chooseEndSending(true, 12);

        $survey->releaseEndKey();

        $this->assertNull($survey->subjectEndKey());
        $this->assertFalse($survey->isAwaitingSubjectEnd());
        $this->assertFalse($survey->sendsOnEnd());
        $this->assertSame(0, $survey->sendDelayHours());
        $this->assertSame('thing-1', $survey->subject()?->subjectId);
    }

    public function test_a_survey_whose_subject_ended_keeps_its_end_key(): void
    {
        $survey = $this->survey(self::KEY, subjectEndedAt: $this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->releaseEndKey();
    }

    public function test_an_opened_survey_keeps_its_end_key(): void
    {
        $survey = $this->survey(self::KEY);
        $survey->open($this->now, 3);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->releaseEndKey();
    }

    public function test_a_draft_of_the_subject_becomes_its_end_survey(): void
    {
        $survey = $this->survey(null);

        $survey->becomeEndSurvey(self::KEY);

        $this->assertSame(self::KEY, $survey->subjectEndKey());
        $this->assertTrue($survey->isAwaitingSubjectEnd());
    }

    public function test_the_key_must_be_the_subjects_own(): void
    {
        $survey = $this->survey(null);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->becomeEndSurvey('example-thing:thing-2');
    }

    public function test_a_survey_that_already_is_an_end_survey_cannot_become_one_again(): void
    {
        $survey = $this->survey(self::KEY);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->becomeEndSurvey(self::KEY);
    }

    public function test_a_general_survey_cannot_become_an_end_survey(): void
    {
        $survey = $this->survey(null, subject: false);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->becomeEndSurvey(self::KEY);
    }

    public function test_an_opened_draft_cannot_become_an_end_survey(): void
    {
        $survey = $this->survey(null);
        $survey->open($this->now, 3);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->becomeEndSurvey(self::KEY);
    }

    public function test_a_dismissed_draft_cannot_become_an_end_survey(): void
    {
        $survey = $this->survey(null);
        $survey->dismiss($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->becomeEndSurvey(self::KEY);
    }

    public function test_a_poll_cannot_become_an_end_survey(): void
    {
        $survey = $this->survey(null, kind: SurveyKind::Poll);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->becomeEndSurvey(self::KEY);
    }

    private function survey(?string $endKey, ?DateTimeImmutable $subjectEndedAt = null, bool $subject = true, SurveyKind $kind = SurveyKind::Form): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: $kind,
            title: 'Valoración de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor($kind),
            audienceType: $subject ? AudienceType::Subject : AudienceType::AllUsers,
            subject: $subject ? new SubjectSnapshot(
                sourceType: 'example-thing',
                subjectId: 'thing-1',
                title: 'Partida de ejemplo',
                startsAt: new DateTimeImmutable('2030-01-05T16:00:00+00:00'),
                endsAt: new DateTimeImmutable('2030-01-05T20:00:00+00:00'),
                organizerIds: ['user-1'],
                attributes: [],
                url: null,
            ) : null,
            durationDays: 7,
            subjectEndKey: $endKey,
            subjectEndedAt: $subjectEndedAt,
        );
    }
}
