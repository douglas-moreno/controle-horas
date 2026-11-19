<?php

use App\Enums\Permission;
use App\Livewire\EmployeeIndex;
use App\Models\Employee;
use App\Models\PointJustification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a user without permissions cannot manage justifications', function () {
    $user = User::factory()->create();

    expect($user->permissions)->toBeNull()
        ->and(Gate::forUser($user)->allows('manage-point-justifications'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('change-closed-period-justifications'))->toBeFalse();
});

test('an HR user manages justifications but cannot change a closed period', function () {
    $user = User::factory()->create();
    $user->grantPermission(Permission::Hr);

    expect(Gate::forUser($user->fresh())->allows('manage-point-justifications'))->toBeTrue()
        ->and(Gate::forUser($user->fresh())->allows('change-closed-period-justifications'))->toBeFalse();
});

test('changing a closed period requires both permissions', function (array $permissions, bool $allowed) {
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->grantPermission($permission);
    }

    expect(Gate::forUser($user->fresh())->allows('change-closed-period-justifications'))->toBe($allowed);
})->with([
    'closed period only' => [[Permission::HrClosedPeriod], false],
    'hr and closed period' => [[Permission::Hr, Permission::HrClosedPeriod], true],
]);

test('granting and revoking are idempotent', function () {
    $user = User::factory()->create();

    $user->grantPermission(Permission::Hr);
    $user->grantPermission(Permission::Hr);
    $user->grantPermission(Permission::HrClosedPeriod);
    $user->revokePermission(Permission::HrClosedPeriod);
    $user->revokePermission(Permission::HrClosedPeriod);

    expect($user->fresh()->permissions)->toBe([Permission::Hr->value]);
});

test('permissions cannot be mass assigned', function () {
    $user = User::factory()->create();

    $user->fill(['permissions' => [Permission::Hr->value]])->save();

    expect($user->fresh()->permissions)->toBeNull();
});

test('the command grants, revokes and lists permissions', function () {
    $user = User::factory()->create(['email' => 'rh@example.com']);

    $this->artisan('users:permissions', ['email' => 'rh@example.com', '--grant' => ['hr', 'hr.closed_period']])
        ->expectsOutput('rh@example.com: hr, hr.closed_period')
        ->assertSuccessful();

    $this->artisan('users:permissions', ['email' => 'rh@example.com', '--revoke' => ['hr.closed_period']])
        ->expectsOutput('rh@example.com: hr')
        ->assertSuccessful();

    $this->artisan('users:permissions', ['email' => 'rh@example.com'])
        ->expectsOutput('rh@example.com: hr')
        ->assertSuccessful();

    expect($user->fresh()->hasPermission(Permission::Hr))->toBeTrue()
        ->and($user->fresh()->hasPermission(Permission::HrClosedPeriod))->toBeFalse();
});

test('the command refuses unknown users and permissions without writing', function () {
    $user = User::factory()->create(['email' => 'rh@example.com']);

    $this->artisan('users:permissions', ['email' => 'outro@example.com', '--grant' => ['hr']])
        ->expectsOutput('Usuário não encontrado.')
        ->assertFailed();

    $this->artisan('users:permissions', ['email' => 'rh@example.com', '--grant' => ['hr', 'admin']])
        ->assertFailed();

    expect($user->fresh()->permissions)->toBeNull();
});

test('an employee with justifications cannot be deleted from the employee list', function () {
    $this->actingAs(User::factory()->create());
    $employee = Employee::factory()->create();
    PointJustification::factory()->canceled()->for($employee)->create();

    Livewire::test(EmployeeIndex::class)->call('destroy', $employee->id);

    $this->assertModelExists($employee);
});
