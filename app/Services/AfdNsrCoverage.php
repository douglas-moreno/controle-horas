<?php

namespace App\Services;

use App\Enums\AfdRecordType;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdImportRejection;
use App\Models\Point;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Cobertura individual das marcações (tipo 3) com NSR até o cursor: o cursor não prova
 * que cada NSR anterior foi gravado. Somente leitura.
 *
 * Cada NSR recebe exatamente uma categoria:
 * - `imported`: há ponto com o REP e o NSR (inclui recuperações, contadas também em
 *   `recovered`);
 * - `rejected`: rejeição registrada para o REP e o NSR;
 * - `legacy_verified`: prova posicional do importador legado (AfdLegacyAlignment);
 * - `pending`: pendência histórica conhecida (`afd_historical_nsrs`), não resolvida;
 * - `manual_retained` / `discarded_by_approval`: decisão documentada do RH;
 * - `withheld`: marcação retida por decisão do RH (classificação R), não importada, que
 *   o cursor já ultrapassou; aguarda decisão final e não é resolvida;
 * - `gap`: nenhuma cobertura válida.
 *
 * Bloqueiam: qualquer `gap` e qualquer inconsistência (pendência registrada para NSR que
 * tem outra cobertura, linha do AFD divergente do registro, decisão sem responsável,
 * justificativa ou data, recuperação sem o ponto). Pendências não bloqueiam, mas não
 * são resolvidas: aparecem como pendências.
 *
 * Retenção acima do cursor (`withheld_ahead`): registrada antes da importação, ainda não
 * alcançada. Não é inconsistência quando a decisão está documentada e, se o arquivo já
 * contém o NSR, a linha e o SHA-256 conferem; o arquivo que ainda não chega ao NSR não a
 * comprova (a importação confere quando chegar).
 *
 * A cobertura só alcança os registros presentes no arquivo. `scope` informa o primeiro e
 * o último NSR do arquivo e o intervalo até o cursor que ficou fora dele: um AFD parcial
 * (sem o início) não comprova esses NSRs. Um AFD terminado antes do cursor já é recusado
 * pelo ponto de partida (AfdImporter::startFor).
 */
class AfdNsrCoverage
{
    public function __construct(private AfdParser $parser, private AfdLegacyAlignment $alignment) {}

    /**
     * @return array{cursor: int, universe: int, scope: array{first_nsr: ?int, last_nsr: ?int, partial: bool, unchecked: list<array{0: int, 1: int}>}, counts: array<string, int>, pending_by_classification: array<string, int>, nsrs: array<string, list<int>>, withheld_ahead: list<int>, inconsistencies: list<string>, legacy_runs: ?array<string, mixed>, registry_available: bool, blocking: bool}
     */
    public function evaluate(string $path, string $repSerial, ?int $repId, int $cursor): array
    {
        $lines = $this->alignment->readLines($path);
        $universe = $this->universe($lines, $cursor);
        $inconsistencies = [];

        $imported = $universe === [] ? collect() : Point::query()->toBase()
            ->where('rep_serial', $repSerial)->whereBetween('nsr', [1, $cursor])
            ->pluck('id', 'nsr')->mapWithKeys(fn ($id, $nsr) => [(int) $nsr => (int) $id]);
        $rejected = $universe === [] || $repId === null ? collect() : AfdImportRejection::query()->toBase()
            ->where('afd_rep_id', $repId)->where('nsr', '<=', $cursor)
            ->pluck('nsr')->map(fn ($nsr) => (int) $nsr)->flip();

        try {
            $alignment = $universe === [] ? null : $this->alignment->align($lines);
        } catch (DomainException $exception) {
            $alignment = null;
            $inconsistencies[] = 'Prova do legado: '.$exception->getMessage().'.';
        }

        $verified = $alignment['verified'] ?? [];
        $registryAvailable = Schema::hasTable('afd_historical_nsrs');
        /** @var Collection<int, AfdHistoricalNsr> $registry */
        $registry = $registryAvailable ? AfdHistoricalNsr::query()->where('rep_serial', $repSerial)->get()->keyBy('nsr') : collect();
        $manualIds = $this->manualIds($registry);

        $categories = ['imported', 'recovered', 'rejected', 'legacy_verified', 'pending', 'manual_retained', 'discarded_by_approval', 'withheld', 'gap'];
        $nsrs = array_fill_keys($categories, []);
        $pendingByClassification = [];

        foreach ($universe as $nsr => $lineNumber) {
            $entry = $registry->get($nsr);

            if ($entry !== null && ($entry->afd_line !== $lineNumber || ! hash_equals($entry->afd_line_sha256, hash('sha256', $lines[$lineNumber])))) {
                $inconsistencies[] = 'NSR '.$nsr.': linha do AFD diferente da registrada na pendência histórica.';

                continue;
            }

            if ($imported->has($nsr)) {
                $nsrs['imported'][] = $nsr;

                if ($entry !== null && $entry->status === AfdHistoricalNsr::STATUS_RECOVERED && $entry->recovered_point_id === $imported->get($nsr)) {
                    $nsrs['recovered'][] = $nsr;
                } elseif ($entry !== null) {
                    $inconsistencies[] = 'NSR '.$nsr.': gravado com REP e NSR, mas registrado como '.$entry->status.'.';
                }

                continue;
            }

            $category = match (true) {
                $rejected->has($nsr) => 'rejected',
                isset($verified[$nsr]) => 'legacy_verified',
                default => null,
            };

            if ($category !== null) {
                $nsrs[$category][] = $nsr;

                if ($entry !== null) {
                    $inconsistencies[] = 'NSR '.$nsr.': registrado como pendência histórica ('.$entry->status.'), mas coberto por '.$category.'.';
                }

                continue;
            }

            if ($entry === null) {
                $nsrs['gap'][] = $nsr;

                continue;
            }

            $problem = $this->entryProblem($entry, $manualIds);

            if ($problem !== null) {
                $inconsistencies[] = 'NSR '.$nsr.': '.$problem;

                continue;
            }

            $nsrs[$entry->status][] = $nsr;

            if ($entry->status === AfdHistoricalNsr::STATUS_PENDING) {
                $pendingByClassification[$entry->classification] = ($pendingByClassification[$entry->classification] ?? 0) + 1;
            }
        }

        $scope = $this->scope($lines, $cursor);
        $withheldAhead = [];

        foreach ($registry->keys()->diff(array_keys($universe))->sort() as $nsr) {
            $entry = $registry->get($nsr);

            if ($entry->status !== AfdHistoricalNsr::STATUS_WITHHELD || $nsr <= $cursor) {
                $inconsistencies[] = 'NSR '.$nsr.': registrado como pendência histórica, mas não é marcação do AFD até o cursor '.$cursor.'.';

                continue;
            }

            $reached = $scope['last_nsr'] !== null && $nsr <= $scope['last_nsr'];
            $problem = $this->withheldProblem($entry, $reached ? ($lines[$entry->afd_line] ?? null) : null, $entry->afd_line, ! $reached);

            if ($problem !== null) {
                $inconsistencies[] = 'NSR '.$nsr.': '.$problem.'.';

                continue;
            }

            $withheldAhead[] = (int) $nsr;
        }

        ksort($pendingByClassification);

        return [
            'cursor' => $cursor,
            'universe' => count($universe),
            'scope' => $scope,
            'counts' => array_map('count', $nsrs),
            'pending_by_classification' => $pendingByClassification,
            'nsrs' => array_diff_key($nsrs, ['imported' => true, 'legacy_verified' => true]),
            'withheld_ahead' => $withheldAhead,
            'inconsistencies' => $inconsistencies,
            'legacy_runs' => $alignment['runs'] ?? null,
            'registry_available' => $registryAvailable,
            'blocking' => $nsrs['gap'] !== [] || $inconsistencies !== [],
        ];
    }

    /**
     * Motivo pelo qual a retenção por decisão do RH não vale para a linha, ou null:
     * classificação R, decisão documentada (responsável, justificativa, data e evidência)
     * e a marcação (tipo 3) com o NSR, a linha e o SHA-256 registrados. `$unreached`:
     * o arquivo ainda não contém o NSR e só a decisão é conferida.
     *
     * @param  ?string  $line  Linha sem o terminador.
     */
    public function withheldProblem(AfdHistoricalNsr $entry, ?string $line, int $lineNumber, bool $unreached = false): ?string
    {
        return match (true) {
            $entry->status !== AfdHistoricalNsr::STATUS_WITHHELD || $entry->classification !== AfdHistoricalNsr::CLASSIFICATION_WITHHELD => 'retenção com estado '.$entry->status.' e classificação '.$entry->classification,
            blank($entry->decided_by) || blank($entry->decision_justification) || $entry->decided_at === null || blank($entry->evidence) => 'retenção sem responsável, justificativa, data ou evidência da decisão do RH',
            $unreached => null,
            $line === null || $entry->afd_line !== $lineNumber || (int) substr($line, 0, 9) !== $entry->nsr || substr($line, 9, 1) !== AfdRecordType::Punch->value
                || ! hash_equals($entry->afd_line_sha256, hash('sha256', $line)) => 'linha do AFD diferente da registrada na retenção',
            default => null,
        };
    }

    /**
     * Primeiro e último NSR do arquivo e intervalo de NSR até o cursor anterior a ele.
     *
     * @param  array<int, string>  $lines
     * @return array{first_nsr: ?int, last_nsr: ?int, partial: bool, unchecked: list<array{0: int, 1: int}>}
     */
    private function scope(array $lines, int $cursor): array
    {
        $nsrs = collect($lines)
            ->filter(fn (string $line, int $lineNumber) => $lineNumber > 1 && $line !== '' && ! str_starts_with($line, '999999999'))
            ->map(fn (string $line) => (int) substr($line, 0, 9));
        $first = $nsrs->min();
        $last = $nsrs->max();

        $end = min($cursor, ($first ?? $cursor + 1) - 1);
        $unchecked = $end >= 1 ? [[1, $end]] : [];

        return ['first_nsr' => $first, 'last_nsr' => $last, 'partial' => $unchecked !== [], 'unchecked' => $unchecked];
    }

    /**
     * Marcações do arquivo com NSR até o cursor: NSR => número da linha.
     *
     * @param  array<int, string>  $lines
     * @return array<int, int>
     */
    private function universe(array $lines, int $cursor): array
    {
        $universe = [];

        foreach ($lines as $lineNumber => $line) {
            if ($line === '' || ! $this->alignment->isPunch($line, $lineNumber)) {
                continue;
            }

            $record = $this->parser->parse($line, $lineNumber);

            if ($record->type === AfdRecordType::Punch && $record->nsr !== null && $record->nsr <= $cursor) {
                $universe[$record->nsr] = $lineNumber;
            }
        }

        ksort($universe);

        return $universe;
    }

    /**
     * Ids, entre os citados nas decisões, de pontos manuais existentes.
     *
     * @param  Collection<int, AfdHistoricalNsr>  $registry
     * @return Collection<int, int>
     */
    private function manualIds(Collection $registry): Collection
    {
        $ids = $registry->pluck('related_manual_point_id')->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : Point::query()->toBase()->where('type', 'manual')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->flip();
    }

    /**
     * @param  Collection<int, int>  $manualIds
     */
    private function entryProblem(AfdHistoricalNsr $entry, Collection $manualIds): ?string
    {
        $decided = filled($entry->decided_by) && filled($entry->decision_justification) && $entry->decided_at !== null;

        return match ($entry->status) {
            AfdHistoricalNsr::STATUS_PENDING => null,
            AfdHistoricalNsr::STATUS_MANUAL_RETAINED => match (true) {
                ! $decided => 'manutenção do ponto manual sem responsável, justificativa ou data.',
                ! $manualIds->has($entry->related_manual_point_id) => 'manutenção de ponto manual inexistente.',
                default => null,
            },
            AfdHistoricalNsr::STATUS_DISCARDED => $decided ? null : 'descarte sem responsável, justificativa ou data.',
            AfdHistoricalNsr::STATUS_RECOVERED => 'registrado como recuperado, mas sem ponto com o REP e o NSR.',
            AfdHistoricalNsr::STATUS_WITHHELD => ($problem = $this->withheldProblem($entry, null, $entry->afd_line, true)) === null ? null : $problem.'.',
            default => 'estado inválido ('.$entry->status.').',
        };
    }
}
