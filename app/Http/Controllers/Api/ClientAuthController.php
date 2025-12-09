<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ClientAuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'telefone' => ['required', 'string', 'max:40'],
        ]);

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
}
