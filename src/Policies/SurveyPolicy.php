<?php

declare(strict_types=1);

namespace Modules\Surveys\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;

/**
 * surveys.manage creates and edits surveys; surveys.view_results reads results and exports.
 * Either lets someone see the list.
 */
final class SurveyPolicy
{
    use AuthorizesWithPermissions;

    private const string MANAGE = 'surveys:surveys.manage';

    private const string VIEW_RESULTS = 'surveys:surveys.view_results';

    public function viewAny(UserModel $user): bool
    {
        return $this->authorizeAny($user, [self::MANAGE, self::VIEW_RESULTS]);
    }

    public function view(UserModel $user, SurveyModel $survey): bool
    {
        return $this->authorizeAny($user, [self::MANAGE, self::VIEW_RESULTS]);
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function update(UserModel $user, SurveyModel $survey): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function delete(UserModel $user, SurveyModel $survey): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function viewResults(UserModel $user, SurveyModel $survey): bool
    {
        return $this->authorize($user, self::VIEW_RESULTS);
    }

    public function export(UserModel $user, SurveyModel $survey): bool
    {
        return $this->authorize($user, self::VIEW_RESULTS);
    }
}
