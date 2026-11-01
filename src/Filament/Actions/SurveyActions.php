<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Js;
use Modules\Surveys\Application\Exceptions\SubjectCancelledException;
use Modules\Surveys\Application\Services\SurveyDuplicationService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Application\Services\SurveyReviewService;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

/**
 * Actions shared by the survey list, the edit page and the read-only page.
 */
final class SurveyActions
{
    public static function results(): Action
    {
        return Action::make('results')
            ->label(__('surveys::filament.results.action'))
            ->icon('heroicon-o-chart-pie')
            ->color('gray')
            ->visible(static fn (SurveyModel $record): bool => $record->status !== SurveyStatus::Draft->value)
            ->authorize(static fn (SurveyModel $record): bool => auth()->user()?->can('viewResults', $record) === true)
            ->url(static fn (SurveyModel $record): string => SurveyResource::getUrl('results', ['record' => $record]));
    }

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
     * Copies the public link shown next to it; nothing goes to the server.
     */
    public static function copyPublicLink(): Action
    {
        return Action::make('copy_public_link')
            ->label(__('surveys::filament.survey.public_link.copy'))
            ->icon('heroicon-o-clipboard-document')
            ->color('gray')
            ->size('sm')
            ->alpineClickHandler(static function (SurveyModel $record): string {
                $url = Js::from(SurveyResource::publicLinkUrl($record));
                $message = Js::from(__('surveys::filament.survey.public_link.copied'));

                return "window.navigator.clipboard.writeText({$url}); \$tooltip({$message}, { theme: \$store.theme, timeout: 2000 })";
            });
    }

    /**
     * A new public link when the old one went where it should not. The old one stops working for
     * everybody at once, so a modal and a ticked checkbox naming that consequence.
     */
    public static function renewPublicLink(): Action
    {
        return Action::make('renew_public_link')
            ->label(__('surveys::filament.actions.renew_public_link'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalDescription(__('surveys::filament.actions.renew_public_link_description'))
            ->modalSubmitActionLabel(__('surveys::filament.actions.renew_public_link'))
            ->schema([
                Checkbox::make('confirmation')
                    ->label(__('surveys::filament.actions.renew_public_link_confirmation'))
                    ->accepted()
                    ->required(),
            ])
            ->visible(static fn (SurveyModel $record): bool => SurveyResource::publicLinkUrl($record) !== null)
            ->authorize(static fn (SurveyModel $record): bool => auth()->user()?->can('update', $record) === true)
            ->action(static function (SurveyModel $record): void {
                self::run(static fn () => app(SurveyLifecycleService::class)->renewPublicLink(SurveyId::fromString($record->id)), 'renew_public_link');
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
     * Approving sends a survey that never went out (its duration counted from now) or lets an open
     * one take responses again.
     */
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('surveys::filament.review.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('surveys::filament.review.approve_heading'))
            ->modalDescription(__('surveys::filament.review.approve_description'))
            ->visible(static fn (SurveyModel $record): bool => $record->status === SurveyStatus::PendingReview->value)
            ->authorize(static fn (SurveyModel $record): bool => auth()->user()?->can('moderate', $record) === true)
            ->action(static function (SurveyModel $record): void {
                self::review(static fn () => app(SurveyReviewService::class)->approve(SurveyId::fromString($record->id), (string) auth()->id()), 'approved');
                $record->refresh();
            });
    }

    /**
     * Rejecting returns the survey to its organizer with the reason, which they receive by mail.
     */
    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('surveys::filament.review.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(__('surveys::filament.review.reject_heading'))
            ->modalDescription(__('surveys::filament.review.reject_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('surveys::filament.review.reason'))
                    ->placeholder(__('surveys::filament.review.reason_placeholder'))
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(static fn (SurveyModel $record): bool => $record->status === SurveyStatus::PendingReview->value)
            ->authorize(static fn (SurveyModel $record): bool => auth()->user()?->can('moderate', $record) === true)
            ->action(static function (SurveyModel $record, array $data): void {
                $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
                self::review(static fn () => app(SurveyReviewService::class)->reject(SurveyId::fromString($record->id), (string) auth()->id(), $reason), 'rejected');
                $record->refresh();
            });
    }

    /**
     * A draft copy with the same content and settings, opened in the editor. A subject survey's
     * copy stays with its subject, which must still be there and not cancelled.
     */
    public static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->label(__('surveys::filament.actions.duplicate'))
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('surveys::filament.actions.duplicate_description'))
            ->authorize(static fn (): bool => auth()->user()?->can('create', SurveyModel::class) === true)
            ->action(static function (SurveyModel $record, Action $action): void {
                try {
                    $copy = app(SurveyDuplicationService::class)->duplicate(SurveyId::fromString($record->id), (string) auth()->id());
                } catch (SubjectNotFoundException|SubjectCancelledException) {
                    Notification::make()->danger()->title(__('surveys::filament.actions.duplicate_failed'))->send();

                    return;
                }

                Notification::make()->success()->title(__('surveys::filament.actions.duplicate_done'))->send();
                $action->redirect(SurveyResource::getUrl('edit', ['record' => $copy->id()->value]));
            });
    }

    /**
     * A template with the survey's questions, meant for its kind of subject (or general surveys).
     */
    public static function saveAsTemplate(): Action
    {
        return Action::make('save_as_template')
            ->label(__('surveys::filament.actions.save_as_template'))
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->modalDescription(__('surveys::filament.actions.save_as_template_description'))
            ->fillForm(static fn (SurveyModel $record): array => ['name' => $record->title, 'description' => $record->description])
            ->schema([
                TextInput::make('name')
                    ->label(__('surveys::filament.survey_template.fields.name'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label(__('surveys::filament.survey_template.fields.description'))
                    ->rows(2),
            ])
            ->authorize(static fn (): bool => auth()->user()?->can('create', SurveyTemplateModel::class) === true)
            ->action(static function (SurveyModel $record, array $data): void {
                $name = is_string($data['name'] ?? null) ? $data['name'] : '';
                $description = is_string($data['description'] ?? null) && trim($data['description']) !== '' ? $data['description'] : null;

                try {
                    $template = app(SurveyDuplicationService::class)->saveAsTemplate(SurveyId::fromString($record->id), $name, $description, (string) auth()->id());
                } catch (InvalidSurveyDefinitionException) {
                    Notification::make()->danger()->title(__('surveys::filament.actions.save_as_template_failed'))->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('surveys::filament.actions.save_as_template_done', ['name' => $template->name()]))
                    ->actions([
                        Action::make('edit_template')
                            ->label(__('surveys::filament.actions.open_template'))
                            ->url(SurveyTemplateResource::getUrl('edit', ['record' => $template->id()->value])),
                    ])
                    ->send();
            });
    }

    /**
     * @param  callable(): mixed  $decision
     */
    private static function review(callable $decision, string $done): void
    {
        try {
            $decision();
        } catch (InvalidSurveyStateException) {
            // Another moderator decided first, or the survey changed meanwhile
            Notification::make()->warning()->title(__('surveys::filament.review.already_reviewed'))->send();

            return;
        }

        Notification::make()->success()->title(__("surveys::filament.review.{$done}"))->send();
    }

    /**
     * @param  callable(): mixed  $change
     */
    private static function run(
        callable $change, string $action): void
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
