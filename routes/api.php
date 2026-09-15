<?php

use App\Http\Controllers\Api\A4PrintSheetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardPreviewController;
use App\Http\Controllers\Api\CardRenderingController;
use App\Http\Controllers\Api\DataMappingController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\StudentImportController;
use App\Http\Controllers\Api\StudentPhotoController;
use App\Http\Controllers\Api\StudentRecordController;
use App\Http\Controllers\Api\TemplateConfigurationController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TemplateDesignController;
use App\Http\Controllers\Api\TemplateDesignPreviewController;
use App\Http\Controllers\Api\TemplateDimensionController;
use App\Http\Controllers\Api\TemplateElementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Public login endpoint.
Route::post('/login', [AuthController::class, 'login']);


/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    // Return the currently authenticated user.
    Route::get('/user', function (Request $request) {
        return response()->json([
            'user' => $request->user(),
        ]);
    });

    // Destroy the current authentication token.
    Route::post('/logout', [AuthController::class, 'logout']);


    /*
    |--------------------------------------------------------------------------
    | Project Management
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'projects',
        ProjectController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Template Management
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'projects.templates',
        TemplateController::class
    )->except([
        'create',
        'edit',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Template Elements
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'templates.elements',
        TemplateElementController::class
    )->except([
        'create',
        'edit',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Data Mapping
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | Student Import
    |--------------------------------------------------------------------------
    */

    // Preview the uploaded student file.
    Route::post(
        'projects/{project}/students/preview',
        [StudentImportController::class, 'preview']
    );

    // Import student records into the project.
    Route::post(
        'projects/{project}/students/import',
        [StudentImportController::class, 'import']
    );


    /*
    |--------------------------------------------------------------------------
    | Student Photos
    |--------------------------------------------------------------------------
    */

    // Upload student photos and match them with student records.
    Route::post(
        'projects/{project}/students/photos',
        [StudentPhotoController::class, 'upload']
    );

    // Get the current photo matching summary.
    Route::get(
        'projects/{project}/students/photos/summary',
        [StudentPhotoController::class, 'summary']
    );


    /*
    |--------------------------------------------------------------------------
    | Template Design Upload
    |--------------------------------------------------------------------------
    */

    // Upload the locked front/back template designs.
    //
    // Supported request fields:
    // - design_pdf
    // - front_design
    // - back_design
    //
    // A two-page PDF uses:
    // Page 1 = Front
    // Page 2 = Back
    Route::post(
        'projects/{project}/templates/{template}/design',
        [TemplateDesignController::class, 'upload']
    );


    /*
    |--------------------------------------------------------------------------
    | Template Configuration
    |--------------------------------------------------------------------------
    */

    // Get the complete configuration for the visual editor.
    Route::get(
        'projects/{project}/templates/{template}/configuration',
        [TemplateConfigurationController::class, 'show']
    );

    // Save the template dimensions and visual elements configuration.
    Route::put(
        'projects/{project}/templates/{template}/configuration',
        [TemplateConfigurationController::class, 'update']
    );


    /*
    |--------------------------------------------------------------------------
    | Template Design Preview
    |--------------------------------------------------------------------------
    */

    // Securely retrieve the front or back design.
    //
    // Example:
    // /projects/1/templates/2/design/front
    // /projects/1/templates/2/design/back
    Route::get(
        'projects/{project}/templates/{template}/design/{side}',
        [TemplateDesignPreviewController::class, 'show']
    );


    /*
    |--------------------------------------------------------------------------
    | Template Dimensions
    |--------------------------------------------------------------------------
    */

    // Update the physical card dimensions in millimetres.
    Route::put(
        'projects/{project}/templates/{template}/dimensions',
        [TemplateDimensionController::class, 'update']
    );


    /*
    |--------------------------------------------------------------------------
    | Student Records
    |--------------------------------------------------------------------------
    */

    // Retrieve paginated student records for a project.
    Route::get(
        'projects/{project}/students',
        [StudentRecordController::class, 'index']
    );

    // Retrieve one student record.
    Route::get(
        'projects/{project}/students/{student}',
        [StudentRecordController::class, 'show']
    );

    // Retrieve the photo belonging to one student.
    Route::get(
        'projects/{project}/students/{student}/photo',
        [StudentRecordController::class, 'photo']
    );


    /*
    |--------------------------------------------------------------------------
    | Card Preview and Rendering
    |--------------------------------------------------------------------------
    */

    // Preview a generated card for one student.
    Route::get(
        'projects/{project}/templates/{template}/students/{student}/preview',
        [CardPreviewController::class, 'preview']
    );

    // Render a generated card for one student.
    Route::get(
        'projects/{project}/templates/{template}/students/{student}/render',
        [CardRenderingController::class, 'render']
    );


    /*
    |--------------------------------------------------------------------------
    | A4 Print Sheet
    |--------------------------------------------------------------------------
    */

    // Generate an A4 print sheet.
    Route::post(
        'projects/{project}/templates/{template}/print-sheet',
        [A4PrintSheetController::class, 'render']
    );

    // Generate an A4 print sheet using a selected layout.
    Route::post(
        'projects/{project}/templates/{template}/print-sheet/layout',
        [A4PrintSheetController::class, 'layout']
    );
});
