<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Actions\SurveyActions;
use Modules\Surveys\Filament\Concerns\HandlesDefinitionErrors;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Filament\Support\SurveyFormData;

final class EditSurvey extends EditRecord
{
    use HandlesDefinitionErrors;

    protected static string $resource = SurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SurveyActions::approve()->after(fn () => $this->refreshFormData(['status'])),
            SurveyActions::reject()->after(fn () => $this->refreshFormData(['status'])),
            SurveyActions::results(),
            SurveyActions::open()->after(fn () => $this->refreshFormData(['status'])),
            SurveyActions::close()->after(fn () => $this->refreshFormData(['status'])),
            SurveyActions::delete(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SurveyFormData::forFill($data);
    }

    /**
     * Every change goes through the survey's own rules; a rule broken by a forged request
     * (the form already disables what may not change) shows as an error on its field.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $surveys = app(SurveyRepositoryInterface::class);
        $survey = $surveys->findOrFail(SurveyId::fromString((string) $record->getKey()));
        $hasResponses = $surveys->hasResponses($survey->id());
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];

        $this->withDefinitionErrors(static function () use ($survey, $sections, $hasResponses): void {
            $survey->replaceDefinition(DefinitionFormState::toDefinition($sections), $hasResponses, new SurveyDefinitionValidator);
        });

        $this->onField('data.title', fn () => $survey->editDetails(
            array_key_exists('title', $data) ? (string) $data['title'] : $survey->title(),
            array_key_exists('description', $data) ? SurveyFormData::nullableText($data, 'description') : $survey->description(),
            $hasResponses,
        ));
        $this->onField('data.anonymous', fn () => $survey->changeAnonymity((bool) ($data['anonymous'] ?? $survey->isAnonymous()), $hasResponses));
        if (array_key_exists('audience_type', $data)) {
            $this->onField('data.audience_type', fn () => $survey->changeAudience(
                SurveyFormData::audienceType($data),
                SurveyFormData::audienceRoles($data),
                (bool) ($data['public_link_enabled'] ?? $survey->isPublicLinkEnabled()),
            ));
        }
        $this->onField('data.closes_at', fn () => $survey->reschedule(SurveyFormData::closesAt($data), SurveyFormData::reminderHoursBefore($data), now()->toDateTimeImmutable()));
        $survey->changeResults(SurveyFormData::results($data, $survey->results()));

        $surveys->save($survey);

        return $record->refresh();
    }

    /**
     * @param  callable(): void  $change
     *
     * @throws ValidationException
     */
    private function onField(string $field, callable $change): void
    {
        try {
            $change();
        } catch (InvalidSurveyStateException) {
            throw ValidationException::withMessages([$field => __("surveys::messages.state_errors.{$this->stateErrorKey($field)}")]);
        }
    }

    private function stateErrorKey(string $field): string
    {
        return match ($field) {
            'data.anonymous' => 'anonymity',
            'data.audience_type' => 'audience_fixed',
            'data.closes_at' => 'closing_date',
            default => 'locked_by_responses',
        };
    }
}
