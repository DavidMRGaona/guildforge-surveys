<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyAnswerFileModel;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleFiles;
use Modules\Surveys\Tests\Unit\Domain\Services\FileQuestionDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class RespondFilesTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createExamplePeople();
    }

    public function test_the_file_sent_with_the_answers_is_stored(): void
    {
        $id = $this->open();

        $this->actingAs($this->people[0])
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-photo' => ExampleFiles::photo(), 'q-name' => 'Ana']])
            ->assertRedirect(route('surveys.respond', $id->value))
            ->assertSessionHasNoErrors();

        $this->assertSame('ejercito.jpg', SurveyAnswerFileModel::query()->sole()->original_name);
    }

    public function test_a_wrong_file_comes_back_on_its_question(): void
    {
        $id = $this->open();

        $this->actingAs($this->people[0])
            ->from(route('surveys.respond', $id->value))
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-photo' => ExampleFiles::fakeImage()]])
            ->assertSessionHasErrors(['answers.q-photo' => __('surveys::messages.submission_errors.file_type')]);

        $this->actingAs($this->people[0])
            ->from(route('surveys.respond', $id->value))
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-photo' => UploadedFile::fake()->image('enorme.jpg')->size(6 * 1024)]])
            ->assertSessionHasErrors(['answers.q-photo' => __('surveys::messages.submission_errors.file_too_large')]);

        $this->assertSame(0, SurveyAnswerFileModel::query()->count());
    }

    private function open(): SurveyId
    {
        return $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(FileQuestionDefinitionTest::data()), false, ResultsSettings::defaultsFor(SurveyKind::Form))[0];
    }
}
