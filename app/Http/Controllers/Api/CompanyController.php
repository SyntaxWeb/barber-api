<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user()->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $this->ensureQrCode($company);

        return response()->json($company);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'icone' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('icone')) {
            if ($company->icon_path) {
                Storage::disk('public')->delete($company->icon_path);
            }
            $data['icon_path'] = $request->file('icone')->store('company-icons', 'public');
        }

        $company->update([
            'nome' => $data['nome'],
            'descricao' => $data['descricao'] ?? null,
            'icon_path' => $data['icon_path'] ?? $company->icon_path,
        ]);

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
