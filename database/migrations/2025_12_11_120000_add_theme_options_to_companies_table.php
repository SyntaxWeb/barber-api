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
            $table->json('dashboard_theme')->nullable()->after('notify_via_telegram');
            $table->json('client_theme')->nullable()->after('dashboard_theme');
        });

        $defaultDashboard = $this->defaultDashboardTheme();
        $defaultClient = $this->defaultClientTheme();

        DB::table('companies')->update([
            'dashboard_theme' => json_encode($defaultDashboard),
            'client_theme' => json_encode($defaultClient),
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['dashboard_theme', 'client_theme']);
        });
    }

    protected function defaultDashboardTheme(): array
    {
        return [
            'primary' => '#0f172a',
            'secondary' => '#1d4ed8',
            'background' => '#f8fafc',
            'surface' => '#ffffff',
            'text' => '#0f172a',
            'accent' => '#f97316',
        ];
    }

    protected function defaultClientTheme(): array
    {
        return [
            'primary' => '#111827',
            'secondary' => '#dc2626',
            'background' => '#fdf2f8',
            'surface' => '#ffffff',
            'text' => '#111827',
            'accent' => '#fbbf24',
        ];
    }
};
