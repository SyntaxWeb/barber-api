<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->dropUnique('appointments_data_horario_unique');
            $table->unique(['data', 'horario', 'company_id'], 'appointments_company_slot_unique');
        });

        Schema::table('blocked_days', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->dropUnique('blocked_days_data_unique');
            $table->unique(['data', 'company_id'], 'blocked_days_company_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blocked_days', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique('blocked_days_company_date_unique');
            $table->unique('data', 'blocked_days_data_unique');
            $table->dropColumn('company_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique('appointments_company_slot_unique');
            $table->unique(['data', 'horario'], 'appointments_data_horario_unique');
            $table->dropColumn('company_id');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
