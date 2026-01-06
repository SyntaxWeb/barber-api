<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'notify_whatsapp')) {
                $table->string('notify_whatsapp')->nullable()->after('notify_email');
            }
            if (!Schema::hasColumn('companies', 'notify_via_whatsapp')) {
                $table->boolean('notify_via_whatsapp')->default(false)->after('notify_whatsapp');
            }
            if (!Schema::hasColumn('companies', 'whatsapp_session_id')) {
                $table->string('whatsapp_session_id')->nullable()->after('notify_via_whatsapp');
            }
            if (!Schema::hasColumn('companies', 'whatsapp_phone')) {
                $table->string('whatsapp_phone')->nullable()->after('whatsapp_session_id');
            }
            if (!Schema::hasColumn('companies', 'whatsapp_status')) {
                $table->string('whatsapp_status')->nullable()->after('whatsapp_phone');
            }
            if (!Schema::hasColumn('companies', 'whatsapp_connected_at')) {
                $table->timestamp('whatsapp_connected_at')->nullable()->after('whatsapp_status');
            }
        });

        if (!Schema::hasTable('notification_logs')) {
            Schema::create('notification_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->string('channel');
                $table->string('recipient')->nullable();
                $table->text('message')->nullable();
                $table->string('status')->default('pending');
                $table->json('meta')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'notify_whatsapp',
                'notify_via_whatsapp',
                'whatsapp_session_id',
                'whatsapp_phone',
                'whatsapp_status',
                'whatsapp_connected_at',
            ]);
        });

        Schema::dropIfExists('notification_logs');
    }
};
