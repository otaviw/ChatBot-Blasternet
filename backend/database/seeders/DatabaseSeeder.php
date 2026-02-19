<?php

namespace Database\Seeders;

use App\Models\Company;
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
        $company = Company::firstOrCreate(
            ['name' => 'teste Company'],
            [
                'meta_phone_number_id' => null,
                'meta_access_token' => null,
            ]
        );

        User::updateOrCreate([
            'email' => 'admin@teste.local',
        ], [
            'name' => 'teste Admin',
            'password' => 'teste123',
            'role' => 'admin',
            'company_id' => null,
            'is_active' => true,
        ]);

        User::updateOrCreate([
            'email' => 'empresa@teste.local',
        ], [
            'name' => 'teste Empresa',
            'password' => 'teste123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }
}
