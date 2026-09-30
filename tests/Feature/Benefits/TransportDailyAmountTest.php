<?php

use App\Models\Employee;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Services\TransportDailyAmount;
use App\Services\TransportFarePriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dailyAmount = new TransportDailyAmount(new TransportFarePriceResolver);
    $this->employee = Employee::factory()->create();
});

function fareWithPrice(string $name, string $amount, string $validFrom = '2026-01-01'): TransportFare
{
    $fare = TransportFare::factory()->create(['name' => $name]);
    TransportFarePrice::factory()->for($fare)->amount($amount)->validFrom($validFrom)->create();

    return $fare;
}

function leg(Employee $employee, TransportFare $fare, int $tripsPerDay, string $startsOn = '2026-01-01', ?string $endsOn = null): TransportRoute
{
    return TransportRoute::factory()->for($employee)->for($fare)->tripsPerDay($tripsPerDay)->between($startsOn, $endsOn)->create();
}

test('G2: two legs sum fare times trips', function () {
    $cptmRoute = leg($this->employee, fareWithPrice('CPTM', '5.40'), 2);
    $spRoute = leg($this->employee, fareWithPrice('SP', '8.40'), 2);

    $result = $this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-10-01'));

    expect($result['daily_cents'])->toBe(2760)
        ->and($result['missing_prices'])->toBe([])
        ->and($result['items'])->toBe([
            ['route_id' => $cptmRoute->id, 'fare_id' => $cptmRoute->transport_fare_id, 'fare_name' => 'CPTM', 'fare_cents' => 540, 'trips_per_day' => 2, 'daily_cents' => 1080],
            ['route_id' => $spRoute->id, 'fare_id' => $spRoute->transport_fare_id, 'fare_name' => 'SP', 'fare_cents' => 840, 'trips_per_day' => 2, 'daily_cents' => 1680],
        ]);
});

test('G4: legs with different trips per day', function () {
    leg($this->employee, fareWithPrice('UNI ABC', '5.90'), 2);
    leg($this->employee, fareWithPrice('TROLEBUS', '6.35'), 1);
    leg($this->employee, fareWithPrice('ETC DIADEMA', '4.50'), 1);
    leg($this->employee, fareWithPrice('INTEGRAÇÃO 1,40', '1.40'), 1);

    $result = $this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-09-01'));

    expect($result['daily_cents'])->toBe(1180 + 635 + 450 + 140)
        ->and($result['daily_cents'])->toBe(2405)
        ->and(array_column($result['items'], 'daily_cents'))->toBe([1180, 635, 450, 140]);
});

test('G10: a fare with cents is exact', function () {
    leg($this->employee, fareWithPrice('SP TRANS', '10.32'), 2);

    expect($this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-09-01'))['daily_cents'])->toBe(2064);
});

test('a mid month price change applies from the next reference', function () {
    $cptm = fareWithPrice('CPTM', '5.40', '2026-09-01');
    TransportFarePrice::factory()->for($cptm)->amount('5.80')->validFrom('2026-10-15')->create();
    leg($this->employee, $cptm, 2);

    expect($this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-10-01'))['daily_cents'])->toBe(1080)
        ->and($this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-11-01'))['daily_cents'])->toBe(1160);
});

test('a mid month itinerary change applies from the next reference', function () {
    leg($this->employee, fareWithPrice('CPTM', '5.40'), 2, '2026-09-01', '2026-10-14');
    leg($this->employee, fareWithPrice('SP', '8.40'), 2, '2026-10-15');

    $october = $this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-10-01'));
    $november = $this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-11-01'));

    expect(array_column($october['items'], 'fare_name'))->toBe(['CPTM'])
        ->and($october['daily_cents'])->toBe(1080)
        ->and(array_column($november['items'], 'fare_name'))->toBe(['SP'])
        ->and($november['daily_cents'])->toBe(1680);
});

test('an employee without legs has zero and no missing prices', function () {
    expect($this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-10-01')))
        ->toBe(['daily_cents' => 0, 'items' => [], 'missing_prices' => []]);
});

test('a leg without a price in force is reported and never counted as zero', function () {
    leg($this->employee, fareWithPrice('CPTM', '5.40'), 2);
    $unpriced = TransportFare::factory()->create(['name' => 'Sem Preço']);
    leg($this->employee, $unpriced, 2);
    $future = fareWithPrice('Futura', '9.00', '2026-12-01');
    leg($this->employee, $future, 2);

    $result = $this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-10-01'));

    expect($result['daily_cents'])->toBe(1080)
        ->and(array_column($result['items'], 'fare_name'))->toBe(['CPTM'])
        ->and($result['missing_prices'])->toBe([$unpriced->id, $future->id]);
});

test('legs of other employees are ignored', function () {
    leg($this->employee, fareWithPrice('CPTM', '5.40'), 2);
    leg(Employee::factory()->create(), fareWithPrice('SP', '8.40'), 2);

    expect($this->dailyAmount->forEmployee($this->employee, CarbonImmutable::parse('2026-10-01'))['daily_cents'])->toBe(1080);
});

test('many employees are resolved with a fixed number of queries', function (int $employeeCount) {
    $cptm = fareWithPrice('CPTM', '5.40');
    $sp = fareWithPrice('SP', '8.40');
    $employees = Employee::factory()->count($employeeCount)->create();
    $employees->each(function (Employee $employee) use ($cptm, $sp) {
        leg($employee, $cptm, 2);
        leg($employee, $sp, 2);
    });

    DB::enableQueryLog();
    $results = $this->dailyAmount->forEmployees($employees, CarbonImmutable::parse('2026-10-01'));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBe(3)
        ->and($results)->toHaveCount($employeeCount)
        ->and($results->pluck('daily_cents')->unique()->all())->toBe([2760]);
})->with([2, 10]);
