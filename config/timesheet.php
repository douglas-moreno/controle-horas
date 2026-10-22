<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Empresa
    |--------------------------------------------------------------------------
    |
    | Razão social e CNPJ exibidos no cabeçalho do espelho de ponto. Sem valor
    | configurado, o espelho informa que a empresa não foi configurada.
    |
    */

    'company' => [
        'name' => env('TIMESHEET_COMPANY_NAME'),
        'cnpj' => env('TIMESHEET_COMPANY_CNPJ'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jornada prevista
    |--------------------------------------------------------------------------
    |
    | Jornada fixa aplicada a todos os funcionários, indexada pelo dia da semana
    | ISO (1 = segunda ... 7 = domingo). Dias sem jornada não têm atraso
    | calculado. Horários no formato H:i.
    |
    */

    'schedule' => [
        1 => ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '17:30'],
        2 => ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '17:30'],
        3 => ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '17:30'],
        4 => ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '17:30'],
        5 => ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '16:30'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tolerância de entrada
    |--------------------------------------------------------------------------
    |
    | Minutos descontados do atraso na entrada. Não se aplica à saída antecipada.
    |
    */

    'entry_tolerance_minutes' => (int) env('TIMESHEET_ENTRY_TOLERANCE_MINUTES', 5),

];
