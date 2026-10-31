<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of what the organizer's editor sends. The content rules live in the domain
 * (SurveyDefinitionValidator) and who may send it is the subject's source's to say.
 */
final class UpdateOrganizerSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'definition' => ['required', 'array'],
            'anonymous' => ['required', 'boolean'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'closes_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'reminder_hours_before' => ['nullable', 'integer', 'min:1', 'max:720'],
            'live_results' => ['required', 'boolean'],
            'visible_to_respondents' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => (string) __('surveys::messages.editor.title'),
            'description' => (string) __('surveys::messages.editor.description'),
            'duration_days' => (string) __('surveys::messages.editor.duration_days'),
            'closes_at' => (string) __('surveys::messages.editor.closes_at'),
            'reminder_hours_before' => (string) __('surveys::messages.editor.reminder_hours_before'),
        ];
    }
}
