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

    public int $skippedCount = 0;

    public int $failedCount = 0;

    /**
     * Row-level reasons returned to the import API.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rowErrors = [];

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
        $seenBarcodes = [];

        foreach ($rows as $index => $row) {
            // Convert the Excel row into a normal PHP array.
            $data = $row->toArray();

            $studentId = isset($data['student_id'])
                ? trim((string) $data['student_id'])
                : '';

            $studentName = isset($data['student_name'])
                ? trim((string) $data['student_name'])
                : '';

            // Rows without identifying student data cannot be imported.
            if ($studentId === '' && $studentName === '') {
                $this->skippedCount++;
                $this->rowErrors[] = [
                    'row' => $index + 2,
                    'reason' => 'Student name or student ID is required.',
                ];

                continue;
            }

            // Keep supplied barcode text exactly, including leading zeroes.
            $barcode = isset($data['barcode'])
                ? trim((string) $data['barcode'])
                : null;

            if ($barcode === '') {
                $barcode = null;
            }

            // Do not process the same non-empty barcode twice in one upload.
            if ($barcode !== null && isset($seenBarcodes[$barcode])) {
                $this->skippedCount++;
                $this->rowErrors[] = [
                    'row' => $index + 2,
                    'barcode' => $barcode,
                    'reason' => 'Duplicate barcode in the uploaded file.',
                ];

                continue;
            }

            if ($barcode !== null) {
                $seenBarcodes[$barcode] = true;
            }

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

            try {
                // Supplied barcodes identify updates; null barcodes create a
                // record for the later explicit generation step.
                $student = $barcode === null
                    ? null
                    : StudentRecord::where('project_id', $this->project->id)
                        ->where('barcode', $barcode)
                        ->first();

                if ($student) {
                    $student->update([
                        'student_id' => $studentId !== '' ? $studentId : null,
                        'student_name' => $studentName !== '' ? $studentName : null,
                        'data' => $extraData,
                    ]);

                    $this->updatedCount++;

                    continue;
                }

                StudentRecord::create([
                    'project_id' => $this->project->id,
                    'barcode' => $barcode,
                    'student_id' => $studentId !== '' ? $studentId : null,
                    'student_name' => $studentName !== '' ? $studentName : null,
                    'data' => $extraData,
                ]);

                $this->importedCount++;
            } catch (\Throwable $exception) {
                $this->failedCount++;
                $this->rowErrors[] = [
                    'row' => $index + 2,
                    'barcode' => $barcode,
                    'reason' => $exception->getMessage(),
                ];
            }
        }
    }
}
