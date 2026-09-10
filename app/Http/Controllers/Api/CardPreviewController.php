<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StudentRecord;
use App\Models\Template;
use App\Services\CardRenderingService;
use Illuminate\Http\Request;

class CardPreviewController extends Controller
{
    /**
     * Generate a single student's card preview.
     */
    public function preview(
        Request $request,
        Project $project,
        Template $template,
        StudentRecord $student,
        CardRenderingService $renderer
    ) {
        /*
         * Make sure the template belongs to this project.
         */
        if ($template->project_id !== $project->id) {

            return response()->json([
                'message' =>
                    'Template does not belong to this project.',
            ], 404);
        }

        /*
         * Make sure the student belongs to this project.
         */
        if ($student->project_id !== $project->id) {

            return response()->json([
                'message' =>
                    'Student record does not belong to this project.',
            ], 404);
        }

        /*
         * Front or back.
         *
         * Default is front.
         */
        $side = $request->query(
            'side',
            'front'
        );

        if (!in_array($side, ['front', 'back'], true)) {

            return response()->json([
                'message' =>
                    'Side must be front or back.',
            ], 422);
        }

        /*
         * Render the card.
         */
        try {

            $pdf = $renderer->render(
                $template,
                $student,
                $side
            );

        } catch (\Throwable $e) {

            return response()->json([
                'message' =>
                    'Card rendering failed.',

                'error' =>
                    $e->getMessage(),
            ], 422);
        }

        /*
         * Return the generated PDF.
         */
        return response($pdf, 200)
            ->header(
                'Content-Type',
                'application/pdf'
            )
            ->header(
                'Content-Disposition',
                'inline; filename="student-card-' .
                $student->barcode .
                '-' .
                $side .
                '.pdf"'
            )
            ->header(
                'Cache-Control',
                'private, no-store'
            );
    }
}
