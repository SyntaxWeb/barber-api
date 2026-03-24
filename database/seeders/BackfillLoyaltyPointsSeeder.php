<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Services\LoyaltyService;
use Illuminate\Database\Seeder;

class BackfillLoyaltyPointsSeeder extends Seeder
{
    public function run(): void
    {
        $loyalty = app(LoyaltyService::class);

        Appointment::query()
            ->with(['user'])
            ->where('status', 'concluido')
            ->whereHas('user', function ($query) {
                $query->where('role', 'client');
            })
            ->orderBy('id')
            ->get()
            ->each(function (Appointment $appointment) use ($loyalty) {
                $loyalty->awardForAppointment($appointment);
            });
    }
}
