<?php

namespace App\Services;

use App\Enums\BenefitType;
use App\Models\BenefitRate;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Resolve o valor diário global de VR ou VD vigente em uma data.
 *
 * Vigente = maior `valid_from` que seja menor ou igual à data. Não existe `valid_to`:
 * a vigência seguinte encerra implicitamente a anterior.
 */
class BenefitRateResolver
{
    /**
     * Retorna null quando não há valor vigente na data. Nunca assume zero: quem
     * consulta decide como tratar a ausência (o cálculo bloqueia, a tela avisa).
     *
     * @throws InvalidArgumentException para VT, que não possui valor global
     */
    public function forDate(BenefitType $benefitType, CarbonInterface $date): ?BenefitRate
    {
        if ($benefitType === BenefitType::Vt) {
            throw new InvalidArgumentException('VT não possui valor global; o valor diário vem do itinerário.');
        }

        return BenefitRate::query()
            ->where('benefit_type', $benefitType)
            ->where('valid_from', '<=', $date->toDateString())
            ->orderByDesc('valid_from')
            ->first();
    }
}
