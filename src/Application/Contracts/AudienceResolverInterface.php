<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use Modules\Surveys\Domain\Entities\Survey;

interface AudienceResolverInterface
{
    /**
     * Who the survey goes to right now: called once, when it opens.
     */
    public function resolve(Survey $survey): SurveyAudienceDTO;
}
