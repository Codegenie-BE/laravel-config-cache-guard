<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Http\Controllers\RepairConfigCacheGuardController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST'], '/_config-cache-guard/repair', RepairConfigCacheGuardController::class)
    ->name('config-cache-guard.repair');
