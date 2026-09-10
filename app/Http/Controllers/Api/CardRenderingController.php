<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StudentRecord;
use App\Models\Template;
use App\Services\CardRenderingService;
use Illuminate\Http\Request;
use RuntimeException;

class CardRenderingController extends Controller
{
    /**
     * Generate a single student's card as a PDF.
     *
     * Example:
     *
     * GET /api/projects/2/templates/1/students/1/render?side=front
     */
    public function render(
        Request $request,
        Project $project,
        Template $template,
        StudentRecord $student,
        CardRenderingService $renderer
    ) {
        /*
        |--------------------------------------------------------------------------
        | 1. Make sure the template belongs to this project.
        |--------------------------------------------------------------------------
        */

        if ($template->project_id !== $project->id) {
            return response()->json([
                'message' => 'Template does not belong to this project.',
            ], 404);
        }


        /*
        |--------------------------------------------------------------------------
        | 2. Make sure the student belongs to this project.
        |--------------------------------------------------------------------------
        |
        | This prevents someone from rendering a student belonging
        | to another project.
        |
        */

        if ($student->project_id !== $project->id) {
            return response()->json([
                'message' => 'Student does not belong to this project.',
            ], 404);
        }


        /*
        |--------------------------------------------------------------------------
        | 3. Validate the requested side.
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'side' => [
                'sometimes',
                'string',
                'in:front,back',
            ],
        ]);


        /*
        |--------------------------------------------------------------------------
        | 4. Default to front.
        |--------------------------------------------------------------------------
        */

        $side = $validated['side'] ?? 'front';


        /*
        |--------------------------------------------------------------------------
        | 5. Load the elements and photo.
        |--------------------------------------------------------------------------
        |
        | The rendering service needs these relationships.
        |
        */

        $template->load([
            'elements.dataMapping',
        ]);

        $student->load([
            'photo',
        ]);


        /*
        |--------------------------------------------------------------------------
        | 6. Generate the PDF.
        |--------------------------------------------------------------------------
        */

        try {

            $pdf = $renderer->render(
                $template,
                $student,
                $side
            );


            /*
            |--------------------------------------------------------------------------
            | 7. Create a useful filename.
            |--------------------------------------------------------------------------
            */

            $barcode = $student->barcode
                ?: 'student-' . $student->id;

            $filename =
                'student-card-' .
                $barcode .
                '-' .
                $side .
                '.pdf';


            /*
            |--------------------------------------------------------------------------
            | 8. Return the PDF directly.
            |--------------------------------------------------------------------------
            |
            | The browser/Postman can display the generated PDF.
            |
            */

            return response(
                $pdf,
                200,
                [
                    'Content-Type' => 'application/pdf',

                    'Content-Disposition' =>
                        'inline; filename="' .
                        $filename .
                        '"',

                    'Cache-Control' =>
                        'no-store, no-cache, must-revalidate',

                    'Pragma' =>
                        'no-cache',
                ]
            );

        } catch (RuntimeException $e) {

            return response()->json([
                'message' =>
                    'Card rendering failed.',

                'error' =>
                    $e->getMessage(),
            ], 422);
        }
    }
}
