<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('service_rating');
            $table->unsignedTinyInteger('professional_rating');
            $table->unsignedTinyInteger('scheduling_rating');
            $table->text('comment')->nullable();
            $table->boolean('allow_public_testimonial')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_feedback');
    }
};
