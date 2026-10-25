<?php

namespace App\Livewire;

use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\Employee;
use App\Models\Point;
use Livewire\Attributes\Locked;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class EmployeePointsEdit extends Component
{
    use WireUiActions;

    #[Locked]
    public $employee;

    #[Locked]
    public $date;

    public $points;

    public $showAddPointModal = false;

    public $newPointTime;

    public function addPoint()
    {
        $this->showAddPointModal = true;
    }

    public function saveNewPoint()
    {
        $this->validate([
            'newPointTime' => 'required|date_format:H:i',
        ]);

        $newPoint = Point::create([
            'pis' => $this->employee->pis,
            'date' => $this->date,
            'time' => $this->newPointTime.':00',
            'type' => 'manual',
        ]);

        $this->points->push($newPoint);
        $this->showAddPointModal = false;
        $this->newPointTime = null;

        $this->notification()->success(
            $title = 'Ponto Adicionado',
            $description = 'O ponto foi adicionado com sucesso.'
        );
    }

    /**
     * Exclui uma batida manual do funcionário e da data da tela. A verificação é feita
     * no servidor: batidas de outro funcionário ou data, batidas importadas do relógio e
     * batidas manuais mantidas por decisão do RH (pendência histórica do AFD) e batidas
     * referenciadas por anotação do RH (original de uma duplicada) são recusadas mesmo quando
     * o método é chamado diretamente.
     */
    public function removePoint($pointId)
    {
        $point = Point::query()
            ->where('pis', $this->employee->pis)
            ->whereDate('date', $this->date)
            ->find($pointId);

        if ($point === null) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = 'O ponto não pertence a este funcionário nesta data.'
            );

            return;
        }

        if ($point->type === 'importado') {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = 'Batidas importadas do relógio não podem ser excluídas.'
            );

            return;
        }

        if ($point->isRetainedByHrDecision()) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = Point::RETAINED_BY_HR_MESSAGE
            );

            return;
        }

        if ($point->isAnnotatedByHr()) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = Point::ANNOTATED_BY_HR_MESSAGE
            );

            return;
        }

        $point->delete();
        $this->points = $this->points->filter(function ($p) use ($point) {
            return $p->id !== $point->id;
        });

        $this->notification()->success(
            $title = 'Ponto Removido',
            $description = 'O ponto foi removido com sucesso.'
        );
    }

    public function mount($employee, $date)
    {
        $this->employee = Employee::find($employee);
        $this->date = $date;

        $this->points = Point::where('pis', $this->employee->pis)
            ->whereDate('date', $date)
            ->orderBy('time', 'asc')
            ->get();
    }

    public function render()
    {
        return view('livewire.employee-points-edit', [
            'employee' => $this->employee,
            'date' => $this->date,
            'points' => $this->points,
            'annotationLabels' => AfdPointDecision::activeLabels(collect($this->points)->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'retainedPointIds' => AfdHistoricalNsr::query()
                ->whereIn('related_manual_point_id', collect($this->points)->pluck('id'))
                ->where('status', AfdHistoricalNsr::STATUS_MANUAL_RETAINED)
                ->pluck('related_manual_point_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ]);
    }
}
