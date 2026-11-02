<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Modules\Surveys\Application\DTOs\IncomingFile;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\DTOs\StoredAnswerFile;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Console\Commands\PruneAnswerFilesCommand;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\InvalidSubmissionException;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Export\SurveyExportTable;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyAnswerFileModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyAnswerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleFiles;
use Modules\Surveys\Tests\Unit\Domain\Services\FileQuestionDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class FileAnswersTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SubmissionService $submissions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->submissions = $this->app->make(SubmissionService::class);
        $this->createExamplePeople();
    }

    public function test_a_photo_is_stored_privately_without_its_exif_and_with_its_name_on_a_named_survey(): void
    {
        $id = $this->open(anonymous: false);

        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);

        $file = SurveyAnswerFileModel::query()->sole();
        $this->assertSame('ejercito.jpg', $file->original_name);
        $this->assertSame('image/jpeg', $file->content_type);
        $this->assertStringStartsWith("surveys/{$id->value}/", $file->path);
        $this->assertStringEndsWith('.jpg', $file->path);
        $this->assertStringNotContainsString('ejercito', $file->path);
        $stored = (string) Storage::disk('local')->get($file->path);
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString(ExampleFiles::EXIF_SECRET, $stored);
        $this->assertSame($file->id, SurveyAnswerModel::query()->where('question_id', 'q-photo')->sole()->text_value);
    }

    public function test_an_anonymous_survey_forgets_the_file_name(): void
    {
        $id = $this->open(anonymous: true);

        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);

        $this->assertNull(SurveyAnswerFileModel::query()->sole()->original_name);
    }

    public function test_a_pdf_is_kept_as_sent(): void
    {
        $id = $this->open(anonymous: false);

        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::pdf())]);

        $file = SurveyAnswerFileModel::query()->sole();
        $this->assertSame('application/pdf', $file->content_type);
        $this->assertStringEndsWith('.pdf', $file->path);
        $this->assertSame((string) file_get_contents((string) ExampleFiles::pdf()->getRealPath()), Storage::disk('local')->get($file->path));
    }

    public function test_the_contents_decide_the_kind_and_the_question_its_size(): void
    {
        $id = $this->open(anonymous: false);

        $this->assertErrors(['q-photo' => 'file_type'], fn () => $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::fakeImage())]));
        $this->assertErrors(['q-photo' => 'file_too_large'], fn () => $this->send($id, 1, [ExampleFiles::incoming('q-photo', ExampleFiles::photo(), size: 5 * 1024 * 1024 + 1)]));

        config(['modules.settings.surveys.max_file_mb' => 2]);
        $this->assertErrors(['q-photo' => 'file_too_large'], fn () => $this->send($id, 2, [ExampleFiles::incoming('q-photo', ExampleFiles::photo(), size: 2 * 1024 * 1024 + 1)]));

        $this->assertSame(0, SurveyAnswerFileModel::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_text_sent_for_a_file_question_is_ignored(): void
    {
        $id = $this->open(anonymous: false);

        $this->assertErrors(['q-photo' => 'required'], fn () => $this->submissions->submit($id, $this->account(0), ['q-photo' => '5f0c1b9e-3d2a-4c7e-9b1f-2a6d8e4c0b17']));
    }

    public function test_a_response_that_fails_leaves_no_file_behind(): void
    {
        $id = $this->open(anonymous: false);
        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);

        try {
            $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);
            $this->fail('The same person answers once.');
        } catch (AlreadyAnsweredException) {
        }

        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1, SurveyAnswerFileModel::query()->count());
    }

    public function test_deleting_the_survey_deletes_its_files(): void
    {
        $id = $this->open(anonymous: false);
        $other = $this->open(anonymous: false);
        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);
        $this->send($other, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::pdf())]);

        SurveyModel::query()->whereKey($id->value)->sole()->delete();

        $this->assertSame(0, SurveyAnswerFileModel::query()->where('survey_id', $id->value)->count());
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith("surveys/{$other->value}/", $files[0]);
    }

    public function test_files_no_answer_points_at_are_pruned_once_a_day_old(): void
    {
        $id = $this->open(anonymous: false);
        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);
        $disk = Storage::disk('local');
        $disk->put("surveys/{$id->value}/old-orphan.jpg", 'x');
        $disk->put("surveys/{$id->value}/new-orphan.jpg", 'x');
        touch($disk->path("surveys/{$id->value}/old-orphan.jpg"), now()->subDays(2)->getTimestamp());
        touch($disk->path(SurveyAnswerFileModel::query()->sole()->path), now()->subDays(2)->getTimestamp());

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PruneAnswerFilesCommand::class));
        $this->artisanCommand('surveys:prune-files')->assertSuccessful();

        $this->assertFalse($disk->exists("surveys/{$id->value}/old-orphan.jpg"));
        $this->assertTrue($disk->exists("surveys/{$id->value}/new-orphan.jpg"));
        $this->assertTrue($disk->exists(SurveyAnswerFileModel::query()->sole()->path));
    }

    public function test_the_organizer_gets_the_files_and_respondents_never_see_the_question(): void
    {
        $id = $this->open(anonymous: false);
        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);
        $results = $this->app->make(ResultsService::class);

        $question = $results->forPanel($id)->questions[0];
        $this->assertSame('q-photo', $question->questionId);
        $this->assertSame(['ejercito.jpg'], array_map(static fn (StoredAnswerFile $file): ?string => $file->originalName, $question->files));
        $this->assertNotContains('q-photo', array_map(static fn ($question): string => $question->questionId, $results->forRespondents($id)->questions));
    }

    public function test_the_export_links_each_file_without_the_file_itself(): void
    {
        $id = $this->open(anonymous: false);
        $this->send($id, 0, [ExampleFiles::incoming('q-photo', ExampleFiles::photo())]);

        $table = $this->app->make(SurveyExportTable::class)->build($id);
        $row = iterator_to_array($table->rows, false)[0];

        $link = (string) $row[2];
        $this->assertStringContainsString('/encuestas/archivos/'.SurveyAnswerFileModel::query()->sole()->id, $link);
        $this->assertStringContainsString('signature=', $link);
    }

    /**
     * @param  list<IncomingFile>  $files
     */
    private function send(SurveyId $id, int $person, array $files): void
    {
        $this->submissions->submit($id, $this->account($person), ['q-name' => 'Ana'], $files);
    }

    private function account(int $person): Respondent
    {
        return Respondent::account($this->people[$person]->id);
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function assertErrors(array $errors, callable $submit): void
    {
        try {
            $submit();
            $this->fail('The submission must be refused.');
        } catch (InvalidSubmissionException $exception) {
            $this->assertSame($errors, $exception->errors);
        }
    }

    private function open(bool $anonymous): SurveyId
    {
        $data = FileQuestionDefinitionTest::data();
        if ($anonymous) {
            // An anonymous survey takes no PDF
            $data['sections'][0]['questions'][0]['file']['accept'] = ['image'];
        }

        return $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray($data), $anonymous, ResultsSettings::defaultsFor(SurveyKind::Form))[0];
    }
}
