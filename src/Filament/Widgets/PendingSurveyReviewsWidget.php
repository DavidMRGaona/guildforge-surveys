<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Widgets;

use App\Application\Services\DashboardWidgetConfigServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;

/**
 * Surveys changed outside the panel that wait for a moderator, oldest first. Shown only while
 * there is one, and only to whoever holds surveys.moderate.
 */
final class PendingSurveyReviewsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 10;

    public static function canView(): bool
    {
        return auth()->user()?->can('moderate', new SurveyModel) === true
            && SurveyModel::query()->where('status', SurveyStatus::PendingReview->value)->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('surveys::filament.widgets.pending_reviews.title'))
            ->query(SurveyModel::query()->where('status', SurveyStatus::PendingReview->value)->orderBy('submitted_for_review_at'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('surveys::filament.survey.fields.title'))
                    ->limit(40),
                TextColumn::make('subject')
                    ->label(__('surveys::filament.widgets.pending_reviews.subject'))
                    ->state(static fn (SurveyModel $record): ?string => is_string($record->subject_snapshot['title'] ?? null) ? $record->subject_snapshot['title'] : null)
                    ->limit(40),
                TextColumn::make('submitted_by')
                    ->label(__('surveys::filament.widgets.pending_reviews.sent_by'))
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : UserModel::query()->whereKey($state)->value('name')),
                TextColumn::make('submitted_for_review_at')
                    ->label(__('surveys::filament.widgets.pending_reviews.sent_at'))
                    ->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(__('surveys::filament.widgets.pending_reviews.review'))
                    ->icon('heroicon-o-eye')
                    ->url(static fn (SurveyModel $record): string => SurveyResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated([$this->limit()])
            ->defaultPaginationPageOption($this->limit());
    }

    private function limit(): int
    {
        return app(DashboardWidgetConfigServiceInterface::class)->getLimit(self::class, 5);
    }
}
