<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Filament\Support\DefinitionFormState;

/**
 * Runs domain work on the edited definition and turns its violations into form errors,
 * each on the field it is about.
 */
trait HandlesDefinitionErrors
{
    /**
     * The editor's state is read from the live form data, not from the validated data the
     * page receives: Filament renumbers list items there, while error paths need their keys.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     *
     * @throws ValidationException
     */
    protected function withDefinitionErrors(Closure $work): mixed
    {
        try {
            return $work();
        } catch (InvalidSurveyDefinitionException $exception) {
            $sections = is_array($this->data['sections'] ?? null) ? $this->data['sections'] : [];
            $messages = [];
            foreach ($exception->violations as $violation) {
                $messages['data.'.DefinitionFormState::formPath($sections, $violation->path)][] = __("surveys::messages.definition_errors.{$violation->messageKey}");
            }

            throw ValidationException::withMessages($messages);
        }
    }
}
