<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

final class EloquentSurveyTemplateRepository implements SurveyTemplateRepositoryInterface
{
    public function save(SurveyTemplate $template): void
    {
        SurveyTemplateModel::query()->updateOrCreate(['id' => $template->id()->value], [
            'name' => $template->name(),
            'description' => $template->description(),
            'kind' => $template->kind()->value,
            'definition' => $template->definition()->toArray(),
            'created_by' => $template->createdBy(),
        ]);
    }

    public function find(SurveyTemplateId $id): ?SurveyTemplate
    {
        $model = SurveyTemplateModel::query()->find($id->value);

        return $model === null ? null : new SurveyTemplate(
            id: SurveyTemplateId::fromString($model->id),
            name: $model->name,
            description: $model->description,
            kind: SurveyKind::from($model->kind),
            definition: SurveyDefinition::fromArray($model->definition),
            createdBy: $model->created_by,
        );
    }

    public function findOrFail(SurveyTemplateId $id): SurveyTemplate
    {
        return $this->find($id) ?? throw (new ModelNotFoundException)->setModel(SurveyTemplateModel::class, [$id->value]);
    }

    public function delete(SurveyTemplateId $id): void
    {
        SurveyTemplateModel::query()->whereKey($id->value)->delete();
    }
}
