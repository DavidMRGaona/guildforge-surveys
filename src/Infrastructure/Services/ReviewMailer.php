<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\ReviewNotifierInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Notifications\SurveySubmittedForReview;

/**
 * Moderators approve in the panel: they need panel access as well as surveys.moderate.
 */
final readonly class ReviewMailer implements ReviewNotifierInterface
{
    private const string MODERATE = 'surveys:surveys.moderate';

    public function __construct(
        private PermissionHolderFinder $holders,
        private SiteBrandingServiceInterface $branding,
    ) {}

    public function submittedForReview(Survey $survey): void
    {
        Notification::send(
            $this->holders->usersWhoCan('admin.access', self::MODERATE),
            new SurveySubmittedForReview($survey->title(), $survey->subject()?->title, $survey->id()->value, $this->branding->siteName()),
        );
    }
}
