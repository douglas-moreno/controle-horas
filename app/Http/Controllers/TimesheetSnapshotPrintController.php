<?php

namespace App\Http\Controllers;

use App\Models\TimesheetSnapshot;
use App\Services\TimesheetMirror;
use App\Services\TimesheetSnapshotHistory;
use App\Services\TimesheetSnapshotRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class TimesheetSnapshotPrintController extends Controller
{
    /**
     * Impressão de uma versão histórica do espelho (F6.2d), somente leitura.
     *
     * Imprime os snapshots gravados juntos com o selecionado (mesma operação, origem e
     * funcionário) exatamente como armazenados: marcações, extra, atraso, ocorrência,
     * justificativas, jornada e tolerância vêm de `result` e `inputs`. Os totais são a soma
     * dos dias gravados. Nada é recalculado nem gravado; a única leitura do estado atual é a
     * coluna de comparação ("Situação atual"), feita por `capture()`, que não persiste.
     */
    public function __invoke(TimesheetSnapshot $timesheetSnapshot, TimesheetSnapshotHistory $history, TimesheetSnapshotRecorder $recorder): View
    {
        Gate::authorize('manage-point-justifications');

        $group = $history->printGroup($timesheetSnapshot);
        $later = $history->laterVersions($group);
        $current = $recorder->capture($timesheetSnapshot->employee, $group->map(fn (TimesheetSnapshot $snapshot) => $snapshot->date->toDateString())->all());

        $days = $group->map(function (TimesheetSnapshot $snapshot) use ($later, $current) {
            $described = TimesheetSnapshotHistory::describe($snapshot);
            $comparison = $described['integrity_problems'] === [] ? TimesheetSnapshotRecorder::compare($snapshot, $current[$snapshot->date->toDateString()]) : null;

            return [
                'snapshot' => $described,
                'result' => is_array($snapshot->result) ? $snapshot->result : [],
                'later' => $later[$snapshot->getKey()] ?? [],
                'current' => $comparison === null ? null : [
                    'result_changed' => $comparison['result_changed'],
                    'fields' => TimesheetSnapshotHistory::fieldLabels($comparison['changed_result_fields']),
                ],
            ];
        });

        $stored = $group->map(fn (TimesheetSnapshot $snapshot) => is_array($snapshot->result) ? $snapshot->result : []);
        $calculations = $group->map(fn (TimesheetSnapshot $snapshot) => $snapshot->inputs['calculation'] ?? []);
        $reference = $timesheetSnapshot->inputs['references']['employee'] ?? [];

        return view('timesheet-snapshot-print', [
            'selected' => TimesheetSnapshotHistory::describe($timesheetSnapshot),
            'days' => $days,
            'employee' => [
                'name' => $reference['name'] ?? null,
                'pis' => $reference['pis'] ?? null,
            ],
            'firstDate' => $group->first()->date,
            'lastDate' => $group->last()->date,
            'totals' => [
                'overtime' => TimesheetMirror::format((int) $stored->sum(fn (array $day) => (int) ($day['overtime_minutes'] ?? 0))),
                'delay' => TimesheetMirror::format((int) $stored->sum(fn (array $day) => (int) ($day['delay_minutes'] ?? 0))),
            ],
            'schedule' => TimesheetPrintController::scheduleSummary($calculations
                ->filter(fn (array $calculation) => is_array($calculation['schedule'] ?? null) && isset($calculation['day_of_week_iso']))
                ->mapWithKeys(fn (array $calculation) => [(int) $calculation['day_of_week_iso'] => $calculation['schedule']])
                ->all()),
            'tolerances' => $calculations->pluck('entry_tolerance_minutes')->filter(fn ($value) => $value !== null)->unique()->values()->all(),
            'company' => [
                'name' => filled(config('timesheet.company.name')) ? (string) config('timesheet.company.name') : null,
                'cnpj' => filled(config('timesheet.company.cnpj')) ? (string) config('timesheet.company.cnpj') : null,
            ],
        ]);
    }
}
