<?php

namespace App\Services;

use App\Enums\AdjustmentStatus;
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
 * Criação em sequência, cálculo (Open/Calculated → Calculated), fechamento
 * (Calculated → Closed, sempre recalculando), reabertura (Closed → Open, com motivo
 * e cascata sobre a prévia de M+1), invalidação da prévia (Calculated → Open) e
 * exclusão. Toda transição passa por changeStatus() e fica no histórico.
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

            $previous = $this->previousPeriod($period);

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
     * Impedimentos conhecidos para fechar a competência na situação atual, sem gravar
     * nada. O fechamento recalcula e confere de novo dentro da transação.
     *
     * @return list<string>
     */
    public function closeBlockers(BenefitPeriod $period): array
    {
        if ($period->status !== BenefitPeriodStatus::Calculated) {
            return ['Somente competências calculadas podem ser fechadas. Calcule a prévia antes de fechar.'];
        }

        $blockers = [];

        $previous = $this->previousPeriod($period);

        if ($previous !== null && $previous->status !== BenefitPeriodStatus::Closed) {
            $blockers[] = 'A competência anterior ('.$previous->competence->format('m/Y').') precisa estar fechada.';
        }

        $pending = $period->adjustments()->where('status', AdjustmentStatus::Pending)->count();

        if ($pending > 0) {
            $blockers[] = $pending.' ajuste(s) aguardando revisão. Confirme ou rejeite os ajustes pendentes antes de fechar.';
        }

        foreach ($this->calculator->issues($period) as $issue) {
            if ($issue['severity'] === 'blocking') {
                $blockers[] = $issue['message'];
            }
        }

        return $blockers;
    }

    /**
     * Fecha a competência: recalcula na mesma transação, confere os impedimentos e
     * congela o snapshot no estado atual das configurações.
     *
     * @return array{issues: list<array{severity: string, employee_id: ?int, benefit_type: ?string, message: string}>}
     *
     * @throws DomainException quando há impedimentos; nada é alterado
     */
    public function close(BenefitPeriod $period): array
    {
        return DB::transaction(function () use ($period) {
            $period = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status !== BenefitPeriodStatus::Calculated) {
                throw new DomainException('Somente competências calculadas podem ser fechadas. Calcule a prévia antes de fechar.');
            }

            $previous = $this->previousPeriod($period);

            if ($previous !== null && $previous->status !== BenefitPeriodStatus::Closed) {
                throw new DomainException('A competência anterior ('.$previous->competence->format('m/Y').') precisa estar fechada.');
            }

            $pending = $period->adjustments()->where('status', AdjustmentStatus::Pending)->count();

            if ($pending > 0) {
                throw new DomainException('Não é possível fechar a competência. '.$pending.' ajuste(s) aguardando revisão.');
            }

            $result = $this->calculator->calculate($period);

            $blocking = array_column(array_filter($result['issues'], fn (array $issue) => $issue['severity'] === 'blocking'), 'message');

            if ($blocking !== []) {
                throw new DomainException('Não é possível fechar a competência. '.implode(' ', $blocking));
            }

            $period->calculated_at = now();
            $period->calculated_by = auth()->id();
            $period->closed_at = now();
            $period->closed_by = auth()->id();

            $this->changeStatus($period, BenefitPeriodStatus::Closed, 'Competência fechada.');

            return $result;
        });
    }

    /**
     * Reabre uma competência fechada: volta para Open com motivo obrigatório e descarta
     * o snapshot. Se M+1 estiver calculada, a prévia de M+1 é descartada antes (ela pode
     * ter aplicado o saldo de M). Com M+1 fechada, a reabertura é recusada.
     *
     * @throws DomainException
     */
    public function reopen(BenefitPeriod $period, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Informe o motivo da reabertura.');
        }

        DB::transaction(function () use ($period, $reason) {
            $period = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status !== BenefitPeriodStatus::Closed) {
                throw new DomainException('Somente competências fechadas podem ser reabertas.');
            }

            $next = BenefitPeriod::query()
                ->where('competence', $period->referenceDate()->addMonthNoOverflow()->toDateString())
                ->lockForUpdate()
                ->first();

            if ($next?->status === BenefitPeriodStatus::Closed) {
                throw new DomainException('A competência '.$next->competence->format('m/Y').' já está fechada. Reabra-a antes de reabrir '.$period->competence->format('m/Y').'.');
            }

            if ($next?->status === BenefitPeriodStatus::Calculated) {
                $this->invalidate($next, 'Prévia descartada pela reabertura de '.$period->competence->format('m/Y').'.');
            }

            $this->calculator->discardSnapshot($period);

            $period->business_days = null;
            $period->calculated_at = null;
            $period->calculated_by = null;
            $period->closed_at = null;
            $period->closed_by = null;

            $this->changeStatus($period, BenefitPeriodStatus::Open, $reason);
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

    private function previousPeriod(BenefitPeriod $period): ?BenefitPeriod
    {
        return BenefitPeriod::query()
            ->where('competence', $period->referenceDate()->subMonthNoOverflow()->toDateString())
            ->first();
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
