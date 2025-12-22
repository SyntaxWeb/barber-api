<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('cliente');
            $table->string('telefone');
            $table->date('data');
            $table->string('horario');
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('preco', 10, 2);
            $table->enum('status', ['confirmado', 'concluido', 'cancelado'])->default('confirmado');
            $table->text('observacoes')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['data', 'horario'], 'appointments_data_horario_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
