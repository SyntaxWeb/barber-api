<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['appointment_id', 'service_id']);
        });

        $appointments = DB::table('appointments')
            ->select('id', 'service_id')
            ->whereNotNull('service_id')
            ->get();

        $now = now();
        $rows = [];
        foreach ($appointments as $appointment) {
            $rows[] = [
                'appointment_id' => $appointment->id,
                'service_id' => $appointment->service_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('appointment_service')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_service');
    }
};
