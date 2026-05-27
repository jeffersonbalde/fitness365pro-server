<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClientSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a test client
        Client::firstOrCreate(
            ['email' => 'client@example.com'],
            [
                'email' => 'client@example.com',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
            );
    }
}
