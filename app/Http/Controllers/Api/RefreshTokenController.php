<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RefreshTokenController extends Controller
{
    public function refresh(Request $request)
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $hashed = hash('sha256', $data['refresh_token']);
        $record = RefreshToken::where('token_hash', $hashed)->first();

        if (!$record || ($record->expires_at && $record->expires_at->isPast())) {
            return response()->json(['message' => 'Refresh token inválido ou expirado.'], 401);
        }

        $user = $record->user;
        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 401);
        }

        $abilities = $record->abilities ?? [];

        $record->delete();

        $newRefreshToken = $this->issueRefreshToken($user, $abilities);
        $accessToken = $user->createToken('access_token', $abilities)->plainTextToken;

        return response()->json([
            'token' => $accessToken,
            'refresh_token' => $newRefreshToken['token'],
            'refresh_expires_at' => $newRefreshToken['expires_at'],
        ]);
    }

    public static function issueRefreshToken($user, array $abilities = []): array
    {
        $plain = Str::random(64);
        $expiresAt = self::resolveExpiry();

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $plain,
            'expires_at' => $expiresAt,
        ];
    }

    private static function resolveExpiry(): ?Carbon
    {
        $days = (int) config('auth.refresh_token_days', env('REFRESH_TOKEN_TTL_DAYS', 30));
        if ($days <= 0) {
            return null;
        }
        return now()->addDays($days);
    }
}
