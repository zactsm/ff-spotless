<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\InteractsWithOperationalDate;
use Illuminate\Foundation\Http\FormRequest;

class ChecklistDateRequest extends FormRequest
{
    use InteractsWithOperationalDate;

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
            'date' => ['nullable', 'string', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.string' => 'Tarikh tidak sah.',
            'date.date_format' => 'Tarikh mesti menggunakan format YYYY-MM-DD.',
        ];
    }
}
