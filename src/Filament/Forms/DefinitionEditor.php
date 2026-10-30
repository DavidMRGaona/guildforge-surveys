<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Forms;

use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Str;
use Modules\Surveys\Domain\Enums\QuestionType;

/**
 * The survey content editor: sections (Repeater) holding questions (Builder, one block per
 * question type). Its state is converted by DefinitionFormState and validated by the domain;
 * the rules here only guide whoever fills it. Fields locked by responses are still sent
 * (dehydrated): the definition needs them whole, and the domain rejects any change to them.
 */
final class DefinitionEditor
{
    /**
     * @param  bool  $withKeys  templates show the stable key of each question
     * @param  Closure|bool  $contentLocked  once a survey has responses only help texts change
     */
    public static function make(bool $withKeys = false, Closure|bool $contentLocked = false): Repeater
    {
        return Repeater::make('sections')
            ->label(__('surveys::filament.definition.sections'))
            ->schema([
                self::id(),
                TextInput::make('title')
                    ->label(__('surveys::filament.definition.section_title'))
                    ->maxLength(255)
                    ->disabled($contentLocked)
                    ->dehydrated(),
                Textarea::make('description')
                    ->label(__('surveys::filament.definition.section_description'))
                    ->rows(2)
                    ->disabled($contentLocked)
                    ->dehydrated(),
                Builder::make('questions')
                    ->label(__('surveys::filament.definition.questions'))
                    ->blocks(array_map(
                        static fn (QuestionType $type): Block => Block::make($type->value)
                            ->label(__("surveys::filament.question_types.{$type->value}"))
                            ->schema(self::questionFields($type, $withKeys, $contentLocked)),
                        QuestionType::cases(),
                    ))
                    ->blockNumbers(false)
                    ->collapsible()
                    ->addActionLabel(__('surveys::filament.definition.add_question'))
                    ->addable(static fn (Builder $component): bool => ! $component->evaluate($contentLocked))
                    ->deletable(static fn (Builder $component): bool => ! $component->evaluate($contentLocked))
                    ->reorderable(static fn (Builder $component): bool => ! $component->evaluate($contentLocked)),
            ])
            ->defaultItems(1)
            ->collapsible()
            ->itemLabel(static fn (array $state): ?string => is_string($state['title'] ?? null) ? $state['title'] : null)
            ->addActionLabel(__('surveys::filament.definition.add_section'))
            ->addable(static fn (Repeater $component): bool => ! $component->evaluate($contentLocked))
            ->deletable(static fn (Repeater $component): bool => ! $component->evaluate($contentLocked))
            ->reorderable(static fn (Repeater $component): bool => ! $component->evaluate($contentLocked))
            ->columnSpanFull();
    }

    /**
     * @return list<Component>
     */
    private static function questionFields(QuestionType $type, bool $withKeys, Closure|bool $locked): array
    {
        $fields = [
            self::id(),
            TextInput::make('title')
                ->label(__('surveys::filament.definition.question_title'))
                ->required()
                ->maxLength(500)
                ->disabled($locked)
                ->dehydrated(),
            Textarea::make('help')
                ->label(__('surveys::filament.definition.help'))
                ->rows(2),
            Toggle::make('required')
                ->label(__('surveys::filament.definition.required'))
                ->default(false)
                ->disabled($locked)
                ->dehydrated(),
        ];

        if ($withKeys) {
            array_splice($fields, 1, 0, [
                TextInput::make('key')
                    ->label(__('surveys::filament.definition.key'))
                    ->helperText(__('surveys::filament.definition.key_help'))
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->maxLength(64),
            ]);
        }

        return [...$fields, ...self::typeFields($type, $locked)];
    }

    /**
     * @return list<Component>
     */
    private static function typeFields(QuestionType $type, Closure|bool $locked): array
    {
        if ($type->hasOptions()) {
            return [
                self::optionList('options', 'surveys::filament.definition.options', 2, $locked),
                Toggle::make('allow_other')
                    ->label(__('surveys::filament.definition.allow_other'))
                    ->default(false)
                    ->disabled($locked)
                    ->dehydrated(),
            ];
        }

        return match ($type) {
            QuestionType::LinearScale => [
                Grid::make(4)->schema([
                    Select::make('scale_min')
                        ->label(__('surveys::filament.definition.scale_min'))
                        ->options([0 => '0', 1 => '1'])
                        ->default(1)
                        ->required()
                        ->disabled($locked)
                        ->dehydrated(),
                    Select::make('scale_max')
                        ->label(__('surveys::filament.definition.scale_max'))
                        ->options(array_combine(range(2, 10), array_map(strval(...), range(2, 10))))
                        ->default(5)
                        ->required()
                        ->disabled($locked)
                        ->dehydrated(),
                    TextInput::make('scale_min_label')
                        ->label(__('surveys::filament.definition.scale_min_label'))
                        ->maxLength(100)
                        ->disabled($locked)
                        ->dehydrated(),
                    TextInput::make('scale_max_label')
                        ->label(__('surveys::filament.definition.scale_max_label'))
                        ->maxLength(100)
                        ->disabled($locked)
                        ->dehydrated(),
                ]),
            ],
            QuestionType::StarRating => [
                Select::make('stars')
                    ->label(__('surveys::filament.definition.stars'))
                    ->options(array_combine(range(3, 10), array_map(strval(...), range(3, 10))))
                    ->default(5)
                    ->required()
                    ->disabled($locked)
                    ->dehydrated(),
            ],
            QuestionType::Grid => [
                Grid::make(2)->schema([
                    self::optionList('grid_rows', 'surveys::filament.definition.grid_rows', 1, $locked),
                    self::optionList('grid_columns', 'surveys::filament.definition.grid_columns', 2, $locked),
                ]),
            ],
            default => [],
        };
    }

    private static function optionList(string $name, string $labelKey, int $minItems, Closure|bool $locked): Repeater
    {
        return Repeater::make($name)
            ->label(__($labelKey))
            ->schema([
                self::id(),
                TextInput::make('label')
                    ->hiddenLabel()
                    ->required()
                    ->maxLength(255)
                    ->disabled($locked)
                    ->dehydrated(),
            ])
            ->minItems($minItems)
            ->defaultItems($minItems)
            ->addActionLabel(__('surveys::filament.definition.add_option'))
            ->addable(static fn (Repeater $component): bool => ! $component->evaluate($locked))
            ->deletable(static fn (Repeater $component): bool => ! $component->evaluate($locked))
            ->reorderable(static fn (Repeater $component): bool => ! $component->evaluate($locked));
    }

    private static function id(): Hidden
    {
        return Hidden::make('id')->default(static fn (): string => (string) Str::uuid());
    }
}
