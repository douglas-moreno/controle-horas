<?php

namespace App\Services;

use App\Enums\AlternativeReviewReason;
use App\Enums\TimesheetDayStatus;

/**
 * Resultado da regra alternativa para um dia. Extra e atraso são null quando o dia
 * não é calculado (conferência manual, sem marcação ou sem jornada). Em sábado,
 * domingo e feriado, o extra é o valor oficial recebido e não há atraso.
 */
final readonly class AlternativeDayResult
{
    /**
     * @param  list<string>  $consideredTimes  marcações H:i após a deduplicação (A1)
     */
    public function __construct(
        public TimesheetDayStatus $status,
        public ?AlternativeReviewReason $reviewReason = null,
        public ?int $overtimeMinutes = null,
        public ?int $delayMinutes = null,
        public ?int $earlyArrivalMinutes = null,
        public ?int $afterExitMinutes = null,
        public ?int $lateEntryMinutes = null,
        public ?int $earlyLeaveMinutes = null,
        public array $consideredTimes = [],
        public bool $usesOfficialOvertime = false,
    ) {}

    public function needsReview(): bool
    {
        return $this->status->needsReview();
    }
}
