<?php

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\TwoFactor;
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
    
    Route::get('employees', \App\Livewire\EmployeeIndex::class)->name('employees.index');
    Route::get('employees/create', \App\Livewire\EmployeeCreate::class)->name('employees.create');
    Route::get('employees/{employee}/edit', \App\Livewire\EmployeeEdit::class)->name('employees.edit');
    Route::get('employees/{employee}/horas-extras', \App\Livewire\EmployeeHorasExtras::class)->name('employees.horas-extras');
    Route::get('employees/{employee}/points-edit/{date}', \App\Livewire\EmployeePointsEdit::class)->name('points-edit');
    // Route::get('employees/{employee}/points-edit/{date}', \App\Livewire\EmployeeHorasExtras::class)->name('points-edit');
});

require __DIR__.'/auth.php';
