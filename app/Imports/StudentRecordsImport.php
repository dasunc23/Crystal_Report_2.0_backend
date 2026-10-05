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
        /*
        |--------------------------------------------------------------------------
        | Keep track of barcodes already processed in this upload.
        |--------------------------------------------------------------------------
        |
        | This prevents the same non-empty barcode from appearing more than
        | once in a single Excel upload.
        |
        */

        $seenBarcodes = [];

        foreach ($rows as $index => $row) {
            /*
            |--------------------------------------------------------------------------
            | Convert the Excel row into a normal PHP array.
            |--------------------------------------------------------------------------
            |
            | Because WithHeadingRow is used, Excel headings such as:
            |
            | First Name
            | Last Name
            | Student ID
            |
            | are normally converted to:
            |
            | first_name
            | last_name
            | student_id
            |
            */

            $data = $row->toArray();

            /*
            |--------------------------------------------------------------------------
            | Student ID
            |--------------------------------------------------------------------------
            |
            | Student ID is still treated as one of the main student fields.
            |
            */

            $studentId = isset($data['student_id'])
                ? trim((string) $data['student_id'])
                : '';

            /*
            |--------------------------------------------------------------------------
            | Existing Student Name support
            |--------------------------------------------------------------------------
            |
            | The old Excel format can still contain:
            |
            | student_name
            |
            | We keep supporting it for backward compatibility.
            |
            */

            $studentName = isset($data['student_name'])
                ? trim((string) $data['student_name'])
                : '';

            /*
            |--------------------------------------------------------------------------
            | First Name
            |--------------------------------------------------------------------------
            |
            | New Excel format:
            |
            | First Name
            |
            | WithHeadingRow normally converts this to:
            |
            | first_name
            |
            */

            $firstName = isset($data['first_name'])
                ? trim((string) $data['first_name'])
                : '';

            /*
            |--------------------------------------------------------------------------
            | Last Name
            |--------------------------------------------------------------------------
            |
            | New Excel format:
            |
            | Last Name
            |
            | WithHeadingRow normally converts this to:
            |
            | last_name
            |
            */

            $lastName = isset($data['last_name'])
                ? trim((string) $data['last_name'])
                : '';

            /*
            |--------------------------------------------------------------------------
            | Barcode
            |--------------------------------------------------------------------------
            |
            | Keep supplied barcode text exactly, including leading zeroes.
            |
            | Example:
            |
            | 00000001
            |
            | must remain:
            |
            | 00000001
            |
            */

            $barcode = isset($data['barcode'])
                ? trim((string) $data['barcode'])
                : null;

            if ($barcode === '') {
                $barcode = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Build Student Name from First Name + Last Name.
            |--------------------------------------------------------------------------
            |
            | If First Name and/or Last Name are supplied, they are combined
            | with a single space.
            |
            | Examples:
            |
            | First Name = Mohamada Allam
            | Last Name  = Rizvi
            |
            | Result:
            |
            | Mohamada Allam Rizvi
            |
            | This keeps the existing student_name database column populated
            | while also preserving first_name and last_name separately.
            |
            */

            if ($firstName !== '' || $lastName !== '') {
                $combinedName = trim(
                    $firstName . ' ' . $lastName
                );

                /*
                |--------------------------------------------------------------------------
                | Use the combined name as the main student name.
                |--------------------------------------------------------------------------
                |
                | This means the new First Name + Last Name Excel structure
                | automatically works with the existing student_name field.
                |
                */

                $studentName = $combinedName;
            }

            /*
            |--------------------------------------------------------------------------
            | Validate identifying student information.
            |--------------------------------------------------------------------------
            |
            | A row is considered valid when it has at least one of:
            |
            | - Barcode
            | - Student ID
            | - Student Name
            | - First Name
            | - Last Name
            |
            | Barcode is included because some templates only require
            | barcode data.
            |
            */

            if (
                $barcode === null
                && $studentId === ''
                && $studentName === ''
                && $firstName === ''
                && $lastName === ''
            ) {
                $this->skippedCount++;

                $this->rowErrors[] = [
                    'row' => $index + 2,
                    'reason' => 'Student name, first name, last name, student ID, or barcode is required.',
                ];

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate barcodes in the same upload.
            |--------------------------------------------------------------------------
            |
            | Only non-empty barcodes are checked.
            |
            */

            if (
                $barcode !== null
                && isset($seenBarcodes[$barcode])
            ) {
                $this->skippedCount++;

                $this->rowErrors[] = [
                    'row' => $index + 2,
                    'barcode' => $barcode,
                    'reason' => 'Duplicate barcode in the uploaded file.',
                ];

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Remember this barcode for the current upload.
            |--------------------------------------------------------------------------
            */

            if ($barcode !== null) {
                $seenBarcodes[$barcode] = true;
            }

            /*
            |--------------------------------------------------------------------------
            | Store all Excel data.
            |--------------------------------------------------------------------------
            |
            | This allows the Excel file to contain additional columns without
            | requiring a new database column for every Excel field.
            |
            | For example:
            |
            | course
            | year
            | NIC
            | first_name
            | last_name
            |
            | can all remain inside the JSON data column.
            |
            */

            $extraData = $data;

            /*
            |--------------------------------------------------------------------------
            | Remove fields that already have dedicated database columns.
            |--------------------------------------------------------------------------
            |
            | barcode
            | student_id
            | student_name
            |
            | are stored directly in student_records.
            |
            | IMPORTANT:
            |
            | first_name and last_name are intentionally NOT removed.
            |
            | They need to remain in the JSON data so the Template Editor
            | can later map:
            |
            | First Name + Last Name
            |
            | independently.
            |
            */

            unset(
                $extraData['barcode'],
                $extraData['student_id'],
                $extraData['student_name']
            );

            /*
            |--------------------------------------------------------------------------
            | Make sure First Name and Last Name remain available in JSON.
            |--------------------------------------------------------------------------
            |
            | Normally they are already present in $extraData because we only
            | removed the three dedicated database fields above.
            |
            | These explicit assignments make the behavior clear and ensure
            | trimmed values are stored.
            |
            */

            if ($firstName !== '') {
                $extraData['first_name'] = $firstName;
            } elseif (array_key_exists('first_name', $extraData)) {
                unset($extraData['first_name']);
            }

            if ($lastName !== '') {
                $extraData['last_name'] = $lastName;
            } elseif (array_key_exists('last_name', $extraData)) {
                unset($extraData['last_name']);
            }

            /*
            |--------------------------------------------------------------------------
            | Process the student record.
            |--------------------------------------------------------------------------
            |
            | Supplied barcodes identify existing records for updates.
            |
            | A null barcode creates a new record for the later explicit
            | generation step.
            |
            */

            try {
                $student = $barcode === null
                    ? null
                    : StudentRecord::where(
                        'project_id',
                        $this->project->id
                    )
                        ->where(
                            'barcode',
                            $barcode
                        )
                        ->first();

                /*
                |--------------------------------------------------------------------------
                | Update existing student.
                |--------------------------------------------------------------------------
                */

                if ($student) {
                    $student->update([
                        'student_id' => $studentId !== ''
                            ? $studentId
                            : null,

                        'student_name' => $studentName !== ''
                            ? $studentName
                            : null,

                        'data' => $extraData,
                    ]);

                    $this->updatedCount++;

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Create new student.
                |--------------------------------------------------------------------------
                */

                StudentRecord::create([
                    'project_id' => $this->project->id,

                    'barcode' => $barcode,

                    'student_id' => $studentId !== ''
                        ? $studentId
                        : null,

                    'student_name' => $studentName !== ''
                        ? $studentName
                        : null,

                    'data' => $extraData,
                ]);

                $this->importedCount++;
            } catch (\Throwable $exception) {
                /*
                |--------------------------------------------------------------------------
                | Record row-level import failure.
                |--------------------------------------------------------------------------
                */

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
