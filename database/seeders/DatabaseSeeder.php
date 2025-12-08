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
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'carlos@barbeariavintage.com'],
            [
                'name' => 'Carlos Vintage',
                'password' => bcrypt('password'),
            ]
        );

        \App\Models\Setting::firstOrCreate([], [
            'horario_inicio' => '09:00',
            'horario_fim' => '19:00',
            'intervalo_minutos' => 30,
        ]);

        \App\Models\Service::insertOrIgnore([
            ['nome' => 'Corte Máquina', 'preco' => 35, 'duracao_minutos' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Corte Tesoura', 'preco' => 45, 'duracao_minutos' => 45, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Corte Degradê', 'preco' => 40, 'duracao_minutos' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Barba Completa', 'preco' => 30, 'duracao_minutos' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Combo Corte + Barba', 'preco' => 55, 'duracao_minutos' => 60, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
