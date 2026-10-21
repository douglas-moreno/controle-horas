<?php

namespace App\Services;

use App\Enums\AfdRecordType;
use App\Enums\AfdRejectionReason;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Interpreta uma linha do AFD no layout da Portaria MTE 1510/2009, Anexo I, item 1.
 * Não acessa o banco. As posições abaixo são as da norma (base 1); o código usa
 * deslocamentos de base 0.
 *
 * Um erro estrutural (registro que impede interpretar o arquivo com segurança) lança
 * DomainException. Uma marcação (tipo 3) com campo inválido é devolvida com o motivo
 * em `rejection`. Mensagens citam linha, NSR e motivo, nunca o conteúdo da linha.
 *
 * Datas são validadas pelo calendário (checkdate, sem transbordo) e limitadas aos
 * anos 2000–2099; horários "hhmm" de 0000 a 2359. CRLF e LF são aceitos.
 */
class AfdParser
{
    public const LAYOUT = 'Portaria MTE 1510/2009, Anexo I';

    private const TRAILER_PREFIX = '999999999';

    public function parse(string $line, int $lineNumber): AfdRecord
    {
        $line = $this->withoutTerminator($line);

        if (str_starts_with($line, self::TRAILER_PREFIX)) {
            return $this->trailer($line, $lineNumber);
        }

        if (strlen($line) < 10) {
            throw $this->structural($lineNumber, null, 'registro incompleto');
        }

        $nsr = substr($line, 0, 9);

        if (! ctype_digit($nsr)) {
            throw $this->structural($lineNumber, null, 'NSR não numérico');
        }

        $type = AfdRecordType::tryFrom($line[9]);

        if ($type === null || $type === AfdRecordType::Trailer) {
            throw $this->structural($lineNumber, (int) $nsr, 'tipo de registro desconhecido');
        }

        return match ($type) {
            AfdRecordType::Header => $this->header($line, $lineNumber, $nsr),
            AfdRecordType::Company => $this->company($line, $lineNumber, (int) $nsr),
            AfdRecordType::Punch => $this->punch($line, $lineNumber, (int) $nsr),
            AfdRecordType::ClockAdjustment => $this->clockAdjustment($line, $lineNumber, (int) $nsr),
            AfdRecordType::Employee => $this->employee($line, $lineNumber, (int) $nsr),
        };
    }

    /**
     * Remove o terminador de linha (LF ou CRLF), preservando espaços dos campos.
     */
    public function withoutTerminator(string $line): string
    {
        if (str_ends_with($line, "\n")) {
            $line = substr($line, 0, -1);
        }

        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }

        return $line;
    }

    /**
     * Data "ddmmaaaa" válida no calendário, entre 2000 e 2099; null caso contrário.
     */
    public function date(string $value): ?CarbonImmutable
    {
        if (strlen($value) !== 8 || ! ctype_digit($value)) {
            return null;
        }

        $day = (int) substr($value, 0, 2);
        $month = (int) substr($value, 2, 2);
        $year = (int) substr($value, 4, 4);

        if ($year < 2000 || $year > 2099 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /**
     * Horário "hhmm" válido como "H:i:00"; null caso contrário.
     */
    public function time(string $value): ?string
    {
        if (strlen($value) !== 4 || ! ctype_digit($value)) {
            return null;
        }

        $hours = (int) substr($value, 0, 2);
        $minutes = (int) substr($value, 2, 2);

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $hours, $minutes);
    }

    /**
     * Item 1.1: NSR "000000000" (001-009), tipo (010), tipo de identificador (011),
     * CNPJ/CPF (012-025), CEI (026-037), razão social (038-187), número de fabricação
     * do REP (188-204), datas inicial (205-212), final (213-220) e de geração
     * (221-228), horário de geração (229-232).
     */
    private function header(string $line, int $lineNumber, string $nsr): AfdRecord
    {
        if (strlen($line) !== AfdRecordType::Header->length()) {
            throw new DomainException('Layout de AFD não suportado: o cabeçalho tem '.strlen($line).' posições, e o layout suportado ('.self::LAYOUT.') tem '.AfdRecordType::Header->length().'. Outros layouts, como o da Portaria 671/2021, não são aceitos.');
        }

        if ($nsr !== '000000000') {
            throw $this->structural($lineNumber, null, 'o cabeçalho deve ter NSR 000000000');
        }

        $repSerial = substr($line, 187, 17);
        $startsOn = $this->date(substr($line, 204, 8));
        $endsOn = $this->date(substr($line, 212, 8));
        $generatedOn = $this->date(substr($line, 220, 8));
        $generatedTime = $this->time(substr($line, 228, 4));

        if (! in_array($line[10], ['1', '2'], true)
            || ! ctype_digit(substr($line, 11, 14))
            || ! $this->digitsOrBlank(substr($line, 25, 12))
            || ! ctype_digit($repSerial)
            || $startsOn === null || $endsOn === null || $generatedOn === null || $generatedTime === null) {
            throw $this->structural($lineNumber, null, 'cabeçalho com campos inválidos');
        }

        return new AfdRecord(
            type: AfdRecordType::Header,
            lineNumber: $lineNumber,
            nsr: 0,
            repSerial: $repSerial,
            startsOn: $startsOn,
            endsOn: $endsOn,
            generatedAt: CarbonImmutable::parse($generatedOn->toDateString().' '.$generatedTime),
        );
    }

    /**
     * Item 1.2: data (011-018), horário (019-022), tipo de identificador (023),
     * CNPJ/CPF (024-037), CEI, razão social e local.
     */
    private function company(string $line, int $lineNumber, int $nsr): AfdRecord
    {
        $this->ensureLength($line, AfdRecordType::Company, $lineNumber, $nsr);

        if ($this->date(substr($line, 10, 8)) === null || $this->time(substr($line, 18, 4)) === null
            || ! in_array($line[22], ['1', '2'], true) || ! ctype_digit(substr($line, 23, 14))) {
            throw $this->structural($lineNumber, $nsr, 'registro de empresa com campos inválidos');
        }

        return new AfdRecord(AfdRecordType::Company, $lineNumber, $nsr);
    }

    /**
     * Item 1.3: data (011-018), horário (019-022) e PIS (023-034, 12 dígitos).
     */
    private function punch(string $line, int $lineNumber, int $nsr): AfdRecord
    {
        $rejected = fn (AfdRejectionReason $reason) => new AfdRecord(AfdRecordType::Punch, $lineNumber, $nsr, rejection: $reason);

        if (strlen($line) !== AfdRecordType::Punch->length()) {
            return $rejected(AfdRejectionReason::InvalidLength);
        }

        $date = $this->date(substr($line, 10, 8));

        if ($date === null) {
            return $rejected(AfdRejectionReason::InvalidDate);
        }

        $time = $this->time(substr($line, 18, 4));

        if ($time === null) {
            return $rejected(AfdRejectionReason::InvalidTime);
        }

        $pis = substr($line, 22, 12);

        if (! ctype_digit($pis) || (int) $pis === 0) {
            return $rejected(AfdRejectionReason::InvalidPis);
        }

        return new AfdRecord(AfdRecordType::Punch, $lineNumber, $nsr, date: $date, time: $time, pis: $pis);
    }

    /**
     * Item 1.4: data e horário antes do ajuste (011-022), data e horário ajustados (023-034).
     */
    private function clockAdjustment(string $line, int $lineNumber, int $nsr): AfdRecord
    {
        $this->ensureLength($line, AfdRecordType::ClockAdjustment, $lineNumber, $nsr);

        if ($this->date(substr($line, 10, 8)) === null || $this->time(substr($line, 18, 4)) === null
            || $this->date(substr($line, 22, 8)) === null || $this->time(substr($line, 30, 4)) === null) {
            throw $this->structural($lineNumber, $nsr, 'registro de ajuste do relógio com campos inválidos');
        }

        return new AfdRecord(AfdRecordType::ClockAdjustment, $lineNumber, $nsr);
    }

    /**
     * Item 1.5: data (011-018), horário (019-022), operação I/A/E (023), PIS (024-035), nome (036-087).
     */
    private function employee(string $line, int $lineNumber, int $nsr): AfdRecord
    {
        $this->ensureLength($line, AfdRecordType::Employee, $lineNumber, $nsr);

        if ($this->date(substr($line, 10, 8)) === null || $this->time(substr($line, 18, 4)) === null
            || ! in_array($line[22], ['I', 'A', 'E'], true) || ! ctype_digit(substr($line, 23, 12))) {
            throw $this->structural($lineNumber, $nsr, 'registro de empregado com campos inválidos');
        }

        return new AfdRecord(AfdRecordType::Employee, $lineNumber, $nsr);
    }

    /**
     * Item 1.6: "999999999" (001-009), quantidades de registros tipo 2 (010-018),
     * 3 (019-027), 4 (028-036) e 5 (037-045), e "9" (046).
     */
    private function trailer(string $line, int $lineNumber): AfdRecord
    {
        if (strlen($line) !== AfdRecordType::Trailer->length() || ! ctype_digit(substr($line, 9, 36)) || $line[45] !== '9') {
            throw $this->structural($lineNumber, null, 'trailer inválido');
        }

        return new AfdRecord(AfdRecordType::Trailer, $lineNumber, trailerCounts: [
            'company' => (int) substr($line, 9, 9),
            'punch' => (int) substr($line, 18, 9),
            'clock_adjustment' => (int) substr($line, 27, 9),
            'employee' => (int) substr($line, 36, 9),
        ]);
    }

    private function ensureLength(string $line, AfdRecordType $type, int $lineNumber, int $nsr): void
    {
        if (strlen($line) !== $type->length()) {
            throw $this->structural($lineNumber, $nsr, 'registro tipo '.$type->value.' com '.strlen($line).' posições (esperado '.$type->length().')');
        }
    }

    private function digitsOrBlank(string $value): bool
    {
        return ctype_digit($value) || trim($value) === '';
    }

    private function structural(int $lineNumber, ?int $nsr, string $reason): DomainException
    {
        return new DomainException('AFD inválido na linha '.$lineNumber.($nsr === null ? '' : ' (NSR '.$nsr.')').': '.$reason.'. Nenhuma batida foi gravada.');
    }
}
