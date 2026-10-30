<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Entities;

use App\Application\Surveys\DTOs\SurveySubjectAttributeDTO;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use DateTimeImmutable;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

final class SurveyTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-11-10T18:00:00+00:00');
    }

    public function test_goes_from_draft_to_open_to_closed(): void
    {
        $survey = $this->survey(closesAt: $this->now->modify('+7 days'));

        $survey->open($this->now);
        $this->assertSame(SurveyStatus::Open, $survey->status());
        $this->assertEquals($this->now, $survey->opensAt());

        $survey->close($this->now->modify('+1 day'));
        $this->assertSame(SurveyStatus::Closed, $survey->status());
        $this->assertEquals($this->now->modify('+1 day'), $survey->closedAt());
    }

    public function test_a_closed_survey_never_reopens(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);
        $survey->close($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->open($this->now);
    }

    public function test_only_an_open_survey_closes(): void
    {
        $this->expectException(InvalidSurveyStateException::class);

        $this->survey()->close($this->now);
    }

    public function test_cannot_open_with_a_closing_date_in_the_past(): void
    {
        $this->expectException(InvalidSurveyStateException::class);

        $this->survey(closesAt: $this->now->modify('-1 minute'))->open($this->now);
    }

    public function test_stops_accepting_responses_at_the_closing_date_even_before_it_is_closed(): void
    {
        $survey = $this->survey(closesAt: $this->now->modify('+1 hour'));
        $this->assertFalse($survey->isAcceptingResponses($this->now));

        $survey->open($this->now);

        $this->assertTrue($survey->isAcceptingResponses($this->now->modify('+59 minutes')));
        $this->assertFalse($survey->isAcceptingResponses($this->now->modify('+1 hour')));
        $this->assertSame(SurveyStatus::Open, $survey->status());
    }

    public function test_an_anonymous_survey_never_becomes_named(): void
    {
        $survey = $this->survey(anonymous: true);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->changeAnonymity(false, hasResponses: false);
    }

    public function test_a_named_survey_becomes_anonymous_only_without_responses(): void
    {
        $survey = $this->survey(anonymous: false);
        $survey->changeAnonymity(true, hasResponses: false);
        $this->assertTrue($survey->isAnonymous());

        $this->expectException(InvalidSurveyStateException::class);

        $this->survey(anonymous: false)->changeAnonymity(true, hasResponses: true);
    }

    public function test_with_responses_only_help_texts_change(): void
    {
        $data = SurveyDefinitionTest::everyTypeDefinition();
        $data['sections'][0]['questions'][0]['help'] = 'Como te llamen en la mesa';
        $survey = $this->survey();

        $survey->replaceDefinition(SurveyDefinition::fromArray($data), hasResponses: true, validator: new SurveyDefinitionValidator);
        $this->assertSame('Como te llamen en la mesa', $survey->definition()->questions()[0]->help);

        $data['sections'][0]['questions'][0]['title'] = 'Otra pregunta';
        $this->expectException(InvalidSurveyDefinitionException::class);

        $survey->replaceDefinition(SurveyDefinition::fromArray($data), hasResponses: true, validator: new SurveyDefinitionValidator);
    }

    public function test_a_replaced_definition_must_be_valid_for_its_kind(): void
    {
        $this->expectException(InvalidSurveyDefinitionException::class);

        $this->survey()->replaceDefinition(new SurveyDefinition([]), hasResponses: false, validator: new SurveyDefinitionValidator);
    }

    public function test_details_change_only_without_responses(): void
    {
        $survey = $this->survey();
        $survey->editDetails('Nuevo título', 'Nueva descripción', hasResponses: false);
        $this->assertSame('Nuevo título', $survey->title());

        $survey->editDetails('Nuevo título', 'Nueva descripción', hasResponses: true);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->editDetails('Otro título', 'Nueva descripción', hasResponses: true);
    }

    public function test_the_audience_is_fixed_once_open(): void
    {
        $survey = $this->survey();
        $survey->changeAudience(AudienceType::Roles, ['socios-de-ejemplo'], publicLinkEnabled: true);
        $this->assertSame(['socios-de-ejemplo'], $survey->audienceRoles());
        $this->assertTrue($survey->isPublicLinkEnabled());

        $survey->changeAudience(AudienceType::AllUsers, ['ignorado'], publicLinkEnabled: true);
        $this->assertSame([], $survey->audienceRoles());

        $survey->open($this->now);
        $survey->changeAudience(AudienceType::AllUsers, [], publicLinkEnabled: true);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->changeAudience(AudienceType::AssociationMembers, [], publicLinkEnabled: true);
    }

    public function test_an_open_survey_can_be_rescheduled_but_not_into_the_past(): void
    {
        $survey = $this->survey(closesAt: $this->now->modify('+1 day'));
        $survey->open($this->now);

        $survey->reschedule($this->now->modify('+3 days'), reminderHoursBefore: 24, now: $this->now);
        $this->assertEquals($this->now->modify('+3 days'), $survey->closesAt());
        $this->assertSame(24, $survey->reminderHoursBefore());

        $this->expectException(InvalidSurveyStateException::class);

        $survey->reschedule($this->now->modify('-1 minute'), reminderHoursBefore: null, now: $this->now);
    }

    public function test_a_closed_survey_cannot_be_rescheduled(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);
        $survey->close($this->now);

        $this->expectException(InvalidSurveyStateException::class);

        $survey->reschedule($this->now->modify('+1 day'), reminderHoursBefore: null, now: $this->now);
    }

    public function test_results_settings_can_change_at_any_time(): void
    {
        $survey = $this->survey();
        $survey->open($this->now);

        $survey->changeResults(new ResultsSettings(liveResults: true));

        $this->assertTrue($survey->results()->liveResults);
    }

    public function test_subject_snapshot_round_trips_from_the_core_dto(): void
    {
        $dto = new SurveySubjectDTO(
            sourceType: 'example-thing',
            subjectId: 'thing-1',
            title: 'Partida de ejemplo',
            startsAt: new DateTimeImmutable('2026-11-07T16:00:00+00:00'),
            endsAt: new DateTimeImmutable('2026-11-07T20:00:00+00:00'),
            organizerIds: ['user-1'],
            attributes: [new SurveySubjectAttributeDTO('game_system', 'Sistema', 'Ejemplo RPG')],
            url: 'https://example.test/cosas/1',
        );

        $snapshot = SubjectSnapshotFactory::fromDto($dto);

        $this->assertEquals($snapshot, SubjectSnapshot::fromArray($snapshot->toArray()));
        $this->assertSame('example-thing', $snapshot->sourceType);
        $this->assertSame(['user-1'], $snapshot->organizerIds);
        $this->assertSame('Ejemplo RPG', $snapshot->toArray()['attributes'][0]['value']);
    }

    public function test_results_settings_default_by_kind(): void
    {
        $this->assertTrue(ResultsSettings::defaultsFor(SurveyKind::Poll)->visibleToRespondents);
        $this->assertFalse(ResultsSettings::defaultsFor(SurveyKind::Form)->visibleToRespondents);
        $this->assertFalse(ResultsSettings::defaultsFor(SurveyKind::Form)->liveResults);

        $settings = new ResultsSettings(liveResults: true, visibleToRespondents: false, chartTypes: ['q-1' => 'pie'], hiddenQuestionIds: ['q-2']);
        $this->assertEquals($settings, ResultsSettings::fromArray($settings->toArray()));
    }

    private function survey(bool $anonymous = true, ?DateTimeImmutable $closesAt = null): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: $anonymous,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
            closesAt: $closesAt,
        );
    }
}
