<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Support\Security\PasswordRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user && $user->is_active) {
            $token = Password::broker()->createToken($user);
            Mail::to($user->email)->send(new ResetPasswordMail($user, $token));
        }

        return response()->json([
            'message' => 'Se o email estiver cadastrado, você receberá as instruções em breve.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [...PasswordRules::required(), 'confirmed'],
        ], [
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.numbers' => 'A senha deve incluir pelo menos 1 numero.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Senha redefinida com sucesso.',
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Aguarde antes de tentar novamente.',
            ], 429);
        }

        return response()->json([
            'message' => 'Link inválido ou expirado. Solicite um novo.',
        ], 422);
    }
}
