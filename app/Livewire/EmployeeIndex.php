<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Models\ImportedLines;
use App\Models\Point;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use WireUi\Traits\WireUiActions;
use Carbon\Carbon;

class EmployeeIndex extends Component
{
    use WireUiActions;
    use WithPagination;
    use WithFileUploads;

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

    public function importPoints()
    {
        try {
            $this->importing = true;
            
            // Remove validação de tamanho e tipo
            $this->validate([
                'file' => 'required|file'
            ]);

            // Usar método alternativo de leitura
            if ($this->file) {
                $contents = $this->file->get();
                $lines = explode("\n", $contents);
                $importedPoints = [];
                $totalLines = ImportedLines::query()->orderBy('line_number', 'desc')->first();
                $startLine = $totalLines ? $totalLines->line_number : 9; // começa da linha 10 se não houver registros
                $counter = 0;

                for ($i = $startLine; $i < count($lines); $i++) {
                    $line = $lines[$i];
                    if (empty($line)) continue;

                    try {
                        $data = substr($line, 10, 8); // ex: "29112017"
                        $hora = substr($line, 18, 4);
                        $pis = substr($line, 23, 12);

                        // Converte corretamente "dmY" -> "Y-m-d"
                        $dt = Carbon::createFromFormat('dmY', $data);
                        if (! $dt || $dt->format('dmY') !== $data) {
                            // formato inválido, pula
                            continue;
                        }
                        $formattedDate = $dt->format('Y-m-d');

                        $hora = substr($hora, 0, 2) . ':' . substr($hora, 2, 2) . ':00';

                        $importedPoints[] = [
                            'date' => $formattedDate,
                            'time' => trim($hora),
                            'pis' => (int)trim($pis),
                            'type' => 'importado',
                        ];
                        $counter++;
                    } catch (\Exception $e) {
                        continue; // pula linhas com formato inválido
                    }
                }
                if (count($importedPoints) > 0) {
                    Point::insert($importedPoints);
                    ImportedLines::create([
                        'line_number' => $startLine + $counter,
                    ]);

                    $this->notification()->success(
                        $title = 'Importação Concluída',
                        $description = count($importedPoints) . ' novos pontos importados com sucesso.'
                    );
                } else {
                    $this->notification()->warning(
                        $title = 'Nenhum Registro',
                        $description = 'Nenhum novo registro encontrado para importar.'
                    );
                }
            }

        } catch (\Exception $e) {
            $this->notification()->error(
                $title = 'Erro na Importação',
                $description = $e->getMessage()
            );
        } finally {
            $this->importing = false;
            $this->file = null;
        }
    }

    public function render()
    {
        $employees = Employee::query()
        ->when($this->filterEmployee, function ($query) {
            if ($this->filterEmployee === 'without_recision_date') {
                $query->whereNull('recision_date')->orWhere('recision_date', '');
            } else {
                $query->WhereNot('recision_date',"");
            }
        })->when($this->search, function ($query) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('pis', 'like', '%' . $this->search . '%')
                  ->orWhere('position', 'like', '%' . $this->search . '%');
            });
        })->orderBy('name')
        ->paginate(25);

        return view('livewire.employee-index', [
            'employees' => $employees,
        ]);
    }
}
