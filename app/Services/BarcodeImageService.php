<?php

namespace App\Services;

use App\Models\StudentRecord;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGeneratorPNG;
use RuntimeException;

class BarcodeImageService
{
    public const FORMAT = 'CODE 128';

    /**
     * Generate and privately store the barcode graphic for a student value.
     *
     * The value is passed as a string so leading zeroes remain part of the
     * encoded barcode data instead of being converted to an integer.
     */
    public function generate(StudentRecord $student, ?string $barcode = null): string
    {
        $barcode = $barcode ?? trim((string) $student->barcode);

        if ($barcode === '') {
            throw new RuntimeException('A barcode value is required.');
        }

        $generator = new BarcodeGeneratorPNG();
        $contents = $generator->getBarcode(
            $barcode,
            $generator::TYPE_CODE_128,
            3,
            100
        );

        $path = $this->pathFor($student);

        if (!Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException(
                'The barcode image could not be stored for student ' . $student->id . '.'
            );
        }

        return $path;
    }

    public function pathFor(StudentRecord $student): string
    {
        $safeValue = hash('sha256', $student->id . '|' . $student->barcode);

        return 'ecls/projects/' . $student->project_id
            . '/student-barcodes/' . $safeValue . '.png';
    }
}
