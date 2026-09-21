<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StudentRecord;
use App\Models\Template;
use App\Services\CardRenderingService;
use Illuminate\Http\Request;
use Throwable;

class CardPreviewController extends Controller
{
    public function preview(
        Request $request,
        Project $project,
        Template $template,
        StudentRecord $student,
        CardRenderingService $renderer
    ) {
        if ((int) $template->project_id !== (int) $project->id) {
            return response()->json(['message' => 'Template does not belong to this project.'], 404);
        }

        if ((int) $student->project_id !== (int) $project->id) {
            return response()->json(['message' => 'Student record does not belong to this project.'], 404);
        }

        $validated = $request->validate([
            'side' => ['sometimes', 'string', 'in:front,back'],
        ]);

        $side = $validated['side'] ?? 'front';
        $template->load(['elements.dataMapping']);
        $student->load(['photo']);

        try {
            $pdf = $renderer->render($template, $student, $side);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Card preview failed.',
                'error' => config('app.debug') ? $exception->getMessage() : 'Unable to generate the preview.',
            ], 422);
        }

        $barcode = trim((string) $student->barcode);
        $filename = 'student-card-preview-' . ($barcode !== '' ? $barcode : $student->id) . '-' . $side . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
