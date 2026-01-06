<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'whatsapp_api_token')) {
                $table->text('whatsapp_api_token')->nullable()->after('whatsapp_connected_at');
            }
            if (!Schema::hasColumn('companies', 'whatsapp_api_token_expires_at')) {
                $table->timestamp('whatsapp_api_token_expires_at')->nullable()->after('whatsapp_api_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_api_token', 'whatsapp_api_token_expires_at']);
        });
    }
};
