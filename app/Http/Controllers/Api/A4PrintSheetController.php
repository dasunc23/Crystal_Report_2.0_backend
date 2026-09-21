<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StudentRecord;
use App\Models\Template;
use App\Services\A4PrintSheetRenderingService;
use App\Services\TemplateGenerationValidationService;
use Illuminate\Http\Request;

class A4PrintSheetController extends Controller
{
    /**
     * Generate an A4 print sheet containing
     * front/back cards for selected students.
     */
    public function render(
        Request $request,
        Project $project,
        Template $template,
        TemplateGenerationValidationService $validator
    ) {

        /*
        |--------------------------------------------------------------------------
        | Validate request.
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([

            'student_ids' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            'student_ids.*' => [
                'integer',
            ],

            // Preview is intentionally still rendered in memory through the
            // existing endpoint and never creates a generation job.
            'preview' => [
                'sometimes',
                'boolean',
            ],

            'orientation' => [
                'nullable',
                'in:portrait,landscape',
            ],

            'page_width_mm' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            'page_height_mm' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            'margin_top_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'margin_right_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'margin_bottom_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'margin_left_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'pair_gap_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'row_gap_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Make sure template belongs to this project.
        |--------------------------------------------------------------------------
        */

        if ((int) $template->project_id !== (int) $project->id) {

            abort(404, 'Template does not belong to this project.');
        }

        /*
        |--------------------------------------------------------------------------
        | Get only students belonging to this project.
        |--------------------------------------------------------------------------
        */

        $students = StudentRecord::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $validated['student_ids'])
            ->with('photo')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Make sure every requested student was found.
        |--------------------------------------------------------------------------
        */

        if ($students->count() !== count($validated['student_ids'])) {

            abort(
                422,
                'One or more student IDs do not belong to this project.'
            );
        }

        $validationErrors = $validator->validate($template, $students);

        if ($validationErrors !== []) {
            $firstError = $validationErrors[0]['reason'] ?? 'Template validation failed.';

            return response()->json([
                'message' => $firstError,
                'error_summary' => 'The selected template or students are not ready for PDF generation.',
                'errors' => $validationErrors,
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Render A4 sheet.
        |--------------------------------------------------------------------------
        */

        $renderer = app(A4PrintSheetRenderingService::class);

        $pdf = $renderer->render(
            $template,
            $students,
            $validated
        );

        /*
        |--------------------------------------------------------------------------
        | Return PDF inline.
        |--------------------------------------------------------------------------
        */

        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header(
                'Content-Disposition',
                'inline; filename="student_cards_a4.pdf"'
            )
            ->header(
                'Cache-Control',
                'no-store, no-cache, must-revalidate'
            );
    }


    /**
     * Return calculated A4 layout information.
     *
     * This will later be useful for the React print preview.
     */
    public function layout(
        Request $request,
        Project $project,
        Template $template
    ) {

        $validated = $request->validate([

            'student_count' => [
                'required',
                'integer',
                'min:1',
                'max:100000',
            ],

            'orientation' => [
                'nullable',
                'in:portrait,landscape',
            ],

            'page_width_mm' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            'page_height_mm' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            'margin_top_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'margin_right_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'margin_bottom_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'margin_left_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'pair_gap_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'row_gap_mm' => [
                'nullable',
                'numeric',
                'gte:0',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Verify ownership.
        |--------------------------------------------------------------------------
        */

        if ((int) $template->project_id !== (int) $project->id) {

            abort(404, 'Template does not belong to this project.');
        }

        $renderer = app(A4PrintSheetRenderingService::class);

        $layout = $renderer->calculateLayout(
            $template,
            (int) $validated['student_count'],
            $validated
        );

        return response()->json([
            'message' => 'A4 layout calculated successfully.',
            'layout' => $layout,
        ]);
    }
}
