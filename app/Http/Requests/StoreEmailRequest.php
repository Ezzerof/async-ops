<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise inputs before validation runs:
     * - Lowercase and trim each recipient to catch case-insensitive duplicates
     *   and strip any leading/trailing whitespace from addresses.
     * - Trim the body so a whitespace-only string fails the min:1 check.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('recipients') && is_array($this->recipients)) {
            $this->merge([
                'recipients' => array_map(
                    fn ($r) => is_string($r) ? strtolower(trim($r)) : $r,
                    $this->recipients,
                ),
            ]);
        }

        if ($this->has('body') && is_string($this->body)) {
            $this->merge(['body' => trim($this->body)]);
        }
    }

    public function rules(): array
    {
        return [
            'subject'      => ['required', 'string', 'max:255'],
            'body'         => ['required', 'string', 'min:1', 'max:10000'],
            'recipients'   => ['required', 'array', 'min:1', 'max:' . config('mail.bulk_max_recipients', 3)],
            'recipients.*' => ['required', 'string', 'email:rfc,strict', 'distinct'],
            'attachment'   => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
