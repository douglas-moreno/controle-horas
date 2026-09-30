<?php

use App\Http\Controllers\TimesheetPrintController;
use App\Livewire\Benefits\BenefitPeriodAdjustments;
use App\Livewire\Benefits\BenefitPeriodIndex;
use App\Livewire\Benefits\BenefitRateIndex;
use App\Livewire\Benefits\EmployeeBenefitsEdit;
use App\Livewire\Benefits\TransportFareEdit;
use App\Livewire\Benefits\TransportFareIndex;
use App\Livewire\EmployeeCreate;
use App\Livewire\EmployeeEdit;
use App\Livewire\EmployeeHorasExtras;
use App\Livewire\EmployeeIndex;
use App\Livewire\EmployeePointsEdit;
use App\Livewire\EmployeeResumeReport;
use App\Livewire\EmployeesExtraReport;
use App\Livewire\HolidayCreate;
use App\Livewire\HolidayEdit;
use App\Livewire\HolidayIndex;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\TwoFactor;
use App\Livewire\TimesheetPrint;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::permanentRedirect('/', 'dashboard');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', Profile::class)->name('settings.profile');
    Route::get('settings/password', Password::class)->name('settings.password');
    Route::get('settings/appearance', Appearance::class)->name('settings.appearance');

    Route::get('settings/two-factor', TwoFactor::class)
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    Route::get('employees', EmployeeIndex::class)->name('employees.index');
    Route::get('employees/create', EmployeeCreate::class)->name('employees.create');
    Route::get('employees/{employee}/edit', EmployeeEdit::class)->name('employees.edit');
    Route::get('employees/{employee}/horas-extras', EmployeeHorasExtras::class)->name('employees.horas-extras');
    Route::get('employees/{employee}/points-edit/{date}', EmployeePointsEdit::class)->name('points-edit');
    Route::get('employees/{employee}/benefits', EmployeeBenefitsEdit::class)->name('employees.benefits');
    Route::get('holidays', HolidayIndex::class)->name('holidays.index');
    Route::get('holidays/create', HolidayCreate::class)->name('holidays.create');
    Route::get('holidays/{holiday}/edit', HolidayEdit::class)->name('holidays.edit');
    Route::get('/reports/extra-hours', EmployeesExtraReport::class)->name('reports.extra-hours');
    Route::get('/reports/resume', EmployeeResumeReport::class)->name('reports.resume');
    Route::get('/reports/timesheet', TimesheetPrint::class)->name('reports.timesheet');
    Route::get('/reports/timesheet/print', TimesheetPrintController::class)->name('reports.timesheet.print');

    Route::get('benefits/periods', BenefitPeriodIndex::class)->name('benefits.periods.index');
    Route::get('benefits/periods/{benefitPeriod}/adjustments', BenefitPeriodAdjustments::class)->name('benefits.periods.adjustments');
    Route::get('benefits/rates', BenefitRateIndex::class)->name('benefits.rates.index');
    Route::get('benefits/fares', TransportFareIndex::class)->name('benefits.fares.index');
    Route::get('benefits/fares/{transportFare}/edit', TransportFareEdit::class)->name('benefits.fares.edit');
});

require __DIR__.'/auth.php';
