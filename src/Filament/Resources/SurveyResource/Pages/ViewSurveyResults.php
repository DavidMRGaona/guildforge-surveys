<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Computed;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;

/**
 * A survey's results: the response rate, one chart or list per question, and the notice when
 * an anonymous survey cannot show them yet.
 */
final class ViewSurveyResults extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SurveyResource::class;

    protected string $view = 'surveys::filament.survey-results';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('viewResults', $this->getRecord()) === true, 403);
    }

    public function getTitle(): string
    {
        return __('surveys::filament.results.title', ['title' => $this->survey()->title()]);
    }

    public function getBreadcrumb(): string
    {
        return __('surveys::filament.results.breadcrumb');
    }

    #[Computed]
    public function results(): SurveyResultsDTO
    {
        return app(ResultsService::class)->forPanel($this->surveyId());
    }

    public function surveyId(): SurveyId
    {
        return SurveyId::fromString((string) $this->getRecord()->getKey());
    }

    /**
     * Titles of the questions hidden from the results.
     *
     * @return list<string>
     */
    public function hiddenQuestionTitles(): array
    {
        $survey = $this->survey();

        return array_values(array_map(
            static fn (Question $question): string => $question->title,
            array_filter($survey->definition()->questions(), static fn (Question $question): bool => $survey->results()->isHidden($question->id)),
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->configureResultsAction(),
        ];
    }

    private function configureResultsAction(): Action
    {
        return Action::make('configure_results')
            ->label(__('surveys::filament.results.configure'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->getRecord()) === true)
            ->fillForm(fn (): array => [
                'chart_types' => $this->survey()->results()->chartTypes,
                'hidden_question_ids' => $this->survey()->results()->hiddenQuestionIds,
            ])
            ->schema(fn (): array => $this->configureResultsSchema())
            ->action(function (array $data): void {
                $survey = $this->survey();
                $current = $survey->results();
                $questions = $survey->definition()->questions();
                $ids = array_map(static fn (Question $question): string => $question->id, $questions);

                $chartTypes = [];
                foreach ($questions as $question) {
                    $chosen = ChartType::tryFrom(is_array($data['chart_types'] ?? null) && is_string($data['chart_types'][$question->id] ?? null) ? $data['chart_types'][$question->id] : '');
                    if ($chosen instanceof ChartType && in_array($chosen, $question->type->chartTypes(), true)) {
                        $chartTypes[$question->id] = $chosen->value;
                    }
                }

                $hidden = array_values(array_filter(
                    is_array($data['hidden_question_ids'] ?? null) ? $data['hidden_question_ids'] : [],
                    static fn (mixed $id): bool => is_string($id) && in_array($id, $ids, true),
                ));

                $survey->changeResults(new ResultsSettings($current->liveResults, $current->visibleToRespondents, $chartTypes, $hidden));
                app(SurveyRepositoryInterface::class)->save($survey);
                unset($this->results);

                Notification::make()->success()->title(__('surveys::filament.results.configure_done'))->send();
            });
    }

    /**
     * A chart choice for each question with more than one fitting chart, and the hidden questions.
     *
     * @return list<Select|CheckboxList>
     */
    private function configureResultsSchema(): array
    {
        $fields = [];
        $titles = [];

        foreach ($this->survey()->definition()->questions() as $question) {
            $titles[$question->id] = $question->title;
            $types = $question->type->chartTypes();

            if (count($types) > 1) {
                $options = [];
                foreach ($types as $type) {
                    $options[$type->value] = __('surveys::filament.results.chart_types.'.$type->value);
                }

                $fields[] = Select::make("chart_types.{$question->id}")
                    ->label(__('surveys::filament.results.chart_type_of', ['question' => $question->title]))
                    ->options($options)
                    ->placeholder(__('surveys::filament.results.chart_types.'.$question->type->defaultChartType()->value))
                    ->native(false);
            }
        }

        $fields[] = CheckboxList::make('hidden_question_ids')
            ->label(__('surveys::filament.results.hidden_question_ids'))
            ->helperText(__('surveys::filament.results.hidden_question_ids_help'))
            ->options($titles);

        return $fields;
    }

    private function survey(): Survey
    {
        return app(SurveyRepositoryInterface::class)->findOrFail($this->surveyId());
    }
}
