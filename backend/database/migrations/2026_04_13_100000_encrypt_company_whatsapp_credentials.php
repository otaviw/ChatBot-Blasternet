<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')->orderBy('id')->each(function ($company) {
            $updates = [];

            if ($company->meta_access_token !== null && $company->meta_access_token !== '') {
                $plaintext = $this->decryptLegacy($company->meta_access_token);
                if ($plaintext !== null) {
                    $updates['meta_access_token'] = Crypt::encrypt($plaintext, false);
                }
            }

            if ($company->meta_waba_id !== null && $company->meta_waba_id !== '') {
                $plaintext = $this->decryptLegacy($company->meta_waba_id);
                if ($plaintext !== null) {
                    $updates['meta_waba_id'] = Crypt::encrypt($plaintext, false);
                }
            }

            if ($updates !== []) {
                DB::table('companies')->where('id', $company->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
    }

    /**
     * Tenta descriptografar um valor que pode estar em qualquer formato:
     * - Crypt::encrypt() (cast 'encrypted') — já no formato certo, extrai o plaintext
     * - Crypt::encryptString() (accessor antigo)
     * - plain text legado
     */
    private function decryptLegacy(string $value): ?string
    {
        try {
            return (string) Crypt::decrypt($value, false);
        } catch (\Throwable) {
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
        }

        return $value;
    }
};
