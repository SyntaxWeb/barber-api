<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleClientVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ClientAuthController extends Controller
{
    public function register(Request $request)
    {
        // Dados recebidos da requisição
        $dados = $request->all();

        // Validação manual com mensagens personalizadas
        $validator = Validator::make($dados, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'telefone' => ['required', 'string', 'max:40'],
        ]);

        // Verifica se a validação falhou
        if ($validator->fails()) {
            // Retorna os erros de validação com um status 422 (Unprocessable Entity)
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        // Se a validação passar, prossegue com o processamento
        $data = $validator->validated();

        $client = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'telefone' => $data['telefone'],
            'role' => 'client',
        ]);

        $token = $client->createToken('client_token', ['client'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $client,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        /** @var \App\Models\User $client */
        $client = Auth::user();

        if ($client->role !== 'client') {
            Auth::logout();
            return response()->json(['message' => 'Esta conta não é de cliente.'], 403);
        }

        $token = $client->createToken('client_token', ['client'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $client,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function loginWithGoogle(Request $request, GoogleClientVerifier $googleClientVerifier)
    {
        $data = $request->validate([
            'credential' => ['required', 'string'],
        ]);

        try {
            $payload = $googleClientVerifier->verify($data['credential']);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Não foi possível validar o token do Google.',
            ], 422);
        }

        $client = User::firstOrCreate(
            ['email' => $payload['email']],
            [
                'name' => $payload['name'] ?? $payload['given_name'] ?? 'Cliente Google',
                'password' => Hash::make(Str::random(32)),
                'telefone' => $payload['phone_number'] ?? null,
                'role' => 'client',
            ],
        );

        if ($client->role !== 'client') {
            return response()->json(['message' => 'Esta conta já está vinculada a outro perfil.'], 403);
        }

        $token = $client->createToken('client_token', ['client'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $client,
        ]);
    }
}
