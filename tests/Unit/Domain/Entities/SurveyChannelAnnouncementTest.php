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
 * A general survey may ask to be announced on the channels when it opens.
 */
final class SurveyChannelAnnouncementTest extends TestCase
{
    public function test_a_survey_is_not_announced_unless_asked(): void
    {
        $this->assertFalse($this->survey()->announceInChannels());
    }

    public function test_a_draft_general_survey_can_ask_for_an_announcement(): void
    {
        $survey = $this->survey();

        $survey->chooseChannelAnnouncement(true);
        $this->assertTrue($survey->announceInChannels());

        $survey->chooseChannelAnnouncement(false);
        $this->assertFalse($survey->announceInChannels());
    }

    public function test_a_subject_survey_cannot_ask_for_an_announcement(): void
    {
        $survey = $this->survey(subject: new SubjectSnapshot(
            sourceType: 'example-thing',
            subjectId: 'thing-1',
            title: 'Partida de ejemplo',
            startsAt: null,
            endsAt: null,
            organizerIds: [],
            attributes: [],
            url: null,
        ));

        $this->expectException(InvalidSurveyStateException::class);

        $survey->chooseChannelAnnouncement(true);
    }

    public function test_an_opened_survey_cannot_change_its_announcement(): void
    {
        $survey = $this->survey();
        $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));

        $this->expectException(InvalidSurveyStateException::class);

        $survey->chooseChannelAnnouncement(true);
    }

    private function survey(?SubjectSnapshot $subject = null): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Horario del local',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: $subject instanceof SubjectSnapshot ? AudienceType::Subject : AudienceType::AllUsers,
            subject: $subject,
        );
    }
}
