<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user('sanctum')?->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $this->ensureQrCode($company);

        return response()->json($company);
    }

    public function update(Request $request)
    {
        $user = $request->user('sanctum');

        $request->merge([
            'notify_via_email'     => filter_var($request->notify_via_email, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'notify_via_telegram'  => filter_var($request->notify_via_telegram, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);
        
        $company = $user->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $hexRule = ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/i'];

        $validator = Validator::make($request->all(), [
            'nome'                 => 'required|string|max:255',
            'descricao'            => 'nullable|string|max:1000',
            'icone'                => 'nullable|image|max:2048',
            'notify_email'         => 'nullable|email',
            'notify_via_email'     => 'nullable|boolean',
            'notify_via_telegram'  => 'nullable|boolean',
            'dashboard_theme'             => ['nullable', 'array'],
            'dashboard_theme.primary'    => $hexRule,
            'dashboard_theme.secondary'  => $hexRule,
            'dashboard_theme.background' => $hexRule,
            'dashboard_theme.surface'    => $hexRule,
            'dashboard_theme.text'       => $hexRule,
            'dashboard_theme.accent'     => $hexRule,
            'client_theme'               => ['nullable', 'array'],
            'client_theme.primary'       => $hexRule,
            'client_theme.secondary'     => $hexRule,
            'client_theme.background'    => $hexRule,
            'client_theme.surface'       => $hexRule,
            'client_theme.text'          => $hexRule,
            'client_theme.accent'        => $hexRule,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Erro de validação.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($request->hasFile('icone')) {
            if ($company->icon_path) {
                Storage::disk('public')->delete($company->icon_path);
            }
            $data['icon_path'] = $request->file('icone')->store('company-icons', 'public');
        }

        $updateData = [
            'nome' => $data['nome'],
            'descricao' => $data['descricao'] ?? null,
            'icon_path' => $data['icon_path'] ?? $company->icon_path,
            'notify_email' => $data['notify_email'] ?? $company->notify_email,
            'notify_via_email' => $request->boolean('notify_via_email'),
            'notify_via_telegram' => $request->boolean('notify_via_telegram'),
        ];

        if (array_key_exists('dashboard_theme', $data)) {
            $updateData['dashboard_theme'] = $data['dashboard_theme'];
        }

        if (array_key_exists('client_theme', $data)) {
            $updateData['client_theme'] = $data['client_theme'];
        }

        $company->update($updateData);

        $company->refresh();
        $this->ensureQrCode($company);

        return response()->json($company);
    }

    public function publicShow(Company $company)
    {
        $this->ensureQrCode($company);
        return response()->json($company);
    }

    protected function ensureQrCode(?Company $company): void
    {
        if (!$company) {
            return;
        }

        if ($company->qr_code_svg) {
            return;
        }

        $company->qr_code_svg = null;
    }
}
