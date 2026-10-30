<?php

declare(strict_types=1);

namespace Modules\Surveys\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

final class SurveyTemplatePolicy
{
    use AuthorizesWithPermissions;

    private const string MANAGE = 'surveys:surveys.manage';

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function view(UserModel $user, SurveyTemplateModel $template): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function update(UserModel $user, SurveyTemplateModel $template): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function delete(UserModel $user, SurveyTemplateModel $template): bool
    {
        return $this->authorize($user, self::MANAGE);
    }
}
