<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_appointment_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'first_appointment_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('address_line')->nullable()->after('descricao');
            $table->string('neighborhood')->nullable()->after('address_line');
            $table->string('city')->nullable()->after('neighborhood');
            $table->string('state', 2)->nullable()->after('city');
            $table->string('postal_code', 12)->nullable()->after('state');
            $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->boolean('discovery_enabled')->default(true)->after('longitude');
            $table->index(['discovery_enabled', 'city']);
            $table->index(['latitude', 'longitude']);
        });

        $now = now();
        DB::table('appointments')
            ->join('users', 'users.id', '=', 'appointments.user_id')
            ->where('users.role', 'client')
            ->whereNotNull('appointments.company_id')
            ->select([
                'appointments.company_id',
                'appointments.user_id',
                DB::raw('MIN(appointments.created_at) as first_appointment_at'),
            ])
            ->groupBy('appointments.company_id', 'appointments.user_id')
            ->orderBy('appointments.company_id')
            ->chunk(500, function ($links) use ($now) {
                DB::table('company_client')->insertOrIgnore(
                    $links->map(fn ($link) => [
                        'company_id' => $link->company_id,
                        'user_id' => $link->user_id,
                        'first_appointment_at' => $link->first_appointment_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });

        DB::table('users')
            ->where('role', 'client')
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->chunk(500, function ($clients) use ($now) {
                DB::table('company_client')->insertOrIgnore(
                    $clients->map(fn ($client) => [
                        'company_id' => $client->company_id,
                        'user_id' => $client->id,
                        'first_appointment_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['discovery_enabled', 'city']);
            $table->dropIndex(['latitude', 'longitude']);
            $table->dropColumn([
                'address_line',
                'neighborhood',
                'city',
                'state',
                'postal_code',
                'latitude',
                'longitude',
                'discovery_enabled',
            ]);
        });

        Schema::dropIfExists('company_client');
    }
};
