<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Health\HealthService;
use App\Http\Controllers\Controller;

final class HealthController extends Controller
{
    public function __invoke(HealthService $health): array
    {
        return $health->all();
    }
}
