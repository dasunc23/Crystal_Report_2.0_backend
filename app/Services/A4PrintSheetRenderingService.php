<?php

namespace App\Services;

use App\Models\StudentRecord;
use App\Models\Template;
use FPDF;
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\File;

class A4PrintSheetRenderingService
{
    /**
     * Default A4 page dimensions in millimetres.
     */
    private const DEFAULT_PAGE_WIDTH = 210;
    private const DEFAULT_PAGE_HEIGHT = 297;

    /**
     * Generate an A4 print sheet containing front/back pairs.
     *
     * The existing CardRenderingService is intentionally reused
     * so the working single-card renderer is not modified.
     */
    public function render(
        Template $template,
        $students,
        array $options = []
    ): string {

        /*
        |--------------------------------------------------------------------------
        | Page configuration
        |--------------------------------------------------------------------------
        */

        $pageWidth = (float) ($options['page_width_mm'] ?? self::DEFAULT_PAGE_WIDTH);
        $pageHeight = (float) ($options['page_height_mm'] ?? self::DEFAULT_PAGE_HEIGHT);

        $orientation = $options['orientation'] ?? 'portrait';

        /*
        |--------------------------------------------------------------------------
        | Margins
        |--------------------------------------------------------------------------
        */

        $marginTop = (float) ($options['margin_top_mm'] ?? 5);
        $marginRight = (float) ($options['margin_right_mm'] ?? 5);
        $marginBottom = (float) ($options['margin_bottom_mm'] ?? 5);
        $marginLeft = (float) ($options['margin_left_mm'] ?? 5);

        /*
        |--------------------------------------------------------------------------
        | Spacing
        |--------------------------------------------------------------------------
        */

        // Space between front and back of the same student.
        $pairGap = (float) ($options['pair_gap_mm'] ?? 1);

        // Vertical space between students.
        $rowGap = (float) ($options['row_gap_mm'] ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Card dimensions come directly from the template.
        |--------------------------------------------------------------------------
        */

        $cardWidth = (float) $template->width_mm;
        $cardHeight = (float) $template->height_mm;

        /*
        |--------------------------------------------------------------------------
        | If landscape is requested, swap the A4 dimensions.
        |--------------------------------------------------------------------------
        */

        if ($orientation === 'landscape') {
            [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate how much horizontal space one student pair requires.
        |--------------------------------------------------------------------------
        */

        $pairWidth = ($cardWidth * 2) + $pairGap;

        $usableWidth = $pageWidth - $marginLeft - $marginRight;
        $usableHeight = $pageHeight - $marginTop - $marginBottom;

        /*
        |--------------------------------------------------------------------------
        | Validate horizontal fit.
        |--------------------------------------------------------------------------
        */

        if ($pairWidth > $usableWidth) {
            throw new \RuntimeException(
                "The front/back card pair ({$pairWidth} mm) does not fit "
                . "inside the available A4 width ({$usableWidth} mm)."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate how many student rows fit vertically.
        |--------------------------------------------------------------------------
        */

        $rowHeight = $cardHeight + $rowGap;

        $studentsPerPage = (int) floor(
            ($usableHeight + $rowGap) / $rowHeight
        );

        if ($studentsPerPage < 1) {
            throw new \RuntimeException(
                "The card height ({$cardHeight} mm) does not fit "
                . "inside the available page height ({$usableHeight} mm)."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create A4 PDF.
        |--------------------------------------------------------------------------
        */

        $pdf = new Fpdi(
            $orientation === 'landscape' ? 'L' : 'P',
            'mm',
            [$pageWidth, $pageHeight]
        );

        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        /*
        |--------------------------------------------------------------------------
        | Existing single-card rendering service.
        |--------------------------------------------------------------------------
        */

        $cardRenderer = app(CardRenderingService::class);

        /*
        |--------------------------------------------------------------------------
        | Temporary files created during rendering.
        |--------------------------------------------------------------------------
        */

        $temporaryFiles = [];

        try {

            /*
            |--------------------------------------------------------------------------
            | Process students.
            |--------------------------------------------------------------------------
            */

            $studentIndex = 0;

            foreach ($students as $student) {

                /*
                |--------------------------------------------------------------------------
                | Determine page and row.
                |--------------------------------------------------------------------------
                */

                $positionOnPage = $studentIndex % $studentsPerPage;

                /*
                |--------------------------------------------------------------------------
                | Start a new A4 page.
                |--------------------------------------------------------------------------
                */

                if ($positionOnPage === 0) {

                    $pdf->AddPage(
                        $orientation === 'landscape' ? 'L' : 'P',
                        [$pageWidth, $pageHeight]
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Calculate Y position.
                |--------------------------------------------------------------------------
                */

                $y = $marginTop + (
                    $positionOnPage * $rowHeight
                );

                /*
                |--------------------------------------------------------------------------
                | Front card position.
                |--------------------------------------------------------------------------
                */

                $frontX = $marginLeft;

                /*
                |--------------------------------------------------------------------------
                | Back card position.
                |--------------------------------------------------------------------------
                */

                $backX = $marginLeft + $cardWidth + $pairGap;

                /*
                |--------------------------------------------------------------------------
                | Render front card using the existing renderer.
                |--------------------------------------------------------------------------
                */

                $frontPdf = $cardRenderer->render(
                    $template,
                    $student,
                    'front'
                );

                $frontTemp = $this->createTemporaryPdf(
                    $frontPdf,
                    'front'
                );

                $temporaryFiles[] = $frontTemp;

                /*
                |--------------------------------------------------------------------------
                | Import the rendered front card.
                |--------------------------------------------------------------------------
                */

                $frontPageCount = $pdf->setSourceFile($frontTemp);

                if ($frontPageCount < 1) {
                    throw new \RuntimeException(
                        "Unable to read rendered front card PDF."
                    );
                }

                $frontTemplate = $pdf->importPage(1);

                $pdf->useTemplate(
                    $frontTemplate,
                    $frontX,
                    $y,
                    $cardWidth,
                    $cardHeight
                );

                /*
                |--------------------------------------------------------------------------
                | Render back card.
                |--------------------------------------------------------------------------
                */

                $backPdf = $cardRenderer->render(
                    $template,
                    $student,
                    'back'
                );

                $backTemp = $this->createTemporaryPdf(
                    $backPdf,
                    'back'
                );

                $temporaryFiles[] = $backTemp;

                /*
                |--------------------------------------------------------------------------
                | Import rendered back card.
                |--------------------------------------------------------------------------
                */

                $backPageCount = $pdf->setSourceFile($backTemp);

                if ($backPageCount < 1) {
                    throw new \RuntimeException(
                        "Unable to read rendered back card PDF."
                    );
                }

                $backTemplate = $pdf->importPage(1);

                $pdf->useTemplate(
                    $backTemplate,
                    $backX,
                    $y,
                    $cardWidth,
                    $cardHeight
                );

                $studentIndex++;
            }

            /*
            |--------------------------------------------------------------------------
            | Return final PDF as a string.
            |--------------------------------------------------------------------------
            */

            return $pdf->Output('S');

        } finally {

            /*
            |--------------------------------------------------------------------------
            | Always remove temporary PDFs.
            |--------------------------------------------------------------------------
            */

            foreach ($temporaryFiles as $temporaryFile) {

                if (File::exists($temporaryFile)) {
                    File::delete($temporaryFile);
                }
            }
        }
    }


    /**
     * Create a temporary PDF file.
     */
    private function createTemporaryPdf(
        string $pdfContent,
        string $prefix
    ): string {

        $directory = storage_path('app/temp');

        if (!File::exists($directory)) {
            File::makeDirectory(
                $directory,
                0755,
                true
            );
        }

        $filename = $prefix . '_' . uniqid('', true) . '.pdf';

        $path = $directory . DIRECTORY_SEPARATOR . $filename;

        File::put($path, $pdfContent);

        return $path;
    }


    /**
     * Calculate the print-sheet layout.
     *
     * Useful later for a visual print preview in React.
     */
    public function calculateLayout(
        Template $template,
        int $studentCount,
        array $options = []
    ): array {

        $pageWidth = (float) ($options['page_width_mm'] ?? self::DEFAULT_PAGE_WIDTH);
        $pageHeight = (float) ($options['page_height_mm'] ?? self::DEFAULT_PAGE_HEIGHT);

        $orientation = $options['orientation'] ?? 'portrait';

        if ($orientation === 'landscape') {
            [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
        }

        $marginTop = (float) ($options['margin_top_mm'] ?? 5);
        $marginRight = (float) ($options['margin_right_mm'] ?? 5);
        $marginBottom = (float) ($options['margin_bottom_mm'] ?? 5);
        $marginLeft = (float) ($options['margin_left_mm'] ?? 5);

        $pairGap = (float) ($options['pair_gap_mm'] ?? 1);
        $rowGap = (float) ($options['row_gap_mm'] ?? 0);

        $cardWidth = (float) $template->width_mm;
        $cardHeight = (float) $template->height_mm;

        $pairWidth = ($cardWidth * 2) + $pairGap;

        $usableWidth = $pageWidth - $marginLeft - $marginRight;
        $usableHeight = $pageHeight - $marginTop - $marginBottom;

        $studentsPerPage = (int) floor(
            ($usableHeight + $rowGap) /
            ($cardHeight + $rowGap)
        );

        $pageCount = $studentsPerPage > 0
            ? (int) ceil($studentCount / $studentsPerPage)
            : 0;

        return [
            'page' => [
                'width_mm' => $pageWidth,
                'height_mm' => $pageHeight,
                'orientation' => $orientation,
            ],

            'card' => [
                'width_mm' => $cardWidth,
                'height_mm' => $cardHeight,
            ],

            'pair' => [
                'width_mm' => $pairWidth,
                'gap_mm' => $pairGap,
            ],

            'margins' => [
                'top_mm' => $marginTop,
                'right_mm' => $marginRight,
                'bottom_mm' => $marginBottom,
                'left_mm' => $marginLeft,
            ],

            'row_gap_mm' => $rowGap,

            'students_per_page' => $studentsPerPage,

            'student_count' => $studentCount,

            'page_count' => $pageCount,
        ];
    }
}
