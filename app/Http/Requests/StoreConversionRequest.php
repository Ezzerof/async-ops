<?php

namespace App\Http\Requests;

use App\Enums\ConversionFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files'         => ['required', 'array', 'min:1', 'max:10'],
            'files.*'       => ['required', 'file', 'max:5120', 'mimes:csv,json,xml,pdf,docx,xlsx,yaml,yml,tsv,txt,pptx'],
            'target_format' => ['required', Rule::enum(ConversionFormat::class)],
        ];
    }
}
