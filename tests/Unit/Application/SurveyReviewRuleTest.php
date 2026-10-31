<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Application;

use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\Services\SurveyReviewRule;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

final class SurveyReviewRuleTest extends TestCase
{
    public function test_with_review_off_nothing_waits(): void
    {
        $survey = $this->survey();
        $survey->markUnreviewedChanges('organizer-1');

        $this->assertFalse($this->rule(reviewRequired: false)->needsReview($survey));
    }

    public function test_with_review_on_only_unreviewed_changes_wait(): void
    {
        $untouched = $this->survey();
        $changed = $this->survey();
        $changed->markUnreviewedChanges('organizer-1');

        $rule = $this->rule(reviewRequired: true);

        $this->assertFalse($rule->needsReview($untouched));
        $this->assertTrue($rule->needsReview($changed));
    }

    private function rule(bool $reviewRequired): SurveyReviewRule
    {
        $settings = $this->createStub(SurveySettingsInterface::class);
        $settings->method('reviewRequired')->willReturn($reviewRequired);

        return new SurveyReviewRule($settings);
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
            audienceType: AudienceType::Subject,
        );
    }
}
