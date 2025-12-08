<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __invoke(Request $request, AvailabilityService $availability)
    {
        $request->validate(['date' => 'required|date']);

        return response()->json([
            'horarios' => $availability->horariosDisponiveis($request->date),
        ]);
    }
}
