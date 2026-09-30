<?php

namespace Database\Seeders;

use App\Enums\BenefitType;
use App\Models\BenefitRate;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial do módulo de benefícios: 20 tarifas (planilha "VT e VR -09.2026.xlsx",
 * aba "Vale transp calc", linhas 3–4, colunas C–V) e os valores globais de VR/VD
 * (células W1/W2), todos vigentes a partir de 01/09/2026.
 *
 * Idempotente e não destrutivo: registros existentes (mesmo nome de tarifa, mesma
 * vigência) são mantidos como estão. Nomes repetidos na planilha recebem sufixo
 * distinto por causa do UNIQUE em transport_fares.name (OI-4).
 */
class BenefitCatalogSeeder extends Seeder
{
    public const VALID_FROM = '2026-09-01';

    public const VR_AMOUNT = '27.50';

    public const VD_AMOUNT = '7.50';

    /**
     * Tarifa => preço, na ordem das colunas C–V da planilha.
     *
     * @var array<string, string>
     */
    public const FARES = [
        'CPTM' => '5.40',
        'CMT BOM 1' => '5.90',
        'CMT BOM 2' => '5.90',
        '372' => '4.55',
        'BR7' => '5.95',
        'INT TRILHOS' => '6.65',
        'MUN MAUÁ' => '5.90',
        'SP' => '8.40',
        'RIB.PIRES' => '6.40',
        'UNI ABC' => '5.90',
        'ETC MAUÁ' => '5.90',
        'SZT MAUÁ' => '5.90',
        'TROLEBUS' => '6.35',
        'RGS' => '5.90',
        'ETC DIADEMA' => '4.50',
        'RGS TLM' => '5.30',
        'INTEGRAÇÃO 1,10' => '1.10',
        'INTEGRAÇÃO 1,40' => '1.40',
        'SP TRANS' => '10.32',
        'Rio Grande -Talismã' => '5.90',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::FARES as $name => $amount) {
            $fare = TransportFare::query()->firstOrCreate(['name' => $name], ['is_active' => true]);

            TransportFarePrice::query()->firstOrCreate(
                ['transport_fare_id' => $fare->id, 'valid_from' => self::VALID_FROM],
                ['amount' => $amount],
            );
        }

        foreach ([BenefitType::Vr->value => self::VR_AMOUNT, BenefitType::Vd->value => self::VD_AMOUNT] as $type => $amount) {
            BenefitRate::query()->firstOrCreate(
                ['benefit_type' => $type, 'valid_from' => self::VALID_FROM],
                ['amount' => $amount],
            );
        }
    }
}
