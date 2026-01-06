<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'notify_whatsapp') && !Schema::hasColumn('companies', 'notify_telegram')) {
                DB::statement('ALTER TABLE companies CHANGE notify_whatsapp notify_telegram VARCHAR(255) NULL');
            }
            if (Schema::hasColumn('companies', 'notify_via_whatsapp') && !Schema::hasColumn('companies', 'notify_via_telegram')) {
                DB::statement('ALTER TABLE companies CHANGE notify_via_whatsapp notify_via_telegram TINYINT(1) NOT NULL DEFAULT 0');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'notify_telegram') && !Schema::hasColumn('companies', 'notify_whatsapp')) {
                DB::statement('ALTER TABLE companies CHANGE notify_telegram notify_whatsapp VARCHAR(255) NULL');
            }
            if (Schema::hasColumn('companies', 'notify_via_telegram') && !Schema::hasColumn('companies', 'notify_via_whatsapp')) {
                DB::statement('ALTER TABLE companies CHANGE notify_via_telegram notify_via_whatsapp TINYINT(1) NOT NULL DEFAULT 0');
            }
        });
    }
};
