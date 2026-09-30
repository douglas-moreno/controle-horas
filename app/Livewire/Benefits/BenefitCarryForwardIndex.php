<?php

namespace App\Livewire\Benefits;

use App\Services\BenefitCarryForwardReport;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Pendências de saldo negativo (somente leitura). O saldo é aplicado pelo cálculo
 * da competência seguinte; esta tela apenas mostra a situação derivada.
 */
class BenefitCarryForwardIndex extends Component
{
    public string $filterStatus = '';

    public function render(BenefitCarryForwardReport $report): View
    {
        $rows = collect($report->rows());

        return view('livewire.benefits.benefit-carry-forward-index', [
            'rows' => $this->filterStatus === '' ? $rows : $rows->where('status', $this->filterStatus)->values(),
            'statusOptions' => collect([BenefitCarryForwardReport::AWAITING, BenefitCarryForwardReport::APPLIED, BenefitCarryForwardReport::NOT_APPLIED])
                ->map(fn (string $status) => ['id' => $status, 'name' => $status])
                ->all(),
        ]);
    }
}
