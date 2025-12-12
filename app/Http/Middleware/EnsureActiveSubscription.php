<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');
        if (!$user || $user->role === 'admin') {
            return $next($request);
        }

        $company = $user->company;
        if (!$company) {
            return response()->json([
                'message' => 'Empresa nao encontrada.',
            ], 403);
        }

        if ($company->subscription_status !== 'ativo') {
            $message = 'Sua assinatura esta inativa. Atualize o plano para continuar usando o sistema.';

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'subscription_status' => $company->subscription_status,
                ], 402);
            }

            return response($message, 402);
        }

        return $next($request);
    }
}
