<?php

namespace Database\Seeders;

use App\Enums\Title;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name'         => 'Test User',
            'title'        => Title::Dr->value,
            'first_name'   => 'Jane',
            'last_name'    => 'Smith',
            'address'      => '42 Acacia Avenue, London, EC1A 1BB',
            'phone_number' => '+44 20 7946 0958',
            'email'        => 'test@example.com',
        ]);
    }
}
