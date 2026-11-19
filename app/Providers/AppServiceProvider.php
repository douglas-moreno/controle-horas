<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::addNamespace('layouts', resource_path('views/components/layouts'));

        $this->defineGates();
    }

    /**
     * Gates das justificativas do controle de ponto (users.permissions):
     * - manage-point-justifications: registrar, consultar, editar e cancelar.
     * - change-closed-period-justifications: alterar justificativa que atinge competência
     *   fechada; exige as duas permissões.
     */
    private function defineGates(): void
    {
        Gate::define('manage-point-justifications', fn (User $user): bool => $user->hasPermission(Permission::Hr));

        Gate::define('change-closed-period-justifications', fn (User $user): bool => $user->hasPermission(Permission::Hr)
            && $user->hasPermission(Permission::HrClosedPeriod));
    }
}
