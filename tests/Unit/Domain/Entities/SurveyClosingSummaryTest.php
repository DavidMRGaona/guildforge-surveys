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
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

/**
 * A survey may ask for a summary of its results by mail when it closes.
 */
final class SurveyClosingSummaryTest extends TestCase
{
    public function test_a_survey_sends_no_summary_unless_asked(): void
    {
        $this->assertFalse($this->survey()->sendsClosingSummary());
    }

    public function test_a_draft_or_open_survey_can_change_its_summary(): void
    {
        $survey = $this->survey();

        $survey->chooseClosingSummary(true);
        $this->assertTrue($survey->sendsClosingSummary());

        $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        $survey->chooseClosingSummary(false);
        $this->assertFalse($survey->sendsClosingSummary());
    }

    public function test_a_closed_survey_cannot_change_its_summary(): void
    {
        $survey = $this->survey();
        $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        $survey->close(new DateTimeImmutable('2030-01-02T10:00:00+00:00'));

        $this->expectException(InvalidSurveyStateException::class);

        $survey->chooseClosingSummary(true);
    }

    public function test_choosing_the_same_value_on_a_closed_survey_changes_nothing(): void
    {
        $survey = $this->survey(closingSummary: true);
        $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        $survey->close(new DateTimeImmutable('2030-01-02T10:00:00+00:00'));

        $survey->chooseClosingSummary(true);

        $this->assertTrue($survey->sendsClosingSummary());
    }

    private function survey(bool $closingSummary = false): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Horario del local',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
            closingSummary: $closingSummary,
        );
    }
}
