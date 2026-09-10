<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class TemplateDesignPreviewController extends Controller
{
    /**
     * Display a template design for the visual editor.
     *
     * side = front or back
     *
     * For a two-page PDF:
     * front -> page 1
     * back  -> page 2
     */
    public function show(
        Project $project,
        Template $template,
        string $side
    ) {
        /*
         * Make sure the template belongs to this project.
         */
        if ($template->project_id !== $project->id) {
            return response()->json([
                'message' => 'Template does not belong to this project.',
            ], 404);
        }

        /*
         * Only front and back are valid sides.
         */
        if (!in_array($side, ['front', 'back'])) {
            return response()->json([
                'message' => 'Side must be either front or back.',
            ], 422);
        }

        /*
         * Get the design path for the requested side.
         */
        $designPath = $side === 'front'
            ? $template->front_design_path
            : $template->back_design_path;

        /*
         * Make sure a design exists.
         */
        if (!$designPath) {
            return response()->json([
                'message' => ucfirst($side) . ' design has not been uploaded.',
            ], 404);
        }

        /*
         * Make sure the private file actually exists.
         */
        if (!Storage::disk('local')->exists($designPath)) {
            return response()->json([
                'message' => 'Template design file could not be found.',
            ], 404);
        }

        /*
         * Determine the stored file's MIME type.
         */
        $mimeType = Storage::disk('local')->mimeType($designPath);

        /*
         * If this is an image, we can return the image directly.
         */
        if (str_starts_with($mimeType, 'image/')) {

            return response()->file(
                Storage::disk('local')->path($designPath),
                [
                    'Content-Type' => $mimeType,
                    'Cache-Control' => 'private, max-age=3600',
                ]
            );
        }

        /*
         * PDF designs need special handling because the user may
         * have uploaded one PDF containing both front and back.
         *
         * We don't return the whole PDF directly because React
         * needs one specific side.
         *
         * The next rendering layer will convert/import the required
         * PDF page correctly.
         */
        if ($mimeType === 'application/pdf') {

            return response()->json([
                'message' =>
                    'PDF design detected. PDF page rendering will be handled by the card rendering service.',

                'side' => $side,

                'page' => $side === 'front' ? 1 : 2,

                'design_path' => $designPath,
            ]);
        }

        /*
         * Unsupported file type.
         */
        return response()->json([
            'message' => 'Unsupported template design file type.',
        ], 422);
    }
}
