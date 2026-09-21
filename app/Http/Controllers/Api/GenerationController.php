<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\StudentRecord;
use App\Models\Template;
use App\Services\A4PrintSheetRenderingService;
use App\Services\TemplateGenerationValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerationController extends Controller
{
    /**
     * Return a small preview summary for the selected project/template.
     */
    public function preview(Request $request, Project $project, Template $template)
    {
        $this->ensureTemplateBelongsToProject($project, $template);

        $limit = min(max((int) $request->query('limit', 6), 1), 50);

        $template->load(['elements.dataMapping']);

        $records = StudentRecord::query()
            ->where('project_id', $project->id)
            ->with('photo')
            ->orderBy('id')
            ->get();

        $requiresPhotos = $template->elements->contains(function ($element) {
            if ($element->visible === false) {
                return false;
            }

            $type = strtolower((string) ($element->type ?? ''));
            $field = strtolower((string) ($element->field_name ?? ''));

            return in_array($type, ['photo', 'student_photo', 'photograph', 'profile_photo'], true)
                || in_array($field, ['photo', 'student_photo', 'photograph', 'profile_photo'], true);
        });

        // A record is valid for this template only when its configured
        // dynamic elements can resolve. Do not require unrelated fields.
        $requiredElements = $template->elements
            ->where('visible', true)
            ->filter(fn ($element) => $element->type !== 'static_text');

        $validRecords = $records->filter(function ($record) use ($requiredElements) {
            foreach ($requiredElements as $element) {
                $field = trim((string) ($element->field_name ?? ''))
                    ?: trim((string) ($element->dataMapping?->source_field ?? ''));

                if ($field === '') {
                    $field = $element->exists && $element->side
                        ? match ($element->type) {
                        'barcode' => 'barcode',
                        'student_id', 'student_id_grid' => 'student_id',
                        'student_name' => 'student_name',
                        default => '',
                        }
                        : '';
                }

                if ($element->type === 'profile_photo') {
                    if (!$record->photo) {
                        return false;
                    }

                    continue;
                }

                if ($field === '') {
                    return false;
                }

                $value = match ($field) {
                    'barcode' => $record->barcode,
                    'student_id' => $record->student_id,
                    'student_name' => $record->student_name,
                    default => is_array($record->data)
                        ? ($record->data[$field] ?? null)
                        : null,
                };

                if (trim((string) $value) === '') {
                    return false;
                }
            }

            return true;
        });

        $invalidCount = $records->count() - $validRecords->count();
        $withPhotos = $validRecords->filter(fn ($record) => $record->photo !== null)->count();
        $missingPhotos = $requiresPhotos
            ? $validRecords->filter(fn ($record) => $record->photo === null)->count()
            : 0;

        return response()->json([
            'message' => 'Generation preview generated successfully.',
            'project_id' => $project->id,
            'template_id' => $template->id,
            'requires_photos' => $requiresPhotos,
            'valid_count' => $validRecords->count(),
            'invalid_count' => $invalidCount,
            'with_photos' => $withPhotos,
            'missing_photos' => $missingPhotos,
            'photo_matched_count' => $withPhotos,
            'photo_missing_count' => $missingPhotos,
            'cards' => $validRecords->take($limit)->values()->map(function ($record) {
                return [
                    'id' => $record->id,
                    'barcode' => $record->barcode,
                    'student_id' => $record->student_id,
                    'student_name' => $record->student_name,
                    'data' => $record->data,
                    'has_photo' => $record->photo !== null,
                ];
            }),
        ]);
    }

    /**
     * Start bulk generation for all valid records.
     * This first implementation generates synchronously so the workflow can be tested.
     */
    public function generate(
        Request $request,
        Project $project,
        Template $template,
        A4PrintSheetRenderingService $renderer,
        TemplateGenerationValidationService $validator
    ) {
        $this->ensureTemplateBelongsToProject($project, $template);

        $request->validate([
            'confirm' => ['required', 'boolean'],
        ]);

        if (!$request->boolean('confirm')) {
            return response()->json([
                'message' => 'Generation confirmation is required.',
            ], 422);
        }

        $students = StudentRecord::query()
            ->where('project_id', $project->id)
            ->with('photo')
            ->orderBy('id')
            ->get();

        if ($students->isEmpty()) {
            return response()->json([
                'message' => 'No student records are available for generation.',
            ], 422);
        }

        $validationErrors = $validator->validate($template, $students);

        if ($validationErrors !== []) {
            return response()->json([
                'message' => 'The selected template or students are not ready for generation.',
                'errors' => $validationErrors,
            ], 422);
        }

        $job = GenerationJob::create([
            'project_id' => $project->id,
            'template_id' => $template->id,
            'status' => 'processing',
            'total_records' => $students->count(),
            'processed_records' => 0,
            'started_at' => now(),
        ]);

        try {
            $pdf = $renderer->render($template, $students, []);
            $path = 'generation-jobs/' . $job->id . '/student-cards-a4.pdf';
            Storage::disk('local')->put($path, $pdf);

            $job->update([
                'status' => 'completed',
                'processed_records' => $students->count(),
                'output_path' => $path,
                'completed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Bulk generation completed successfully.',
                'job' => $this->jobPayload($job),
            ], 201);
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Bulk generation failed.',
                'error' => $exception->getMessage(),
                'job' => $this->jobPayload($job),
            ], 422);
        }
    }

    /**
     * Return generation job status and download information.
     */
    public function status(Project $project, GenerationJob $job)
    {
        if ((int) $job->project_id !== (int) $project->id) {
            return response()->json([
                'message' => 'Generation job does not belong to this project.',
            ], 404);
        }

        return response()->json([
            'job' => $this->jobPayload($job),
        ]);
    }

    private function ensureTemplateBelongsToProject(Project $project, Template $template): void
    {
        if ((int) $template->project_id !== (int) $project->id) {
            abort(404, 'Template does not belong to this project.');
        }
    }

    private function jobPayload(GenerationJob $job): array
    {
        $payload = $job->toArray();
        $payload['progress'] = $job->total_records > 0
            ? (int) round(($job->processed_records / $job->total_records) * 100)
            : 0;

        if ($job->output_path && Storage::disk('local')->exists($job->output_path)) {
            $payload['download_url'] = url('/api/projects/' . $job->project_id . '/generation-jobs/' . $job->id . '/download');
        }

        return $payload;
    }

    public function download(Project $project, GenerationJob $job)
    {
        if ((int) $job->project_id !== (int) $project->id) {
            abort(404, 'Generation job does not belong to this project.');
        }

        if (!$job->output_path || !Storage::disk('local')->exists($job->output_path)) {
            return response()->json([
                'message' => 'Generated PDF is not available.',
            ], 404);
        }

        return Storage::disk('local')->download(
            $job->output_path,
            'student-cards-' . $job->id . '.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }
}
