<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * F5.3 (D10). No MySQL o DDL encerra a transação do teste com commit implícito: o teste
 * não cria nada antes do DDL, remove o que cria e sempre restaura a coluna.
 */
test('the skipped_justified migration is reversible and fills previous runs with zero', function () {
    $migration = require database_path('migrations/2026_11_20_232926_add_skipped_justified_to_benefit_suggestion_runs_table.php');
    $periodId = null;

    try {
        $migration->down();

        expect(Schema::hasColumn('benefit_suggestion_runs', 'skipped_justified'))->toBeFalse()
            ->and(Schema::hasColumn('benefit_suggestion_runs', 'skipped_covered'))->toBeTrue();

        $periodId = DB::table('benefit_periods')->insertGetId([
            'competence' => '2031-10-01',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::table('benefit_suggestion_runs')->insertGetId([
            'benefit_period_id' => $periodId,
            'last_imported_on' => '2031-09-29',
            'horizon_on' => '2031-09-28',
            'processed_from' => '2031-09-01',
            'processed_to' => '2031-09-28',
            'created' => 3,
            'skipped_existing' => 2,
            'skipped_covered' => 1,
            'skipped_unobserved_days' => 4,
        ]);
        $before = (array) DB::table('benefit_suggestion_runs')->find($runId);

        $migration->up();

        $after = (array) DB::table('benefit_suggestion_runs')->find($runId);
        $column = collect(Schema::getColumns('benefit_suggestion_runs'))->firstWhere('name', 'skipped_justified');
        $columnNames = Schema::getColumnListing('benefit_suggestion_runs');

        expect($after['skipped_justified'])->toBe(0)
            ->and(collect($after)->except('skipped_justified')->all())->toEqual($before)
            ->and($column['type'])->toBe('int unsigned')
            ->and($column['nullable'])->toBeFalse()
            ->and((string) $column['default'])->toBe('0')
            ->and(array_search('skipped_justified', $columnNames, true))->toBe(array_search('skipped_covered', $columnNames, true) + 1);
    } finally {
        if (! Schema::hasColumn('benefit_suggestion_runs', 'skipped_justified')) {
            $migration->up();
        }

        if ($periodId !== null) {
            DB::table('benefit_periods')->where('id', $periodId)->delete();
        }
    }

    expect(DB::table('benefit_periods')->where('competence', '2031-10-01')->exists())->toBeFalse();
});
