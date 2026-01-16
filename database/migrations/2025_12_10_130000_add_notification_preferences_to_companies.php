<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('notify_email')->nullable()->after('agendamento_url');
            $table->string('notify_telegram')->nullable()->after('notify_email');
            $table->boolean('notify_via_email')->default(false)->after('notify_telegram');
            $table->boolean('notify_via_telegram')->default(false)->after('notify_via_email');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'notify_email',
                'notify_telegram',
                'notify_via_email',
                'notify_via_telegram',
            ]);
        });
    }
};
