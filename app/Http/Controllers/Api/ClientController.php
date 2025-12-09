<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $provider = $request->user();
        $search = $request->string('search')->toString();

        $clients = User::query()
            ->where('role', 'client')
            ->where('company_id', $provider->company_id)
            ->when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('telefone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return ClientResource::collection($clients);
    }

    public function store(Request $request)
    {
        
        $provider = $request->user();
        if (!$provider->company_id) {
            return response()->json(['message' => 'Empresa nao encontrada para este usuario.'], 422);
        }

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'telefone' => ['required', 'string', 'max:40'],
            'observacoes' => ['nullable', 'string'],
        ]);

        $client = User::create([
            'name' => $data['nome'],
            'email' => $data['email'],
            'telefone' => $data['telefone'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
            'password' => Hash::make(Str::random(16)),
            'role' => 'client',
            'company_id' => $provider->company_id,
        ]);

        return new ClientResource($client);
    }
}
