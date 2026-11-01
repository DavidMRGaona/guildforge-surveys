<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Collection;
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

        $viewers = null;
        $creator = $survey->createdBy();
        if ($creator !== null && ! isset($recipients[$creator])) {
            if ($this->gate->canManage($creator, $survey)) {
                $recipients[$creator] = false;
            } elseif ($this->resultsViewers($viewers)->contains($creator)) {
                $recipients[$creator] = true;
            }
        }

        if ($this->settings->closingSummaryToResultsViewers()) {
            foreach ($this->resultsViewers($viewers) as $userId) {
                $recipients[$userId] ??= true;
            }
        }

        return $recipients;
    }

    /**
     * Who may open results in the panel, looked up once.
     *
     * @param  Collection<int, string>|null  $viewers
     * @return Collection<int, string>
     */
    private function resultsViewers(?Collection &$viewers): Collection
    {
        return $viewers ??= $this->holders->usersWhoCan('admin.access', self::VIEW_RESULTS)->pluck('id');
    }
}
