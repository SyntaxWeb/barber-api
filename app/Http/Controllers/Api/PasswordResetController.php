<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function forgot(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])
            ->whereIn('role', ['provider', 'admin'])
            ->first();

        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ],
            );

            $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
            $resetUrl = "{$frontendUrl}/redefinir-senha?token={$token}&email=" . urlencode($user->email);

            try {
                Mail::raw(
                    "Recebemos uma solicitação para redefinir sua senha no SyntaxAtendimento.\n\n" .
                    "Acesse o link abaixo para criar uma nova senha:\n{$resetUrl}\n\n" .
                    "Este link expira em 60 minutos. Se você não solicitou essa alteração, ignore este e-mail.",
                    function ($message) use ($user) {
                        $message->to($user->email)
                            ->subject('Redefinição de senha - SyntaxAtendimento');
                    },
                );
            } catch (\Throwable $error) {
                Log::error('Falha ao enviar e-mail de redefinição de senha.', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $error->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Não foi possível enviar o e-mail de redefinição. Verifique a configuração de e-mail.',
                ], 500);
            }
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, enviaremos um link para redefinir sua senha.',
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (!$record || now()->diffInMinutes($record->created_at) > 60 || !Hash::check($data['token'], $record->token)) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 422);
        }

        $user = User::where('email', $data['email'])
            ->whereIn('role', ['provider', 'admin'])
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }
}
