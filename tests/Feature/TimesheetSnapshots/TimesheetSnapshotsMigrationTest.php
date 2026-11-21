<?php

use App\Enums\TimesheetSnapshotSource;
use App\Models\Employee;
use App\Models\TimesheetSnapshot;
use App\Services\TimesheetSnapshotRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * F6.2a. No MySQL o DDL encerra a transação do teste com commit implícito: o teste não
 * cria dados e sempre restaura a tabela.
 */
test('the timesheet_snapshots migration is reversible and creates the versioning indexes', function () {
    $migration = require database_path('migrations/2026_11_21_032611_create_timesheet_snapshots_table.php');

    try {
        $migration->down();

        expect(Schema::hasTable('timesheet_snapshots'))->toBeFalse();

        $migration->up();

        $indexes = collect(Schema::getIndexes('timesheet_snapshots'))->keyBy('name');
        $foreignKeys = collect(Schema::getForeignKeys('timesheet_snapshots'))->mapWithKeys(fn (array $key) => [$key['columns'][0] => [$key['foreign_table'], strtolower((string) $key['on_delete'])]]);

        expect(Schema::getColumnListing('timesheet_snapshots'))->toBe([
            'id', 'employee_id', 'date', 'sequence', 'source', 'competence', 'benefit_period_id', 'point_justification_history_id',
            'operation_uuid', 'schema_version', 'rule_version', 'rules_hash', 'inputs', 'inputs_hash', 'result', 'result_hash',
            'record_hash', 'reason', 'created_by', 'created_by_name', 'created_at',
        ])
            ->and($indexes['timesheet_snapshots_version_unique'])->toMatchArray(['columns' => ['employee_id', 'date', 'sequence'], 'unique' => true])
            ->and($indexes['timesheet_snapshots_source_index']['columns'])->toBe(['employee_id', 'date', 'source'])
            ->and($indexes['timesheet_snapshots_competence_index']['columns'])->toBe(['competence', 'employee_id'])
            ->and($foreignKeys->all())->toEqual([
                'employee_id' => ['employees', 'restrict'],
                'benefit_period_id' => ['benefit_periods', 'restrict'],
                'point_justification_history_id' => ['point_justification_histories', 'restrict'],
                'created_by' => ['users', 'set null'],
            ]);
    } finally {
        if (! Schema::hasTable('timesheet_snapshots')) {
            $migration->up();
        }
    }
});

/**
 * Com snapshots gravados, o rollback é recusado antes de qualquer DDL: nada é confirmado
 * implicitamente e a transação do teste desfaz os dados no fim.
 */
test('the rollback is refused and keeps the history when snapshots exist', function () {
    $migration = require database_path('migrations/2026_11_21_032611_create_timesheet_snapshots_table.php');
    app(TimesheetSnapshotRecorder::class)->record(Employee::factory()->create(), ['2026-09-15', '2026-09-16'], TimesheetSnapshotSource::Recalculation, null);
    $rows = DB::table('timesheet_snapshots')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Rollback recusado: timesheet_snapshots contém o histórico permanente')
        ->and(Schema::hasTable('timesheet_snapshots'))->toBeTrue()
        ->and(DB::table('timesheet_snapshots')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($rows)
        ->and(TimesheetSnapshot::query()->count())->toBe(2)
        ->and(DB::transactionLevel())->toBeGreaterThan(0);
});

test('the rollback with an empty table drops it', function () {
    $migration = require database_path('migrations/2026_11_21_032611_create_timesheet_snapshots_table.php');

    try {
        expect(TimesheetSnapshot::query()->exists())->toBeFalse();

        $migration->down();

        expect(Schema::hasTable('timesheet_snapshots'))->toBeFalse();
    } finally {
        if (! Schema::hasTable('timesheet_snapshots')) {
            $migration->up();
        }
    }
});
