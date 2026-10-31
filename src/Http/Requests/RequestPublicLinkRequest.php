<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The address someone gives on the verified public link. Anyone may send it: what happens
 * with it is PublicLinkAccessService's to decide, and never told back.
 */
final class RequestPublicLinkRequest extends FormRequest
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
            // Strict: a quoted or commented local part would be another address to tell apart
            'email' => ['required', 'string', 'email:strict,filter', 'max:254'],
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }
}
