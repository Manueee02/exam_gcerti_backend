<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitLevelRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'uuid'],
            'answers.*.answer_id'   => ['required', 'uuid'],
            'answers.*.time_spent_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
