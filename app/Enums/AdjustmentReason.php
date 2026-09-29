<?php

namespace App\Enums;

enum AdjustmentReason: string
{
    case JustifiedAbsence = 'justified_absence';
    case UnjustifiedAbsence = 'unjustified_absence';
    case Vacation = 'vacation';
    case MedicalCertificate = 'medical_certificate';
    case Leave = 'leave';
    case LeaveOfAbsence = 'leave_of_absence';
    case OtherAbsence = 'other_absence';
    case SaturdayWorked = 'saturday_worked';
    case SundayWorked = 'sunday_worked';
    case HolidayWorked = 'holiday_worked';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::JustifiedAbsence => 'Ausência justificada',
            self::UnjustifiedAbsence => 'Ausência injustificada',
            self::Vacation => 'Férias',
            self::MedicalCertificate => 'Atestado médico',
            self::Leave => 'Licença',
            self::LeaveOfAbsence => 'Afastamento',
            self::OtherAbsence => 'Outro motivo de ausência',
            self::SaturdayWorked => 'Sábado trabalhado',
            self::SundayWorked => 'Domingo trabalhado',
            self::HolidayWorked => 'Feriado trabalhado',
            self::Manual => 'Ajuste manual',
        };
    }

    public function isAbsence(): bool
    {
        return in_array($this, [
            self::JustifiedAbsence,
            self::UnjustifiedAbsence,
            self::Vacation,
            self::MedicalCertificate,
            self::Leave,
            self::LeaveOfAbsence,
            self::OtherAbsence,
        ], true);
    }

    public function isWork(): bool
    {
        return in_array($this, [
            self::SaturdayWorked,
            self::SundayWorked,
            self::HolidayWorked,
        ], true);
    }

    /**
     * Sinal do impacto padrão por dia: -1 para ausências, +1 para trabalho e
     * null para o ajuste manual, cujos impactos são livres por tipo de benefício.
     */
    public function defaultSign(): ?int
    {
        return match (true) {
            $this->isAbsence() => -1,
            $this->isWork() => 1,
            default => null,
        };
    }

    /**
     * @return list<AdjustmentSource>
     */
    public function allowedSources(): array
    {
        return match ($this) {
            self::JustifiedAbsence,
            self::Vacation,
            self::MedicalCertificate,
            self::Leave,
            self::LeaveOfAbsence => [AdjustmentSource::Manual, AdjustmentSource::Hr, AdjustmentSource::Import],
            self::UnjustifiedAbsence => [AdjustmentSource::Manual, AdjustmentSource::Timesheet, AdjustmentSource::Import],
            self::OtherAbsence => [AdjustmentSource::Manual, AdjustmentSource::Import],
            self::SaturdayWorked,
            self::SundayWorked,
            self::HolidayWorked => [AdjustmentSource::Timesheet, AdjustmentSource::Manual, AdjustmentSource::Import],
            self::Manual => [AdjustmentSource::Manual],
        };
    }
}
