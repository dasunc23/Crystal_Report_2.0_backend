<?php

namespace App\Imports;

use App\Models\Project;
use App\Models\StudentRecord;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentRecordsImport implements ToCollection, WithHeadingRow
{
    /**
     * The project that owns these student records.
     */
    protected Project $project;

    /**
     * Store import statistics.
     */
    public int $importedCount = 0;

    public int $updatedCount = 0;

    /**
     * Receive the project when creating the importer.
     */
    public function __construct(Project $project)
    {
        $this->project = $project;
    }

    /**
     * Process the Excel rows.
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            // Convert the Excel row into a normal PHP array.
            $data = $row->toArray();

            // Get the barcode and always treat it as text.
            $barcode = isset($data['barcode'])
                ? trim((string) $data['barcode'])
                : '';

            // Skip rows without a barcode.
            if ($barcode === '') {
                continue;
            }

            // Get the standard student fields.
            $studentId = isset($data['student_id'])
                ? trim((string) $data['student_id'])
                : null;

            $studentName = isset($data['student_name'])
                ? trim((string) $data['student_name'])
                : null;

            // Store all Excel data.
            //
            // This allows the Excel file to contain additional
            // columns without changing the database structure.
            $extraData = $data;

            // These fields already have dedicated database columns,
            // so we don't need to duplicate them inside the JSON data.
            unset(
                $extraData['barcode'],
                $extraData['student_id'],
                $extraData['student_name']
            );

            // Check whether this barcode already exists
            // inside the current project.
            $student = StudentRecord::where('project_id', $this->project->id)
                ->where('barcode', $barcode)
                ->first();

            if ($student) {
                // Update the existing student record.
                $student->update([
                    'student_id' => $studentId,
                    'student_name' => $studentName,
                    'data' => $extraData,
                ]);

                $this->updatedCount++;

                continue;
            }

            // Create a new student record.
            StudentRecord::create([
                'project_id' => $this->project->id,
                'barcode' => $barcode,
                'student_id' => $studentId,
                'student_name' => $studentName,
                'data' => $extraData,
            ]);

            $this->importedCount++;
        }
    }
}
