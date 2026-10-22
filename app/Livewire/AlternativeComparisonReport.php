<?php

namespace App\Livewire;

use App\Services\AlternativeComparison;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tela de comparação entre a regra oficial e a alternativa (fase 7.5): totais por
 * código de funcionário e dias divergentes. Os valores vêm do AlternativeComparison;
 * a tela não calcula. Com a regra alternativa desligada, não exibe dados.
 */
class AlternativeComparisonReport extends Component
{
    public int $mes;

    public int $ano;

    #[Url]
    public string $startDate = '';

    #[Url]
    public string $endDate = '';

    public bool $onlyDivergent = true;

    public function mount(): void
    {
        $this->mes = now()->month;
        $this->ano = now()->year;

        if ($this->startDate === '' || $this->endDate === '') {
            $this->setPeriodFromMonth($this->ano, $this->mes);
        }
    }

    public function periodoAnterior(): void
    {
        $this->mes--;
        if ($this->mes < 1) {
            $this->mes = 12;
            $this->ano--;
        }
        $this->setPeriodFromMonth($this->ano, $this->mes);
    }

    public function periodoProximo(): void
    {
        $this->mes++;
        if ($this->mes > 12) {
            $this->mes = 1;
            $this->ano++;
        }
        $this->setPeriodFromMonth($this->ano, $this->mes);
    }

    private function setPeriodFromMonth(int $ano, int $mes): void
    {
        $this->startDate = Carbon::create($ano, $mes - 1, 26)->format('Y-m-d');
        $this->endDate = Carbon::create($ano, $mes, 25)->format('Y-m-d');
    }

    /**
     * Comparação do período, ou null quando as datas são inválidas ou invertidas.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function comparison(): ?array
    {
        $start = $this->parseDate($this->startDate);
        $end = $this->parseDate($this->endDate);

        if ($start === null || $end === null || $start->gt($end)) {
            return null;
        }

        return app(AlternativeComparison::class)->compare($start, $end, 'screen');
    }

    public function exportUrl(): ?string
    {
        if ($this->comparison === null || ! $this->comparison['enabled']) {
            return null;
        }

        return route('reports.alternative-comparison.csv', [
            'start' => $this->comparison['start'],
            'end' => $this->comparison['end'],
        ]);
    }

    /**
     * Aceita Y-m-d e, como fallback, outros formatos que o seletor de datas possa enviar.
     */
    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    public function render(): View
    {
        $days = collect($this->comparison['days'] ?? []);

        return view('livewire.alternative-comparison-report', [
            'days' => $this->onlyDivergent ? $days->where('divergent', true)->values() : $days,
            'exportUrl' => $this->exportUrl(),
        ]);
    }
}
