<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Point;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inclusão de batida manual (`type = manual`), usada pela tela de edição de pontos e,
 * a partir da F3, pelas justificativas de esquecimento de marcação.
 *
 * - O funcionário precisa existir e ter PIS: a batida se relaciona a ele pelo PIS.
 * - Data no formato Y-m-d e horário H:i (ou H:i:s; os segundos são descartados, como na
 *   tela, que sempre gravou H:i:00).
 * - Duplicidade: recusa quando já existe qualquer batida (importada ou manual) com o mesmo
 *   PIS, data e minuto — a mesma chave usada pela correção histórica do AFD.
 * - Tudo em uma transação, com bloqueio do funcionário para serializar inclusões
 *   simultâneas e impedir que duas requisições gravem o mesmo minuto.
 *
 * `move()` e `remove()` (F4) atuam somente sobre a batida manual vinculada a uma
 * justificativa, a pedido do PointJustificationService: recusam batida importada, de outro
 * funcionário e protegida pelo RH (mantida por decisão ou anotada), e passam pelo Eloquent,
 * cujos eventos de Point repetem a proteção.
 *
 * Nunca altera batidas importadas do AFD.
 */
class ManualPointRegistrar
{
    public function register(Employee $employee, string $date, string $time): Point
    {
        $date = $this->normalizedDate($date);
        $time = $this->normalizedTime($time);

        return DB::transaction(function () use ($employee, $date, $time) {
            $locked = Employee::query()->lockForUpdate()->find($employee->getKey());

            if ($locked === null) {
                throw new DomainException('Funcionário não encontrado.');
            }

            if (blank($locked->pis)) {
                throw new DomainException('O funcionário não possui PIS cadastrado.');
            }

            if ($this->existsAtSameMinute((string) $locked->pis, $date, $time)) {
                throw new DomainException('Já existe uma batida deste funcionário nesta data às '.substr($time, 0, 5).'.');
            }

            return Point::query()->create([
                'pis' => $locked->pis,
                'date' => $date,
                'time' => $time,
                'type' => 'manual',
            ]);
        });
    }

    /**
     * Move a batida manual do funcionário para outra data e/ou horário, recusando a
     * duplicidade no novo minuto (sem contar a própria batida).
     */
    public function move(Employee $employee, Point $point, string $date, string $time): Point
    {
        $date = $this->normalizedDate($date);
        $time = $this->normalizedTime($time);

        return DB::transaction(function () use ($employee, $point, $date, $time) {
            [$locked, $current] = $this->lockedManualPoint($employee, $point);

            if ($this->existsAtSameMinute((string) $locked->pis, $date, $time, (int) $current->getKey())) {
                throw new DomainException('Já existe uma batida deste funcionário nesta data às '.substr($time, 0, 5).'.');
            }

            $current->forceFill(['date' => $date, 'time' => $time])->save();

            return $current->refresh();
        });
    }

    /**
     * Exclui a batida manual do funcionário, se não estiver protegida.
     */
    public function remove(Employee $employee, Point $point): void
    {
        DB::transaction(function () use ($employee, $point) {
            [, $current] = $this->lockedManualPoint($employee, $point);

            $current->delete();
        });
    }

    /**
     * Motivo pelo qual a batida não pode ser alterada nem excluída, ou null.
     */
    public function protectionReason(Point $point): ?string
    {
        return match (true) {
            $point->type !== 'manual' => 'A marcação vinculada não é manual: batidas importadas do relógio não podem ser alteradas nem excluídas.',
            $point->isRetainedByHrDecision() => Point::RETAINED_BY_HR_MESSAGE,
            $point->isAnnotatedByHr() => Point::ANNOTATED_BY_HR_MESSAGE,
            default => null,
        };
    }

    /**
     * Existe batida (de qualquer tipo) do PIS na data, no mesmo minuto.
     */
    public function existsAtSameMinute(string $pis, string $date, string $time, ?int $exceptPointId = null): bool
    {
        return Point::query()
            ->where('pis', $pis)
            ->whereDate('date', $date)
            ->where('time', 'like', substr($time, 0, 5).'%')
            ->when($exceptPointId !== null, fn ($query) => $query->whereKeyNot($exceptPointId))
            ->exists();
    }

    /**
     * @return array{0: Employee, 1: Point}
     */
    private function lockedManualPoint(Employee $employee, Point $point): array
    {
        $locked = Employee::query()->lockForUpdate()->find($employee->getKey());

        if ($locked === null) {
            throw new DomainException('Funcionário não encontrado.');
        }

        $current = Point::query()->lockForUpdate()->find($point->getKey());

        if ($current === null) {
            throw new DomainException('A marcação vinculada não existe mais.');
        }

        if (blank($locked->pis) || (string) $current->pis !== (string) $locked->pis) {
            throw new DomainException('A marcação vinculada não pertence a este funcionário.');
        }

        $protection = $this->protectionReason($current);

        if ($protection !== null) {
            throw new DomainException($protection);
        }

        return [$locked, $current];
    }

    private function normalizedDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Data da batida inválida.');
        }

        return $date;
    }

    private function normalizedTime(string $time): string
    {
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
            throw new DomainException('Horário da batida inválido.');
        }

        return substr($time, 0, 5).':00';
    }
}
