<?php

namespace App\Enums;

/**
 * Classe temporal de um ajuste em relação à sua competência. É derivada das datas
 * do ajuste (BenefitPeriod::timingOf) e nunca é persistida.
 */
enum AdjustmentTiming: string
{
    case Forecast = 'forecast';
    case Realized = 'realized';
    case Retroactive = 'retroactive';

    public function label(): string
    {
        return match ($this) {
            self::Forecast => 'Previsto',
            self::Realized => 'Realizado',
            self::Retroactive => 'Correção retroativa',
        };
    }
}
