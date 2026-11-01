<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources;

use App\Filament\Resources\BaseResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;
use Modules\Surveys\Filament\Forms\DefinitionEditor;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages;
use Modules\Surveys\Filament\Support\SubjectTypeOptions;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

final class SurveyTemplateResource extends BaseResource
{
    protected static ?string $model = SurveyTemplateModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?int $navigationSort = 2;

    /**
     * The filter value for templates meant for anything: never a kind of subject, which is kebab-case.
     */
    public const string UNSPECIFIED_USE = '_unspecified';

    public static function getNavigationGroup(): string
    {
        return __('surveys::filament.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('surveys::filament.survey_template.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('surveys::filament.survey_template.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('surveys::filament.survey_template.sections.general'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('surveys::filament.survey_template.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('kind')
                            ->label(__('surveys::filament.fields.kind'))
                            ->options(self::kindOptions())
                            ->default(SurveyKind::Form->value)
                            ->required()
                            ->native(false)
                            ->disabledOn('edit'),
                        Textarea::make('description')
                            ->label(__('surveys::filament.survey_template.fields.description'))
                            ->rows(2)
                            ->columnSpanFull(),
                        CheckboxList::make('uses')
                            ->label(__('surveys::filament.survey_template.fields.uses'))
                            ->helperText(__('surveys::filament.survey_template.fields.uses_help'))
                            ->options(static fn (?SurveyTemplateModel $record): array => SubjectTypeOptions::options($record->uses ?? []))
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('surveys::filament.definition.title'))
                    ->description(__('surveys::filament.survey_template.definition_help'))
                    ->schema([DefinitionEditor::make(withKeys: true)]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('surveys::filament.survey_template.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label(__('surveys::filament.fields.kind'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::kindOptions()[$state] ?? $state),
                TextColumn::make('uses')
                    ->label(__('surveys::filament.survey_template.fields.uses'))
                    ->badge()
                    ->state(static fn (SurveyTemplateModel $record): array => array_map(SubjectTypeOptions::label(...), $record->uses ?? []))
                    ->placeholder(__('surveys::filament.survey_template.fields.uses_unspecified')),
                TextColumn::make('questions_count')
                    ->label(__('surveys::filament.fields.questions_count'))
                    ->state(static fn (SurveyTemplateModel $record): int => self::questionCount($record->definition)),
                TextColumn::make('updated_at')
                    ->label(__('surveys::filament.survey_template.fields.updated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('uses')
                    ->label(__('surveys::filament.survey_template.fields.uses'))
                    ->options(static fn (): array => [
                        ...SubjectTypeOptions::options(self::storedUses()),
                        self::UNSPECIFIED_USE => __('surveys::filament.survey_template.fields.uses_unspecified'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $use = $data['value'] ?? null;
                        if (! is_string($use) || $use === '') {
                            return $query;
                        }

                        return $use === self::UNSPECIFIED_USE ? $query->whereNull('uses') : $query->whereJsonContains('uses', $use);
                    }),
                TernaryFilter::make('recommended')
                    ->label(__('surveys::filament.survey_template.fields.origin'))
                    ->placeholder(__('surveys::filament.survey_template.origins.all'))
                    ->trueLabel(__('surveys::filament.survey_template.origins.recommended'))
                    ->falseLabel(__('surveys::filament.survey_template.origins.own'))
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->whereNotNull('catalog_key'),
                        false: static fn (Builder $query): Builder => $query->whereNull('catalog_key'),
                        blank: static fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('trends')
                    ->label(__('surveys::filament.trends.action'))
                    ->icon('heroicon-o-chart-bar')
                    ->color('gray')
                    ->visible(static fn (): bool => SurveyTrendsPage::canAccess())
                    ->url(static fn (SurveyTemplateModel $record): string => SurveyTrendsPage::getUrl(['template' => $record->getKey()])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveyTemplates::route('/'),
            'create' => Pages\CreateSurveyTemplate::route('/create'),
            'edit' => Pages\EditSurveyTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            SurveyKind::Form->value => __('surveys::filament.kinds.form'),
            SurveyKind::Poll->value => __('surveys::filament.kinds.poll'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function usesFrom(array $data): TemplateUses
    {
        $uses = is_array($data['uses'] ?? null) ? $data['uses'] : [];

        return new TemplateUses(array_values(array_filter($uses, is_string(...))));
    }

    /**
     * Every use some template keeps, so a kind of subject whose module is gone can still be filtered.
     *
     * @return list<string>
     */
    private static function storedUses(): array
    {
        $uses = [];
        foreach (SurveyTemplateModel::query()->whereNotNull('uses')->pluck('uses') as $values) {
            foreach (is_array($values) ? $values : [] as $use) {
                if (is_string($use)) {
                    $uses[$use] = true;
                }
            }
        }

        return array_keys($uses);
    }

    /**
     * @param  array<mixed>  $definition
     */
    public static function questionCount(array $definition): int
    {
        $count = 0;
        foreach (is_array($definition['sections'] ?? null) ? $definition['sections'] : [] as $section) {
            $count += is_array($section) && is_array($section['questions'] ?? null) ? count($section['questions']) : 0;
        }

        return $count;
    }
}
