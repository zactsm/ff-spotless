<?php

namespace App\Http\Requests;

use App\Enums\TaskSession;
use App\Http\Requests\Concerns\SanitizesPlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskTemplateRequest extends FormRequest
{
    use SanitizesPlainText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'task_name' => $this->sanitizePlainText($this->input('task_name')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'task_name' => ['bail', 'required', 'string', 'max:255'],
            'session' => ['bail', 'required', Rule::enum(TaskSession::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'task_name.required' => 'Nama tugasan diperlukan.',
            'task_name.string' => 'Nama tugasan mesti berupa teks.',
            'task_name.max' => 'Nama tugasan tidak boleh melebihi 255 aksara.',
            'session.required' => 'Sesi tugasan diperlukan.',
            'session.enum' => 'Sesi tugasan tidak sah.',
        ];
    }
}
