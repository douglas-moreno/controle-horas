<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Enums\CalendarDayType;

/**
 * @param  class-string<BackedEnum>  $enum
 * @return array<string, string>
 */
function enumValues(string $enum): array
{
    return collect($enum::cases())->mapWithKeys(fn (BackedEnum $case) => [$case->name => $case->value])->all();
}

describe('BenefitType', function () {
    test('has the three benefit types', function () {
        expect(enumValues(BenefitType::class))->toBe(['Vt' => 'vt', 'Vr' => 'vr', 'Vd' => 'vd']);
    });

    test('labels', function () {
        expect(BenefitType::Vt->label())->toBe('VT')
            ->and(BenefitType::Vr->label())->toBe('VR')
            ->and(BenefitType::Vd->label())->toBe('VD');
    });
});

describe('BenefitPeriodStatus', function () {
    test('values', function () {
        expect(enumValues(BenefitPeriodStatus::class))
            ->toBe(['Open' => 'open', 'Calculated' => 'calculated', 'Closed' => 'closed']);
    });

    test('labels', function () {
        expect(BenefitPeriodStatus::Open->label())->toBe('Aberta')
            ->and(BenefitPeriodStatus::Calculated->label())->toBe('Calculada')
            ->and(BenefitPeriodStatus::Closed->label())->toBe('Fechada');
    });

    test('only closed periods are not editable', function () {
        expect(BenefitPeriodStatus::Open->isEditable())->toBeTrue()
            ->and(BenefitPeriodStatus::Calculated->isEditable())->toBeTrue()
            ->and(BenefitPeriodStatus::Closed->isEditable())->toBeFalse();
    });
});

describe('AdjustmentReason', function () {
    test('has exactly the eleven reasons of the specification', function () {
        expect(enumValues(AdjustmentReason::class))->toBe([
            'JustifiedAbsence' => 'justified_absence',
            'UnjustifiedAbsence' => 'unjustified_absence',
            'Vacation' => 'vacation',
            'MedicalCertificate' => 'medical_certificate',
            'Leave' => 'leave',
            'LeaveOfAbsence' => 'leave_of_absence',
            'OtherAbsence' => 'other_absence',
            'SaturdayWorked' => 'saturday_worked',
            'SundayWorked' => 'sunday_worked',
            'HolidayWorked' => 'holiday_worked',
            'Manual' => 'manual',
        ]);
    });

    test('every reason has a label', function (AdjustmentReason $reason) {
        expect($reason->label())->toBeString()->not->toBeEmpty();
    })->with(AdjustmentReason::cases());

    test('absence reasons discount one day', function (AdjustmentReason $reason) {
        expect($reason->isAbsence())->toBeTrue()
            ->and($reason->isWork())->toBeFalse()
            ->and($reason->defaultSign())->toBe(-1);
    })->with([
        AdjustmentReason::JustifiedAbsence,
        AdjustmentReason::UnjustifiedAbsence,
        AdjustmentReason::Vacation,
        AdjustmentReason::MedicalCertificate,
        AdjustmentReason::Leave,
        AdjustmentReason::LeaveOfAbsence,
        AdjustmentReason::OtherAbsence,
    ]);

    test('work reasons credit one day', function (AdjustmentReason $reason) {
        expect($reason->isWork())->toBeTrue()
            ->and($reason->isAbsence())->toBeFalse()
            ->and($reason->defaultSign())->toBe(1);
    })->with([
        AdjustmentReason::SaturdayWorked,
        AdjustmentReason::SundayWorked,
        AdjustmentReason::HolidayWorked,
    ]);

    test('manual reason has free impacts', function () {
        expect(AdjustmentReason::Manual->isAbsence())->toBeFalse()
            ->and(AdjustmentReason::Manual->isWork())->toBeFalse()
            ->and(AdjustmentReason::Manual->defaultSign())->toBeNull();
    });

    test('allowed sources follow the specification', function (AdjustmentReason $reason, array $sources) {
        expect($reason->allowedSources())->toEqualCanonicalizing($sources);
    })->with([
        'justified absence' => [AdjustmentReason::JustifiedAbsence, [AdjustmentSource::Manual, AdjustmentSource::Hr, AdjustmentSource::Import]],
        'unjustified absence' => [AdjustmentReason::UnjustifiedAbsence, [AdjustmentSource::Manual, AdjustmentSource::Timesheet, AdjustmentSource::Import]],
        'vacation' => [AdjustmentReason::Vacation, [AdjustmentSource::Manual, AdjustmentSource::Hr, AdjustmentSource::Import]],
        'medical certificate' => [AdjustmentReason::MedicalCertificate, [AdjustmentSource::Manual, AdjustmentSource::Hr, AdjustmentSource::Import]],
        'leave' => [AdjustmentReason::Leave, [AdjustmentSource::Manual, AdjustmentSource::Hr, AdjustmentSource::Import]],
        'leave of absence' => [AdjustmentReason::LeaveOfAbsence, [AdjustmentSource::Manual, AdjustmentSource::Hr, AdjustmentSource::Import]],
        'other absence' => [AdjustmentReason::OtherAbsence, [AdjustmentSource::Manual, AdjustmentSource::Import]],
        'saturday worked' => [AdjustmentReason::SaturdayWorked, [AdjustmentSource::Timesheet, AdjustmentSource::Manual, AdjustmentSource::Import]],
        'sunday worked' => [AdjustmentReason::SundayWorked, [AdjustmentSource::Timesheet, AdjustmentSource::Manual, AdjustmentSource::Import]],
        'holiday worked' => [AdjustmentReason::HolidayWorked, [AdjustmentSource::Timesheet, AdjustmentSource::Manual, AdjustmentSource::Import]],
        'manual' => [AdjustmentReason::Manual, [AdjustmentSource::Manual]],
    ]);

    test('only reasons fed by the time clock accept the timesheet source', function () {
        $timesheetReasons = collect(AdjustmentReason::cases())
            ->filter(fn (AdjustmentReason $reason) => in_array(AdjustmentSource::Timesheet, $reason->allowedSources(), true))
            ->values()
            ->all();

        expect($timesheetReasons)->toEqualCanonicalizing([
            AdjustmentReason::UnjustifiedAbsence,
            AdjustmentReason::SaturdayWorked,
            AdjustmentReason::SundayWorked,
            AdjustmentReason::HolidayWorked,
        ]);
    });
});

describe('AdjustmentSource', function () {
    test('values', function () {
        expect(enumValues(AdjustmentSource::class))
            ->toBe(['Manual' => 'manual', 'Timesheet' => 'timesheet', 'Hr' => 'hr', 'Import' => 'import']);
    });

    test('labels', function () {
        expect(AdjustmentSource::Manual->label())->toBe('Manual')
            ->and(AdjustmentSource::Timesheet->label())->toBe('Ponto')
            ->and(AdjustmentSource::Hr->label())->toBe('RH')
            ->and(AdjustmentSource::Import->label())->toBe('Importação');
    });

    test('initial status follows the specification', function () {
        expect(AdjustmentSource::Manual->initialStatus())->toBe(AdjustmentStatus::Confirmed)
            ->and(AdjustmentSource::Timesheet->initialStatus())->toBe(AdjustmentStatus::Pending)
            ->and(AdjustmentSource::Hr->initialStatus())->toBe(AdjustmentStatus::Confirmed)
            ->and(AdjustmentSource::Import->initialStatus())->toBe(AdjustmentStatus::Pending);
    });
});

describe('AdjustmentStatus', function () {
    test('values', function () {
        expect(enumValues(AdjustmentStatus::class))
            ->toBe(['Pending' => 'pending', 'Confirmed' => 'confirmed', 'Rejected' => 'rejected']);
    });

    test('labels', function () {
        expect(AdjustmentStatus::Pending->label())->toBe('Pendente')
            ->and(AdjustmentStatus::Confirmed->label())->toBe('Confirmado')
            ->and(AdjustmentStatus::Rejected->label())->toBe('Rejeitado');
    });
});

describe('CalendarDayType', function () {
    test('values', function () {
        expect(enumValues(CalendarDayType::class))->toBe([
            'BusinessDay' => 'business_day',
            'Saturday' => 'saturday',
            'Sunday' => 'sunday',
            'Holiday' => 'holiday',
        ]);
    });
});
