<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\FileKind;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Services\FileQuestionRules;
use Modules\Surveys\Domain\Services\SubmissionValidator;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\FileSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileQuestionDefinitionTest extends TestCase
{
    private const string FILE_ID = '5f0c1b9e-3d2a-4c7e-9b1f-2a6d8e4c0b17';

    public function test_a_file_question_survives_the_round_trip_and_is_valid(): void
    {
        $definition = SurveyDefinition::fromArray(self::data());

        $this->assertEquals(new FileSettings([FileKind::Image, FileKind::Pdf], 5), $definition->questions()[0]->file);
        $this->assertNull($definition->questions()[1]->file);
        $this->assertSame([], (new SurveyDefinitionValidator)->validate($definition, SurveyKind::Form));
        $this->assertSame(self::data(), $definition->toArray());
    }

    public function test_a_definition_stored_before_files_reads_without_them(): void
    {
        $data = self::data();
        unset($data['sections'][0]['questions'][1]['file']);

        $this->assertNull(SurveyDefinition::fromArray($data)->questions()[1]->file);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('brokenFiles')]
    public function test_reports_each_broken_file_rule(callable $break, string $path, string $messageKey): void
    {
        $violations = (new SurveyDefinitionValidator)->validate(SurveyDefinition::fromArray($break(self::data())), SurveyKind::Form);

        $this->assertContainsEquals(new DefinitionViolation($path, $messageKey), $violations);
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string, 2: string}>
     */
    public static function brokenFiles(): iterable
    {
        $q = static fn (int $index): string => "sections.0.questions.{$index}";
        $set = static function (array $d, int $index, string $field, mixed $value): array {
            $d['sections'][0]['questions'][$index][$field] = $value;

            return $d;
        };

        yield 'no settings' => [static fn (array $d): array => $set($d, 0, 'file', null), $q(0).'.file', 'file_required'];
        yield 'nothing accepted' => [static fn (array $d): array => $set($d, 0, 'file', ['accept' => [], 'max_mb' => 5]), $q(0).'.file.accept', 'invalid_file_accept'];
        yield 'too small' => [static fn (array $d): array => $set($d, 0, 'file', ['accept' => ['image'], 'max_mb' => 0]), $q(0).'.file.max_mb', 'invalid_file_size'];
        yield 'too big' => [static fn (array $d): array => $set($d, 0, 'file', ['accept' => ['image'], 'max_mb' => 11]), $q(0).'.file.max_mb', 'invalid_file_size'];
        yield 'settings on text' => [static fn (array $d): array => $set($d, 1, 'file', ['accept' => ['image'], 'max_mb' => 5]), $q(1).'.file', 'file_not_allowed'];
        yield 'a kind twice' => [static fn (array $d): array => $set($d, 0, 'file', ['accept' => ['image', 'image'], 'max_mb' => 5]), $q(0).'.file.accept', 'invalid_file_accept'];
    }

    public function test_an_unknown_kind_of_file_is_malformed(): void
    {
        $data = self::data();
        $data['sections'][0]['questions'][0]['file']['accept'] = ['video'];

        $this->expectException(InvalidSurveyDefinitionException::class);

        SurveyDefinition::fromArray($data);
    }

    public function test_an_anonymous_survey_takes_no_pdf_and_an_open_link_no_file(): void
    {
        $rules = new FileQuestionRules;
        $definition = SurveyDefinition::fromArray(self::data());

        $this->assertEquals(
            [new DefinitionViolation('sections.0.questions.0.file.accept', 'pdf_not_allowed_anonymous')],
            $rules->violations($definition, anonymous: true, publicLinkEnabled: false, publicLinkMode: PublicLinkMode::VerifiedEmail),
        );
        $this->assertEquals(
            [new DefinitionViolation('public_link_mode', 'file_needs_verified_link')],
            $rules->violations($definition, anonymous: false, publicLinkEnabled: true, publicLinkMode: PublicLinkMode::Open),
        );
        $this->assertSame([], $rules->violations($definition, anonymous: false, publicLinkEnabled: true, publicLinkMode: PublicLinkMode::VerifiedEmail));
        $this->assertSame([], $rules->violations($definition, anonymous: false, publicLinkEnabled: false, publicLinkMode: PublicLinkMode::Open));
    }

    public function test_the_survey_keeps_its_file_rules_whatever_changes(): void
    {
        $survey = $this->survey(anonymous: false);

        try {
            $survey->changeAnonymity(true, hasResponses: false);
            $this->fail('An anonymous survey takes no PDF.');
        } catch (InvalidSurveyDefinitionException $exception) {
            $this->assertSame('pdf_not_allowed_anonymous', $exception->violations[0]->messageKey);
            $this->assertFalse($survey->isAnonymous());
        }

        try {
            $survey->changeAudience(AudienceType::AllUsers, [], true, PublicLinkMode::Open);
            $this->fail('An open link takes no file.');
        } catch (InvalidSurveyDefinitionException $exception) {
            $this->assertSame('file_needs_verified_link', $exception->violations[0]->messageKey);
            $this->assertFalse($survey->isPublicLinkEnabled());
        }

        $this->expectException(InvalidSurveyDefinitionException::class);
        $this->survey(anonymous: true);
    }

    public function test_a_file_answer_is_the_id_of_the_stored_file(): void
    {
        $definition = SurveyDefinition::fromArray(self::data());
        $validator = new SubmissionValidator;

        $this->assertSame([], $validator->validate($definition, Submission::fromInput($definition, ['q-photo' => self::FILE_ID])));
        $this->assertSame(['q-photo' => 'invalid_file'], $validator->validate($definition, Submission::fromInput($definition, ['q-photo' => 'army.jpg'])));
        $this->assertSame(['q-photo' => 'required'], $validator->validate($definition, Submission::fromInput($definition, [])));
    }

    private function survey(bool $anonymous): Survey
    {
        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Concurso de pintura',
            description: null,
            definition: SurveyDefinition::fromArray(self::data()),
            anonymous: $anonymous,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
        );
    }

    /**
     * A required photo or PDF of up to 5 MB and an optional name.
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        $question = static fn (string $id, string $type, bool $required, ?array $file): array => [
            'id' => $id, 'key' => null, 'type' => $type, 'title' => "Pregunta {$id}", 'help' => null, 'required' => $required,
            'options' => [], 'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null,
            'next_by_option' => [], 'slots' => [], 'correct_option_ids' => [], 'points' => null, 'file' => $file,
        ];

        return [
            'sections' => [[
                'id' => 's-1',
                'title' => null,
                'description' => null,
                'next' => null,
                'questions' => [
                    $question('q-photo', 'file', true, ['accept' => ['image', 'pdf'], 'max_mb' => 5]),
                    $question('q-name', 'short_text', false, null),
                ],
            ]],
            'quiz' => null,
        ];
    }
}
