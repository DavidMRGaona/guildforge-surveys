<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources;

use App\Filament\Resources\BaseResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Filament\Forms\DefinitionEditor;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

final class SurveyTemplateResource extends BaseResource
{
    protected static ?string $model = SurveyTemplateModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?int $navigationSort = 2;

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
                TextColumn::make('questions_count')
                    ->label(__('surveys::filament.fields.questions_count'))
                    ->state(static fn (SurveyTemplateModel $record): int => self::questionCount($record->definition)),
                TextColumn::make('updated_at')
                    ->label(__('surveys::filament.survey_template.fields.updated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name')
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
