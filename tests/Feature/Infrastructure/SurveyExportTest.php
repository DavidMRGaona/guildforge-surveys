<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Infrastructure;

use Livewire\Livewire;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\ResultsNotAvailableException;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurveyResults;
use Modules\Surveys\Infrastructure\Export\CsvSurveyExport;
use Modules\Surveys\Infrastructure\Export\ExportTable;
use Modules\Surveys\Infrastructure\Export\SurveyExportTable;
use Modules\Surveys\Infrastructure\Export\XlsxSurveyExport;
use Modules\Surveys\Tests\Feature\Application\AvailabilityResultsTest;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyExportTest extends ModuleTestCase
{
    use AnswersExampleSurveys;
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createExamplePeople();
    }

    public function test_a_named_survey_exports_one_row_per_response_with_the_person(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, [
            'q-single' => ['options' => [Submission::OTHER], 'other' => 'Otra cosa'],
            'q-multiple' => ['o-1', 'o-2'],
            'q-grid' => ['r-1' => 'c-2', 'r-2' => 'c-1'],
            'q-scale' => 4,
            'q-rank' => ['k-3', 'k-1', 'k-2'],
        ]);
        $this->answer($id, 1);

        $table = $this->app->make(SurveyExportTable::class)->build($id);
        $rows = $this->rows($table);

        $this->assertSame(
            [__('surveys::messages.export.date'), __('surveys::messages.export.person'), 'Pregunta q-short', 'Pregunta q-paragraph', 'Pregunta q-single', 'Pregunta q-multiple', 'Pregunta q-dropdown', 'Pregunta q-scale', 'Pregunta q-stars', 'Pregunta q-date', 'Pregunta q-time', 'Pregunta q-grid: Ambiente', 'Pregunta q-grid: Ritmo', 'Pregunta q-rank: Rol', 'Pregunta q-rank: Wargames', 'Pregunta q-rank: Tablero'],
            $table->headers,
        );
        $this->assertCount(2, $rows);
        $first = $this->rowOf($rows, 'Persona A');
        $this->assertSame(__('surveys::messages.export.other', ['text' => 'Otra cosa']), $first[4]);
        $this->assertSame('Uno; Dos', $first[5]);
        $this->assertSame(4, $first[7]);
        $this->assertSame('2030-01-01', $first[9]);
        $this->assertSame('Bien', $first[11]);
        $this->assertSame('Mal', $first[12]);
        // A ranking gives each option its place
        $this->assertSame([2, 3, 1], array_slice($first, 13, 3));
        $this->assertStringStartsWith('encuesta-de-ejemplo', $table->fileName);
    }

    public function test_an_availability_survey_has_a_column_per_slot_with_each_answer(): void
    {
        ExampleThingSource::reset();
        [$id] = $this->openExample(SurveyKind::Availability, SurveyDefinition::fromArray(AvailabilityResultsTest::definition()), false, ResultsSettings::defaultsFor(SurveyKind::Availability));
        $this->answer($id, 0, ['q-1' => ['d-1' => 'yes', 'd-2' => 'if_needed']]);

        $table = $this->app->make(SurveyExportTable::class)->build($id);

        $this->assertSame([
            __('surveys::messages.export.date'),
            __('surveys::messages.export.person'),
            '¿Qué día os viene bien?: sábado, 5 de diciembre de 2026',
            '¿Qué día os viene bien?: viernes, 11 de diciembre de 2026, de 17:00 a 21:00',
            '¿Qué día os viene bien?: sábado, 12 de diciembre de 2026, a las 11:00',
        ], $table->headers);
        $this->assertSame(['Sí', 'Si hace falta', 'No'], array_slice($this->rowOf($this->rows($table), 'Persona A'), 2));
    }

    public function test_a_question_someone_did_not_reach_says_so(): void
    {
        $definition = SurveyDefinitionTest::branchingDefinition();
        $definition['sections'][1]['questions'][0]['required'] = false;
        [$id] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray($definition), false, ResultsSettings::defaultsFor(SurveyKind::Form));
        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($id, Respondent::account($this->people[0]->id), ['q-played' => 'o-no', 'q-comments' => 'Bien']);
        $submissions->submit($id, Respondent::account($this->people[1]->id), ['q-played' => 'o-yes', 'q-comments' => 'Bien']);

        $rows = $this->rows($this->app->make(SurveyExportTable::class)->build($id));

        // The finale is the fourth column, after date, person and the first question
        $this->assertSame(__('surveys::messages.export.not_reached'), $this->rowOf($rows, 'Persona A')[3]);
        $this->assertNull($this->rowOf($rows, 'Persona B')[3]);
    }

    public function test_a_withheld_jumping_question_leaves_no_trace_of_the_way_taken(): void
    {
        config(['modules.settings.surveys.min_responses' => 2]);
        $definition = SurveyDefinitionTest::branchingDefinition();
        $definition['sections'][0]['questions'][0]['required'] = false;
        [$id] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray($definition), true, new ResultsSettings(liveResults: true));
        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($id, Respondent::account($this->people[0]->id), ['q-played' => 'o-no', 'q-comments' => 'Bien']);
        foreach ([1, 2] as $person) {
            $submissions->submit($id, Respondent::account($this->people[$person]->id), ['q-finale' => 'Épico', 'q-comments' => 'Bien']);
        }

        $rows = $this->rows($this->app->make(SurveyExportTable::class)->build($id));

        // "Not reached" would tell, row by row, the answer the minimum withholds
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertNotContains(__('surveys::messages.export.not_reached'), $row);
        }
    }

    public function test_an_anonymous_survey_needs_its_results_to_be_visible_and_has_no_person_or_date(): void
    {
        [$id] = $this->openForm(anonymous: true);
        foreach ([0, 1, 2] as $person) {
            $this->answer($id, $person);
        }

        try {
            $this->app->make(SurveyExportTable::class)->build($id);
            $this->fail('An open anonymous survey cannot be exported.');
        } catch (ResultsNotAvailableException) {
        }

        $this->app->make(SurveyLifecycleService::class)->close($id);
        $table = $this->app->make(SurveyExportTable::class)->build($id);

        $this->assertNotContains(__('surveys::messages.export.person'), $table->headers);
        $this->assertNotContains(__('surveys::messages.export.date'), $table->headers);
        $this->assertSame('Pregunta q-short', $table->headers[0]);
        $this->assertCount(3, $this->rows($table));
    }

    public function test_open_link_responses_do_not_reach_the_minimum_of_an_export(): void
    {
        [$id, $opened] = $this->openForm(anonymous: true, publicLink: true);
        $this->answer($id, 0);
        $this->answer($id, 1);
        foreach (range(1, 3) as $ignored) {
            $this->answerPublicly($id, $opened);
        }
        $this->app->make(SurveyLifecycleService::class)->close($id);

        $this->expectException(ResultsNotAvailableException::class);

        $this->app->make(SurveyExportTable::class)->build($id);
    }

    public function test_the_csv_neutralises_formulas_and_keeps_accents(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-short' => '=HYPERLINK("https://example.test")', 'q-paragraph' => 'Partida estupenda, sí']);

        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);
        (new CsvSurveyExport)->write($this->app->make(SurveyExportTable::class)->build($id), $output);
        rewind($output);
        $csv = (string) stream_get_contents($output);

        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString(';', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Partida estupenda, sí', $csv);
    }

    public function test_the_excel_file_opens_and_holds_the_same_table(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-short' => '=1+1']);
        $path = tempnam(sys_get_temp_dir(), 'survey').'.xlsx';

        (new XlsxSurveyExport)->writeToFile($this->app->make(SurveyExportTable::class)->build($id), $path);

        $reader = new Reader;
        $reader->open($path);
        $read = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $read[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        $this->assertSame(__('surveys::messages.export.date'), $read[0][0]);
        $this->assertSame("'=1+1", $read[1][2]);
    }

    public function test_the_results_page_offers_the_downloads_only_while_the_results_are_visible(): void
    {
        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->actingAsPanelAdmin();
        [$named] = $this->openForm(anonymous: false);
        [$anonymous] = $this->openForm(anonymous: true);

        $this->answer($named, 0);

        Livewire::test(ViewSurveyResults::class, ['record' => $named->value])
            ->assertActionVisible('export_csv')
            ->assertActionVisible('export_xlsx')
            ->callAction('export_csv')
            ->assertHasNoActionErrors()
            ->callAction('export_xlsx')
            ->assertHasNoActionErrors();
        Livewire::test(ViewSurveyResults::class, ['record' => $anonymous->value])
            ->assertActionHidden('export_csv')
            ->assertActionHidden('export_xlsx');
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function rows(ExportTable $table): array
    {
        return iterator_to_array($table->rows, false);
    }

    /**
     * @param  list<list<string|int|null>>  $rows
     * @return list<string|int|null>
     */
    private function rowOf(array $rows, string $person): array
    {
        foreach ($rows as $row) {
            if (($row[1] ?? null) === $person) {
                return $row;
            }
        }

        $this->fail("No row for {$person}.");
    }
}
