<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StudentRecord;
use App\Services\BarcodeImageService;
use Illuminate\Support\Facades\DB;
use Throwable;

class BarcodeGenerationController extends Controller
{
    private const BARCODE_WIDTH = 8;
    private const MAX_BARCODE = 99999999;

    /**
     * Generate missing project-scoped barcodes without changing existing ones.
     */
    public function generate(Project $project, BarcodeImageService $barcodeImages)
    {
        $generated = [];
        $failed = [];
        $existingCount = $project->studentRecords()
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->count();

        DB::beginTransaction();

        try {
            // Lock the project rows while calculating the next sequence so two
            // simultaneous requests cannot allocate the same project barcode.
            $students = StudentRecord::query()
                ->where('project_id', $project->id)
                ->where(function ($query) {
                    $query->whereNull('barcode')
                        ->orWhere('barcode', '');
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $usedNumbers = StudentRecord::query()
                ->where('project_id', $project->id)
                ->whereNotNull('barcode')
                ->where('barcode', '!=', '')
                ->pluck('barcode')
                ->filter(fn ($barcode) => ctype_digit((string) $barcode))
                ->map(fn ($barcode) => (int) $barcode)
                ->flip();

            $nextNumber = $usedNumbers->keys()->max() ?? 0;

            foreach ($students as $student) {
                do {
                    $nextNumber++;
                } while ($usedNumbers->has($nextNumber));

                if ($nextNumber > self::MAX_BARCODE) {
                    $failed[] = [
                        'student_record_id' => $student->id,
                        'reason' => 'No available 8-digit barcode remains in this project.',
                    ];

                    continue;
                }

                $barcode = str_pad(
                    (string) $nextNumber,
                    self::BARCODE_WIDTH,
                    '0',
                    STR_PAD_LEFT
                );

                $student->update(['barcode' => $barcode]);
                $usedNumbers->put($nextNumber, true);

                $generated[] = [
                    'student_record_id' => $student->id,
                    'barcode' => $barcode,
                ];
            }

            if ($failed !== []) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Barcode generation could not complete.',
                    'project_id' => $project->id,
                    'summary' => [
                        'total_students' => $project->studentRecords()->count(),
                        'generated' => 0,
                        'already_existing' => $project->studentRecords()
                            ->whereNotNull('barcode')
                            ->where('barcode', '!=', '')
                            ->count(),
                        'failed' => count($failed),
                    ],
                    'generated_students' => [],
                    'failed_students' => $failed,
                ], 422);
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            report($exception);

            return response()->json([
                'message' => 'Barcode generation failed.',
                'error' => config('app.debug')
                    ? $exception->getMessage()
                    : 'Unable to generate barcodes.',
            ], 422);
        }

        $allStudents = $project->studentRecords()
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->get();
        $generatedImages = [];
        $failedImages = [];

        foreach ($allStudents as $student) {
            try {
                $path = $barcodeImages->generate($student);
                $generatedImages[] = [
                    'student_record_id' => $student->id,
                    'barcode' => (string) $student->barcode,
                    'path' => $path,
                ];
            } catch (Throwable $exception) {
                report($exception);
                $failedImages[] = [
                    'student_record_id' => $student->id,
                    'barcode' => (string) $student->barcode,
                    'reason' => config('app.debug')
                        ? $exception->getMessage()
                        : 'Unable to generate barcode image.',
                ];
            }
        }

        $totalStudents = $project->studentRecords()->count();

        return response()->json([
            'message' => $failedImages === []
                ? 'Barcode values and images generated successfully.'
                : 'Barcode generation completed with image failures.',
            'project_id' => $project->id,
            'summary' => [
                'total_students' => $totalStudents,
                'generated' => count($generated),
                'already_existing' => $existingCount,
                'failed' => count($failed),
                'barcode_images_generated' => count($generatedImages),
                'barcode_images_failed' => count($failedImages),
                'existing_values_preserved' => $existingCount,
            ],
            'generated_students' => $generated,
            'failed_students' => $failed,
            'barcode_images' => $generatedImages,
            'failed_barcode_images' => $failedImages,
        ], $failedImages === [] ? 200 : 422);
    }
}
