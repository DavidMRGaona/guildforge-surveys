<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Surveys\Contracts\SubjectEndSurveyChoiceInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\EndSurveyChoiceDTO;
use App\Application\Surveys\DTOs\EndSurveyOptionDTO;
use App\Application\Surveys\DTOs\EndSurveySelection;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\EndSurveyChoiceOutcome;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Enums\SurveySurface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\Services\EndSurveyTemplateResolver;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SubjectEndChoiceRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SubjectEndChoice;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The survey that goes out when a subject ends, chosen in the subject's own form (core field):
 * its default template (nothing stored: its game system's, else its type's, decided when it is
 * needed), another template or none (a row per subject), a survey
 * of its own (the subject's end survey itself, prepared from a template or promoted from one of
 * its drafts). It can change until the subject ends; a prepared survey that is replaced is
 * dropped when untouched and kept as a draft of the subject when edited, so nothing is lost.
 *
 * Option values: type-default, template:<id>, own (with a base template:<id>), survey:<id> (the
 * end survey in force), draft:<id>, none. A subject its module does not offer yet (an unpublished
 * event, say) keeps a template or none only: an own survey keeps its template to start from.
 */
final readonly class SubjectEndSurveyChoice implements SubjectEndSurveyChoiceInterface
{
    private const string TYPE_DEFAULT = 'type-default';

    private const string OWN = 'own';

    private const string NONE = 'none';

    private const string MANAGE = 'surveys:surveys.manage';

    private const string VIEW = 'surveys:surveys.view_results';

    public function __construct(
        private SurveySubjectSourceRegistryInterface $sources,
        private SubjectEndChoiceRepositoryInterface $choices,
        private SurveyRepositoryInterface $surveys,
        private SurveyTemplateRepositoryInterface $templates,
        private EndSurveyTemplateResolver $endTemplates,
        private OrganizerSurveyService $organizerSurveys,
        private SurveySettingsInterface $settings,
        private AuthorizationServiceInterface $authorization,
    ) {}

    public function forForm(string $sourceType, ?string $subjectId, string $userId, SurveySurface $surface): ?EndSurveyChoiceDTO
    {
        $source = $this->sources->source($sourceType);
        if (! $source instanceof SurveySubjectSourceInterface) {
            return null;
        }

        $names = $this->templates->formNames();
        if ($subjectId === null) {
            // Nothing to ask canManage() about yet: saving checks it with the new id
            return $names === [] ? null : $this->offer($sourceType, null, $names, null, [], self::TYPE_DEFAULT, withOwn: true);
        }

        if (! $this->may($userId, $source, $subjectId)) {
            return null;
        }

        // Null while its module does not offer it yet (an unpublished event, say): it can still
        // keep a template or none, but there is nothing to prepare a survey of its own about
        $subject = $source->find($subjectId);
        $endSurvey = $this->surveys->findBySubjectEnd($this->endKey($sourceType, $subjectId));
        if ($names === [] && ! $endSurvey instanceof Survey) {
            return null;
        }

        $current = $this->selected($sourceType, $subject, $endSurvey, $this->choices->find($sourceType, $subjectId), $names);
        $offer = $subject instanceof SurveySubjectDTO
            ? $this->offer($sourceType, $subject, $names, $this->inForce($endSurvey), $this->drafts($subject), $current, withOwn: true)
            : $this->offer($sourceType, null, $names, $this->inForce($endSurvey), [], $current, withOwn: false);
        $lockedReason = $this->lockedReason($subject, $endSurvey);

        return new EndSurveyChoiceDTO(
            options: $offer->options,
            selected: $offer->selected,
            ownValue: $offer->ownValue,
            baseTemplates: $offer->baseTemplates,
            locked: $lockedReason !== null,
            lockedReason: $lockedReason,
            editUrl: $this->editUrl($userId, $source, $subjectId, $this->inForce($endSurvey), $surface),
        );
    }

    public function choose(string $sourceType, string $subjectId, EndSurveySelection $selection, string $userId): EndSurveyChoiceOutcome
    {
        $source = $this->sources->source($sourceType);
        if (! $source instanceof SurveySubjectSourceInterface) {
            return EndSurveyChoiceOutcome::Unchanged;
        }

        if (! $this->may($userId, $source, $subjectId)) {
            throw new AuthorizationException;
        }

        // Null while its module does not offer it yet: the choice is kept for when it does
        $subject = $source->find($subjectId);

        return DB::transaction(function () use ($subject, $sourceType, $subjectId, $selection, $userId): EndSurveyChoiceOutcome {
            // Held until commit, so the end (or another save) waits for this choice
            $stored = $this->choices->findForUpdate($sourceType, $subjectId);
            $endSurvey = $this->surveys->findBySubjectEndForUpdate($this->endKey($sourceType, $subjectId));
            if ($this->lockedReason($subject, $endSurvey) !== null) {
                return EndSurveyChoiceOutcome::Locked;
            }

            $names = $this->templates->formNames();
            if ($selection->value !== self::OWN && $selection->value === $this->selected($sourceType, $subject, $endSurvey, $stored, $names)) {
                return EndSurveyChoiceOutcome::Unchanged;
            }

            return $this->apply($sourceType, $subjectId, $subject, $selection, $endSurvey, $names, $userId);
        });
    }

    /**
     * @param  array<string, string>  $names
     */
    private function apply(string $sourceType, string $subjectId, ?SurveySubjectDTO $subject, EndSurveySelection $selection, ?Survey $endSurvey, array $names, string $userId): EndSurveyChoiceOutcome
    {
        $value = $selection->value;

        if ($value === self::TYPE_DEFAULT || $value === self::NONE || str_starts_with($value, 'template:')) {
            $template = str_starts_with($value, 'template:') ? $this->knownTemplateId($value, $names) : null;
            $released = $this->stepDown($endSurvey);
            $default = $this->endTemplates->defaultTemplate($sourceType, $subject);

            if ($value === self::NONE) {
                $this->choices->save(SubjectEndChoice::none($sourceType, $subjectId), $userId);
            } elseif ($template instanceof SurveyTemplateId && $template->value !== $default?->id()->value) {
                $this->choices->save(SubjectEndChoice::template($sourceType, $subjectId, $template), $userId);
            } else {
                // The default needs no row, and follows the settings and the game system if they change
                $this->choices->forget($sourceType, $subjectId);
            }

            return $released ? EndSurveyChoiceOutcome::ReleasedAsDraft : EndSurveyChoiceOutcome::Saved;
        }

        if ($value === self::OWN) {
            $base = $this->templates->find($this->knownTemplateId((string) $selection->baseTemplate, $names));
            if (! $base instanceof SurveyTemplate) {
                throw new InvalidArgumentException("Unknown template to start from: '{$selection->baseTemplate}'.");
            }

            $released = $this->stepDown($endSurvey);
            if (! $subject instanceof SurveySubjectDTO) {
                // Nothing to prepare a survey about yet: its template goes out on the end instead
                if ($base->id()->value === $this->endTemplates->defaultTemplate($sourceType, null)?->id()->value) {
                    $this->choices->forget($sourceType, $subjectId);
                } else {
                    $this->choices->save(SubjectEndChoice::template($sourceType, $subjectId, $base->id()), $userId);
                }

                return EndSurveyChoiceOutcome::Deferred;
            }

            $this->choices->forget($sourceType, $subjectId);
            $this->organizerSurveys->prepareFrom($subject, $base, $userId, now()->toDateTimeImmutable());

            return $released ? EndSurveyChoiceOutcome::ReleasedAsDraft : EndSurveyChoiceOutcome::Saved;
        }

        if (str_starts_with($value, 'draft:') && $subject instanceof SurveySubjectDTO) {
            $draft = $this->draft($subject, substr($value, strlen('draft:')));
            $released = $this->stepDown($endSurvey);
            $this->choices->forget($sourceType, $subjectId);

            $type = $this->settings->forSubjectType($sourceType);
            $draft->becomeEndSurvey($this->endKey($sourceType, $subjectId));
            $draft->chooseEndSending($type->autoSend, $type->sendDelayHours);
            $this->surveys->save($draft);

            return $released ? EndSurveyChoiceOutcome::ReleasedAsDraft : EndSurveyChoiceOutcome::Saved;
        }

        // survey:<id> is only ever the one in force, which was handled as unchanged; drafts are
        // only offered for a subject its module offers
        throw new InvalidArgumentException("Unknown end survey choice '{$value}'.");
    }

    /**
     * The end survey in force steps aside: dropped when it is still its template as it was
     * (nothing to lose, it never opened), kept as a draft of the subject when it was edited.
     * True when it was kept.
     */
    private function stepDown(?Survey $endSurvey): bool
    {
        if (! $endSurvey instanceof Survey) {
            return false;
        }

        $template = $endSurvey->templateId() instanceof SurveyTemplateId ? $this->templates->find($endSurvey->templateId()) : null;
        $untouched = $template instanceof SurveyTemplate
            && ! $endSurvey->hasUnreviewedChanges()
            && ! $endSurvey->contentDiffers($template->name(), $template->description(), $template->definition());

        if ($untouched) {
            $this->surveys->delete($endSurvey->id());

            return false;
        }

        $endSurvey->releaseEndKey();
        $this->surveys->save($endSurvey);

        return true;
    }

    /**
     * @param  array<string, string>  $names
     * @param  list<Survey>  $drafts
     */
    private function offer(string $sourceType, ?SurveySubjectDTO $subject, array $names, ?Survey $inForce, array $drafts, string $selected, bool $withOwn): EndSurveyChoiceDTO
    {
        $systemTemplate = $this->endTemplates->gameSystemTemplate($sourceType, $subject);
        $typeTemplate = $this->endTemplates->typeTemplate($sourceType);
        $defaultId = ($systemTemplate ?? $typeTemplate)?->id()->value;

        $options = [];
        if ($inForce instanceof Survey) {
            $options[] = new EndSurveyOptionDTO('survey:'.$inForce->id()->value, (string) __('surveys::messages.end_choice.survey', ['title' => $inForce->title()]));
        }

        $options[] = new EndSurveyOptionDTO(self::TYPE_DEFAULT, $this->defaultLabel($sourceType, $subject, $systemTemplate, $typeTemplate));

        $baseTemplates = [];
        foreach ($names as $id => $name) {
            if ($id !== $defaultId) {
                $options[] = new EndSurveyOptionDTO('template:'.$id, $name, (string) __('surveys::messages.end_choice.templates'));
            }

            $base = new EndSurveyOptionDTO('template:'.$id, $name);
            $id === $defaultId ? array_unshift($baseTemplates, $base) : $baseTemplates[] = $base;
        }

        if ($withOwn && $names !== []) {
            $options[] = new EndSurveyOptionDTO(self::OWN, (string) __('surveys::messages.end_choice.own'));
        }

        foreach ($drafts as $draft) {
            $options[] = new EndSurveyOptionDTO('draft:'.$draft->id()->value, (string) __('surveys::messages.end_choice.draft', ['title' => $draft->title()]), (string) __('surveys::messages.end_choice.drafts'));
        }

        $options[] = new EndSurveyOptionDTO(self::NONE, (string) __('surveys::messages.end_choice.none'));

        return new EndSurveyChoiceDTO(
            options: $options,
            selected: $selected,
            ownValue: $withOwn && $names !== [] ? self::OWN : null,
            baseTemplates: $withOwn ? $baseTemplates : [],
        );
    }

    /**
     * What the default option says it is: its game system's template when it has one, else its
     * type's. Without the subject (being created, or not offered yet) its game system is not
     * known, so with templates per game system it says they decide.
     */
    private function defaultLabel(string $sourceType, ?SurveySubjectDTO $subject, ?SurveyTemplate $systemTemplate, ?SurveyTemplate $typeTemplate): string
    {
        return (string) match (true) {
            $systemTemplate instanceof SurveyTemplate => __('surveys::messages.end_choice.game_system_default', ['name' => $systemTemplate->name()]),
            ! $subject instanceof SurveySubjectDTO && $this->endTemplates->hasGameSystemTemplates($sourceType) => $typeTemplate instanceof SurveyTemplate
                ? __('surveys::messages.end_choice.by_game_system', ['name' => $typeTemplate->name()])
                : __('surveys::messages.end_choice.by_game_system_none'),
            $typeTemplate instanceof SurveyTemplate => __('surveys::messages.end_choice.type_default', ['name' => $typeTemplate->name()]),
            default => __('surveys::messages.end_choice.type_default_none'),
        };
    }

    /**
     * The value in force: the subject's end survey when it has one, none when it was dismissed or
     * chosen, a template chosen for it (while it exists), else its default.
     *
     * @param  array<string, string>  $names
     */
    private function selected(string $sourceType, ?SurveySubjectDTO $subject, ?Survey $endSurvey, ?SubjectEndChoice $stored, array $names): string
    {
        if ($endSurvey instanceof Survey) {
            return $this->inForce($endSurvey) instanceof Survey ? 'survey:'.$endSurvey->id()->value : self::NONE;
        }

        if ($stored instanceof SubjectEndChoice && $stored->isNone()) {
            return self::NONE;
        }

        $chosen = $stored?->templateId?->value;
        $defaultId = $this->endTemplates->defaultTemplate($sourceType, $subject)?->id()->value;

        return $chosen !== null && $chosen !== $defaultId && array_key_exists($chosen, $names)
            ? 'template:'.$chosen
            : self::TYPE_DEFAULT;
    }

    /**
     * The end survey unless its organizers chose not to send it (it then means "none").
     */
    private function inForce(?Survey $endSurvey): ?Survey
    {
        return $endSurvey instanceof Survey && (! $endSurvey->dismissedAt() instanceof DateTimeImmutable || $endSurvey->wasOpened())
            ? $endSurvey
            : null;
    }

    private function lockedReason(?SurveySubjectDTO $subject, ?Survey $endSurvey): ?string
    {
        return match (true) {
            $subject?->status === SurveySubjectStatus::Cancelled => (string) __('surveys::messages.end_choice.locked.cancelled'),
            $endSurvey instanceof Survey && $endSurvey->wasOpened() => (string) __('surveys::messages.end_choice.locked.sent'),
            $subject?->status === SurveySubjectStatus::Ended,
            $endSurvey instanceof Survey && ! $endSurvey->isAwaitingSubjectEnd() => (string) __('surveys::messages.end_choice.locked.ended'),
            default => null,
        };
    }

    /**
     * The subject's drafts that could become its end survey.
     *
     * @return list<Survey>
     */
    private function drafts(SurveySubjectDTO $subject): array
    {
        return array_values(array_filter(
            $this->surveys->findBySubject($subject->sourceType, $subject->subjectId),
            static fn (Survey $survey): bool => $survey->subjectEndKey() === null
                && $survey->kind() === SurveyKind::Form
                && $survey->status() === SurveyStatus::Draft
                && ! $survey->wasOpened()
                && ! $survey->dismissedAt() instanceof DateTimeImmutable,
        ));
    }

    private function draft(SurveySubjectDTO $subject, string $id): Survey
    {
        foreach ($this->drafts($subject) as $draft) {
            if ($draft->id()->value === $id) {
                return $this->surveys->findForUpdate(SurveyId::fromString($id));
            }
        }

        throw new InvalidArgumentException("Survey '{$id}' is not a draft of this subject.");
    }

    /**
     * @param  array<string, string>  $names
     */
    private function knownTemplateId(string $value, array $names): SurveyTemplateId
    {
        $id = str_starts_with($value, 'template:') ? substr($value, strlen('template:')) : '';
        if (! array_key_exists($id, $names)) {
            throw new InvalidArgumentException("Unknown end survey template '{$value}'.");
        }

        return SurveyTemplateId::fromString($id);
    }

    /**
     * The panel edits the survey for whoever manages surveys (or shows it once it went its way);
     * the organizer's editor serves everyone else who manages the subject while it still waits.
     */
    private function editUrl(string $userId, SurveySubjectSourceInterface $source, string $subjectId, ?Survey $endSurvey, SurveySurface $surface): ?string
    {
        if (! $endSurvey instanceof Survey) {
            return null;
        }

        $user = UserModel::query()->find($userId);
        if ($surface === SurveySurface::Panel && $user instanceof UserModel) {
            $page = match (true) {
                $endSurvey->isAwaitingSubjectEnd() && $this->authorization->can($user, self::MANAGE) => 'edit',
                ! $endSurvey->isAwaitingSubjectEnd() && $this->authorization->canAny($user, [self::MANAGE, self::VIEW]) => 'view',
                default => null,
            };

            try {
                if ($page !== null) {
                    return SurveyResource::getUrl($page, ['record' => $endSurvey->id()->value]);
                }
            } catch (RouteNotFoundException) {
                // No panel routes in this context: the organizer's pages below still work
            }
        }

        return $endSurvey->isAwaitingSubjectEnd() && $source->canManage($userId, $subjectId)
            ? route('surveys.manage.edit', $endSurvey->id()->value)
            : null;
    }

    private function may(string $userId, SurveySubjectSourceInterface $source, string $subjectId): bool
    {
        $user = UserModel::query()->find($userId);

        return ($user instanceof UserModel && $this->authorization->can($user, self::MANAGE))
            || $source->canManage($userId, $subjectId);
    }

    private function endKey(string $sourceType, string $subjectId): string
    {
        return "{$sourceType}:{$subjectId}";
    }
}
