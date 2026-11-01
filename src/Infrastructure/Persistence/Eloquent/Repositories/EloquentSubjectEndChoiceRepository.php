<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Surveys\Domain\Repositories\SubjectEndChoiceRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SubjectEndChoice;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SubjectEndChoiceModel;

final class EloquentSubjectEndChoiceRepository implements SubjectEndChoiceRepositoryInterface
{
    public function find(string $sourceType, string $subjectId): ?SubjectEndChoice
    {
        return $this->toChoice(SubjectEndChoiceModel::query()
            ->where('subject_type', $sourceType)
            ->where('subject_id', $subjectId)
            ->first());
    }

    public function findForUpdate(string $sourceType, string $subjectId): ?SubjectEndChoice
    {
        return $this->toChoice(SubjectEndChoiceModel::query()
            ->where('subject_type', $sourceType)
            ->where('subject_id', $subjectId)
            ->lockForUpdate()
            ->first());
    }

    public function save(SubjectEndChoice $choice, ?string $chosenBy): void
    {
        SubjectEndChoiceModel::query()->updateOrCreate(
            ['subject_type' => $choice->sourceType, 'subject_id' => $choice->subjectId],
            [
                'mode' => $choice->isNone() ? SubjectEndChoiceModel::MODE_NONE : SubjectEndChoiceModel::MODE_TEMPLATE,
                'template_id' => $choice->templateId?->value,
                'chosen_by' => $chosenBy,
            ],
        );
    }

    public function forget(string $sourceType, string $subjectId): void
    {
        SubjectEndChoiceModel::query()
            ->where('subject_type', $sourceType)
            ->where('subject_id', $subjectId)
            ->delete();
    }

    private function toChoice(?SubjectEndChoiceModel $model): ?SubjectEndChoice
    {
        if (! $model instanceof SubjectEndChoiceModel) {
            return null;
        }

        return $model->mode === SubjectEndChoiceModel::MODE_TEMPLATE && $model->template_id !== null
            ? SubjectEndChoice::template($model->subject_type, $model->subject_id, SurveyTemplateId::fromString($model->template_id))
            : SubjectEndChoice::none($model->subject_type, $model->subject_id);
    }
}
