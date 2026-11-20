<?php

namespace App\Services;

use App\Enums\BenefitPeriodStatus;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Enums\PunchKind;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validação e normalização dos dados de uma justificativa do controle de ponto, no
 * servidor. Usada pelo formulário da tela de edição de pontos (F2) e, na F3, pelo serviço
 * de gravação, para que as regras não dependam da interface.
 *
 * Regras:
 * - Motivo ativo (na edição, também o motivo atual da justificativa, ainda que
 *   desativado); tratamento permitido pelo motivo e suportado (sem banco de horas).
 * - Horários conforme o modo do motivo (JustificationTimeMode):
 *   - FullDay: sem horários; duração = jornada prevista do dia (nula sem jornada).
 *   - Interval: início e fim H:i; fim posterior ao início, ou anterior/igual com
 *     `ends_next_day` (virada de dia); duração calculada, até 24h.
 *   - FullDayOrInterval: `full_day` escolhe entre os dois anteriores.
 *   - SinglePunch: horário e tipo de marcação; sem fim e sem duração.
 * - Abono parcial: minutos obrigatórios, entre 1 e a duração (ou 1440 sem duração);
 *   nos demais tratamentos, os minutos são descartados.
 * - Observação até 1000 caracteres.
 * - `request_uuid` UUID ainda não usado por outra justificativa.
 * - Data em competência de benefícios fechada (janela M−1 da competência M): exige o gate
 *   `change-closed-period-justifications`.
 *
 * Somente lê; nada é gravado.
 */
class PointJustificationValidator
{
    public const MAX_OBSERVATION_LENGTH = 1000;

    private const DAY_MINUTES = 1440;

    /**
     * @param  array<string, mixed>  $input  reason_id, treatment, full_day, start_time, end_time, ends_next_day, punch_kind, allowed_minutes, observation, request_uuid
     * @param  bool  $checkRequestUuid  false somente para comparar um reenvio com a justificativa já gravada pelo mesmo identificador.
     * @param  int|null  $keptReasonId  Na edição, o motivo atual da justificativa: aceito mesmo desativado, para que os demais campos possam ser salvos. Qualquer outro motivo precisa estar ativo.
     * @return array{reason: PointJustificationReason, closed_period: ?BenefitPeriod, attributes: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function validate(Employee $employee, string $date, array $input, User $user, bool $checkRequestUuid = true, ?int $keptReasonId = null): array
    {
        $occurrenceDate = $this->parsedDate($date);

        if ($occurrenceDate === null) {
            throw ValidationException::withMessages(['occurrence_date' => 'Data da ocorrência inválida.']);
        }

        $reason = $this->selectableReason($input['reason_id'] ?? null, $keptReasonId);

        Validator::make($input, [
            'reason_id' => ['required', 'integer'],
            'treatment' => ['required', Rule::enum(JustificationTreatment::class)],
            'observation' => ['nullable', 'string', 'max:'.self::MAX_OBSERVATION_LENGTH],
            'request_uuid' => ['required', 'uuid', ...($checkRequestUuid ? [Rule::unique(PointJustification::class, 'request_uuid')] : [])],
        ], [
            'reason_id.required' => 'Informe o motivo.',
            'reason_id.integer' => 'Motivo inválido.',
            'treatment.required' => 'Informe o tratamento.',
            'treatment.enum' => 'Tratamento inválido.',
            'observation.max' => 'A observação deve ter no máximo '.self::MAX_OBSERVATION_LENGTH.' caracteres.',
            'request_uuid.required' => 'Operação sem identificador. Abra o formulário novamente.',
            'request_uuid.uuid' => 'Identificador da operação inválido. Abra o formulário novamente.',
            'request_uuid.unique' => 'Esta operação já foi registrada. Abra o formulário novamente para um novo lançamento.',
        ])->after(function ($validator) use ($reason, $input) {
            if (! $validator->errors()->has('reason_id') && $reason === null) {
                $validator->errors()->add('reason_id', 'Motivo inexistente ou inativo.');
            }

            $treatment = JustificationTreatment::tryFrom((string) ($input['treatment'] ?? ''));

            if ($reason !== null && $treatment !== null && ! $treatment->isSupported()) {
                $validator->errors()->add('treatment', 'Tratamento indisponível nesta versão.');
            } elseif ($reason !== null && $treatment !== null && ! $reason->allowsTreatment($treatment)) {
                $validator->errors()->add('treatment', 'Tratamento não permitido para o motivo.');
            }
        })->validate();

        $treatment = JustificationTreatment::from((string) $input['treatment']);
        $times = $this->times($reason->time_mode, $occurrenceDate, $input);
        $allowedMinutes = $this->allowedMinutes($treatment, $times['duration_minutes'], $input['allowed_minutes'] ?? null);
        $closedPeriod = $this->closedPeriodFor($occurrenceDate);

        if ($closedPeriod !== null && ! Gate::forUser($user)->allows('change-closed-period-justifications')) {
            throw ValidationException::withMessages([
                'occurrence_date' => 'A data pertence à competência fechada '.$closedPeriod->competence->format('m/Y').'. É necessária a permissão de alteração em competência fechada.',
            ]);
        }

        $observation = trim((string) ($input['observation'] ?? ''));

        return [
            'reason' => $reason,
            'closed_period' => $closedPeriod,
            'attributes' => [
                'employee_id' => $employee->getKey(),
                'reason_id' => $reason->getKey(),
                'occurrence_date' => $occurrenceDate->toDateString(),
                ...$times,
                'treatment' => $treatment,
                'allowed_minutes' => $allowedMinutes,
                'observation' => $observation === '' ? null : $observation,
                'request_uuid' => (string) $input['request_uuid'],
            ],
        ];
    }

    /**
     * Motivo ativo com o identificador informado ou, na edição, o motivo atual da
     * justificativa ainda que desativado. Outro motivo desativado não é encontrado.
     */
    public function selectableReason(mixed $reasonId, ?int $keptReasonId = null): ?PointJustificationReason
    {
        if (! is_numeric($reasonId)) {
            return null;
        }

        return PointJustificationReason::query()
            ->where(fn ($query) => $query->where('active', true)->when($keptReasonId !== null, fn ($query) => $query->orWhere('id', $keptReasonId)))
            ->find((int) $reasonId);
    }

    /**
     * Competência de benefícios fechada cuja janela de eventos (mês anterior ao da
     * competência) contém a data.
     */
    public function closedPeriodFor(CarbonImmutable $date): ?BenefitPeriod
    {
        return BenefitPeriod::query()
            ->where('competence', $date->startOfMonth()->addMonthNoOverflow()->toDateString())
            ->where('status', BenefitPeriodStatus::Closed)
            ->first();
    }

    /**
     * Minutos de jornada prevista no dia (fora do intervalo), ou null sem jornada.
     */
    public function scheduledMinutes(CarbonImmutable $date): ?int
    {
        $schedule = config('timesheet.schedule.'.$date->dayOfWeekIso);

        if (! is_array($schedule)) {
            return null;
        }

        return ($this->toMinutes($schedule['break_start']) - $this->toMinutes($schedule['entry']))
            + ($this->toMinutes($schedule['exit']) - $this->toMinutes($schedule['break_end']));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{start_time: ?string, end_time: ?string, ends_next_day: bool, duration_minutes: ?int, punch_kind: ?PunchKind}
     */
    private function times(JustificationTimeMode $mode, CarbonImmutable $date, array $input): array
    {
        $fullDay = match ($mode) {
            JustificationTimeMode::FullDay => true,
            JustificationTimeMode::FullDayOrInterval => filter_var($input['full_day'] ?? false, FILTER_VALIDATE_BOOLEAN),
            default => false,
        };

        if ($fullDay) {
            return ['start_time' => null, 'end_time' => null, 'ends_next_day' => false, 'duration_minutes' => $this->scheduledMinutes($date), 'punch_kind' => null];
        }

        if ($mode === JustificationTimeMode::SinglePunch) {
            Validator::make($input, [
                'start_time' => ['required', 'date_format:H:i'],
                'punch_kind' => ['required', Rule::enum(PunchKind::class)],
            ], [
                'start_time.required' => 'Informe o horário da marcação.',
                'start_time.date_format' => 'Informe o horário no formato HH:MM.',
                'punch_kind.required' => 'Informe o tipo de marcação.',
                'punch_kind.enum' => 'Tipo de marcação inválido.',
            ])->validate();

            return ['start_time' => $input['start_time'].':00', 'end_time' => null, 'ends_next_day' => false, 'duration_minutes' => null, 'punch_kind' => PunchKind::from((string) $input['punch_kind'])];
        }

        Validator::make($input, [
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ], [
            'start_time.required' => 'Informe o horário inicial.',
            'start_time.date_format' => 'Informe o horário inicial no formato HH:MM.',
            'end_time.required' => 'Informe o horário final.',
            'end_time.date_format' => 'Informe o horário final no formato HH:MM.',
        ])->validate();

        $endsNextDay = filter_var($input['ends_next_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $start = $this->toMinutes((string) $input['start_time']);
        $end = $this->toMinutes((string) $input['end_time']);

        if (! $endsNextDay && $end <= $start) {
            throw ValidationException::withMessages(['end_time' => 'O horário final deve ser posterior ao inicial. Marque "termina no dia seguinte" se a ocorrência atravessa a meia-noite.']);
        }

        if ($endsNextDay && $end > $start) {
            throw ValidationException::withMessages(['end_time' => 'Com término no dia seguinte, o horário final deve ser anterior ou igual ao inicial (até 24 horas).']);
        }

        return [
            'start_time' => $input['start_time'].':00',
            'end_time' => $input['end_time'].':00',
            'ends_next_day' => $endsNextDay,
            'duration_minutes' => $endsNextDay ? $end + self::DAY_MINUTES - $start : $end - $start,
            'punch_kind' => null,
        ];
    }

    private function allowedMinutes(JustificationTreatment $treatment, ?int $durationMinutes, mixed $value): ?int
    {
        if (! $treatment->requiresAllowedMinutes()) {
            return null;
        }

        $max = $durationMinutes ?? self::DAY_MINUTES;

        Validator::make(['allowed_minutes' => $value], [
            'allowed_minutes' => ['required', 'integer', 'min:1', 'max:'.$max],
        ], [
            'allowed_minutes.required' => 'Informe os minutos abonados.',
            'allowed_minutes.integer' => 'Os minutos abonados devem ser um número inteiro.',
            'allowed_minutes.min' => 'Os minutos abonados devem ser maiores que zero.',
            'allowed_minutes.max' => 'Os minutos abonados não podem passar de '.$max.' (duração da ocorrência).',
        ])->validate();

        return (int) $value;
    }

    private function parsedDate(string $date): ?CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false || $parsed->format('Y-m-d') !== $date ? null : CarbonImmutable::instance($parsed);
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
