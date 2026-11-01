<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyOrganizerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;

final readonly class EloquentSurveyRepository implements SurveyRepositoryInterface
{
    public function __construct(private TimezoneServiceInterface $timezone) {}

    public function save(Survey $survey): void
    {
        DB::transaction(function () use ($survey): void {
            $model = $this->write($survey);

            // The snapshot's organizers, kept in step with it: a survey prepared before its subject
            // ended gets the subject as it is on the end
            if ($survey->subject() instanceof SubjectSnapshot) {
                $this->syncOrganizers($model, $survey->subject()->organizerIds);
            }
        });
    }

    /**
     * @param  list<string>  $organizerIds
     */
    private function syncOrganizers(SurveyModel $model, array $organizerIds): void
    {
        $current = SurveyOrganizerModel::query()->where('survey_id', $model->id)->pluck('user_id')->map(static fn (mixed $id): string => (string) $id)->all();

        $gone = array_values(array_diff($current, $organizerIds));
        if ($gone !== []) {
            SurveyOrganizerModel::query()->where('survey_id', $model->id)->whereIn('user_id', $gone)->delete();
        }

        foreach (array_values(array_diff($organizerIds, $current)) as $userId) {
            SurveyOrganizerModel::query()->create(['survey_id' => $model->id, 'user_id' => $userId]);
        }
    }

    private function write(Survey $survey): SurveyModel
    {
        $subject = $survey->subject();

        return SurveyModel::query()->updateOrCreate(['id' => $survey->id()->value], [
            'kind' => $survey->kind()->value,
            'title' => $survey->title(),
            'description' => $survey->description(),
            'definition' => $survey->definition()->toArray(),
            'status' => $survey->status()->value,
            'anonymous' => $survey->isAnonymous(),
            'results_settings' => $survey->results()->toArray(),
            'audience_type' => $survey->audienceType()->value,
            'audience_roles' => $survey->audienceRoles(),
            'public_link_enabled' => $survey->isPublicLinkEnabled(),
            'public_link_mode' => $survey->publicLinkMode()->value,
            'public_token' => $survey->publicToken(),
            'public_token_hash' => $survey->publicToken() === null ? null : hash('sha256', $survey->publicToken()),
            'audience_size' => $survey->audienceSize(),
            'template_id' => $survey->templateId()?->value,
            'subject_type' => $subject?->sourceType,
            'subject_id' => $subject?->subjectId,
            'subject_snapshot' => $subject?->toArray(),
            'reminder_hours_before' => $survey->reminderHoursBefore(),
            'opens_at' => $survey->opensAt(),
            'closes_at' => $survey->closesAt(),
            'closed_at' => $survey->closedAt(),
            'created_by' => $survey->createdBy(),
            'duration_days' => $survey->durationDays(),
            'unreviewed_changes' => $survey->hasUnreviewedChanges(),
            'last_changed_by' => $survey->lastChangedBy(),
            'rejection_reason' => $survey->rejectionReason(),
            'submitted_by' => $survey->submittedBy(),
            'submitted_for_review_at' => $survey->submittedForReviewAt(),
            'reviewed_by' => $survey->reviewedBy(),
            'reviewed_at' => $survey->reviewedAt(),
            'scheduled_send_at' => $survey->scheduledSendAt(),
            'dismissed_at' => $survey->dismissedAt(),
            'subject_end_key' => $survey->subjectEndKey(),
            'subject_ended_at' => $survey->subjectEndedAt(),
            'send_on_end' => $survey->sendsOnEnd(),
            'announce_in_channels' => $survey->announceInChannels(),
            'closing_summary' => $survey->sendsClosingSummary(),
            'chosen_slot_id' => $survey->chosenSlot()?->id,
            'chosen_slot_at' => $survey->chosenSlotAt(),
            'send_delay_hours' => $survey->sendDelayHours(),
        ]);
    }

    public function findMany(array $ids): array
    {
        $values = array_map(static fn (SurveyId $id): string => $id->value, $ids);
        $models = SurveyModel::query()->whereKey($values)->get()->keyBy('id');

        return array_values(array_map(
            $this->toEntity(...),
            array_filter(array_map(static fn (string $id): ?SurveyModel => $models->get($id), $values)),
        ));
    }

    public function findForUpdate(SurveyId $id): Survey
    {
        $model = SurveyModel::query()->whereKey($id->value)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel(SurveyModel::class, [$id->value]);

        return $this->toEntity($model);
    }

    public function findBySubjectEnd(string $key): ?Survey
    {
        $model = SurveyModel::query()->where('subject_end_key', $key)->first();

        return $model === null ? null : $this->toEntity($model);
    }

    public function findBySubjectEndForUpdate(string $key): ?Survey
    {
        $model = SurveyModel::query()->where('subject_end_key', $key)->lockForUpdate()->first();

        return $model === null ? null : $this->toEntity($model);
    }

    public function existsForSubjectEnd(string $key): bool
    {
        return SurveyModel::query()->where('subject_end_key', $key)->exists();
    }

    public function dueForSending(DateTimeImmutable $now): array
    {
        return array_values(SurveyModel::query()
            ->where('status', SurveyStatus::Draft->value)
            ->whereNull('dismissed_at')
            ->whereNotNull('scheduled_send_at')
            ->where('scheduled_send_at', '<=', $this->timezone->toStorage($now))
            ->orderBy('scheduled_send_at')
            ->pluck('id')
            ->map(static fn (string $id): SurveyId => SurveyId::fromString($id))
            ->all());
    }

    public function pendingReviewCount(): int
    {
        return SurveyModel::query()->where('status', SurveyStatus::PendingReview->value)->count();
    }

    public function find(SurveyId $id): ?Survey
    {
        $model = SurveyModel::query()->find($id->value);

        return $model === null ? null : $this->toEntity($model);
    }

    public function findOrFail(SurveyId $id): Survey
    {
        return $this->find($id) ?? throw (new ModelNotFoundException)->setModel(SurveyModel::class, [$id->value]);
    }

    public function delete(SurveyId $id): void
    {
        SurveyModel::query()->whereKey($id->value)->delete();
    }

    public function findDueToClose(DateTimeImmutable $now): array
    {
        return array_values(SurveyModel::query()
            ->where('status', SurveyStatus::Open->value)
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', $this->timezone->toStorage($now))
            ->get()
            ->map(fn (SurveyModel $model): Survey => $this->toEntity($model))
            ->all());
    }

    public function findDueForReminder(DateTimeImmutable $now): array
    {
        $candidates = SurveyModel::query()
            ->where('status', SurveyStatus::Open->value)
            ->whereNotNull('reminder_hours_before')
            ->whereNull('reminder_sent_at')
            ->where('closes_at', '>', $this->timezone->toStorage($now))
            ->get()
            ->map(fn (SurveyModel $model): Survey => $this->toEntity($model))
            ->all();

        // The window depends on each survey's hours, so it is checked here, portable across databases
        return array_values(array_filter(
            $candidates,
            static fn (Survey $survey): bool => $survey->closesAt() instanceof DateTimeImmutable
                && $survey->closesAt()->modify("-{$survey->reminderHoursBefore()} hours") <= $now,
        ));
    }

    public function claimReminder(SurveyId $id, DateTimeImmutable $now): bool
    {
        return SurveyModel::query()->whereKey($id->value)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => $this->timezone->toStorage($now)]) === 1;
    }

    public function hasResponses(SurveyId $id): bool
    {
        return SurveyResponseModel::query()->where('survey_id', $id->value)->exists();
    }

    public function findBySubject(string $sourceType, string $subjectId): array
    {
        return array_values(SurveyModel::query()
            ->where('subject_type', $sourceType)
            ->where('subject_id', $subjectId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (SurveyModel $model): Survey => $this->toEntity($model))
            ->all());
    }

    public function findByTemplate(SurveyTemplateId $templateId): array
    {
        return array_values(SurveyModel::query()
            ->where('template_id', $templateId->value)
            ->whereNotNull('opens_at')
            ->whereIn('status', [SurveyStatus::Open->value, SurveyStatus::Closed->value])
            ->orderBy('opens_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SurveyModel $model): Survey => $this->toEntity($model))
            ->all());
    }

    private function toEntity(SurveyModel $model): Survey
    {
        /** @var array{source_type: string, subject_id: string, title: string, starts_at: ?string, ends_at: ?string, organizer_ids: list<string>, attributes: list<array{key: string, label: string, value: string|int|float|bool|null}>, url: ?string}|null $snapshot */
        $snapshot = $model->subject_snapshot;

        return new Survey(
            id: SurveyId::fromString($model->id),
            kind: SurveyKind::from($model->kind),
            title: $model->title,
            description: $model->description,
            definition: SurveyDefinition::fromArray($model->definition),
            anonymous: $model->anonymous,
            results: ResultsSettings::fromArray($model->results_settings),
            audienceType: AudienceType::from($model->audience_type),
            audienceRoles: $model->audience_roles,
            publicLinkEnabled: $model->public_link_enabled,
            templateId: $model->template_id === null ? null : SurveyTemplateId::fromString($model->template_id),
            subject: $snapshot === null ? null : SubjectSnapshot::fromArray($snapshot),
            status: SurveyStatus::from($model->status),
            opensAt: $this->instant($model->opens_at),
            closesAt: $this->instant($model->closes_at),
            closedAt: $this->instant($model->closed_at),
            reminderHoursBefore: $model->reminder_hours_before,
            createdBy: $model->created_by,
            audienceSize: $model->audience_size,
            publicToken: $model->public_token,
            durationDays: $model->duration_days,
            unreviewedChanges: $model->unreviewed_changes,
            lastChangedBy: $model->last_changed_by,
            rejectionReason: $model->rejection_reason,
            submittedBy: $model->submitted_by,
            submittedForReviewAt: $this->instant($model->submitted_for_review_at),
            reviewedBy: $model->reviewed_by,
            reviewedAt: $this->instant($model->reviewed_at),
            scheduledSendAt: $this->instant($model->scheduled_send_at),
            dismissedAt: $this->instant($model->dismissed_at),
            subjectEndKey: $model->subject_end_key,
            subjectEndedAt: $this->instant($model->subject_ended_at),
            sendOnEnd: $model->send_on_end,
            sendDelayHours: $model->send_delay_hours,
            publicLinkMode: PublicLinkMode::from($model->public_link_mode),
            announceInChannels: $model->announce_in_channels,
            closingSummary: $model->closing_summary,
            chosenSlotId: $model->chosen_slot_id,
            chosenSlotAt: $this->instant($model->chosen_slot_at),
        );
    }

    private function instant(?Carbon $value): ?DateTimeImmutable
    {
        return $value?->toDateTimeImmutable();
    }
}
