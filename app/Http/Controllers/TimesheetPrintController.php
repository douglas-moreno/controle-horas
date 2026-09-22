<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimesheetPrintRequest;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TimesheetPrintController extends Controller
{
    /**
     * Gera a página de impressão do espelho de ponto dos funcionários ativos selecionados.
     */
    public function __invoke(TimesheetPrintRequest $request): View
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
            'days' => $this->buildDays($employee->points, $start, $end),
        ]);

        return view('timesheet-print', [
            'timesheets' => $timesheets,
            'startDate' => $start,
            'endDate' => $end,
        ]);
    }

    /**
     * Monta uma linha por dia do período, inclusive os dias sem marcação.
     *
     * @param  Collection<int, Point>  $points
     * @return array<int, array{date: string, is_highlighted: bool, holiday: ?string, entrada: string, almoco_inicio: string, almoco_fim: string, saida: string}>
     */
    private function buildDays(Collection $points, Carbon $start, Carbon $end): array
    {
        $pointsByDate = $points->groupBy(fn (Point $point) => Carbon::parse($point->date)->format('Y-m-d'));

        $days = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $holiday = Holiday::descriptionFor($day);

            $days[] = [
                'date' => $this->formatDisplayDate($day),
                'is_highlighted' => $day->isWeekend() || $holiday !== null,
                'holiday' => $holiday,
                ...$this->formatTimeValues($pointsByDate->get($day->format('Y-m-d'), collect())),
            ];
        }

        return $days;
    }

    /**
     * Formata a data como na tela de horas extras, ex.: "26/06/2026 Sex".
     */
    private function formatDisplayDate(Carbon $day): string
    {
        $dayAbbrev = $day->copy()->locale('pt_BR')->translatedFormat('D');
        $dayAbbrev = mb_strtoupper(mb_substr($dayAbbrev, 0, 1)).mb_substr($dayAbbrev, 1);

        return $day->format('d/m/Y').' '.$dayAbbrev;
    }

    /**
     * Distribui as marcações do dia nas colunas seguindo a mesma regra da tela de horas extras.
     *
     * @param  Collection<int, Point>  $points
     * @return array{entrada: string, almoco_inicio: string, almoco_fim: string, saida: string}
     */
    private function formatTimeValues(Collection $points): array
    {
        $times = $points
            ->sortBy('time')
            ->pluck('time')
            ->filter()
            ->map(fn ($time) => Carbon::parse($time)->format('H:i'))
            ->values();

        $slots = match (true) {
            $times->count() === 1 => ['entrada' => 0],
            $times->count() === 2 => ['entrada' => 0, 'saida' => 1],
            $times->count() === 3 => ['entrada' => 0, 'almoco_inicio' => 1, 'saida' => 2],
            $times->count() >= 4 => ['entrada' => 0, 'almoco_inicio' => 1, 'almoco_fim' => 2, 'saida' => 3],
            default => [],
        };

        $result = ['entrada' => '', 'almoco_inicio' => '', 'almoco_fim' => '', 'saida' => ''];

        foreach ($slots as $column => $index) {
            $result[$column] = $times->get($index);
        }

        return $result;
    }
}
