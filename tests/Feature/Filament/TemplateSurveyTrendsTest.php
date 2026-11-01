<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Application\DTOs\Trends\QuestionTrendDTO;
use Modules\Surveys\Application\DTOs\Trends\TemplateTrendsDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\Services\TrendsService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\CreateSurvey;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\BuildsTrendSurveys;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * General surveys made from a template in the panel trend together with the template.
 */
final class TemplateSurveyTrendsTest extends ModuleTestCase
{
    use BuildsTrendSurveys;
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyRepositoryInterface $surveys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->actingAsPanelAdmin();
        config(['modules.settings.surveys.min_responses' => 3]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->template = $this->saveTemplate($this->definition());
    }

    public function test_choices_and_grid_rows_of_general_surveys_from_a_template_trend_by_option(): void
    {
        $answers = [['Historia', 'Bien'], ['Historia', 'Bien'], ['Combate', 'Mal']];
        $this->answerAndClose($this->createFromTemplate('Encuesta de enero'), '2027-01-15', $answers);
        $this->answerAndClose($this->createFromTemplate('Encuesta de febrero'), '2027-02-15', $answers);

        $trends = $this->app->make(TrendsService::class)->forPanel($this->template, TrendFilters::fromInput([]));

        $this->assertSame(2, $trends->surveys);
        $this->assertEqualsWithDelta(['o-1' => 4 / 6, 'o-2' => 2 / 6, Submission::OTHER => 0.0], $this->shares($trends, 'best_part'), 0.0001);
        $this->assertEqualsWithDelta(['c-1' => 2 / 6, 'c-2' => 4 / 6], $this->shares($trends, 'aspects.r-1'), 0.0001);
    }

    private function createFromTemplate(string $title): SurveyId
    {
        $before = SurveyModel::query()->pluck('id')->all();

        Livewire::test(CreateSurvey::class)
            ->fillForm(['title' => $title, 'audience_type' => 'all_users', 'closes_at' => '2030-01-10 20:00:00'])
            ->set('data.template_id', $this->template->value)
            ->call('create')
            ->assertHasNoFormErrors();

        return SurveyId::fromString(SurveyModel::query()->whereNotIn('id', $before)->sole()->id);
    }

    /**
     * Opens the survey on the given day, stores verified responses picked by label, as a person
     * sees them, and closes it a week later.
     *
     * @param  list<array{string, string}>  $responses  best part, then the column for the first grid row
     */
    private function answerAndClose(SurveyId $id, string $opensOn, array $responses): void
    {
        $survey = $this->surveys->findOrFail($id);
        $opens = new DateTimeImmutable("{$opensOn}T10:00:00+00:00");
        $survey->open($opens);
        $this->surveys->save($survey);

        $best = $this->asked($survey, 'best_part');
        $grid = $this->asked($survey, 'aspects');
        $store = $this->app->make(ResponseStoreInterface::class);
        foreach ($responses as [$option, $column]) {
            $store->record($id, null, hash('sha256', (string) Str::uuid()), $opens, null, null, [
                new AnswerValue($best->id, optionId: $this->optionId($best->options, $option)),
                new AnswerValue($grid->id, optionId: $this->optionId($grid->grid->columns ?? [], $column), rowId: $this->optionId($grid->grid->rows ?? [], 'Ambiente')),
            ]);
        }

        $survey = $this->surveys->findOrFail($id);
        $survey->close($opens->modify('+7 days'));
        $this->surveys->save($survey);
    }

    private function asked(Survey $survey, string $key): Question
    {
        foreach ($survey->definition()->questions() as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }

        $this->fail("The survey does not ask {$key}.");
    }

    /**
     * @param  list<Option>  $options
     */
    private function optionId(array $options, string $label): string
    {
        foreach ($options as $option) {
            if ($option->label === $label) {
                return $option->id;
            }
        }

        $this->fail("No option {$label}.");
    }

    /**
     * @return array<string, float>
     */
    private function shares(TemplateTrendsDTO $trends, string $key): array
    {
        foreach ($trends->questions as $question) {
            if ($question->key === $key) {
                return $this->firstQuarter($question);
            }
        }

        $this->fail("No trend for {$key}.");
    }

    /**
     * @return array<string, float>
     */
    private function firstQuarter(QuestionTrendDTO $question): array
    {
        foreach ($question->series[0]->points ?? [] as $point) {
            if ($point->period === '2027-Q1') {
                return $point->shares;
            }
        }

        $this->fail("No point for 2027-Q1 in {$question->key}.");
    }
}
