<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('rule_type')->default('spend');
            $table->unsignedInteger('spend_amount_cents_per_point')->default(1000);
            $table->unsignedInteger('points_per_visit')->default(1);
            $table->boolean('expiration_enabled')->default(false);
            $table->unsignedInteger('expiration_days')->nullable();
            $table->timestamps();

            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
    }
};
