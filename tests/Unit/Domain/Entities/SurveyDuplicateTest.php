<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

/**
 * Repeating a survey: a new draft with the same content and settings, and nothing of what
 * happened to the original (responses, audience, link, review).
 */
final class SurveyDuplicateTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2030-01-05T18:00:00+00:00');
    }

    public function test_the_copy_keeps_the_content_and_settings(): void
    {
        $original = $this->general(closesAt: $this->now->modify('+7 days'));
        $copyId = SurveyId::generate();

        $copy = $original->duplicate($copyId, 'Copia de la valoración', null, 'user-2', $this->now->modify('+14 days'));

        $this->assertTrue($copy->id()->equals($copyId));
        $this->assertSame(SurveyKind::Form, $copy->kind());
        $this->assertSame('Copia de la valoración', $copy->title());
        $this->assertSame('Cuéntanos qué tal', $copy->description());
        $this->assertSame($original->definition()->toArray(), $copy->definition()->toArray());
        $this->assertTrue($copy->isAnonymous());
        $this->assertEquals($original->results(), $copy->results());
        $this->assertSame(AudienceType::Roles, $copy->audienceType());
        $this->assertSame(['socio'], $copy->audienceRoles());
        $this->assertTrue($copy->isPublicLinkEnabled());
        $this->assertSame(PublicLinkMode::Open, $copy->publicLinkMode());
        $this->assertSame('3f0d9c52-4b8a-4d8e-9b1c-6a2f3e4d5c6b', $copy->templateId()?->value);
        $this->assertSame(24, $copy->reminderHoursBefore());
        $this->assertTrue($copy->announceInChannels());
        $this->assertSame('user-2', $copy->createdBy());
        $this->assertNull($copy->subject());
    }

    public function test_the_copy_starts_as_a_draft_with_nothing_of_the_original_run(): void
    {
        $original = $this->general(closesAt: $this->now->modify('+7 days'));
        $original->open($this->now, audienceSize: 40, publicToken: str_repeat('a', 64));
        $original->markUnreviewedChanges('user-1');
        $original->submitForReview('user-1', $this->now);
        $original->reject('moderator-1', 'Falta una pregunta', $this->now);

        $copy = $original->duplicate(SurveyId::generate(), 'Copia', null, 'user-2', $this->now->modify('+14 days'));

        $this->assertSame(SurveyStatus::Draft, $copy->status());
        $this->assertFalse($copy->wasOpened());
        $this->assertNull($copy->closedAt());
        $this->assertSame(0, $copy->audienceSize());
        $this->assertNull($copy->publicToken());
        $this->assertFalse($copy->hasUnreviewedChanges());
        $this->assertNull($copy->lastChangedBy());
        $this->assertNull($copy->rejectionReason());
        $this->assertNull($copy->submittedBy());
        $this->assertNull($copy->submittedForReviewAt());
        $this->assertNull($copy->reviewedBy());
        $this->assertNull($copy->reviewedAt());
        $this->assertNull($copy->scheduledSendAt());
        $this->assertNull($copy->dismissedAt());
    }

    public function test_an_end_survey_is_copied_as_an_ordinary_draft(): void
    {
        $original = $this->ofSubject(durationDays: 5);
        $original->becomeEndSurvey('example-thing:thing-1');
        $original->chooseEndSending(true, 12);
        $original->scheduleSending($this->now);

        $copy = $original->duplicate(SurveyId::generate(), 'Copia', $this->snapshot('thing-1'), 'user-2', null);

        $this->assertNull($copy->subjectEndKey());
        $this->assertNull($copy->subjectEndedAt());
        $this->assertFalse($copy->sendsOnEnd());
        $this->assertSame(0, $copy->sendDelayHours());
        $this->assertNull($copy->scheduledSendAt());
        $this->assertFalse($copy->isAwaitingSubjectEnd());
    }

    public function test_a_subject_survey_is_copied_to_the_subject_given(): void
    {
        $original = $this->ofSubject(durationDays: 5);

        $copy = $original->duplicate(SurveyId::generate(), 'Valoración', $this->snapshot('thing-3'), 'user-2', null);

        $this->assertSame(AudienceType::Subject, $copy->audienceType());
        $this->assertSame('thing-3', $copy->subject()?->subjectId);
        $this->assertSame(5, $copy->durationDays());
        $this->assertNull($copy->closesAt());
    }

    public function test_a_subject_survey_never_loses_its_subject(): void
    {
        $this->expectException(InvalidSurveyStateException::class);

        $this->ofSubject(durationDays: 5)->duplicate(SurveyId::generate(), 'Copia', null, 'user-2', null);
    }

    public function test_a_general_survey_never_gains_a_subject(): void
    {
        $this->expectException(InvalidSurveyStateException::class);

        $this->general(closesAt: null)->duplicate(SurveyId::generate(), 'Copia', $this->snapshot('thing-1'), 'user-2', null);
    }

    public function test_a_survey_closing_on_a_fixed_date_gets_the_date_given(): void
    {
        $closesAt = $this->now->modify('+14 days');

        $copy = $this->general(closesAt: $this->now->modify('-30 days'))->duplicate(SurveyId::generate(), 'Copia', null, 'user-2', $closesAt);

        $this->assertEquals($closesAt, $copy->closesAt());
        $this->assertNull($copy->durationDays());
    }

    public function test_a_survey_without_closing_date_stays_without_one(): void
    {
        $copy = $this->general(closesAt: null)->duplicate(SurveyId::generate(), 'Copia', null, 'user-2', $this->now->modify('+14 days'));

        $this->assertNull($copy->closesAt());
        $this->assertNull($copy->durationDays());
    }

    private function general(?DateTimeImmutable $closesAt): Survey
    {
        $definition = SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
        $questionId = $definition->questions()[0]->id;

        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Valoración de ejemplo',
            description: 'Cuéntanos qué tal',
            definition: $definition,
            anonymous: true,
            results: new ResultsSettings(true, true, [$questionId => 'pie'], [$questionId]),
            audienceType: AudienceType::Roles,
            audienceRoles: ['socio'],
            publicLinkEnabled: true,
            templateId: SurveyTemplateId::fromString('3f0d9c52-4b8a-4d8e-9b1c-6a2f3e4d5c6b'),
            closesAt: $closesAt,
            reminderHoursBefore: 24,
            createdBy: 'user-1',
            publicLinkMode: PublicLinkMode::Open,
            announceInChannels: true,
        );
    }

    private function ofSubject(int $durationDays): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Valoración',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: false,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: $this->snapshot('thing-1'),
            createdBy: 'user-1',
            durationDays: $durationDays,
        );
    }

    private function snapshot(string $subjectId): SubjectSnapshot
    {
        return new SubjectSnapshot(
            sourceType: 'example-thing',
            subjectId: $subjectId,
            title: 'Partida de ejemplo',
            startsAt: new DateTimeImmutable('2030-01-05T16:00:00+00:00'),
            endsAt: new DateTimeImmutable('2030-01-05T20:00:00+00:00'),
            organizerIds: ['user-1'],
            attributes: [],
            url: null,
        );
    }
}
