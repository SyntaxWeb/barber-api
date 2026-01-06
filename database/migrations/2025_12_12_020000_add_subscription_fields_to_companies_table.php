<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('subscription_plan')->default('mensal')->after('client_theme');
            $table->string('subscription_status')->default('ativo')->after('subscription_plan');
            $table->unsignedDecimal('subscription_price', 10, 2)->default(0)->after('subscription_status');
            $table->timestamp('subscription_renews_at')->nullable()->after('subscription_price');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_plan',
                'subscription_status',
                'subscription_price',
                'subscription_renews_at',
            ]);
        });
    }
};
