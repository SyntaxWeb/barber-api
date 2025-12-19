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

        $adminEmail = env('SUPERADMIN_EMAIL', 'admin@barber.com');
        $adminPassword = env('SUPERADMIN_PASSWORD', 'admin123');

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

        if (\App\Models\Feedback::where('company_id', $company->id)->count() === 0) {
            \App\Models\Feedback::insert([
                [
                    'company_id' => $company->id,
                    'client_name' => 'João Silva',
                    'rating' => 5,
                    'comment' => 'Atendimento impecável e ambiente acolhedor. Voltarei com certeza!',
                    'created_at' => now()->subDays(2),
                    'updated_at' => now()->subDays(2),
                ],
                [
                    'company_id' => $company->id,
                    'client_name' => 'Mariana Costa',
                    'rating' => 4,
                    'comment' => 'Profissionais pontuais e muito educados. Poderia ter mais opções de horários.',
                    'created_at' => now()->subDay(),
                    'updated_at' => now()->subDay(),
                ],
                [
                    'company_id' => $company->id,
                    'client_name' => 'Pedro Oliveira',
                    'rating' => 5,
                    'comment' => 'Melhor corte que já fiz! Recomendo demais o trabalho do Carlos.',
                    'created_at' => now()->subHours(8),
                    'updated_at' => now()->subHours(8),
                ],
            ]);
        }

        \App\Models\User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Super Admin',
                'password' => bcrypt($adminPassword),
                'role' => 'admin',
                'company_id' => null,
            ]
        );
    }
}
