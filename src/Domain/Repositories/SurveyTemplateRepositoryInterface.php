<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Repositories;

use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

interface SurveyTemplateRepositoryInterface
{
    public function save(SurveyTemplate $template): void;

    public function find(SurveyTemplateId $id): ?SurveyTemplate;

    public function findOrFail(SurveyTemplateId $id): SurveyTemplate;

    public function delete(SurveyTemplateId $id): void;

    /**
     * Form templates (polls are never sent on an end), by name.
     *
     * @return array<string, string> template id => name
     */
    public function formNames(): array;
}
