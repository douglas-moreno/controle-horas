<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimesheetPrintRequest;
use App\Models\Employee;
use App\Services\TimesheetJustifications;
use App\Services\TimesheetMirror;
use App\Services\TimesheetSnapshotHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

class TimesheetPrintController extends Controller
{
    /**
     * Gera a página de impressão do espelho de ponto dos funcionários ativos selecionados,
     * com as justificativas ativas carregadas em uma consulta (mesmos valores da tela).
     *
     * `snapshot_notices` (F6.2d): dias de competência fechada em que o espelho atual difere
     * do registro histórico de referência (ou cuja referência falhou na integridade). Só
     * leitura; o espelho impresso continua sendo o cálculo atual.
     */
    public function __invoke(TimesheetPrintRequest $request, TimesheetMirror $mirror, TimesheetJustifications $justifications, TimesheetSnapshotHistory $history): View
    {
        $start = Carbon::createFromFormat('Y-m-d', $request->validated('start'))->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $request->validated('end'))->startOfDay();

        $employees = Employee::query()
            ->whereIn('id', $request->validated('employees'))
            ->where(fn ($query) => $query->whereNull('recision_date')->orWhere('recision_date', ''))
            ->with(['points' => fn ($query) => $query
                ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->orderBy('date')
                ->orderBy('time')])
            ->orderBy('name')
            ->get();

        $justificationsByEmployee = $justifications->forEmployees($employees->modelKeys(), $start, $end);

        $timesheets = $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            ...$mirror->build($employee->points, $start, $end, $justificationsByEmployee[$employee->id] ?? []),
            'snapshot_notices' => $history->printNotices($employee, $start, $end),
        ]);

        return view('timesheet-print', [
            'timesheets' => $timesheets,
            'startDate' => $start,
            'endDate' => $end,
            'company' => [
                'name' => filled(config('timesheet.company.name')) ? (string) config('timesheet.company.name') : null,
                'cnpj' => filled(config('timesheet.company.cnpj')) ? (string) config('timesheet.company.cnpj') : null,
            ],
            'schedule' => self::scheduleSummary(config('timesheet.schedule', [])),
            'tolerance' => (int) config('timesheet.entry_tolerance_minutes', 0),
        ]);
    }

    /**
     * Agrupa os dias da semana consecutivos com a mesma jornada, ex.: "Seg a Qui".
     *
     * @param  array<int, array{entry: string, break_start: string, break_end: string, exit: string}>  $schedule
     * @return list<array{days: string, times: array{entry: string, break_start: string, break_end: string, exit: string}}>
     */
    public static function scheduleSummary(array $schedule): array
    {
        $names = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
        ksort($schedule);

        $groups = [];

        foreach ($schedule as $weekday => $times) {
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['times'] == $times && $groups[$last]['last'] === $weekday - 1) {
                $groups[$last]['last'] = $weekday;

                continue;
            }

            $groups[] = ['first' => $weekday, 'last' => $weekday, 'times' => $times];
        }

        return array_map(fn (array $group) => [
            'days' => $group['first'] === $group['last']
                ? $names[$group['first']]
                : $names[$group['first']].' a '.$names[$group['last']],
            'times' => $group['times'],
        ], $groups);
    }
}
