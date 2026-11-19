<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    /**
     * Permissão individual do usuário. Sem a coluna preenchida, nenhuma permissão.
     */
    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions ?? [], true);
    }

    /**
     * Concede a permissão e grava. Sem efeito quando o usuário já a possui.
     */
    public function grantPermission(Permission $permission): void
    {
        if ($this->hasPermission($permission)) {
            return;
        }

        $this->permissions = [...($this->permissions ?? []), $permission->value];
        $this->save();
    }

    /**
     * Retira a permissão e grava. Sem efeito quando o usuário não a possui.
     */
    public function revokePermission(Permission $permission): void
    {
        if (! $this->hasPermission($permission)) {
            return;
        }

        $this->permissions = array_values(array_diff($this->permissions ?? [], [$permission->value]));
        $this->save();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
