<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Pages;

use App\Application\Modules\Services\ModuleContextServiceInterface;
use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Application\Surveys\Contracts\SurveySubjectAttributeValuesInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Concerns\SafeModuleNavigation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Infrastructure\Services\SurveySettings;

/**
 * The module's settings, open to surveys.manage (admins hold every permission): results,
 * defaults, review of what changes outside the panel and, per kind of subject offered by a
 * module, how its surveys go out when a subject ends (with a template per game system when its
 * source lists its game systems).
 *
 * @property Schema $form
 */
final class SurveySettingsPage extends Page implements HasForms
{
    use ChecksPermissions;
    use InteractsWithForms;
    use SafeModuleNavigation;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'surveys-settings';

    protected string $view = 'filament.pages.simple-settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationGroup(): string
    {
        return __('surveys::filament.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('surveys::messages.settings.title');
    }

    public function getTitle(): string
    {
        return __('surveys::messages.settings.title');
    }

    public static function canAccess(): bool
    {
        return self::userCan('surveys:surveys.manage');
    }

    public function mount(
        ModuleSettingsStoreInterface $settingsStore,
        ModuleContextServiceInterface $moduleContext,
        SurveySettingsInterface $settings,
        SurveySubjectSourceRegistryInterface $sources,
    ): void {
        // The shipped defaults with the saved values on top, so keys added by a release show
        // their defaults instead of loading empty and being saved off
        $data = $settingsStore->effective('surveys', $moduleContext->modulePath('surveys'));

        // A kind of subject nobody configured yet shows what it would get
        $data['subject_types'] = [];
        foreach (array_keys($sources->sources()) as $type) {
            $current = $settings->forSubjectType($type);
            $data['subject_types'][$type] = [
                'template_id' => $current->templateId,
                'auto_send' => $current->autoSend,
                'send_delay_hours' => $current->sendDelayHours,
                'close_days' => $current->closeDays,
                'anonymous' => $current->anonymous,
                'game_system_templates' => array_map(
                    static fn (string $gameSystem, string $templateId): array => ['game_system' => $gameSystem, 'template_id' => $templateId],
                    array_keys($current->gameSystemTemplates),
                    array_values($current->gameSystemTemplates),
                ),
            ];
        }

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->sections())
            ->statePath('data');
    }

    public function save(ModuleManagerServiceInterface $moduleManager, ModuleSettingsStoreInterface $settingsStore): void
    {
        // Livewire methods can be called without the page's button
        abort_unless(self::canAccess(), 403);

        $state = $this->form->getState();

        // The form only shows the kinds of subject of enabled modules, and the templates per game
        // system only while there is something to show: whatever is not shown keeps its value
        $stored = $settingsStore->stored('surveys')['subject_types'] ?? [];
        $types = is_array($stored) ? $stored : [];
        $shown = is_array($state['subject_types'] ?? null) ? $state['subject_types'] : [];
        foreach ($shown as $type => $entry) {
            $entry = is_array($entry) ? $entry : [];
            if (is_array($entry['game_system_templates'] ?? null)) {
                $entry['game_system_templates'] = array_values($entry['game_system_templates']);
            }

            $types[$type] = array_merge(is_array($types[$type] ?? null) ? $types[$type] : [], $entry);
        }

        $state['subject_types'] = $types;

        $moduleManager->updateSettings(new ModuleName('surveys'), $state);

        Notification::make()
            ->title(__('surveys::messages.settings.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('common.save'))
                ->submit('save'),
        ];
    }

    /**
     * @return array<Component>
     */
    private function sections(): array
    {
        return [
            Section::make(__('surveys::messages.settings.results'))
                ->schema([
                    TextInput::make('min_responses')
                        ->label(__('surveys::messages.settings.min_responses'))
                        ->helperText(__('surveys::messages.settings.min_responses_help'))
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->default(SurveySettings::DEFAULT_MIN_RESPONSES),
                ]),
            Section::make(__('surveys::messages.settings.defaults'))
                ->schema([
                    TextInput::make('default_close_days')
                        ->label(__('surveys::messages.settings.default_close_days'))
                        ->helperText(__('surveys::messages.settings.default_close_days_help'))
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->suffix(__('surveys::messages.settings.days'))
                        ->default(SurveySettings::DEFAULT_CLOSE_DAYS),
                    Toggle::make('anonymous_by_default')
                        ->label(__('surveys::messages.settings.anonymous_by_default'))
                        ->helperText(__('surveys::messages.settings.anonymous_by_default_help'))
                        ->default(true),
                    Toggle::make('announce_in_channels_by_default')
                        ->label(__('surveys::messages.settings.announce_in_channels_by_default'))
                        ->helperText(__('surveys::messages.settings.announce_in_channels_by_default_help'))
                        ->default(false),
                ]),
            Section::make(__('surveys::messages.settings.review'))
                ->schema([
                    Toggle::make('review_required')
                        ->label(__('surveys::messages.settings.review_required'))
                        ->helperText(__('surveys::messages.settings.review_required_help'))
                        ->default(false),
                ]),
            Section::make(__('surveys::messages.settings.subject_types'))
                ->description(__('surveys::messages.settings.subject_types_help'))
                ->schema($this->subjectTypeSections()),
        ];
    }

    /**
     * One section per kind of subject a module offers; the list follows the enabled modules.
     *
     * @return array<Component>
     */
    private function subjectTypeSections(): array
    {
        $sources = app(SurveySubjectSourceRegistryInterface::class)->sources();
        if ($sources === []) {
            return [Text::make(__('surveys::messages.settings.no_subject_types'))];
        }

        $templates = SurveyTemplateModel::query()->where('kind', SurveyKind::Form->value)->orderBy('name')->pluck('name', 'id')->all();
        $settings = app(SurveySettingsInterface::class);

        return array_map(static function (SurveySubjectSourceInterface $source) use ($templates, $settings): Section {
            $path = 'subject_types.'.$source->sourceType();
            $gameSystems = self::gameSystemOptions($source, array_keys($settings->forSubjectType($source->sourceType())->gameSystemTemplates));

            return Section::make($source->sourceLabel())
                ->compact()
                ->columns(2)
                ->schema([
                    Select::make("{$path}.template_id")
                        ->label(__('surveys::messages.settings.template'))
                        ->helperText(__('surveys::messages.settings.template_help'))
                        ->options($templates)
                        ->native(false)
                        ->columnSpanFull(),
                    Toggle::make("{$path}.auto_send")
                        ->label(__('surveys::messages.settings.auto_send'))
                        ->helperText(__('surveys::messages.settings.auto_send_help'))
                        ->default(false)
                        ->live(),
                    TextInput::make("{$path}.send_delay_hours")
                        ->label(__('surveys::messages.settings.send_delay_hours'))
                        ->helperText(__('surveys::messages.settings.send_delay_hours_help'))
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->suffix(__('surveys::messages.settings.hours'))
                        ->default(SurveySettings::DEFAULT_SEND_DELAY_HOURS)
                        ->visible(static fn (Get $get): bool => (bool) $get("{$path}.auto_send")),
                    TextInput::make("{$path}.close_days")
                        ->label(__('surveys::messages.settings.default_close_days'))
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->suffix(__('surveys::messages.settings.days'))
                        ->default(SurveySettings::DEFAULT_CLOSE_DAYS),
                    Toggle::make("{$path}.anonymous")
                        ->label(__('surveys::messages.settings.anonymous'))
                        ->default(true),
                    Repeater::make("{$path}.game_system_templates")
                        ->label(__('surveys::messages.settings.game_system_templates'))
                        ->helperText(__('surveys::messages.settings.game_system_templates_help'))
                        ->schema([
                            Select::make('game_system')
                                ->label(__('surveys::messages.settings.game_system'))
                                ->options($gameSystems)
                                ->native(false)
                                ->searchable()
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            Select::make('template_id')
                                ->label(__('surveys::messages.settings.template'))
                                ->options($templates)
                                ->native(false)
                                ->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel(__('surveys::messages.settings.add_game_system_template'))
                        ->columnSpanFull()
                        ->visible($gameSystems !== []),
                ]);
        }, array_values($sources));
    }

    /**
     * The game systems the source lists, by id, and those a saved template still names after
     * they were gone, so they can be seen and removed. None when the source lists none.
     *
     * @param  list<string>  $saved
     * @return array<string, string>
     */
    private static function gameSystemOptions(SurveySubjectSourceInterface $source, array $saved): array
    {
        $listed = $source instanceof SurveySubjectAttributeValuesInterface ? $source->attributeValues('game_system') : [];

        $options = [];
        foreach ($listed as $value) {
            $options[$value->id] = $value->label;
        }

        foreach ($saved as $gameSystem) {
            $options[$gameSystem] ??= (string) __('surveys::messages.settings.game_system_gone');
        }

        return $options;
    }
}
