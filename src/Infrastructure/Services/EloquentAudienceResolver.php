<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Surveys\Application\Contracts\AudienceResolverInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;

/**
 * Accounts count while active: neither anonymised nor deactivated (soft deleted). Roles count
 * only while active for their holder, as the core's authorization decides.
 */
final readonly class EloquentAudienceResolver implements AudienceResolverInterface
{
    public function __construct(
        private AuthorizationServiceInterface $authorization,
        private AssociationMembershipServiceInterface $membership,
        private SurveySubjectSourceRegistryInterface $sources,
    ) {}

    public function resolve(Survey $survey): SurveyAudienceDTO
    {
        return match ($survey->audienceType()) {
            AudienceType::AllUsers => new SurveyAudienceDTO($this->ids($this->accounts())),
            AudienceType::AssociationMembers => $this->associationMembers(),
            AudienceType::Roles => $this->roleHolders($survey->audienceRoles()),
            AudienceType::Subject => $this->subjectAudience($survey),
        };
    }

    private function associationMembers(): SurveyAudienceDTO
    {
        $role = $this->membership->roleName();
        if ($role === null) {
            return SurveyAudienceDTO::empty();
        }

        $members = $this->accounts()
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles->where('name', $role))
            ->get()
            ->filter(fn (UserModel $user): bool => $this->membership->isAssociationMember($user));

        return new SurveyAudienceDTO(array_values($members->pluck('id')->all()));
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function roleHolders(array $roleNames): SurveyAudienceDTO
    {
        if ($roleNames === []) {
            return SurveyAudienceDTO::empty();
        }

        $holders = $this->accounts()
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles->whereIn('name', $roleNames))
            ->get()
            ->filter(fn (UserModel $user): bool => array_any($roleNames, fn ($role) => $this->authorization->hasRole($user, $role)));

        return new SurveyAudienceDTO(array_values($holders->pluck('id')->all()));
    }

    private function subjectAudience(Survey $survey): SurveyAudienceDTO
    {
        $subject = $survey->subject();
        if (! $subject instanceof SubjectSnapshot) {
            return SurveyAudienceDTO::empty();
        }

        // Without the module that offered it, the subject has nobody to ask
        $source = $this->sources->source($subject->sourceType);

        return $source instanceof SurveySubjectSourceInterface ? $source->audience($subject->subjectId) : SurveyAudienceDTO::empty();
    }

    /**
     * @return Builder<UserModel>
     */
    private function accounts(): Builder
    {
        return UserModel::query()->whereNull('anonymized_at');
    }

    /**
     * @param  Builder<UserModel>  $query
     * @return list<string>
     */
    private function ids(Builder $query): array
    {
        return array_values($query->pluck('id')->all());
    }
}
