<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_nome_unique');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unique(['company_id', 'nome'], 'services_company_nome_unique');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_company_nome_unique');
            $table->unique('nome', 'services_nome_unique');
        });
    }
};
