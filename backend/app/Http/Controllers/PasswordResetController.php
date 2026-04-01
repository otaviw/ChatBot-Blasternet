<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Models\User;
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
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'password.min'       => 'A senha deve ter pelo menos 6 caracteres.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
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

        $errors = [
            Password::INVALID_TOKEN  => 'Link inválido ou expirado. Solicite um novo.',
            Password::INVALID_USER   => 'Não foi possível redefinir a senha.',
            Password::RESET_THROTTLED => 'Aguarde antes de tentar novamente.',
        ];

        return response()->json([
            'message' => $errors[$status] ?? 'Não foi possível redefinir a senha.',
        ], 422);
    }
}
