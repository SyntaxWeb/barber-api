<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('feedback_token', 100)->nullable()->unique();
            $table->timestamp('feedback_token_expires_at')->nullable();
            $table->timestamp('feedback_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_feedback_token_unique');
            $table->dropColumn('feedback_token');
            $table->dropColumn('feedback_token_expires_at');
            $table->dropColumn('feedback_requested_at');
        });
    }
};
