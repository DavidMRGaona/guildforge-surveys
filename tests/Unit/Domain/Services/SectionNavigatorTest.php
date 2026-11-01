<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Services\SectionNavigator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cases live in tests/fixtures/navigation-cases.json, which the response page's
 * TypeScript navigator is tested against too, so both walk a survey the same way.
 */
final class SectionNavigatorTest extends TestCase
{
    /**
     * @param  array<mixed>  $definition
     * @param  array<mixed>  $answers
     * @param  list<string>  $reached
     */
    #[DataProvider('cases')]
    public function test_walks_the_sections_the_answers_lead_to(array $definition, array $answers, array $reached): void
    {
        $survey = SurveyDefinition::fromArray($definition);

        $this->assertSame($reached, (new SectionNavigator)->reachedSectionIds($survey, Submission::fromInput($survey, $answers)));
    }

    public function test_lists_the_questions_of_the_reached_sections(): void
    {
        $fixture = self::fixture();
        $survey = SurveyDefinition::fromArray($fixture['definitions']['campaign']);

        $reached = (new SectionNavigator)->reachedQuestionIds($survey, Submission::fromInput($survey, ['q-played' => 'o-part']));

        $this->assertSame(['q-played', 'q-why', 'q-comments'], $reached);
    }

    /**
     * @return iterable<string, array{array<mixed>, array<mixed>, list<string>}>
     */
    public static function cases(): iterable
    {
        $fixture = self::fixture();
        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [$fixture['definitions'][$case['definition']], $case['answers'], $case['reached']];
        }
    }

    /**
     * @return array{definitions: array<string, array<mixed>>, cases: list<array{name: string, definition: string, answers: array<mixed>, reached: list<string>}>}
     */
    private static function fixture(): array
    {
        $json = file_get_contents(__DIR__.'/../../../fixtures/navigation-cases.json');
        self::assertIsString($json);

        /** @var array{definitions: array<string, array<mixed>>, cases: list<array{name: string, definition: string, answers: array<mixed>, reached: list<string>}>} */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
