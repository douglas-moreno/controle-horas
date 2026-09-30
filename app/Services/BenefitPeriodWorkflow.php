<?php

namespace App\Services;

use App\Enums\BenefitPeriodStatus;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodStatusChange;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida da competência: Open → Calculated → Closed, com reabertura para Open.
 *
 * Implementado: criação em sequência, cálculo (Open/Calculated → Calculated),
 * invalidação da prévia (Calculated → Open) e exclusão. Fechamento e reabertura
 * entram na fase seguinte e devem usar changeStatus() para manter o histórico.
 * Violações de regra lançam DomainException com mensagem para o usuário.
 */
class BenefitPeriodWorkflow
{
    public function __construct(private BenefitPeriodCalculator $calculator) {}

    /**
     * Cria a próxima competência: o mês seguinte à última existente ou, quando não há
     * nenhuma, a primeira competência informada explicitamente.
     *
     * @param  CarbonImmutable|null  $firstCompetence  qualquer dia do mês da primeira competência
     *
     * @throws DomainException quando a primeira competência não foi informada ou não é a próxima
     */
    public function createNext(?CarbonImmutable $firstCompetence = null): BenefitPeriod
    {
        try {
            return DB::transaction(function () use ($firstCompetence) {
                $latest = BenefitPeriod::query()->orderByDesc('competence')->lockForUpdate()->first();

                $competence = $this->nextCompetence($latest, $firstCompetence?->startOfMonth());

                $period = new BenefitPeriod(['competence' => $competence]);
                $period->status = BenefitPeriodStatus::Open;
                $period->created_by = auth()->id();
                $period->save();

                $this->recordStatusChange($period, null, BenefitPeriodStatus::Open, 'Competência criada.');

                return $period;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Esta competência já foi criada. Atualize a lista e tente novamente.');
        }
    }

    /**
     * Calcula (ou recalcula) a prévia da competência e a deixa em Calculated.
     * Exige competência não fechada e, se existir, a competência anterior fechada.
     *
     * @return array{issues: list<array{severity: string, employee_id: ?int, benefit_type: ?string, message: string}>}
     *
     * @throws DomainException quando o cálculo não é permitido ou está bloqueado
     */
    public function calculate(BenefitPeriod $period): array
    {
        return DB::transaction(function () use ($period) {
            $period = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status === BenefitPeriodStatus::Closed) {
                throw new DomainException('A competência '.$period->competence->format('m/Y').' está fechada e só pode ser recalculada após reabertura.');
            }

            $previous = BenefitPeriod::query()
                ->where('competence', $period->referenceDate()->subMonthNoOverflow()->toDateString())
                ->first();

            if ($previous !== null && $previous->status !== BenefitPeriodStatus::Closed) {
                throw new DomainException('A competência anterior ('.$previous->competence->format('m/Y').') precisa estar fechada antes do cálculo de '.$period->competence->format('m/Y').'.');
            }

            $wasCalculated = $period->status === BenefitPeriodStatus::Calculated;

            $result = $this->calculator->calculate($period);

            $period->calculated_at = now();
            $period->calculated_by = auth()->id();

            $this->changeStatus($period, BenefitPeriodStatus::Calculated, $wasCalculated ? 'Prévia recalculada.' : 'Prévia calculada.');

            return $result;
        });
    }

    /**
     * Descarta a prévia de uma competência calculada e a devolve para Open.
     * Uma competência aberta permanece como está; uma fechada exige reabertura (F9).
     *
     * @return bool true quando houve mudança de status
     *
     * @throws DomainException para competência fechada
     */
    public function invalidate(BenefitPeriod $period, string $reason = 'Prévia descartada por alteração na competência.'): bool
    {
        return DB::transaction(function () use ($period, $reason) {
            $period = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status === BenefitPeriodStatus::Open) {
                return false;
            }

            if ($period->status === BenefitPeriodStatus::Closed) {
                throw new DomainException('A competência '.$period->competence->format('m/Y').' está fechada e só pode ser alterada após reabertura.');
            }

            $period->periodEmployees()->delete();

            $period->business_days = null;
            $period->calculated_at = null;
            $period->calculated_by = null;

            $this->changeStatus($period, BenefitPeriodStatus::Open, $reason);

            return true;
        });
    }

    /**
     * Motivo pelo qual a competência não pode ser excluída, ou null quando pode.
     */
    public function deletionBlocker(BenefitPeriod $period): ?string
    {
        if ($period->status !== BenefitPeriodStatus::Open) {
            return 'Somente competências abertas podem ser excluídas.';
        }

        if (BenefitPeriod::query()->where('competence', '>', $period->competence->toDateString())->exists()) {
            return 'Somente a competência mais recente pode ser excluída.';
        }

        if ($period->periodEmployees()->exists()) {
            return 'A competência possui apuração gerada.';
        }

        if ($period->adjustments()->exists()) {
            return 'A competência possui ajustes lançados.';
        }

        return null;
    }

    /**
     * Exclui fisicamente uma competência aberta, sem apuração, sem ajustes e mais
     * recente. O histórico de status é removido em cascata pelo banco.
     *
     * @throws DomainException quando a exclusão não é permitida
     */
    public function delete(BenefitPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            BenefitPeriod::query()->orderByDesc('competence')->lockForUpdate()->first();
            $period = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

            $blocker = $this->deletionBlocker($period);

            if ($blocker !== null) {
                throw new DomainException('A competência '.$period->competence->format('m/Y').' não pode ser excluída. '.$blocker);
            }

            $period->delete();
        });
    }

    /**
     * Aplica a transição e registra o histórico. Deve ser chamado dentro de uma transação.
     */
    private function changeStatus(BenefitPeriod $period, BenefitPeriodStatus $to, ?string $reason = null): void
    {
        $from = $period->status;

        $period->status = $to;
        $period->save();

        $this->recordStatusChange($period, $from, $to, $reason);
    }

    private function recordStatusChange(BenefitPeriod $period, ?BenefitPeriodStatus $from, BenefitPeriodStatus $to, ?string $reason): void
    {
        $statusChange = new BenefitPeriodStatusChange([
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
        ]);
        $statusChange->benefitPeriod()->associate($period);
        $statusChange->user_id = auth()->id();
        $statusChange->save();
    }

    private function nextCompetence(?BenefitPeriod $latest, ?CarbonImmutable $firstCompetence): CarbonImmutable
    {
        if ($latest === null) {
            if ($firstCompetence === null) {
                throw new DomainException('Informe o mês da primeira competência.');
            }

            return $firstCompetence;
        }

        $next = $latest->competence->startOfMonth()->addMonthNoOverflow();

        if ($firstCompetence !== null && ! $firstCompetence->equalTo($next)) {
            throw new DomainException('A próxima competência deve ser '.$next->format('m/Y').'.');
        }

        return $next;
    }
}
