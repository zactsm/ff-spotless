<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToggleTaskRequest extends FormRequest
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
            'task_id' => ['bail', 'required', 'integer', 'exists:daily_checklists,id'],
            'date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'is_completed' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'task_id.required' => 'Tugasan diperlukan.',
            'task_id.integer' => 'Tugasan tidak sah.',
            'task_id.exists' => 'Tugasan tidak ditemui.',
            'date.required' => 'Tarikh diperlukan.',
            'date.string' => 'Tarikh tidak sah.',
            'date.date_format' => 'Tarikh mesti menggunakan format YYYY-MM-DD.',
            'is_completed.required' => 'Status tugasan diperlukan.',
            'is_completed.boolean' => 'Status tugasan tidak sah.',
        ];
    }
}
