<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TimesheetPrintRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'employees' => ['required', 'array', 'min:1'],
            'employees.*' => ['integer', 'exists:employees,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start.required' => 'Informe a data inicial.',
            'start.date_format' => 'A data inicial deve estar no formato AAAA-MM-DD.',
            'end.required' => 'Informe a data final.',
            'end.date_format' => 'A data final deve estar no formato AAAA-MM-DD.',
            'end.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
            'employees.required' => 'Selecione ao menos um funcionário.',
            'employees.min' => 'Selecione ao menos um funcionário.',
            'employees.*.exists' => 'Funcionário selecionado não encontrado.',
        ];
    }
}
