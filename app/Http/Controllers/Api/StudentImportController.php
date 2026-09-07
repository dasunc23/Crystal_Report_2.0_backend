<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ExcelPreviewImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\StudentRecordsImport;
use App\Models\Project;

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

        // Create the importer for this project.
        $import = new StudentRecordsImport($project);

        // Import the Excel data.
        Excel::import($import, $file);

        return response()->json([
            'message' => 'Student data imported successfully.',
            'project_id' => $project->id,
            'imported_count' => $import->importedCount,
            'updated_count' => $import->updatedCount,
        ]);
    }
}
