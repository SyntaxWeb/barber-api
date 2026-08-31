<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Integration;

interface IntegrationProviderInterface
{
    public function provider(): string;

    public function type(): string;

    public function isConnected(Integration $integration): bool;

    public function disconnect(Integration $integration): void;
}
