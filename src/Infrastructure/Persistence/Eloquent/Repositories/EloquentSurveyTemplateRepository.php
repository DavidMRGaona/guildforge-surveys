<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;
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
            // None is stored as null, so "meant for anything" is one plain query
            'uses' => $template->uses()->isUnspecified() ? null : $template->uses()->values,
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
            uses: new TemplateUses($model->uses ?? []),
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

    public function formNames(): array
    {
        /** @var array<string, string> */
        return SurveyTemplateModel::query()
            ->where('kind', SurveyKind::Form->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function formNamesFor(string $use): array
    {
        $split = ['matching' => [], 'others' => []];
        foreach (SurveyTemplateModel::query()->where('kind', SurveyKind::Form->value)->orderBy('name')->get(['id', 'name', 'uses']) as $model) {
            $split[in_array($use, $model->uses ?? [], true) ? 'matching' : 'others'][$model->id] = $model->name;
        }

        return $split;
    }
}
