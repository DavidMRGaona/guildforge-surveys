<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;

/**
 * Actions shared by the survey list and the edit page.
 */
final class SurveyActions
{
    public static function open(): Action
    {
        return Action::make('open')
            ->label(__('surveys::filament.actions.open'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('surveys::filament.actions.open_description'))
            ->visible(static fn (SurveyModel $record): bool => $record->status === SurveyStatus::Draft->value)
            ->authorize(static fn (SurveyModel $record): bool => auth()->user()?->can('update', $record) === true)
            ->action(static function (SurveyModel $record): void {
                self::run(static fn () => app(SurveyLifecycleService::class)->open(SurveyId::fromString($record->id)), 'open');
                $record->refresh();
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label(__('surveys::filament.actions.close'))
            ->icon('heroicon-o-stop')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('surveys::filament.actions.close_description'))
            ->visible(static fn (SurveyModel $record): bool => $record->status === SurveyStatus::Open->value)
            ->authorize(static fn (SurveyModel $record): bool => auth()->user()?->can('update', $record) === true)
            ->action(static function (SurveyModel $record): void {
                self::run(static fn () => app(SurveyLifecycleService::class)->close(SurveyId::fromString($record->id)), 'close');
                $record->refresh();
            });
    }

    /**
     * Deleting takes every response with it and cannot be undone: a modal and a ticked
     * checkbox naming that consequence. Surveys are never deleted in bulk.
     */
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(__('surveys::filament.actions.delete_description'))
            ->schema([
                Checkbox::make('confirmation')
                    ->label(__('surveys::filament.actions.delete_confirmation'))
                    ->accepted()
                    ->required(),
            ]);
    }

    /**
     * @param  callable(): mixed  $change
     */
    private static function run(callable $change, string $action): void
    {
        try {
            $change();
        } catch (InvalidSurveyStateException) {
            Notification::make()->danger()->title(__("surveys::filament.actions.{$action}_failed"))->send();

            return;
        }

        Notification::make()->success()->title(__("surveys::filament.actions.{$action}_done"))->send();
    }
}
