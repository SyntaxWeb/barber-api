<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->role !== 'provider') {
            Auth::logout();
            return response()->json(['message' => 'Acesso permitido apenas para administradores.'], 403);
        }

        $token = $user->createToken('provider_token', ['provider'])->plainTextToken;

        $user->load('company');

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function register(Request $request)
    {
        // Dados recebidos da requisição
        $dados = $request->all();

        // Validação manual com mensagens personalizadas
        $validator = Validator::make($dados, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'telefone' => ['required', 'string', 'max:40'],
            'objetivo' => ['nullable', 'string', 'max:1000'],
            'empresa' => ['nullable', 'string', 'max:255'],
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

        $companyName = $request->input('empresa') ?: ($data['objetivo'] ?? ($data['name'] . ' Studio'));
        $slug = Company::generateUniqueSlug($companyName);
        $baseUrl = rtrim(config('app.frontend_url', config('app.url', 'http://localhost')), '/');

        $company = Company::create([
            'nome' => $companyName,
            'slug' => $slug,
            'descricao' => $data['objetivo'] ?? null,
            'agendamento_url' => "{$baseUrl}/e/{$slug}/agendar",
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'telefone' => $data['telefone'],
            'objetivo' => $data['objetivo'] ?? null,
            'role' => 'provider',
            'company_id' => $company->id,
        ]);

        $user->load('company');

        $token = $user->createToken('provider_token', ['provider'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logout realizado']);
    }

    public function me(Request $request)
    {
        return $request->user()->load('company');
    }
}
