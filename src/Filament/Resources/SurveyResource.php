<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources;

use App\Filament\Resources\BaseResource;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Actions\SurveyActions;
use Modules\Surveys\Filament\Forms\DefinitionEditor;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Infrastructure\Services\SurveySettings;

final class SurveyResource extends BaseResource
{
    protected static ?string $model = SurveyModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string
    {
        return __('surveys::filament.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('surveys::filament.survey.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('surveys::filament.survey.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        $hasResponses = static fn (?SurveyModel $record): bool => $record instanceof SurveyModel && $record->responses()->exists();
        $notDraft = static fn (?SurveyModel $record): bool => $record instanceof SurveyModel && $record->status !== SurveyStatus::Draft->value;

        return $schema
            ->components([
                Section::make(__('surveys::filament.survey.sections.general'))
                    ->schema([
                        Select::make('template_id')
                            ->label(__('surveys::filament.survey.fields.template'))
                            ->helperText(__('surveys::filament.survey.fields.template_help'))
                            ->options(static fn (): array => SurveyTemplateModel::query()->where('kind', SurveyKind::Form->value)->orderBy('name')->pluck('name', 'id')->all())
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(self::copyTemplate(...))
                            ->visibleOn('create')
                            ->columnSpanFull(),
                        TextInput::make('title')
                            ->label(__('surveys::filament.survey.fields.title'))
                            ->required()
                            ->maxLength(255)
                            ->disabled($hasResponses),
                        Textarea::make('description')
                            ->label(__('surveys::filament.survey.fields.description'))
                            ->rows(2)
                            ->disabled($hasResponses),
                    ]),
                Section::make(__('surveys::filament.definition.title'))
                    ->description(static fn (?SurveyModel $record): ?string => $hasResponses($record) ? (string) __('surveys::filament.survey.locked_by_responses') : null)
                    ->schema([DefinitionEditor::make(contentLocked: $hasResponses)]),
                Section::make(__('surveys::filament.survey.sections.audience'))
                    ->schema([
                        Toggle::make('anonymous')
                            ->label(__('surveys::filament.survey.fields.anonymous'))
                            ->helperText(__('surveys::filament.survey.fields.anonymous_help'))
                            ->default(static fn (): bool => app(SurveySettings::class)->anonymousByDefault())
                            ->live()
                            ->disabled(static fn (?SurveyModel $record): bool => $record instanceof SurveyModel && ($record->anonymous || $hasResponses($record))),
                        Select::make('audience_type')
                            ->label(__('surveys::filament.survey.fields.audience'))
                            ->options(self::audienceOptions())
                            ->default(AudienceType::AllUsers->value)
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabled($notDraft),
                        Select::make('audience_roles')
                            ->label(__('surveys::filament.survey.fields.audience_roles'))
                            ->options(static fn (): array => RoleModel::query()->orderBy('display_name')->pluck('display_name', 'name')->all())
                            ->multiple()
                            ->required()
                            ->visible(static fn (Get $get): bool => $get('audience_type') === AudienceType::Roles->value)
                            ->disabled($notDraft),
                        Toggle::make('public_link_enabled')
                            ->label(__('surveys::filament.survey.fields.public_link'))
                            ->helperText(__('surveys::filament.survey.fields.public_link_help'))
                            ->live()
                            ->disabled($notDraft),
                        Radio::make('public_link_mode')
                            ->label(__('surveys::filament.survey.fields.public_link_mode'))
                            ->options([
                                PublicLinkMode::VerifiedEmail->value => __('surveys::filament.survey.public_link_modes.verified_email'),
                                PublicLinkMode::Open->value => __('surveys::filament.survey.public_link_modes.open'),
                            ])
                            ->descriptions([
                                PublicLinkMode::VerifiedEmail->value => __('surveys::filament.survey.public_link_modes.verified_email_help'),
                                PublicLinkMode::Open->value => __('surveys::filament.survey.public_link_modes.open_help'),
                            ])
                            ->default(PublicLinkMode::VerifiedEmail->value)
                            ->required()
                            ->visible(static fn (Get $get): bool => (bool) $get('public_link_enabled'))
                            ->disabled($notDraft),
                    ])
                    ->columns(2),
                Section::make(__('surveys::filament.survey.sections.schedule'))
                    ->schema([
                        DateTimePicker::make('closes_at')
                            ->label(__('surveys::filament.survey.fields.closes_at'))
                            ->native(false)
                            ->displayFormat('d/m/Y H:i')
                            ->seconds(false)
                            ->default(static fn (): string => now()->addDays(app(SurveySettings::class)->defaultCloseDays())->startOfHour()->toDateTimeString()),
                        TextInput::make('reminder_hours_before')
                            ->label(__('surveys::filament.survey.fields.reminder'))
                            ->helperText(__('surveys::filament.survey.fields.reminder_help'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(168)
                            ->suffix(__('surveys::filament.survey.fields.hours')),
                    ])
                    ->columns(2),
                Section::make(__('surveys::filament.survey.sections.results'))
                    ->schema([
                        Toggle::make('live_results')
                            ->label(__('surveys::filament.survey.fields.live_results'))
                            ->helperText(static fn (Get $get): string => $get('anonymous') === true
                                ? __('surveys::filament.survey.fields.live_results_anonymous_help')
                                : __('surveys::filament.survey.fields.live_results_help')),
                        Toggle::make('visible_to_respondents')
                            ->label(__('surveys::filament.survey.fields.visible_to_respondents')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn ($query) => $query->withCount('responses'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('surveys::filament.survey.fields.title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label(__('surveys::filament.fields.kind'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => SurveyTemplateResource::kindOptions()[$state] ?? $state),
                TextColumn::make('status')
                    ->label(__('surveys::filament.survey.fields.status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        SurveyStatus::Open->value => 'success',
                        SurveyStatus::Closed->value => 'gray',
                        SurveyStatus::PendingReview->value => 'info',
                        default => 'warning',
                    })
                    ->formatStateUsing(static fn (string $state): string => self::statusOptions()[$state] ?? $state)
                    // Prepared by its organizers for the end of what it is about: it waits for it
                    ->description(static fn (SurveyModel $record): ?string => $record->subject_end_key !== null && $record->subject_ended_at === null && $record->opens_at === null
                        ? ($record->send_on_end
                            ? (string) __('surveys::filament.survey.awaiting_end_auto', ['hours' => $record->send_delay_hours])
                            : (string) __('surveys::filament.survey.awaiting_end_one_click'))
                        : null),
                IconColumn::make('anonymous')
                    ->label(__('surveys::filament.survey.fields.anonymous'))
                    ->boolean(),
                TextColumn::make('closes_at')
                    ->label(__('surveys::filament.survey.fields.closes_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('responses_count')
                    ->label(__('surveys::filament.survey.fields.responses'))
                    ->numeric(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('surveys::filament.survey.fields.status'))
                    ->options(self::statusOptions()),
                SelectFilter::make('kind')
                    ->label(__('surveys::filament.fields.kind'))
                    ->options(SurveyTemplateResource::kindOptions()),
            ])
            ->recordActions([
                // Moderators without surveys.manage read the survey they review here
                ViewAction::make()->visible(static fn (SurveyModel $record): bool => auth()->user()?->can('update', $record) !== true),
                EditAction::make(),
                SurveyActions::approve(),
                SurveyActions::reject(),
                SurveyActions::results(),
                SurveyActions::open(),
                SurveyActions::close(),
                SurveyActions::delete(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveys::route('/'),
            'create' => Pages\CreateSurvey::route('/create'),
            'edit' => Pages\EditSurvey::route('/{record}/edit'),
            'view' => Pages\ViewSurvey::route('/{record}'),
            'results' => Pages\ViewSurveyResults::route('/{record}/results'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return array_combine(
            array_map(static fn (SurveyStatus $status): string => $status->value, SurveyStatus::cases()),
            array_map(static fn (SurveyStatus $status): string => __("surveys::filament.statuses.{$status->value}"), SurveyStatus::cases()),
        );
    }

    /**
     * The audiences a survey made in the panel may have: a subject's audience comes from its source.
     *
     * @return array<string, string>
     */
    public static function audienceOptions(): array
    {
        $options = [];
        foreach ([AudienceType::AllUsers, AudienceType::AssociationMembers, AudienceType::Roles] as $type) {
            $options[$type->value] = __("surveys::filament.audiences.{$type->value}");
        }

        return $options;
    }

    /**
     * Starting from a template copies its questions with new ids and the same keys.
     */
    private static function copyTemplate(?string $state, Set $set): void
    {
        if ($state === null || $state === '') {
            return;
        }

        $template = app(SurveyTemplateRepositoryInterface::class)->find(SurveyTemplateId::fromString($state));
        if ($template === null) {
            return;
        }

        $set('sections', DefinitionFormState::fromDefinition(DefinitionFormState::withFreshIds($template->definition())));
    }
}
