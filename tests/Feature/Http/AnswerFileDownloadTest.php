<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\DTOs\StoredAnswerFile;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Files\AnswerFileLinks;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentSurveyResultsQuery;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleFiles;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\Services\FileQuestionDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class AnswerFileDownloadTest extends ModuleTestCase
{
    use AnswersExampleSurveys;
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createExamplePeople();
    }

    public function test_someone_who_sees_the_results_downloads_it_through_a_signed_link(): void
    {
        $file = $this->sentFile(anonymous: false, upload: ExampleFiles::pdf('Lista del ejército.pdf'));

        $this->actingAsPanelAdmin();
        $response = $this->get(AnswerFileLinks::forPage($file, inline: true));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        // A PDF never opens in the browser, and keeps its name only on a named survey
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''Lista%20del%20ej%C3%A9rcito.pdf", (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('local')->get($file->path), $response->streamedContent());
    }

    public function test_an_image_may_open_in_the_browser(): void
    {
        $file = $this->sentFile(anonymous: false, upload: ExampleFiles::photo());

        $this->actingAsPanelAdmin();

        $this->assertStringStartsWith('inline;', (string) $this->get(AnswerFileLinks::forPage($file, inline: true))->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('attachment;', (string) $this->get(AnswerFileLinks::forPage($file))->headers->get('Content-Disposition'));
    }

    public function test_the_subject_organizer_downloads_it_and_nobody_else(): void
    {
        $file = $this->sentFile(anonymous: false, upload: ExampleFiles::photo());
        $url = AnswerFileLinks::forPage($file);
        ExampleThingSource::$managers = [$this->people[1]->id];

        $this->actingAs($this->people[1])->get($url)->assertOk();
        $this->actingAs($this->people[2])->get($url)->assertForbidden();
        $this->actingAs($this->people[1])->get(route('surveys.files.show', $file->id))->assertForbidden();
    }

    public function test_an_anonymous_survey_shows_no_file_before_its_results_or_below_the_minimum(): void
    {
        $file = $this->sentFile(anonymous: true, upload: ExampleFiles::photo());
        $this->actingAsPanelAdmin();

        // Open, without live results
        $this->get(AnswerFileLinks::forPage($file))->assertNotFound();

        // Closed, but one answer is below the minimum of three
        $this->app->make(SurveyLifecycleService::class)->close(SurveyId::fromString($file->surveyId));
        $this->get(AnswerFileLinks::forPage($file))->assertNotFound();

        config(['modules.settings.surveys.min_responses' => 1]);
        $this->get(AnswerFileLinks::forPage($file))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('archivo-', (string) $this->get(AnswerFileLinks::forPage($file))->headers->get('Content-Disposition'));
    }

    public function test_a_guest_never_gets_a_file(): void
    {
        $file = $this->sentFile(anonymous: false, upload: ExampleFiles::photo());

        $this->get(AnswerFileLinks::forPage($file))->assertRedirect();
        $this->assertNotInstanceOf(UserModel::class, auth()->user());
    }

    private function sentFile(bool $anonymous, UploadedFile $upload): StoredAnswerFile
    {
        $data = FileQuestionDefinitionTest::data();
        if ($anonymous) {
            $data['sections'][0]['questions'][0]['file']['accept'] = ['image'];
        }
        [$id] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray($data), $anonymous, ResultsSettings::defaultsFor(SurveyKind::Form));

        $this->app->make(SubmissionService::class)->submit($id, Respondent::account($this->people[0]->id), [], [ExampleFiles::incoming('q-photo', $upload)]);

        return $this->app->make(EloquentSurveyResultsQuery::class)->answerFiles($id, ['q-photo'])['q-photo'][0];
    }
}
