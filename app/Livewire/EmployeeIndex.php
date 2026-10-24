<?php

namespace App\Livewire;

use App\Enums\AfdRejectionReason;
use App\Models\AfdImport;
use App\Models\Employee;
use App\Services\AfdImporter;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;
use WireUi\Traits\WireUiActions;

class EmployeeIndex extends Component
{
    use WireUiActions;
    use WithFileUploads;
    use WithPagination;

    public $search;

    public $filterEmployee = 'without_recision_date';

    public $file;

    public $importing = false;

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFilterEmployee()
    {
        $this->resetPage();
    }

    public function destroy(Employee $employee)
    {
        if ($employee->hasBenefitHistory()) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = 'O funcionário possui histórico de benefícios. Informe a data de rescisão e encerre as vigências de benefício em vez de excluí-lo.'
            );

            return;
        }

        $employee->delete();

        $this->notification()->success(
            $title = 'Funcionário Excluído',
            $description = 'O funcionário foi excluído com sucesso.'
        );
    }

    public function horasExtras(Employee $employee)
    {
        return redirect()->route('employees.horas-extras', ['employee' => $employee->id]);
    }

    public function importPoints(AfdImporter $importer)
    {
        try {
            ini_set('memory_limit', '512M');
            $this->importing = true;

            $maxKb = (int) config('livewire.upload_max_filesize') * 1024;

            $this->validate([
                'file' => 'required|file|mimetypes:text/plain|max:'.$maxKb,
            ]);

            if (! $this->file) {
                return;
            }

            $result = $importer->import($this->file->path(), auth()->id());

            $this->notification()->success(
                $title = 'Importação Concluída',
                $description = $this->importSummary($result)
            );

        } catch (DomainException|ValidationException $e) {
            $this->notification()->error(
                $title = 'Erro na Importação',
                $description = $e->getMessage()
            );
        } catch (Throwable $e) {
            Log::error('Falha inesperada na importação do AFD.', ['exception' => $e::class]);

            $this->notification()->error(
                $title = 'Erro na Importação',
                $description = AfdImporter::FAILURE_MESSAGE
            );
        } finally {
            $this->importing = false;
            $this->file = null;
        }
    }

    /**
     * Resumo da importação sem dados pessoais: totais, coincidências com batidas manuais
     * (contadas por batida manual), NSRs rejeitados e transição.
     *
     * @param  array<string, mixed>  $result
     */
    private function importSummary(array $result): string
    {
        $lines = [
            $result['imported'].' marcação(ões) importada(s); '.$result['existing'].' já existente(s); '
                .$result['already_processed'].' já processada(s) em importações anteriores.',
            $result['manual_coincidences'].' batida(s) manual(is) com o mesmo PIS, data e minuto de uma marcação importada (verificar duplicidade no espelho).',
            'Registros ignorados (não são marcações): '.$result['ignored']['company'].' de empresa, '
                .$result['ignored']['clock_adjustment'].' de ajuste do relógio e '.$result['ignored']['employee'].' de empregado, além do cabeçalho e do trailer.',
        ];

        if ($result['rejected'] > 0) {
            $nsrs = collect($result['rejections'])->take(20)
                ->map(fn (array $rejection) => $rejection['nsr'].' ('.AfdRejectionReason::from($rejection['reason'])->label().')')
                ->implode(', ');
            $lines[] = $result['rejected'].' marcação(ões) inválida(s), não importada(s): NSR '.$nsrs.($result['rejected'] > 20 ? '…' : '').'.';
        }

        if ($result['withheld'] > 0) {
            $lines[] = $result['withheld'].' marcação(ões) retida(s) por decisão do RH, não importada(s): NSR '.implode(', ', array_slice($result['withheld_nsrs'], 0, 20)).'.';
        }

        if ($result['transition'] === AfdImport::TRANSITION_LEGACY) {
            $lines[] = 'Transição do controle legado: última importação anterior até a linha '.$result['legacy_line_number'].'; importação a partir do NSR '.($result['cursor_before'] + 1).'.';
        }

        $lines[] = 'Processado até o NSR '.$result['cursor_after'].'.';

        return implode(' ', $lines);
    }

    public function render()
    {
        $employees = Employee::query()
            ->when($this->filterEmployee, function ($query) {
                if ($this->filterEmployee === 'without_recision_date') {
                    $query->whereNull('recision_date')->orWhere('recision_date', '');
                } else {
                    $query->WhereNot('recision_date', '');
                }
            })->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('pis', 'like', '%'.$this->search.'%')
                        ->orWhere('position', 'like', '%'.$this->search.'%');
                });
            })->orderBy('name')
            ->paginate(25);

        return view('livewire.employee-index', [
            'employees' => $employees,
        ]);
    }
}
