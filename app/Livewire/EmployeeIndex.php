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
            ini_set('memory_limit', '512M');
            $this->importing = true;
            
            // pega o limite do .env (em MB) e converte para KB para a regra 'max:'
            $maxMb = (int) env('LIVEWIRE_UPLOAD_MAX_FILESIZE', 50); // ex: 100
            $maxKb = $maxMb * 1024;

            $this->validate([
                'file' => 'required|file|mimetypes:text/plain|max:' . $maxKb
            ]);

            if (!$this->file) {
                return;
            }

            $handle = fopen($this->file->path(), 'r');
            $totalLines = ImportedLines::query()->orderBy('line_number', 'desc')->first();
            $startLine = $totalLines ? $totalLines->line_number : 9;
            $counter = 0;
            $batch = [];
            $batchSize = 1000; // processa 1000 registros por vez

            // Pula as linhas já importadas
            for ($i = 0; $i < $startLine; $i++) {
                fgets($handle);
            }

            while (!feof($handle)) {
                $line = fgets($handle);
                if (empty($line)) continue;

                try {
                    $data = substr($line, 10, 8);
                    $hora = substr($line, 18, 4);
                    $pis = substr($line, 23, 12);

                    $dt = Carbon::createFromFormat('dmY', $data);
                    $formattedDate = $dt->format('Y-m-d');
                    $hora = substr($hora, 0, 2) . ':' . substr($hora, 2, 2) . ':00';

                    $batch[] = [
                        'date' => $formattedDate,
                        'time' => trim($hora),
                        'pis' => trim($pis),
                        'type' => 'importado',
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    
                    $counter++;

                    // Insere em lotes para economizar memória
                    if (count($batch) >= $batchSize) {
                        Point::insert($batch);
                        $batch = [];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            // Insere o último lote
            if (!empty($batch)) {
                Point::insert($batch);
            }

            fclose($handle);

            ImportedLines::create([
                'line_number' => $startLine + $counter,
            ]);

            $this->notification()->success(
                $title = 'Importação Concluída',
                $description = $counter . ' novos pontos importados com sucesso.'
            );

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
