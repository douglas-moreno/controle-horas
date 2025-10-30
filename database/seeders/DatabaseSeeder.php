<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Douglas Moreno',
            'email' => 'douglas_moreno@dalferinox.com.br',
            'password' => bcrypt('dogaom08'),
        ]);

        User::factory()->create([
            'name' => 'Vanessa Nazaro',
            'email' => 'drh@dalferinox.com.br',
            'password' => bcrypt('super'),
        ]);
    }
}
