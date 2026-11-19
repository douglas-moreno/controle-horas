<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Consulta, concede e retira permissões individuais de um usuário (App\Enums\Permission).
 * Sem --grant nem --revoke, só lista as permissões atuais. Código de saída 1 quando o
 * usuário ou a permissão não existem; nesse caso nada é gravado.
 */
#[Signature('users:permissions
    {email : E-mail do usuário}
    {--grant=* : Permissão a conceder (hr, hr.closed_period)}
    {--revoke=* : Permissão a retirar}')]
#[Description('Consulta, concede ou retira permissões de um usuário')]
class UserPermissionsCommand extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $grant = $this->permissions((array) $this->option('grant'));
        $revoke = $this->permissions((array) $this->option('revoke'));

        if ($grant === null || $revoke === null) {
            $this->error('Permissão desconhecida. Use: '.implode(', ', array_column(Permission::cases(), 'value')).'.');

            return self::FAILURE;
        }

        foreach ($grant as $permission) {
            $user->grantPermission($permission);
        }

        foreach ($revoke as $permission) {
            $user->revokePermission($permission);
        }

        $current = $user->refresh()->permissions ?? [];
        $this->line($user->email.': '.($current === [] ? 'nenhuma permissão' : implode(', ', $current)));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $values
     * @return list<Permission>|null null quando algum valor não é uma permissão conhecida.
     */
    private function permissions(array $values): ?array
    {
        $permissions = array_map(fn (string $value) => Permission::tryFrom($value), $values);

        return in_array(null, $permissions, true) ? null : $permissions;
    }
}
