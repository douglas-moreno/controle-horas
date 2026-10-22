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

    /*
    |--------------------------------------------------------------------------
    | Regra alternativa (comparação)
    |--------------------------------------------------------------------------
    |
    | Regra alternativa de horas extras e atrasos, calculada em paralelo à
    | oficial somente para comparação (docs/espelho-ponto-regra-alternativa.md).
    | Desligada por padrão: nenhum dado alternativo é produzido. Ligada ou
    | desligada, a regra oficial e seus resultados não mudam.
    |
    */

    'alternative_rule' => [
        'enabled' => (bool) env('TIMESHEET_ALTERNATIVE_RULE_ENABLED', false),
    ],

];
