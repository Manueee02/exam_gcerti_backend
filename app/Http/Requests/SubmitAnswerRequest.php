<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_id' => [
                'required',
                'uuid',
            ],

            'answer' => [
                'required',
                'array',
            ],

            'answer.answer_id' => [
                'required',
                'uuid',
            ],

            'time_spent_seconds' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ];
    }
}
