<?php

namespace Database\Seeders;

use App\Enums\JustificationCategory;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Models\PointJustificationReason;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de motivos de justificativa (especificação v1.1, item 3.1).
 *
 * Idempotente e não destrutivo: motivos já existentes (mesmo `code`) são mantidos como
 * estão, preservando as alterações do RH. Nenhum motivo exige documento: a política
 * será definida pelo RH. `allowed_treatments` nulo libera todos os tratamentos suportados
 * até o RH definir a matriz motivo × tratamento.
 */
class PointJustificationReasonSeeder extends Seeder
{
    /**
     * code => [nome, categoria, modo de horário, tratamento padrão, cria batida manual].
     *
     * @var array<string, array{0: string, 1: JustificationCategory, 2: JustificationTimeMode, 3: JustificationTreatment, 4: bool}>
     */
    public const REASONS = [
        'justified_absence' => ['Falta justificada', JustificationCategory::Absence, JustificationTimeMode::FullDayOrInterval, JustificationTreatment::FullAllowance, false],
        'medical_certificate' => ['Atestado médico', JustificationCategory::Absence, JustificationTimeMode::FullDayOrInterval, JustificationTreatment::FullAllowance, false],
        'family_medical_care' => ['Acompanhamento médico de familiar', JustificationCategory::Absence, JustificationTimeMode::FullDayOrInterval, JustificationTreatment::FullAllowance, false],
        'maternity_leave' => ['Licença-maternidade', JustificationCategory::Absence, JustificationTimeMode::FullDay, JustificationTreatment::FullAllowance, false],
        'paternity_leave' => ['Licença-paternidade', JustificationCategory::Absence, JustificationTimeMode::FullDay, JustificationTreatment::FullAllowance, false],
        'bereavement' => ['Falecimento de familiar', JustificationCategory::Absence, JustificationTimeMode::FullDay, JustificationTreatment::FullAllowance, false],
        'marriage' => ['Casamento', JustificationCategory::Absence, JustificationTimeMode::FullDay, JustificationTreatment::FullAllowance, false],
        'blood_donation' => ['Doação de sangue', JustificationCategory::Absence, JustificationTimeMode::FullDayOrInterval, JustificationTreatment::FullAllowance, false],
        'court_appearance' => ['Comparecimento judicial', JustificationCategory::Absence, JustificationTimeMode::FullDayOrInterval, JustificationTreatment::FullAllowance, false],

        'justified_delay' => ['Atraso justificado', JustificationCategory::DelayAndLeave, JustificationTimeMode::Interval, JustificationTreatment::FullAllowance, false],
        'authorized_early_leave' => ['Saída antecipada autorizada', JustificationCategory::DelayAndLeave, JustificationTimeMode::Interval, JustificationTreatment::FullAllowance, false],
        'medical_appointment_leave' => ['Saída para atendimento médico', JustificationCategory::DelayAndLeave, JustificationTimeMode::Interval, JustificationTreatment::FullAllowance, false],
        'late_break_return' => ['Retorno tardio do intervalo', JustificationCategory::DelayAndLeave, JustificationTimeMode::Interval, JustificationTreatment::NoAllowance, false],
        'external_business' => ['Compromisso externo a serviço', JustificationCategory::DelayAndLeave, JustificationTimeMode::Interval, JustificationTreatment::FullAllowance, false],

        'forgotten_punch' => ['Esquecimento de marcação', JustificationCategory::Punch, JustificationTimeMode::SinglePunch, JustificationTreatment::NoAllowance, true],
        'duplicate_punch' => ['Marcação duplicada', JustificationCategory::Punch, JustificationTimeMode::SinglePunch, JustificationTreatment::NoAllowance, false],
        'incorrect_punch' => ['Marcação incorreta', JustificationCategory::Punch, JustificationTimeMode::SinglePunch, JustificationTreatment::NoAllowance, false],
        'excess_punch' => ['Marcação excedente', JustificationCategory::Punch, JustificationTimeMode::SinglePunch, JustificationTreatment::NoAllowance, false],
        'clock_failure' => ['Falha no relógio de ponto', JustificationCategory::Punch, JustificationTimeMode::SinglePunch, JustificationTreatment::NoAllowance, false],
        'external_work_without_punch' => ['Trabalho externo sem marcação', JustificationCategory::Punch, JustificationTimeMode::SinglePunch, JustificationTreatment::NoAllowance, false],

        'authorized_overtime' => ['Hora extra autorizada', JustificationCategory::WorkdayAndOvertime, JustificationTimeMode::Interval, JustificationTreatment::NoAllowance, false],
        'hours_compensation' => ['Compensação de horas', JustificationCategory::WorkdayAndOvertime, JustificationTimeMode::FullDayOrInterval, JustificationTreatment::NoAllowance, false],
        'day_off_call' => ['Convocação em dia de folga', JustificationCategory::WorkdayAndOvertime, JustificationTimeMode::Interval, JustificationTreatment::NoAllowance, false],
        'holiday_work' => ['Trabalho em feriado', JustificationCategory::WorkdayAndOvertime, JustificationTimeMode::Interval, JustificationTreatment::NoAllowance, false],
        'exceptional_schedule_change' => ['Alteração excepcional de jornada', JustificationCategory::WorkdayAndOvertime, JustificationTimeMode::Interval, JustificationTreatment::NoAllowance, false],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $existing = PointJustificationReason::query()->pluck('code')->flip();
        $sortOrder = 0;

        foreach (self::REASONS as $code => [$name, $category, $timeMode, $defaultTreatment, $createsManualPoint]) {
            $sortOrder += 10;

            if ($existing->has($code)) {
                continue;
            }

            PointJustificationReason::query()->create([
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'time_mode' => $timeMode,
                'requires_document' => false,
                'default_treatment' => $defaultTreatment,
                'allowed_treatments' => null,
                'creates_manual_point' => $createsManualPoint,
                'active' => true,
                'sort_order' => $sortOrder,
            ]);
        }
    }
}
