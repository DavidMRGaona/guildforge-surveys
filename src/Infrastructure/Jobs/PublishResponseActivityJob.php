<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Jobs;

use App\Application\Activity\Services\ActivityPublisherInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Surveys\Infrastructure\Activity\ResponseActivitySource;

/**
 * Publishes again a batch of responses whose facts may have changed (core#774) because their
 * survey started or stopped counting, or was deleted. A survey may hold thousands of responses, so
 * whoever dispatches this cuts the ids into batches of BATCH: no job grows with the survey, and a
 * retry starts again from its own batch. Whether each response counts is decided when the consumer
 * reads it, so publishing one that did not change is harmless.
 */
final class PublishResponseActivityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int BATCH = 500;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  list<string>  $responseIds  at most BATCH
     */
    public function __construct(
        public readonly array $responseIds,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(ActivityPublisherInterface $activity): void
    {
        foreach ($this->responseIds as $responseId) {
            $activity->recorded(ResponseActivitySource::TYPE, $responseId);
        }
    }
}
