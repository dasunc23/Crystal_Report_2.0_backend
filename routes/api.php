<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TemplateElementController;
use App\Http\Controllers\Api\DataMappingController;
use App\Http\Controllers\Api\StudentImportController;
use App\Http\Controllers\Api\StudentPhotoController;
use App\Http\Controllers\Api\TemplateDesignController;
use App\Http\Controllers\Api\TemplateConfigurationController;
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

    // Template APIs belonging to a specific project.
    Route::apiResource(
        'projects.templates',
        TemplateController::class
    )->except(['create', 'edit']);

    Route::apiResource(
    'templates.elements',
    TemplateElementController::class
    )->except(['create', 'edit']);

    // Data mapping APIs for a specific template element.
    Route::get(
        'elements/{element}/mapping',
        [DataMappingController::class, 'show']
    );

    Route::post(
        'elements/{element}/mapping',
        [DataMappingController::class, 'store']
    );

    Route::put(
        'elements/{element}/mapping',
        [DataMappingController::class, 'update']
    );

    Route::delete(
        'elements/{element}/mapping',
        [DataMappingController::class, 'destroy']
    );

    Route::post(
    'projects/{project}/students/preview',
    [StudentImportController::class, 'preview']
    );

    Route::post(
    'projects/{project}/students/import',
    [StudentImportController::class, 'import']
    );

    // Student photo upload and barcode matching.
    Route::post(
        'projects/{project}/students/photos',
        [StudentPhotoController::class, 'upload']
    );

    // Get the current photo matching summary.
    Route::get(
        'projects/{project}/students/photos/summary',
        [StudentPhotoController::class, 'summary']
    );

    // Upload the locked front/back base designs for a template.
    Route::post(
        'projects/{project}/templates/{template}/design',
        [TemplateDesignController::class, 'upload']
    );

    // Return the complete configuration required by the visual editor.
    Route::get(
        'projects/{project}/templates/{template}/configuration',
        [TemplateConfigurationController::class, 'show']
    );
});
