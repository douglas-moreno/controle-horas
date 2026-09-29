<?php

namespace App\Enums;

enum BenefitType: string
{
    case Vt = 'vt';
    case Vr = 'vr';
    case Vd = 'vd';

    public function label(): string
    {
        return match ($this) {
            self::Vt => 'VT',
            self::Vr => 'VR',
            self::Vd => 'VD',
        };
    }
}
