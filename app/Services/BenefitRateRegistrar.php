<?php

namespace App\Services;

use App\Enums\BenefitType;
use App\Models\BenefitRate;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Registra vigências de valor diário de VR/VD sem nunca sobrescrever o histórico:
 * alterar um valor é sempre cadastrar uma nova vigência.
 */
class BenefitRateRegistrar
{
    /**
     * @throws InvalidArgumentException quando o tipo, o valor ou a vigência violam as regras
     */
    public function register(BenefitType $benefitType, string $amount, CarbonInterface $validFrom, ?int $userId = null): BenefitRate
    {
        if (! in_array($benefitType, [BenefitType::Vr, BenefitType::Vd], true)) {
            throw new InvalidArgumentException('Somente VR e VD possuem valor diário global.');
        }

        if ($validFrom->day !== 1) {
            throw new InvalidArgumentException('A vigência de VR/VD deve começar no dia 01 do mês.');
        }

        $cents = Money::toCents($amount);

        if ($cents < 0) {
            throw new InvalidArgumentException('O valor diário não pode ser negativo.');
        }

        $rate = new BenefitRate([
            'benefit_type' => $benefitType,
            'amount' => Money::fromCents($cents),
            'valid_from' => $validFrom,
        ]);
        $rate->created_by = $userId;
        $rate->save();

        return $rate;
    }

    /**
     * Uma vigência só pode ser excluída enquanto nenhum cálculo a utilizou e enquanto
     * não fizer parte do histórico de uma competência fechada (início até 01 da última
     * competência fechada).
     */
    public function canDelete(BenefitRate $benefitRate): bool
    {
        return ! $benefitRate->isProtectedByClosedPeriod()
            && ! $benefitRate->calculations()->exists();
    }
}
