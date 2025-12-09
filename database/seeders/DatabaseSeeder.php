<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $baseUrl = rtrim(config('app.url') ?? 'http://localhost:4001', '/');

        $company = \App\Models\Company::firstOrCreate(
            ['slug' => 'syntaxatendimento'],
            [
                'nome' => 'SyntaxAtendimento Demo',
                'descricao' => 'Ambiente demonstrativo',
                'agendamento_url' => "{$baseUrl}/e/syntaxatendimento/agendar",
            ]
        );

        $user = \App\Models\User::firstOrCreate(
            ['email' => 'carlos@barbeariavintage.com'],
            [
                'name' => 'Carlos Vintage',
                'password' => bcrypt('password'),
                'telefone' => '(11) 99999-9999',
                'objetivo' => 'Conta demonstrativa',
                'role' => 'provider',
                'company_id' => $company->id,
            ]
        );

        \App\Models\Setting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'horario_inicio' => '09:00',
                'horario_fim' => '19:00',
                'intervalo_minutos' => 30,
            ]
        );

        \App\Models\Service::insertOrIgnore([
            [
                'nome' => 'Corte Máquina',
                'preco' => 35,
                'duracao_minutos' => 30,
                'company_id' => $company->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Corte Tesoura',
                'preco' => 45,
                'duracao_minutos' => 45,
                'company_id' => $company->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Corte Degradê',
                'preco' => 40,
                'duracao_minutos' => 40,
                'company_id' => $company->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Barba Completa',
                'preco' => 30,
                'duracao_minutos' => 30,
                'company_id' => $company->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Combo Corte + Barba',
                'preco' => 55,
                'duracao_minutos' => 60,
                'company_id' => $company->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
