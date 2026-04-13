<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Re-salva meta_access_token e meta_waba_id usando Crypt::encrypt() (formato do cast
// 'encrypted' do Laravel), substituindo o formato antigo de Crypt::encryptString().
// meta_phone_number_id não é criptografado pois é usado em WHERE e UNIQUE constraints.
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
        // Sem rollback destrutivo: descriptografar de volta para plain text
        // removeria a proteção. Execute um novo deploy se necessário.
    }

    /**
     * Tenta descriptografar um valor que pode estar em qualquer formato:
     * - Crypt::encrypt() (cast 'encrypted') — já no formato certo, extrai o plaintext
     * - Crypt::encryptString() (accessor antigo)
     * - plain text legado
     */
    private function decryptLegacy(string $value): ?string
    {
        // Tenta Crypt::decrypt() (formato do cast 'encrypted', serialize=false)
        try {
            return (string) Crypt::decrypt($value, false);
        } catch (\Throwable) {
        }

        // Tenta Crypt::decryptString() (formato do accessor antigo)
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
        }

        // Plain text legado (nunca foi criptografado)
        return $value;
    }
};
