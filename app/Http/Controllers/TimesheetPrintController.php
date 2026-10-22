<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimesheetPrintRequest;
use App\Models\Employee;
use App\Services\TimesheetMirror;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

class TimesheetPrintController extends Controller
{
    /**
     * Gera a página de impressão do espelho de ponto dos funcionários ativos selecionados.
     */
    public function __invoke(TimesheetPrintRequest $request, TimesheetMirror $mirror): View
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

        $timesheets = $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            ...$mirror->build($employee->points, $start, $end),
        ]);

        return view('timesheet-print', [
            'timesheets' => $timesheets,
            'startDate' => $start,
            'endDate' => $end,
            'company' => [
                'name' => filled(config('timesheet.company.name')) ? (string) config('timesheet.company.name') : null,
                'cnpj' => filled(config('timesheet.company.cnpj')) ? (string) config('timesheet.company.cnpj') : null,
            ],
            'schedule' => $this->scheduleSummary(config('timesheet.schedule', [])),
            'tolerance' => (int) config('timesheet.entry_tolerance_minutes', 0),
        ]);
    }

    /**
     * Agrupa os dias da semana consecutivos com a mesma jornada, ex.: "Seg a Qui".
     *
     * @param  array<int, array{entry: string, break_start: string, break_end: string, exit: string}>  $schedule
     * @return list<array{days: string, times: array{entry: string, break_start: string, break_end: string, exit: string}}>
     */
    private function scheduleSummary(array $schedule): array
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
