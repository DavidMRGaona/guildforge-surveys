<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Integration\Persistence;

use DateTimeImmutable;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
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
