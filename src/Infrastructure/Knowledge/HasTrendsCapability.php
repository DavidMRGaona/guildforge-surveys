<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Surveys\Application\Services\TrendsService;

/**
 * The person has trends to see on the site: the same rule as the profile's link to them.
 */
final readonly class HasTrendsCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private TrendsService $trends) {}

    public function key(): string
    {
        return 'surveys.has_trends';
    }

    public function passes(object $user): bool
    {
        return $user instanceof Authenticatable
            && $this->trends->templatesFor((string) $user->getAuthIdentifier()) !== [];
    }
}
