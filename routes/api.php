<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public login endpoint.
// No authentication is required to access this route.
Route::post('/login', [AuthController::class, 'login']);

// Protected API routes.
// A valid Sanctum token is required.
Route::middleware('auth:sanctum')->group(function () {

    // Return the currently authenticated user.
    Route::get('/user', function (Request $request) {
        return response()->json([
            'user' => $request->user(),
        ]);
    });

    // Destroy the current authentication token.
    Route::post('/logout', [AuthController::class, 'logout']);

    // Project management APIs.
    Route::apiResource('projects', ProjectController::class);
});
