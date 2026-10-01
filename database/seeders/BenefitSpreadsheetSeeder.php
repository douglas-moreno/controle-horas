<?php

namespace Database\Seeders;

use App\Services\BenefitSpreadsheetImporter;
use App\Services\Money;
use Illuminate\Database\Seeder;

/**
 * Carga inicial dos benefícios a partir da planilha de controle de VT/VR: catálogo,
 * competência, elegibilidade, itinerários e ajustes da competência da planilha.
 *
 * A planilha contém dados pessoais e não é versionada: copie-a para PATH (pasta
 * storage/app/private, ignorada pelo git) antes de executar. Funcionários são
 * vinculados pelo PIS da coluna A; quem já possui elegibilidade ou itinerário é ignorado.
 */
class BenefitSpreadsheetSeeder extends Seeder
{
    public const PATH = 'app/private/beneficios/controle-vt-vr.xlsx';

    /**
     * Run the database seeds.
     */
    public function run(BenefitSpreadsheetImporter $importer): void
    {
        $this->call(BenefitCatalogSeeder::class);

        $report = $importer->import(storage_path(self::PATH));

        $this->command->info('Competência '.$report['competence'].': '.count($report['imported']).' funcionário(s) importado(s).');
        $this->command->line('Elegibilidades: '.$report['benefits'].' · Trechos: '.$report['routes'].' · Ajustes: '.$report['adjustments']);

        foreach ($report['skipped'] as $skipped) {
            $this->command->warn('Linha '.$skipped['line'].' ignorada: '.$skipped['reason']);
        }

        $this->command->line('Totais esperados para as linhas importadas (colunas AA/AB da planilha): VT '
            .Money::fromCents($report['expected_vt_cents']).' · VR+VD '.Money::fromCents($report['expected_vr_vd_cents'])
            .' · Total '.Money::fromCents($report['expected_vt_cents'] + $report['expected_vr_vd_cents']));
    }
}
