<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentRecordController extends Controller
{
    /**
     * Get students belonging to a project.
     *
     * Supports:
     *
     * - Search
     * - Barcode filtering
     * - Student ID filtering
     * - Photo status filtering
     * - Pagination
     */
    public function index(Request $request, Project $project)
    {
        /*
         * Validate the optional filters.
         */
        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'barcode' => [
                'nullable',
                'string',
                'max:255',
            ],

            'student_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            'photo_status' => [
                'nullable',
                'in:matched,missing',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        /*
         * Start with students belonging ONLY to this project.
         */
        $query = $project->studentRecords()
            ->with('photo');

        /*
         * General search.
         *
         * Searches:
         * - barcode
         * - student ID
         * - student name
         */
        if (!empty($validated['search'])) {

            $search = $validated['search'];

            $query->where(function ($q) use ($search) {

                $q->where('barcode', 'like', '%' . $search . '%')
                    ->orWhere('student_id', 'like', '%' . $search . '%')
                    ->orWhere('student_name', 'like', '%' . $search . '%');
            });
        }

        /*
         * Exact barcode filter.
         *
         * We intentionally use exact matching here because
         * barcodes identify students.
         */
        if (!empty($validated['barcode'])) {

            $query->where(
                'barcode',
                $validated['barcode']
            );
        }

        /*
         * Exact student ID filter.
         */
        if (!empty($validated['student_id'])) {

            $query->where(
                'student_id',
                $validated['student_id']
            );
        }

        /*
         * Filter students according to photo status.
         */
        if (!empty($validated['photo_status'])) {

            if ($validated['photo_status'] === 'matched') {

                /*
                 * Student must have a matched photo.
                 */
                $query->whereHas('photo', function ($q) {
                    $q->where('match_status', 'matched');
                });
            }

            if ($validated['photo_status'] === 'missing') {

                /*
                 * Student must NOT have a matched photo.
                 */
                $query->whereDoesntHave('photo', function ($q) {
                    $q->where('match_status', 'matched');
                });
            }
        }

        /*
         * Latest records first.
         *
         * Pagination prevents thousands of records from being
         * returned in a single request.
         */
        $students = $query
            ->latest('id')
            ->paginate(
                $validated['per_page'] ?? 25
            );

        /*
         * Transform the records into a clean API response.
         */
        $students->through(function ($student) {

            return [
                'id' => $student->id,

                'barcode' => $student->barcode,

                'student_id' => $student->student_id,

                'student_name' => $student->student_name,

                /*
                 * Additional Excel columns.
                 */
                'data' => $student->data ?? [],

                /*
                 * Photo information.
                 *
                 * We return metadata only for now.
                 *
                 * The actual image will be delivered through
                 * a secure photo endpoint.
                 */
                'photo' => $student->photo
                    ? [
                        'id' => $student->photo->id,

                        'barcode' =>
                            $student->photo->barcode,

                        'match_status' =>
                            $student->photo->match_status,

                        'original_filename' =>
                            $student->photo->original_filename,

                        'mime_type' =>
                            $student->photo->mime_type,

                        'file_size' =>
                            $student->photo->file_size,
                    ]
                    : null,
            ];
        });

        return response()->json([
            'message' => 'Student records retrieved successfully.',

            'project_id' => $project->id,

            'students' => $students,
        ]);
    }


    /**
     * Get one student record.
     *
     * This will be useful when previewing an individual card.
     */
    public function show(
        Project $project,
        int $student
    ) {
        /*
         * Find the student only inside this project.
         *
         * This prevents accessing a student belonging
         * to another project.
         */
        $studentRecord = $project->studentRecords()
            ->with('photo')
            ->find($student);

        if (!$studentRecord) {

            return response()->json([
                'message' => 'Student record not found in this project.',
            ], 404);
        }

        return response()->json([
            'message' => 'Student record retrieved successfully.',

            'student' => [
                'id' => $studentRecord->id,

                'project_id' =>
                    $studentRecord->project_id,

                'barcode' =>
                    $studentRecord->barcode,

                'student_id' =>
                    $studentRecord->student_id,

                'student_name' =>
                    $studentRecord->student_name,

                'data' =>
                    $studentRecord->data ?? [],

                'photo' => $studentRecord->photo
                    ? [
                        'id' =>
                            $studentRecord->photo->id,

                        'barcode' =>
                            $studentRecord->photo->barcode,

                        'match_status' =>
                            $studentRecord->photo->match_status,

                        'original_filename' =>
                            $studentRecord->photo->original_filename,

                        'mime_type' =>
                            $studentRecord->photo->mime_type,

                        'file_size' =>
                            $studentRecord->photo->file_size,
                    ]
                    : null,
            ],
        ]);
    }

    /**
     * Securely retrieve a student's photo.
     *
     * The photo is stored in Laravel's private local storage,
     * so we do not expose a public storage URL.
     */
    public function photo(
        Project $project,
        int $student
    ) {
        /*
        * Find the student only inside this project.
        *
        * This prevents a student from another project
        * being accessed using this endpoint.
        */
        $studentRecord = $project->studentRecords()
            ->with('photo')
            ->find($student);

        /*
        * Student does not exist in this project.
        */
        if (!$studentRecord) {

            return response()->json([
                'message' => 'Student record not found in this project.',
            ], 404);
        }

        /*
        * Get the student's photo.
        */
        $photo = $studentRecord->photo;

        /*
        * Student does not have a photo.
        */
        if (!$photo) {

            return response()->json([
                'message' => 'Student photo not found.',
            ], 404);
        }

        /*
        * Use Laravel's private local storage.
        */
        $disk = Storage::disk('local');

        /*
        * Check whether the physical photo file still exists.
        */
        if (!$disk->exists($photo->file_path)) {

            return response()->json([
                'message' => 'Student photo file is missing from storage.',
            ], 404);
        }

        /*
        * Get the actual physical file path.
        */
        $filePath = $disk->path($photo->file_path);

        /*
        * Return the image directly.
        *
        * The storage path is NOT exposed to the client.
        */
        return response()->file($filePath, [
            'Content-Type' =>
                $photo->mime_type ?? 'application/octet-stream',

            'Cache-Control' =>
                'private, no-store',
        ]);
    }
}
