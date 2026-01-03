<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('services', 'services_company_id_index')) {
            Schema::table('services', function (Blueprint $table) {
                $table->index('company_id', 'services_company_id_index');
            });
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_company_nome_unique');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unique(['company_id', 'nome'], 'services_company_nome_unique');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return count(DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        )) > 0;
    }
};
