<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Support\SurveyFormData;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Services\SurveySettings;

final class ListSurveys extends ListRecords
{
    protected static string $resource = SurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->quickPollAction(),
            CreateAction::make(),
        ];
    }

    /**
     * One tab per status, in the order surveys go through them, then every survey.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = SurveyModel::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $tabs = [];
        foreach ([SurveyStatus::Open, SurveyStatus::PendingReview, SurveyStatus::Draft, SurveyStatus::Closed] as $status) {
            $tabs[$status->value] = Tab::make(__("surveys::filament.survey.tabs.{$status->value}"))
                ->badge((int) ($counts[$status->value] ?? 0))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', $status->value));
        }
        $tabs['all'] = Tab::make(__('surveys::filament.survey.tabs.all'))->badge((int) $counts->sum());

        return $tabs;
    }

    /**
     * Every survey, as before there were tabs, so a draft just saved is still in sight.
     */
    public function getDefaultActiveTab(): string
    {
        return 'all';
    }

    /**
     * A one-question poll, as in messaging apps: it is a form with a single choice question.
     */
    private function quickPollAction(): Action
    {
        return Action::make('quick_poll')
            ->label(__('surveys::filament.quick_poll.action'))
            ->icon('heroicon-o-chart-bar')
            ->authorize(static fn (): bool => SurveyResource::canCreate())
            ->modalHeading(__('surveys::filament.quick_poll.heading'))
            ->schema([
                TextInput::make('question')
                    ->label(__('surveys::filament.quick_poll.question'))
                    ->required()
                    ->maxLength(500),
                Repeater::make('options')
                    ->label(__('surveys::filament.definition.options'))
                    ->schema([TextInput::make('label')->hiddenLabel()->required()->maxLength(255)])
                    ->minItems(2)
                    ->defaultItems(2)
                    ->addActionLabel(__('surveys::filament.definition.add_option')),
                Toggle::make('multiple')
                    ->label(__('surveys::filament.quick_poll.multiple')),
                Toggle::make('anonymous')
                    ->label(__('surveys::filament.survey.fields.anonymous'))
                    ->live()
                    ->default(static fn (): bool => app(SurveySettings::class)->anonymousByDefault()),
                Toggle::make('live_results')
                    ->label(__('surveys::filament.survey.fields.live_results'))
                    ->helperText(static fn (Get $get): string => $get('anonymous') === true
                        ? __('surveys::filament.survey.fields.live_results_anonymous_help')
                        : __('surveys::filament.survey.fields.live_results_help'))
                    ->default(ResultsSettings::defaultsFor(SurveyKind::Poll)->liveResults),
                DateTimePicker::make('closes_at')
                    ->label(__('surveys::filament.survey.fields.closes_at'))
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->seconds(false)
                    ->default(static fn (): string => now()->addDays(app(SurveySettings::class)->defaultCloseDays())->startOfHour()->toDateTimeString()),
                SurveyResource::announceInChannelsToggle(),
                SurveyResource::closingSummaryToggle(),
            ])
            ->action(function (array $data): void {
                try {
                    $survey = $this->pollFrom($data);
                } catch (InvalidSurveyDefinitionException) {
                    Notification::make()->danger()->title(__('surveys::filament.quick_poll.invalid'))->send();

                    return;
                }

                app(SurveyRepositoryInterface::class)->save($survey);

                $this->redirect(SurveyResource::getUrl('edit', ['record' => SurveyModel::query()->findOrFail($survey->id()->value)]));
            });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSurveyDefinitionException
     */
    private function pollFrom(array $data): Survey
    {
        $labels = [];
        foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $option) {
            if (is_array($option) && is_string($option['label'] ?? null)) {
                $labels[] = $option['label'];
            }
        }
        $question = (string) $data['question'];

        $definition = new SurveyDefinition([new Section((string) Str::uuid(), null, null, [new Question(
            id: (string) Str::uuid(),
            key: null,
            type: ($data['multiple'] ?? false) === true ? QuestionType::MultipleChoice : QuestionType::SingleChoice,
            title: $question,
            help: null,
            required: true,
            options: array_map(static fn (string $label): Option => new Option((string) Str::uuid(), $label), $labels),
        )])]);
        (new SurveyDefinitionValidator)->assertValid($definition, SurveyKind::Poll);

        return new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Poll,
            title: Str::limit($question, 250),
            description: null,
            definition: $definition,
            anonymous: (bool) ($data['anonymous'] ?? true),
            results: new ResultsSettings(
                liveResults: (bool) ($data['live_results'] ?? ResultsSettings::defaultsFor(SurveyKind::Poll)->liveResults),
                visibleToRespondents: true,
            ),
            audienceType: AudienceType::AllUsers,
            closesAt: SurveyFormData::closesAt($data),
            createdBy: is_string(auth()->id()) ? auth()->id() : null,
            announceInChannels: (bool) ($data['announce_in_channels'] ?? false),
            closingSummary: (bool) ($data['closing_summary'] ?? false),
        );
    }
}
