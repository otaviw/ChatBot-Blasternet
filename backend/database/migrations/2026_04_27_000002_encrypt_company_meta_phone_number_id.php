<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'meta_phone_number_id_hash')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('meta_phone_number_id_hash', 64)
                    ->nullable()
                    ->index()
                    ->after('meta_phone_number_id');
            });
        }

        if (! Schema::hasColumn('companies', 'meta_phone_number_id')) {
            return;
        }

        DB::table('companies')->orderBy('id')->each(function ($company) {
            $rawPhoneNumberId = $company->meta_phone_number_id ?? null;
            if ($rawPhoneNumberId === null || $rawPhoneNumberId === '') {
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['meta_phone_number_id_hash' => null]);
                return;
            }

            $phoneNumberId = $this->decryptLegacy((string) $rawPhoneNumberId);

            DB::table('companies')->where('id', $company->id)->update([
                'meta_phone_number_id' => Crypt::encryptString($phoneNumberId),
                'meta_phone_number_id_hash' => $this->phoneNumberIdHash($phoneNumberId),
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'meta_phone_number_id_hash')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('meta_phone_number_id_hash');
            });
        }
    }

    private function decryptLegacy(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
        }

        try {
            return (string) Crypt::decrypt($value, false);
        } catch (\Throwable) {
        }

        return $value;
    }

    private function phoneNumberIdHash(?string $phoneNumberId): ?string
    {
        $normalized = trim((string) $phoneNumberId);
        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
};
