<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Models\ImportedLines;
use App\Models\Point;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class PointList extends Component
{
    use WireUiActions;

    public $points;
    public $employies;
    public $totalLines;
    public $counter = 0;

    public function getNewPoints()
    {
        $filePath = storage_path('points.txt'); // ajuste o caminho conforme necessário
        if (!file_exists($filePath)) {
            return []; // ou lance uma exceção
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $importedPoints = [];

        // Começa da linha 10 (índice 9)
        for ($i = $this->totalLines->line_number; $i < count($lines); $i++) {
            $line = $lines[$i];

            $data = substr($line, 10, 8);
            $hora = substr($line, 18, 4);
            $pis = substr($line, 23, 12);

            $data = date('Ymd', strtotime($data));
            $hora = substr($hora, 0, 2) . ':' . substr($hora, 2, 2) . ':00';

            $importedPoints[] = [
                'data' => trim($data),
                'hora' => trim($hora),
                'pis' => trim($pis),
            ];
            $this->counter++;
        }
        
        Point::create($importedPoints);

        ImportedLines::create([
            'line_number' =>  $this->totalLines->line_number + $this->counter,
        ]);
        
        $this->notification()->success(
            $title = 'Importação Concluída',
            $description = count($importedPoints) . ' novos pontos importados com sucesso.'
        );
    }

    public function mount()
    {
        // $this->points = Point::all();
        $this->employies = Employee::all();
        $this->totalLines = ImportedLines::query()->orderBy('line_number', 'desc')->limit(1)->get();
    }

    public function render()
    {
        return view('livewire.point-list');
    }
}
