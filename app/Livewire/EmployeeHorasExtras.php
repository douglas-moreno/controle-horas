<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Services\OvertimeCalculator;
use App\Services\TimesheetMirror;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class EmployeeHorasExtras extends Component
{
    use WireUiActions;

    public $employee;

    /**
     * Dias do espelho do período, montados pelo TimesheetMirror (mesmos dados da impressão).
     * Somente campos oficiais: os dados da regra alternativa não entram no estado da tela.
     *
     * @var list<array<string, mixed>>
     */
    public array $days = [];

    public $mes;

    public $ano;

    public $totalWeekdayMinutes = 0;

    public $totalSaturdayMinutes = 0;

    public $totalSundayMinutes = 0;

    public $totalHolidayMinutes = 0;

    public $totalExtraMinutes = 0;

    public $totalWeekdayHours = '00:00';

    public $totalSaturdayHours = '00:00';

    public $totalSundayHours = '00:00';

    public $totalHolidayHours = '00:00';

    public $totalExtraHours = '00:00';

    public int $totalDelayMinutes = 0;

    public string $totalDelayHours = '00:00';

    public int $reviewDays = 0;

    #[Url()]
    public $startDate;

    #[Url()]
    public $endDate;

    public function periodoAnterior()
    {
        $this->mes--;
        if ($this->mes < 1) {
            $this->mes = 12;
            $this->ano--;
        }
        $this->startDate = Carbon::create($this->ano, $this->mes - 1, 26)->format('Y-m-d');
        $this->endDate = Carbon::create($this->ano, $this->mes, 25)->format('Y-m-d');
        $this->updatePoints();
    }

    public function periodoProximo()
    {
        $this->mes++;
        if ($this->mes > 12) {
            $this->mes = 1;
            $this->ano++;
        }
        $this->startDate = Carbon::create($this->ano, $this->mes - 1, 26)->format('Y-m-d');
        $this->endDate = Carbon::create($this->ano, $this->mes, 25)->format('Y-m-d');
        $this->updatePoints();
    }

    public function mount($employee)
    {
        $this->employee = Employee::query()->findOrFail($employee);

        // Pegar o mês e ano atuais
        $this->mes = now()->month;
        $this->ano = now()->year;

        // Monta as datas de início e fim do mês atual
        if (! $this->startDate) {
            $this->startDate = Carbon::create($this->ano, $this->mes - 1, 26)->format('Y-m-d');
        }
        if (! $this->endDate) {
            $this->endDate = Carbon::create($this->ano, $this->mes, 25)->format('Y-m-d');
        }

        $this->updatePoints();
    }

    public function updatePoints()
    {
        $start = $this->parseDate($this->startDate);
        $end = $this->parseDate($this->endDate);

        if ($start === null || $end === null) {
            $this->days = [];
            $this->applyTotals(app(TimesheetMirror::class)->build(collect(), now(), now()->subDay())['totals']);

            return;
        }

        $mirror = app(TimesheetMirror::class)->forEmployee($this->employee, $start, $end);

        $this->days = array_map(fn (array $day) => [
            ...Arr::except($day, 'alternative'),
            'day_type' => $day['day_type']->value,
            'status' => $day['status']->value,
        ], $mirror['days']);

        $this->applyTotals($mirror['totals']);
    }

    // Atualiza automaticamente quando startDate ou endDate mudarem
    public function updated($property)
    {
        if (in_array($property, ['startDate', 'endDate'])) {
            $this->updatePoints();
        }
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function applyTotals(array $totals): void
    {
        $this->totalWeekdayMinutes = $totals['business_day_overtime_minutes'];
        $this->totalSaturdayMinutes = $totals['saturday_overtime_minutes'];
        $this->totalSundayMinutes = $totals['sunday_overtime_minutes'];
        $this->totalHolidayMinutes = $totals['holiday_overtime_minutes'];
        $this->totalExtraMinutes = $totals['overtime_minutes'];

        $this->totalWeekdayHours = OvertimeCalculator::format($this->totalWeekdayMinutes);
        $this->totalSaturdayHours = OvertimeCalculator::format($this->totalSaturdayMinutes);
        $this->totalSundayHours = OvertimeCalculator::format($this->totalSundayMinutes);
        $this->totalHolidayHours = OvertimeCalculator::format($this->totalHolidayMinutes);
        $this->totalExtraHours = $totals['overtime'];

        $this->totalDelayMinutes = $totals['delay_minutes'];
        $this->totalDelayHours = $totals['delay'];
        $this->reviewDays = $totals['review_days'];
    }

    /**
     * Aceita Y-m-d e, como fallback, outros formatos que o seletor de datas possa enviar.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $value)->startOfDay();
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        }
    }

    public function render()
    {
        return view('livewire.employee-horas-extras');
    }
}
