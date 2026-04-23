<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Reseller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultResellerSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $reseller = Reseller::firstOrCreate(
                ['slug' => 'default'],
                ['name' => 'Default']
            );

            Company::whereNull('reseller_id')
                ->update(['reseller_id' => $reseller->id]);
        });
    }
}
