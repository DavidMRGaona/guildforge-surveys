<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Exceptions\SubjectCancelledException;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SurveyDuplicationService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyOrganizerModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Asking the same again: a survey copied as a draft in the panel, by an organizer on the public
 * site (to the same subject or another they manage, reviewed as content made outside the
 * panel), or kept as a template.
 */
final class SurveyDuplicationServiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private SurveyDuplicationService $service;

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
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO([UserModel::factory()->create()->id]);

        $this->service = $this->app->make(SurveyDuplicationService::class);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->now = new DateTimeImmutable('2030-01-05T20:30:00+00:00');
        $this->travelTo($this->now);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_panel_copies_a_general_survey_as_a_draft(): void
    {
        config()->set('modules.settings.surveys.default_close_days', 10);
        $original = $this->general();

        $copy = $this->service->duplicate($original->id(), $this->organizer->id);

        $stored = $this->surveys->findOrFail($copy->id());
        $this->assertFalse($stored->id()->equals($original->id()));
        $this->assertSame('Copia de Satisfacción del trimestre', $stored->title());
        $this->assertSame(SurveyStatus::Draft, $stored->status());
        $this->assertSame(AudienceType::AssociationMembers, $stored->audienceType());
        $this->assertSame($original->definition()->toArray(), $stored->definition()->toArray());
        $this->assertSame($this->organizer->id, $stored->createdBy());
        // The old date has passed: the copy closes as a new survey of the panel would
        $this->assertEquals(new DateTimeImmutable('2030-01-15T20:00:00+00:00'), $stored->closesAt());
    }

    public function test_a_copy_made_in_the_panel_is_not_held_for_review(): void
    {
        config()->set('modules.settings.surveys.review_required', true);

        $copy = $this->service->duplicate($this->ofSubject()->id(), $this->organizer->id);

        $this->assertFalse($copy->hasUnreviewedChanges());
    }

    public function test_the_panel_copies_a_subject_survey_to_its_subject_as_it_is_now(): void
    {
        $original = $this->ofSubject();
        ExampleThingSource::$title = 'Partida renombrada';

        $copy = $this->service->duplicate($original->id(), $this->organizer->id);

        $stored = $this->surveys->findOrFail($copy->id());
        $this->assertSame('thing-1', $stored->subject()?->subjectId);
        $this->assertSame('Partida renombrada', $stored->subject()->title);
        $this->assertSame('Copia de Valoración de ejemplo', $stored->title());
        $this->assertNotNull($original->templateId());
        $this->assertSame($original->templateId()->value, $stored->templateId()?->value);
        $this->assertSame(7, $stored->durationDays());
        $this->assertTrue(SurveyOrganizerModel::query()->where('survey_id', $copy->id()->value)->where('user_id', $this->organizer->id)->exists());
    }

    public function test_no_copy_for_a_cancelled_subject(): void
    {
        $original = $this->ofSubject();
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        $this->expectException(SubjectCancelledException::class);

        $this->service->duplicate($original->id(), $this->organizer->id);
    }

    public function test_no_copy_for_a_subject_that_is_gone(): void
    {
        $original = $this->ofSubject();
        // The module that offered the subject is gone
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->clear();

        $this->expectException(SubjectNotFoundException::class);

        $this->service->duplicate($original->id(), $this->organizer->id);
    }

    public function test_an_organizer_copies_a_survey_to_its_subject_for_review(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $original = $this->ofSubject();

        $copy = $this->service->duplicateForOrganizer($original->id(), $this->organizer->id);

        $this->assertSame('Copia de Valoración de ejemplo', $copy->title());
        $this->assertSame('thing-1', $copy->subject()?->subjectId);
        $this->assertTrue($copy->hasUnreviewedChanges());
        $this->assertSame(
            SendOutcome::SentForReview,
            $this->app->make(OrganizerSurveyService::class)->send($copy->id(), $this->organizer->id),
        );
    }

    public function test_an_organizer_moves_the_copy_to_another_subject_they_manage(): void
    {
        ExampleThingSource::$otherManagers = [$this->organizer->id];
        $original = $this->ofSubject();

        $copy = $this->service->duplicateForOrganizer($original->id(), $this->organizer->id, ExampleThingSource::TYPE, 'thing-3');

        $stored = $this->surveys->findOrFail($copy->id());
        $this->assertSame('thing-3', $stored->subject()?->subjectId);
        $this->assertSame('Otra partida de ejemplo', $stored->subject()->title);
        // Another subject tells the copies apart: the title stays
        $this->assertSame('Valoración de ejemplo', $stored->title());
        $this->assertNotNull($original->templateId());
        $this->assertSame($original->templateId()->value, $stored->templateId()?->value);
    }

    public function test_an_organizer_cannot_copy_to_a_subject_they_do_not_manage(): void
    {
        $original = $this->ofSubject();

        $this->expectException(AuthorizationException::class);

        $this->service->duplicateForOrganizer($original->id(), $this->organizer->id, ExampleThingSource::TYPE, 'thing-3');
    }

    public function test_an_organizer_cannot_copy_to_a_cancelled_subject(): void
    {
        ExampleThingSource::$otherManagers = [$this->organizer->id];
        ExampleThingSource::$otherStatus = SurveySubjectStatus::Cancelled;
        $original = $this->ofSubject();

        $this->expectException(SubjectCancelledException::class);

        $this->service->duplicateForOrganizer($original->id(), $this->organizer->id, ExampleThingSource::TYPE, 'thing-3');
    }

    public function test_an_organizer_cannot_copy_to_an_unknown_subject(): void
    {
        $original = $this->ofSubject();

        $this->expectException(SubjectNotFoundException::class);

        $this->service->duplicateForOrganizer($original->id(), $this->organizer->id, ExampleThingSource::TYPE, 'thing-404');
    }

    public function test_only_whoever_manages_a_survey_copies_it_on_the_public_site(): void
    {
        ExampleThingSource::$otherManagers = [$this->organizer->id];
        $original = $this->ofSubject();
        ExampleThingSource::$managers = [];

        $this->expectException(AuthorizationException::class);

        $this->service->duplicateForOrganizer($original->id(), $this->organizer->id, ExampleThingSource::TYPE, 'thing-3');
    }

    public function test_a_general_survey_is_not_copied_on_the_public_site(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->duplicateForOrganizer($this->general()->id(), $this->organizer->id);
    }

    public function test_a_subject_survey_is_kept_as_a_template_for_its_kind_of_subject(): void
    {
        $original = $this->ofSubject();

        $template = $this->service->saveAsTemplate($original->id(), 'Valoración de partidas', 'Para las mesas', $this->organizer->id);

        $stored = $this->app->make(SurveyTemplateRepositoryInterface::class)->findOrFail($template->id());
        $this->assertSame('Valoración de partidas', $stored->name());
        $this->assertSame('Para las mesas', $stored->description());
        $this->assertSame(SurveyKind::Form, $stored->kind());
        $this->assertSame($original->definition()->toArray(), $stored->definition()->toArray());
        $this->assertSame([ExampleThingSource::TYPE], $stored->uses()->values);
        $this->assertSame($this->organizer->id, $stored->createdBy());
    }

    public function test_a_general_survey_is_kept_as_a_template_for_general_surveys(): void
    {
        $template = $this->service->saveAsTemplate($this->general()->id(), 'Satisfacción', null, $this->organizer->id);

        $this->assertSame([TemplateUses::GENERAL], $template->uses()->values);
    }

    private function general(): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Satisfacción del trimestre',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AssociationMembers,
            closesAt: new DateTimeImmutable('2029-12-20T20:00:00+00:00'),
            createdBy: $this->organizer->id,
        );
        $this->surveys->save($survey);

        return $survey;
    }

    private function ofSubject(): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: null);
    }
}
