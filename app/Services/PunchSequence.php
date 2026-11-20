<?php

namespace App\Services;

use App\Enums\PunchKind;
use DomainException;

/**
 * Classificação de uma marcação candidata pela sequência cronológica do dia: posição
 * ímpar é entrada, posição par é saída, contando todas as batidas do funcionário na data
 * (importadas e manuais) mais a candidata.
 *
 * A data de trabalho é a data civil da batida, como no espelho (TimesheetMirror agrupa
 * por `points.date` e ordena por horário): uma jornada que atravessa a meia-noite tem as
 * batidas após 00:00 contadas no dia seguinte.
 *
 * Recusa, sem escolher uma classificação:
 * - horário da candidata fora do formato H:i;
 * - batida existente sem horário válido (sequência indeterminada);
 * - empate: batida existente no mesmo minuto da candidata.
 */
class PunchSequence
{
    /**
     * @param  list<string|null>  $existingTimes  Horários (H:i ou H:i:s) das batidas do dia.
     * @return array{position: int, kind: PunchKind, total: int}
     */
    public function classify(array $existingTimes, string $candidate): array
    {
        $candidateMinute = $this->minute($candidate);

        if ($candidateMinute === null) {
            throw new DomainException('Horário da marcação inválido.');
        }

        $minutes = [];

        foreach ($existingTimes as $time) {
            $minute = $time === null ? null : $this->minute($time);

            if ($minute === null) {
                throw new DomainException('Há batida sem horário válido nesta data: não é possível determinar a sequência das marcações.');
            }

            if ($minute === $candidateMinute) {
                throw new DomainException('Já existe uma batida às '.substr($candidate, 0, 5).' nesta data: o empate impede determinar a sequência das marcações.');
            }

            $minutes[] = $minute;
        }

        $position = count(array_filter($minutes, fn (int $minute) => $minute < $candidateMinute)) + 1;

        return [
            'position' => $position,
            'kind' => $position % 2 === 1 ? PunchKind::Entry : PunchKind::Exit,
            'total' => count($minutes) + 1,
        ];
    }

    /**
     * Confere o tipo informado com a classificação da sequência; recusa a divergência.
     *
     * @param  array{position: int, kind: PunchKind, total: int}  $classification
     */
    public function ensureCompatible(PunchKind $informed, array $classification, string $time): void
    {
        if ($informed->isEntrySide() === $classification['kind']->isEntrySide()) {
            return;
        }

        throw new DomainException(sprintf(
            'Pela sequência do dia, a marcação das %s seria a %dª (%s), incompatível com o tipo informado (%s).',
            substr($time, 0, 5),
            $classification['position'],
            mb_strtolower($classification['kind']->label()),
            mb_strtolower($informed->label()),
        ));
    }

    private function minute(string $time): ?int
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time, $matches)) {
            return null;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }
}
