<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ExcelPreviewImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\StudentRecordsImport;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentImportController extends Controller
{
    /**
     * Preview an uploaded Excel file.
     *
     * This endpoint only reads the Excel file.
     * It does not save students into the database.
     */
    public function preview(Request $request): JsonResponse
    {
        // Validate the uploaded file.
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:20480',
            ],

            // Each upload represents the current batch for this project.
            // The flag remains explicit so future clients can opt into a
            // merge workflow without changing this endpoint.
            'replace_existing' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $file = $validated['file'];

        // Create an actual Laravel Excel import object.
        $import = new ExcelPreviewImport();

        // Read the Excel file into an array.
        //
        // IMPORTANT:
        // Laravel Excel 4.x requires an Import object
        // as the first argument.
        $sheets = Excel::toArray($import, $file);

        // Make sure at least one worksheet exists.
        if (empty($sheets) || empty($sheets[0])) {
            return response()->json([
                'message' => 'The uploaded Excel file is empty.',
            ], 422);
        }

        // Get the first worksheet.
        $rows = $sheets[0];

        // Get the first row's column names.
        if (empty($rows[0])) {
            return response()->json([
                'message' => 'The Excel file does not contain a header row.',
            ], 422);
        }

        $columns = array_keys($rows[0]);

        // Return only the first 10 rows for preview.
        $previewRows = array_slice($rows, 0, 10);

        return response()->json([
            'message' => 'Excel file preview generated successfully.',
            'filename' => $file->getClientOriginalName(),
            'columns' => $columns,
            'total_rows' => count($rows),
            'preview_rows' => $previewRows,
        ]);
    }

    /**
     * Import student records from an Excel file.
     *
     * Unlike preview(), this method saves the data
     * into the ecls_student_records table.
     */
    public function import(Request $request, Project $project): JsonResponse
    {
        // Validate the uploaded Excel file.
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:20480',
            ],
        ]);

        $file = $validated['file'];

        /*
        |--------------------------------------------------------------------------
        | Read the Excel headers before starting the database import.
        |--------------------------------------------------------------------------
        |
        | The system supports multiple student-data formats:
        |
        | 1. Barcode-only format:
        |
        |       Barcode
        |
        | 2. Existing/legacy format:
        |
        |       student_name
        |
        | 3. New format:
        |
        |       first_name + last_name
        |
        | Student ID is supported when supplied, but is NOT required
        | when a barcode is provided.
        |
        | Barcode can therefore be used as the only identifying field
        | for templates that only require barcode data.
        |
        */

        $sheets = Excel::toArray(new ExcelPreviewImport(), $file);

        $rows = $sheets[0] ?? [];

        /*
        |--------------------------------------------------------------------------
        | Make sure the Excel file contains a header row.
        |--------------------------------------------------------------------------
        */

        if (empty($rows) || empty($rows[0])) {
            return response()->json([
                'message' => 'The Excel file does not contain a header row.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Excel column names.
        |--------------------------------------------------------------------------
        |
        | Depending on the Excel heading configuration, headers may arrive as:
        |
        |   Student ID
        |   student_id
        |   First Name
        |   first_name
        |   Barcode
        |
        | Normalize them so the validation works consistently.
        |
        */

        $columns = array_map(
            function ($column) {
                $column = strtolower(trim((string) $column));

                // Convert spaces and hyphens to underscores.
                $column = str_replace(
                    [' ', '-'],
                    '_',
                    $column
                );

                // Prevent multiple underscores from appearing.
                $column = preg_replace('/_+/', '_', $column);

                return trim($column, '_');
            },
            array_keys($rows[0])
        );

        /*
        |--------------------------------------------------------------------------
        | Identify available main fields.
        |--------------------------------------------------------------------------
        */

        $hasBarcode = in_array('barcode', $columns, true);

        $hasStudentId = in_array('student_id', $columns, true);

        /*
        |--------------------------------------------------------------------------
        | Student name validation.
        |--------------------------------------------------------------------------
        |
        | A student name can now come from either:
        |
        |   student_name
        |
        | OR:
        |
        |   first_name + last_name
        |
        | We also allow either first_name or last_name because the
        | StudentRecordsImport class can safely combine whichever values
        | are available.
        |
        | IMPORTANT:
        |
        | Barcode-only files do NOT require student name information.
        |
        */

        $hasStudentName = in_array(
            'student_name',
            $columns,
            true
        );

        $hasFirstName = in_array(
            'first_name',
            $columns,
            true
        );

        $hasLastName = in_array(
            'last_name',
            $columns,
            true
        );

        /*
        |--------------------------------------------------------------------------
        | Validate the available identifying format.
        |--------------------------------------------------------------------------
        |
        | A valid Excel file must contain either:
        |
        |   1. barcode
        |
        | OR:
        |
        |   2. student_id
        |
        |      together with student_name / first_name / last_name
        |
        | Barcode-only templates therefore work without Student ID or
        | student-name columns.
        |
        */

        $missingColumns = [];

        if (!$hasBarcode && !$hasStudentId) {
            $missingColumns[] = 'barcode or student_id';
        }

        /*
        |--------------------------------------------------------------------------
        | If the file is not barcode-only, require student-name information.
        |--------------------------------------------------------------------------
        |
        | Barcode is sufficient by itself.
        |
        | When barcode is not present, the traditional student-data format
        | still requires student name information.
        |
        */

        if (
            !$hasBarcode &&
            !$hasStudentName &&
            !$hasFirstName &&
            !$hasLastName
        ) {
            $missingColumns[] =
                'student_name or first_name/last_name';
        }

        /*
        |--------------------------------------------------------------------------
        | Return validation error if required information is missing.
        |--------------------------------------------------------------------------
        */

        if ($missingColumns !== []) {
            return response()->json([
                'message' => 'The Excel file is missing required columns.',
                'missing_columns' => $missingColumns,
                'required_format' => [
                    'barcode OR student_id',
                    'student_name OR first_name/last_name when barcode is not provided',
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Continue with the existing import workflow.
        |--------------------------------------------------------------------------
        */

        $replaceExisting = $request->boolean('replace_existing', true);

        $deletedCount = 0;

        $import = null;

        DB::beginTransaction();

        try {
            /*
            |--------------------------------------------------------------------------
            | Replace existing student records.
            |--------------------------------------------------------------------------
            |
            | When replace_existing is true, the previous batch is deleted
            | before importing the new Excel data.
            |
            */

            if ($replaceExisting) {
                $oldStudents = $project->studentRecords()->get();

                $deletedCount = $oldStudents->count();

                /*
                |--------------------------------------------------------------------------
                | Collect old student photo paths before deleting records.
                |--------------------------------------------------------------------------
                |
                | Database deletion cascades StudentPhoto records.
                | Therefore, collect the file paths first.
                |
                | The actual files are removed only after the database
                | transaction successfully commits.
                |
                */

                $oldPhotoPaths = $oldStudents
                    ->load('photo')
                    ->pluck('photo.file_path')
                    ->filter()
                    ->values()
                    ->all();

                // Delete the old student records.
                $project->studentRecords()->delete();

                /*
                |--------------------------------------------------------------------------
                | Import the new student records.
                |--------------------------------------------------------------------------
                |
                | StudentRecordsImport handles:
                |
                | - Barcode
                | - Student ID
                | - Student Name
                | - First Name
                | - Last Name
                | - Extra Excel columns
                | - Duplicate/update handling
                |
                | For the new format:
                |
                | first_name = Achini
                | last_name  = Sara
                |
                | it creates:
                |
                | student_name = "Achini Sara"
                |
                */

                $import = new StudentRecordsImport($project);

                Excel::import($import, $file);

                // Commit the database transaction.
                DB::commit();

                /*
                |--------------------------------------------------------------------------
                | Delete old private student photo files after successful commit.
                |--------------------------------------------------------------------------
                |
                | This ensures a failed import does not accidentally remove
                | files belonging to the previous successful batch.
                |
                */

                foreach ($oldPhotoPaths as $path) {
                    Storage::disk('local')->delete($path);
                }

                /*
                |--------------------------------------------------------------------------
                | Delete old generated barcode images.
                |--------------------------------------------------------------------------
                |
                | Barcode images are keyed by the old student IDs.
                | They are regenerated when needed for the new batch.
                |
                */

                Storage::disk('local')->deleteDirectory(
                    'ecls/projects/' . $project->id . '/student-barcodes'
                );
            } else {
                /*
                |--------------------------------------------------------------------------
                | Merge mode.
                |--------------------------------------------------------------------------
                |
                | Existing student records are kept and the new Excel rows
                | are imported/updated by StudentRecordsImport.
                |
                */

                $import = new StudentRecordsImport($project);

                Excel::import($import, $file);

                // Commit the database transaction.
                DB::commit();
            }
        } catch (\Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Roll back everything when the import fails.
            |--------------------------------------------------------------------------
            */

            DB::rollBack();

            // Log the real exception for debugging.
            report($exception);

            return response()->json([
                'message' => 'Student data import failed.',
                'error' => config('app.debug')
                    ? $exception->getMessage()
                    : 'Unable to import student data.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Get the final number of students in the project.
        |--------------------------------------------------------------------------
        */

        $currentCount = $project
            ->studentRecords()
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Return the import summary.
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' => 'Student data imported successfully.',
            'project_id' => $project->id,
            'replace_existing' => $replaceExisting,
            'deleted_count' => $deletedCount,
            'current_student_count' => $currentCount,

            // Counts provided by StudentRecordsImport.
            'imported_count' => $import?->importedCount ?? 0,
            'updated_count' => $import?->updatedCount ?? 0,
            'skipped_count' => $import?->skippedCount ?? 0,
            'failed_count' => $import?->failedCount ?? 0,

            // Individual row errors, if any.
            'row_errors' => $import?->rowErrors ?? [],
        ]);
    }
}
