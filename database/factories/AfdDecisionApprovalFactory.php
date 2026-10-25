<?php

namespace Database\Factories;

use App\Models\AfdDecisionApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdDecisionApproval>
 */
class AfdDecisionApprovalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'approver_name' => fn (array $attributes) => User::query()->find($attributes['user_id'])?->name ?? fake()->name(),
            'approver_email' => fn (array $attributes) => User::query()->find($attributes['user_id'])?->email ?? fake()->safeEmail(),
            'manifest_sha256' => hash('sha256', fake()->uuid()),
            'source_manifest_sha256' => hash('sha256', fake()->uuid()),
            'preview_sha256' => hash('sha256', fake()->uuid()),
            'rows' => 1,
            'target' => ['hostname' => 'ensaio', 'server_uuid' => fake()->uuid(), 'database' => 'testing'],
            'approved_at' => now(),
        ];
    }

    /**
     * Aprovação do manifesto gravado em `$path` (SHA-256 do conteúdo do arquivo).
     */
    public function forManifest(string $path): static
    {
        return $this->state(fn (array $attributes) => ['manifest_sha256' => hash_file('sha256', $path)]);
    }
}
