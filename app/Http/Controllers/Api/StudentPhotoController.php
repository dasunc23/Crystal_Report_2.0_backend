<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StudentPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentPhotoController extends Controller
{
    /**
     * Upload and match student photos using the exact barcode
     * in the photo filename.
     *
     * Example:
     * 00000001.jpg  -> barcode 00000001
     * 00000002.png  -> barcode 00000002
     */
    public function upload(Request $request, Project $project)
    {
        // Validate that the request contains an array of photos.
        $request->validate([
            'photos' => [
                'required',
                'array',
                'min:1',
            ],

            // Validate each uploaded file as an image.
            'photos.*' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240', // Maximum 10 MB per photo.
            ],
        ]);

        $matchedCount = 0;
        $invalidCount = 0;
        $duplicateCount = 0;

        $matchedPhotos = [];
        $invalidPhotos = [];
        $duplicatePhotos = [];
        $seenBarcodes = [];

        foreach ($request->file('photos') as $photo) {

            /*
             * Get the original filename.
             *
             * Example:
             * "00000001.jpg"
             */
            $originalFilename = $photo->getClientOriginalName();

            /*
             * Get the filename without the extension.
             *
             * Example:
             * "00000001.jpg" -> "00000001"
             */
            $barcode = pathinfo(
                $originalFilename,
                PATHINFO_FILENAME
            );

            // Do not let two files in one upload silently replace each other.
            if (isset($seenBarcodes[$barcode])) {
                $duplicateCount++;
                $duplicatePhotos[] = [
                    'filename' => $originalFilename,
                    'barcode' => $barcode,
                    'reason' => 'Another uploaded file uses the same barcode filename.',
                ];

                continue;
            }

            $seenBarcodes[$barcode] = true;

            /*
             * Find the student ONLY inside this project.
             *
             * We use exact matching.
             *
             * We do NOT try to guess a barcode.
             */
            $student = $project->studentRecords()
                ->where('barcode', $barcode)
                ->first();

            /*
             * If there is no student with this exact barcode,
             * the photo is invalid/unmatched.
             */
            if (!$student) {

                $invalidCount++;

                $invalidPhotos[] = [
                    'filename' => $originalFilename,
                    'barcode' => $barcode,
                    'reason' => 'No student record found with this exact barcode.',
                ];

                continue;
            }

            /*
             * Get the uploaded file's extension and MIME type.
             */
            $extension = strtolower(
                $photo->getClientOriginalExtension()
            );

            $mimeType = $photo->getMimeType();

            /*
             * We use a generated storage filename instead of
             * directly using the barcode as a filesystem path.
             *
             * This is safer because barcode values are user data.
             */
            $storageFilename = hash(
                'sha256',
                $student->id . '|' . $student->barcode
            ) . '.' . $extension;

            /*
             * Store photos on Laravel's private "local" disk.
             *
             * These photos should NOT be publicly accessible.
             */
            $filePath = $photo->storeAs(
                'ecls/projects/' . $project->id . '/student-photos',
                $storageFilename,
                'local'
            );

            /*
             * If the student already has a photo,
             * replace/update the existing photo information.
             *
             * This means uploading the same student's photo again
             * will update the existing record instead of creating
             * duplicate StudentPhoto records.
             */
            StudentPhoto::updateOrCreate(
                [
                    'student_record_id' => $student->id,
                ],
                [
                    'barcode' => $student->barcode,
                    'file_path' => $filePath,
                    'original_filename' => $originalFilename,
                    'match_status' => 'matched',
                    'mime_type' => $mimeType,
                    'file_size' => $photo->getSize(),
                ]
            );

            $matchedCount++;

            $matchedPhotos[] = [
                'filename' => $originalFilename,
                'barcode' => $student->barcode,
                'student_record_id' => $student->id,
            ];
        }

        /*
         * Calculate how many students are still missing photos.
         *
         * We do this by counting students in this project
         * that do not have a matched StudentPhoto record.
         */
        $totalStudents = $project->studentRecords()->count();

        $studentsWithPhotos = StudentPhoto::whereHas(
            'studentRecord',
            function ($query) use ($project) {
                $query->where('project_id', $project->id);
            }
        )
            ->where('match_status', 'matched')
            ->count();

        $missingCount = max(
            0,
            $totalStudents - $studentsWithPhotos
        );

        $missingStudents = $project->studentRecords()
            ->whereDoesntHave('photo', function ($query) {
                $query->where('match_status', 'matched');
            })
            ->get(['id', 'barcode', 'student_id', 'student_name'])
            ->map(fn ($student) => [
                'student_record_id' => $student->id,
                'barcode' => $student->barcode,
                'student_id' => $student->student_id,
                'student_name' => $student->student_name,
            ])
            ->values();

        return response()->json([
            'message' => 'Student photos processed successfully.',

            'project_id' => $project->id,

            'summary' => [
                'total_students' => $totalStudents,
                'matched' => $matchedCount,
                'missing' => $missingCount,
                'invalid' => $invalidCount,
                'duplicates' => $duplicateCount,
            ],

            'matched_photos' => $matchedPhotos,

            'invalid_photos' => $invalidPhotos,

            'duplicate_photos' => $duplicatePhotos,

            'missing_students' => $missingStudents,
        ]);
    }


    /**
     * Get the current photo matching summary for a project.
     */
    public function summary(Project $project)
    {
        // Total students in this project.
        $totalStudents = $project->studentRecords()->count();

        // Students that currently have a matched photo.
        $matchedCount = StudentPhoto::whereHas(
            'studentRecord',
            function ($query) use ($project) {
                $query->where('project_id', $project->id);
            }
        )
            ->where('match_status', 'matched')
            ->count();

        // Students without a matched photo.
        $missingCount = max(
            0,
            $totalStudents - $matchedCount
        );

        return response()->json([
            'project_id' => $project->id,

            'summary' => [
                'total_students' => $totalStudents,
                'matched' => $matchedCount,
                'missing' => $missingCount,
            ],
        ]);
    }
}
