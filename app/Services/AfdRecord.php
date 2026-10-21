<?php

namespace App\Services;

use App\Enums\AfdRecordType;
use App\Enums\AfdRejectionReason;
use Carbon\CarbonImmutable;

/**
 * Registro do AFD interpretado pelo AfdParser. Somente os campos do tipo são
 * preenchidos. Uma marcação (tipo 3) com campo inválido tem `rejection` preenchido e
 * os campos de data, horário e PIS nulos: ela nunca gera batida.
 *
 * O PIS é mantido apenas em memória para gravar a batida; nunca entra em mensagens.
 */
final readonly class AfdRecord
{
    /**
     * @param  array{company: int, punch: int, clock_adjustment: int, employee: int}|null  $trailerCounts
     */
    public function __construct(
        public AfdRecordType $type,
        public int $lineNumber,
        public ?int $nsr = null,
        public ?CarbonImmutable $date = null,
        public ?string $time = null,
        public ?string $pis = null,
        public ?AfdRejectionReason $rejection = null,
        public ?string $repSerial = null,
        public ?CarbonImmutable $startsOn = null,
        public ?CarbonImmutable $endsOn = null,
        public ?CarbonImmutable $generatedAt = null,
        public ?array $trailerCounts = null,
    ) {}

    public function isValidPunch(): bool
    {
        return $this->type === AfdRecordType::Punch && $this->rejection === null;
    }
}
