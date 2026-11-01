<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use DateTimeImmutable;

interface PendingSurveysQueryInterface
{
    /**
     * Open surveys, still on time, sent to this account and not answered by it yet; the
     * ones closing soonest first.
     *
     * @return list<array{id: string, title: string, subjectType: ?string, subjectTitle: ?string, closesAt: ?string, url: string}>
     */
    public function forUser(string $userId, DateTimeImmutable $now): array;
}
