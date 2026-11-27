<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\VarianceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ImportActualController;
use App\Http\Controllers\Api\WeeklyRecapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Health Check
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'application' => 'PEMI-ISUZU Variance Order',
        'version' => '1.0.0',
        'status' => 'operational',
        'timestamp' => now()->toISOString(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    // Public login with rate limiting.
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    // Authenticated and active users.
    Route::middleware([
        'auth:sanctum',
        'active',
    ])->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/me', [AuthController::class, 'me']);

    });

});

/*
|--------------------------------------------------------------------------
| Protected Business API
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'active',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | All active users can access dashboard.
    |
    */

    Route::prefix('dashboard')->group(function () {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('role:administrator,manager,staff,viewer');

        Route::get('/trends', [DashboardController::class, 'trends'])
            ->middleware('role:administrator,manager,staff,viewer');

    });

    /*
    |--------------------------------------------------------------------------
    | Imports
    |--------------------------------------------------------------------------
    */

    Route::prefix('imports')->group(function () {

        // All roles can view imports.
        Route::get('/', [ImportController::class, 'index'])
            ->middleware('role:administrator,manager,staff,viewer');

        Route::get('/{id}', [ImportController::class, 'show'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager,staff,viewer');

        // Administrator, Manager and Staff can upload Excel.
        Route::post('/', [ImportController::class, 'store'])
            ->middleware('role:administrator,manager,staff');

        Route::post('/preview', [ImportController::class, 'preview'])
            ->middleware('role:administrator,manager,staff');

        Route::get('/{id}/actual', [ImportActualController::class, 'show'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager,staff');

        Route::patch('/{id}/actual', [ImportActualController::class, 'update'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager,staff');

        Route::get('/weekly-recap', WeeklyRecapController::class)
            ->middleware('role:administrator,manager,staff,viewer');

            });

    /*
    |--------------------------------------------------------------------------
    | Variance Reports
    |--------------------------------------------------------------------------
    */

    Route::prefix('variance-reports')->group(function () {

        // All active roles can view variance reports.
        Route::get('/', [VarianceController::class, 'index'])
            ->middleware('role:administrator,manager,staff,viewer');

        Route::get('/{id}', [VarianceController::class, 'show'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager,staff,viewer');

        // Administrator, Manager and Staff can generate variance.
        Route::post('/', [VarianceController::class, 'store'])
            ->middleware('role:administrator,manager,staff');

    });

    /*
    |--------------------------------------------------------------------------
    | Review Management
    |--------------------------------------------------------------------------
    */

    Route::prefix('reviews')->group(function () {

        /*
        | Read Operations
        |
        | All roles can view review information.
        */

        Route::get('/', [ReviewController::class, 'index'])
            ->middleware('role:administrator,manager,staff,viewer');

        Route::get('/statistics', [ReviewController::class, 'statistics'])
            ->middleware('role:administrator,manager,staff,viewer');

        Route::get('/{id}', [ReviewController::class, 'show'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager,staff,viewer');

        Route::get('/{id}/history', [ReviewController::class, 'history'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager,staff,viewer');

        /*
        | Individual Resolve
        |
        | Administrator, Manager and Staff.
        */

        Route::patch('/{id}/resolve', [ReviewController::class, 'resolve'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager,staff');

        /*
        | Individual Reopen
        |
        | Administrator and Manager only.
        */

        Route::patch('/{id}/reopen', [ReviewController::class, 'reopen'])
            ->whereNumber('id')
            ->middleware('role:administrator,manager');

        /*
        | Bulk Resolve
        |
        | Administrator and Manager only.
        */

        Route::patch('/bulk-resolve', [ReviewController::class, 'bulkResolve'])
            ->middleware('role:administrator,manager');

        /*
        | Bulk Reopen
        |
        | Administrator and Manager only.
        */

        Route::patch('/bulk-reopen', [ReviewController::class, 'bulkReopen'])
            ->middleware('role:administrator,manager');

    });

    /*
    |--------------------------------------------------------------------------
    | User Management
    |--------------------------------------------------------------------------
    |
    | Administrator only.
    |
    | Other roles cannot access user management.
    |
    */

    Route::prefix('users')
        ->middleware('role:administrator')
        ->group(function () {

            // Display all users with filters and pagination.
            Route::get('/', [UserController::class, 'index']);

            // Create a new user.
            Route::post('/', [UserController::class, 'store']);

            // Display user details.
            Route::get('/{id}', [UserController::class, 'show'])
                ->whereNumber('id');

            // Update user profile.
            Route::put('/{id}', [UserController::class, 'update'])
                ->whereNumber('id');

            // Update user role.
            Route::patch('/{id}/role', [UserController::class, 'updateRole'])
                ->whereNumber('id');

            // Activate or deactivate user.
            Route::patch('/{id}/status', [UserController::class, 'updateStatus'])
                ->whereNumber('id');

            // Reset user password.
            Route::patch('/{id}/password', [UserController::class, 'resetPassword'])
                ->whereNumber('id');

        });

});
