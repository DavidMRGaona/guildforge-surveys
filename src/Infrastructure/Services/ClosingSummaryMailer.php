<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Surveys\Application\Contracts\ClosingSummaryNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\Services\ClosingSummaryService;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Notifications\SurveyClosingSummary;

/**
 * Sends a closed survey's summary to whoever may see its results, each with a link to where
 * they see them: the subject's organizers who still manage it (on the site), its creator when
 * they still manage the subject (on the site) or may see results in the panel, and, when the
 * setting says so, everyone who may see results in the panel. Nobody gets it twice, and
 * anonymized accounts get nothing.
 */
final readonly class ClosingSummaryMailer implements ClosingSummaryNotifierInterface
{
    private const string VIEW_RESULTS = 'surveys:surveys.view_results';

    public function __construct(
        private ClosingSummaryService $summaries,
        private SurveyManagerGate $gate,
        private PermissionHolderFinder $holders,
        private SurveySettingsInterface $settings,
        private SiteBrandingServiceInterface $branding,
    ) {}

    public function surveyClosed(Survey $survey): void
    {
        if (! $survey->sendsClosingSummary()) {
            return;
        }

        $recipients = $this->recipients($survey);
        if ($recipients === []) {
            return;
        }

        $summary = $this->summaries->for($survey->id());
        $siteName = $this->branding->siteName();
        $users = UserModel::query()->whereIn('id', array_keys($recipients))->whereNull('anonymized_at')->get();

        foreach ($users as $user) {
            $user->notify(new SurveyClosingSummary($summary, $survey->id()->value, $recipients[$user->id], $siteName));
        }
    }

    /**
     * @return array<string, bool> user id => whether their link goes to the panel
     */
    private function recipients(Survey $survey): array
    {
        $recipients = [];
        foreach ($this->gate->managingOrganizers($survey) as $userId) {
            $recipients[$userId] = false;
        }

        $creator = $survey->createdBy();
        if ($creator !== null && ! isset($recipients[$creator]) && $this->gate->canManage($creator, $survey)) {
            $recipients[$creator] = false;
        }

        $toViewers = $this->settings->closingSummaryToResultsViewers();
        $creatorLeft = $creator !== null && ! isset($recipients[$creator]);
        $viewers = $toViewers || $creatorLeft ? $this->resultsViewers() : [];

        if ($creatorLeft && in_array($creator, $viewers, true)) {
            $recipients[$creator] = true;
        }

        if ($toViewers) {
            foreach ($viewers as $userId) {
                $recipients[$userId] ??= true;
            }
        }

        return $recipients;
    }

    /**
     * Who may open results in the panel.
     *
     * @return list<string>
     */
    private function resultsViewers(): array
    {
        return array_values(array_map(
            static fn (UserModel $user): string => $user->id,
            $this->holders->usersWhoCan('admin.access', self::VIEW_RESULTS)->all(),
        ));
    }
}
