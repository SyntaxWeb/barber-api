<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;

class ServiceController extends Controller
{
    public function index()
    {
        return ServiceResource::collection(Service::all());
    }

    public function store(ServiceRequest $request)
    {
        $service = Service::create($request->validated());
        return new ServiceResource($service);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $service->update($request->validated());
        return new ServiceResource($service);
    }

    public function destroy(Service $service)
    {
        $service->delete();
        return response()->noContent();
    }
}
