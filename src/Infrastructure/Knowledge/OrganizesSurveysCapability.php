<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Surveys\Application\Contracts\OrganizedSurveysQueryInterface;

/**
 * The person has surveys of something they still organize: the same rule as the
 * "what you organize" part of the profile's surveys tab.
 */
final readonly class OrganizesSurveysCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private OrganizedSurveysQueryInterface $organized) {}

    public function key(): string
    {
        return 'surveys.organizes_surveys';
    }

    public function passes(object $user): bool
    {
        return $user instanceof Authenticatable
            && $this->organized->forUser((string) $user->getAuthIdentifier()) !== [];
    }
}
