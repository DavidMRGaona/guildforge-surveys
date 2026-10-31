<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Integration\Persistence;

use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyOrganizerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class EloquentSurveyRepositoryTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyRepositoryInterface $surveys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
    }

    public function test_saves_and_finds_a_complete_survey(): void
    {
        $template = new SurveyTemplate(SurveyTemplateId::generate(), 'Plantilla', null, SurveyKind::Form, $this->definition());
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        $survey = $this->survey(
            status: SurveyStatus::Open,
            closesAt: new DateTimeImmutable('2026-11-17T18:00:00+00:00'),
            templateId: $template->id(),
            subject: $this->snapshot('thing-1'),
        );
        $this->surveys->save($survey);

        $found = $this->surveys->find($survey->id());

        $this->assertNotNull($found);
        $this->assertEquals($survey->definition(), $found->definition());
        $this->assertEquals($survey->subject(), $found->subject());
        $this->assertEquals($survey->results(), $found->results());
        $this->assertSame(AudienceType::Subject, $found->audienceType());
        $this->assertSame(SurveyStatus::Open, $found->status());
        $this->assertSame('2026-11-17T18:00:00+00:00', $found->closesAt()?->format(DATE_ATOM));
        $this->assertTrue($found->templateId()?->equals($template->id()));
        $this->assertSame(48, $found->reminderHoursBefore());
    }

    public function test_keeps_the_public_link_mode(): void
    {
        $survey = $this->survey();
        $survey->changeAudience(AudienceType::AllUsers, [], publicLinkEnabled: true, publicLinkMode: PublicLinkMode::Open);
        $this->surveys->save($survey);

        $this->assertSame(PublicLinkMode::Open, $this->surveys->find($survey->id())?->publicLinkMode());

        $survey->changeAudience(AudienceType::AllUsers, [], publicLinkEnabled: true, publicLinkMode: PublicLinkMode::VerifiedEmail);
        $this->surveys->save($survey);

        $this->assertSame(PublicLinkMode::VerifiedEmail, $this->surveys->find($survey->id())?->publicLinkMode());
    }

    public function test_finds_open_surveys_whose_closing_date_has_come(): void
    {
        $now = new DateTimeImmutable('2026-11-17T18:00:00+00:00');
        $due = $this->survey(status: SurveyStatus::Open, closesAt: $now->modify('-1 minute'));
        $exactlyNow = $this->survey(status: SurveyStatus::Open, closesAt: $now);
        $later = $this->survey(status: SurveyStatus::Open, closesAt: $now->modify('+1 minute'));
        $draft = $this->survey(status: SurveyStatus::Draft, closesAt: $now->modify('-1 day'));
        $withoutDate = $this->survey(status: SurveyStatus::Open);
        foreach ([$due, $exactlyNow, $later, $draft, $withoutDate] as $survey) {
            $this->surveys->save($survey);
        }

        $ids = array_map(static fn (Survey $survey): string => $survey->id()->value, $this->surveys->findDueToClose($now));

        $this->assertEqualsCanonicalizing([$due->id()->value, $exactlyNow->id()->value], $ids);
    }

    public function test_finds_every_survey_about_a_subject(): void
    {
        $first = $this->survey(subject: $this->snapshot('thing-1'));
        $second = $this->survey(subject: $this->snapshot('thing-1'));
        $other = $this->survey(subject: $this->snapshot('thing-2'));
        foreach ([$first, $second, $other] as $survey) {
            $this->surveys->save($survey);
        }

        $ids = array_map(static fn (Survey $survey): string => $survey->id()->value, $this->surveys->findBySubject('example-thing', 'thing-1'));

        $this->assertEqualsCanonicalizing([$first->id()->value, $second->id()->value], $ids);
    }

    public function test_knows_whether_a_survey_has_responses_and_deletes_it_with_them(): void
    {
        $survey = $this->survey(status: SurveyStatus::Open);
        $this->surveys->save($survey);
        $this->assertSame(['socios-de-ejemplo'], $this->surveys->findOrFail($survey->id())->audienceRoles());
        $this->assertFalse($this->surveys->hasResponses($survey->id()));

        SurveyResponseModel::query()->create(['survey_id' => $survey->id()->value, 'submitted_on' => '2026-11-10']);
        $this->assertTrue($this->surveys->hasResponses($survey->id()));

        $this->surveys->delete($survey->id());

        $this->assertNull($this->surveys->find($survey->id()));
        $this->assertSame(0, SurveyResponseModel::query()->count());
    }

    public function test_keeps_the_review_and_scheduling_fields(): void
    {
        $at = new DateTimeImmutable('2026-11-10T18:00:00+00:00');
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: $this->definition(),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: $this->snapshot('thing-1'),
            status: SurveyStatus::PendingReview,
            durationDays: 5,
            unreviewedChanges: true,
            lastChangedBy: '00000000-0000-4000-8000-000000000001',
            rejectionReason: 'Motivo de ejemplo',
            submittedBy: '00000000-0000-4000-8000-000000000001',
            submittedForReviewAt: $at,
            reviewedBy: '00000000-0000-4000-8000-000000000002',
            reviewedAt: $at->modify('+1 hour'),
            scheduledSendAt: $at->modify('+12 hours'),
            dismissedAt: $at->modify('+2 hours'),
            subjectEndKey: 'example-thing:thing-1',
        );
        $this->surveys->save($survey);

        $found = $this->surveys->findOrFail($survey->id());

        $this->assertSame(SurveyStatus::PendingReview, $found->status());
        $this->assertSame(5, $found->durationDays());
        $this->assertTrue($found->hasUnreviewedChanges());
        $this->assertSame('00000000-0000-4000-8000-000000000001', $found->lastChangedBy());
        $this->assertSame('Motivo de ejemplo', $found->rejectionReason());
        $this->assertSame('00000000-0000-4000-8000-000000000001', $found->submittedBy());
        $this->assertEquals($at, $found->submittedForReviewAt());
        $this->assertSame('00000000-0000-4000-8000-000000000002', $found->reviewedBy());
        $this->assertEquals($at->modify('+1 hour'), $found->reviewedAt());
        $this->assertEquals($at->modify('+12 hours'), $found->scheduledSendAt());
        $this->assertEquals($at->modify('+2 hours'), $found->dismissedAt());
        $this->assertSame('example-thing:thing-1', $found->subjectEndKey());
    }

    public function test_a_subject_end_key_is_unique(): void
    {
        $this->surveys->save($this->subjectSurvey('example-thing:thing-1'));
        $this->assertTrue($this->surveys->existsForSubjectEnd('example-thing:thing-1'));
        $this->assertFalse($this->surveys->existsForSubjectEnd('example-thing:thing-2'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->surveys->save($this->subjectSurvey('example-thing:thing-1'));
    }

    public function test_surveys_without_an_end_key_never_collide(): void
    {
        $this->surveys->save($this->subjectSurvey(null));
        $this->surveys->save($this->subjectSurvey(null));

        $this->assertSame(2, SurveyModel::query()->count());
    }

    public function test_organizers_come_from_the_snapshot_once(): void
    {
        $survey = $this->subjectSurvey(null);
        $this->surveys->save($survey);
        $this->surveys->save($survey);

        $this->assertSame(
            ['00000000-0000-4000-8000-000000000001'],
            SurveyOrganizerModel::query()->where('survey_id', $survey->id()->value)->pluck('user_id')->all(),
        );
    }

    public function test_finds_drafts_whose_sending_time_has_come(): void
    {
        $now = new DateTimeImmutable('2026-11-10T18:00:00+00:00');
        $due = $this->subjectSurvey(null);
        $due->scheduleSending($now->modify('-1 minute'));
        $later = $this->subjectSurvey(null);
        $later->scheduleSending($now->modify('+1 hour'));
        $dismissed = $this->subjectSurvey(null);
        $dismissed->scheduleSending($now->modify('-1 hour'));
        $dismissed->dismiss($now);
        foreach ([$due, $later, $dismissed] as $survey) {
            $this->surveys->save($survey);
        }

        $this->assertEquals([$due->id()], $this->surveys->dueForSending($now));
    }

    public function test_counts_surveys_pending_review(): void
    {
        $pending = $this->subjectSurvey(null);
        $pending->submitForReview('00000000-0000-4000-8000-000000000001', new DateTimeImmutable);
        $this->surveys->save($pending);
        $this->surveys->save($this->subjectSurvey(null));

        $this->assertSame(1, $this->surveys->pendingReviewCount());
    }

    public function test_finds_a_survey_for_update(): void
    {
        $survey = $this->subjectSurvey(null);
        $this->surveys->save($survey);

        $found = DB::transaction(fn (): Survey => $this->surveys->findForUpdate($survey->id()));

        $this->assertEquals($survey->id(), $found->id());
    }

    private function subjectSurvey(?string $endKey): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: $this->definition(),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: $this->snapshot('thing-1'),
            subjectEndKey: $endKey,
        );
    }

    private function survey(
        SurveyStatus $status = SurveyStatus::Draft,
        ?DateTimeImmutable $closesAt = null,
        ?SurveyTemplateId $templateId = null,
        ?SubjectSnapshot $subject = null,
    ): Survey {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: 'Descripción',
            definition: $this->definition(),
            anonymous: true,
            results: new ResultsSettings(liveResults: true, chartTypes: ['q-single' => 'pie'], hiddenQuestionIds: ['q-paragraph']),
            audienceType: $subject instanceof SubjectSnapshot ? AudienceType::Subject : AudienceType::Roles,
            audienceRoles: $subject instanceof SubjectSnapshot ? [] : ['socios-de-ejemplo'],
            templateId: $templateId,
            subject: $subject,
            status: $status,
            opensAt: $status === SurveyStatus::Draft ? null : new DateTimeImmutable('2026-11-10T18:00:00+00:00'),
            closesAt: $closesAt,
            reminderHoursBefore: 48,
        );
    }

    private function snapshot(string $subjectId): SubjectSnapshot
    {
        return new SubjectSnapshot(
            sourceType: 'example-thing',
            subjectId: $subjectId,
            title: 'Partida de ejemplo',
            startsAt: new DateTimeImmutable('2026-11-07T16:00:00+00:00'),
            endsAt: null,
            organizerIds: ['00000000-0000-4000-8000-000000000001'],
            attributes: [['key' => 'game_system', 'label' => 'Sistema', 'value' => 'Ejemplo RPG']],
            url: null,
        );
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }
}
