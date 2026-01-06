<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('telegram_link_token')->nullable()->after('notify_via_telegram');
            $table->timestamp('telegram_link_token_created_at')->nullable()->after('telegram_link_token');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['telegram_link_token', 'telegram_link_token_created_at']);
        });
    }
};
