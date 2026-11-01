<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

/**
 * The subject surveys an organizer manages on the public site, as their pages show them.
 * Dismissed offers are left out, and so is anything the person no longer manages (asked live).
 *
 * @phpstan-type OrganizedSurvey array{
 *     id: string,
 *     title: string,
 *     subjectTitle: ?string,
 *     status: 'draft'|'prepared'|'to_send'|'scheduled'|'pending_review'|'rejected'|'open'|'closed',
 *     scheduledSendAt: ?string,
 *     closesAt: ?string,
 *     rejectionReason: ?string,
 *     responses: int,
 *     audienceSize: int,
 *     sendUrl: ?string,
 *     sendOnEnd: bool,
 *     sendDelayHours: int,
 *     dismissUrl: ?string,
 *     editUrl: ?string,
 *     resultsUrl: ?string,
 *     closeUrl: ?string,
 *     duplicateUrl: string,
 *     saveTemplateUrl: ?string,
 * }
 */
interface OrganizedSurveysQueryInterface
{
    /**
     * The surveys of every subject the person organized, newest first.
     *
     * @return list<OrganizedSurvey>
     */
    public function forUser(string $userId): array;

    /**
     * The surveys of one subject, newest first, for someone who manages it.
     *
     * @return list<OrganizedSurvey>
     */
    public function forSubject(string $sourceType, string $subjectId, string $userId): array;
}
