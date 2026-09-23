<?php

namespace App\Services;

use App\Models\Template;
use Illuminate\Support\Facades\File;
use setasign\Fpdi\Fpdi;

class A4PrintSheetRenderingService
{
    private const DEFAULT_PAGE_WIDTH = 210;
    private const DEFAULT_PAGE_HEIGHT = 297;

    /**
     * Render selected students on an A4 sheet using only enabled template sides.
     */
    public function render(
        Template $template,
        $students,
        array $options = []
    ): string {
        $hasFront = (bool) $template->has_front;
        $hasBack = (bool) $template->has_back;

        if (!$hasFront && !$hasBack) {
            throw new \RuntimeException(
                'The template must have at least one enabled card side.'
            );
        }

        $pageWidth = (float) (
            $options['page_width_mm'] ?? self::DEFAULT_PAGE_WIDTH
        );

        $pageHeight = (float) (
            $options['page_height_mm'] ?? self::DEFAULT_PAGE_HEIGHT
        );

        $orientation = $options['orientation'] ?? 'portrait';

        if ($orientation === 'landscape') {
            [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
        }

        /*
        |--------------------------------------------------------------------------
        | A4 vertical layout defaults
        |--------------------------------------------------------------------------
        |
        | The card dimensions come directly from the template and MUST NOT be
        | changed or scaled here.
        |
        | For the current 92 mm card width and an 8.1 mm centre gap:
        |
        |   210 - 92 - 8.1 - 92 = 17.9 mm
        |   17.9 / 2 = 8.95 mm on each outer side
        |
        | Therefore, when both front and back are enabled, the pair is centred
        | on A4 automatically. This keeps the left and right spaces identical.
        |
        | Note: exactly 10 mm on both sides AND an 8.1 mm centre gap cannot fit
        | two 92 mm cards on a 210 mm A4 page. Keeping the cards fixed means
        | 8.95 mm equal outer spaces is the physically correct layout.
        |
        */

        $marginTop = (float) (
            $options['margin_top_mm'] ?? 6
        );

        $marginBottom = (float) (
            $options['margin_bottom_mm'] ?? 6
        );

        $pairGap = (float) (
            $options['pair_gap_mm'] ?? 8.1
        );

        $rowGap = (float) (
            $options['row_gap_mm'] ?? 0
        );

        $cardWidth = (float) $template->width_mm;
        $cardHeight = (float) $template->height_mm;

        $horizontalCardCount = ($hasFront && $hasBack) ? 2 : 1;

        $requiredWidth = ($cardWidth * $horizontalCardCount)
            + (
                ($horizontalCardCount === 2)
                    ? $pairGap
                    : 0
            );

        /*
        |--------------------------------------------------------------------------
        | Calculate horizontal placement
        |--------------------------------------------------------------------------
        |
        | When front and back are both enabled, calculate the outer margins from
        | the actual fixed card dimensions. This guarantees equal left/right
        | whitespace and prevents accidental overflow caused by old 5 mm margins.
        |
        | When only one side is enabled, retain the configured/default 10 mm
        | left/right margins.
        |
        */

        if ($horizontalCardCount === 2) {
            $remainingWidth = $pageWidth - $requiredWidth;

            if ($remainingWidth < 0) {
                throw new \RuntimeException(
                    "The enabled front/back card pair ({$requiredWidth} mm) does not fit "
                    . "inside the page width ({$pageWidth} mm) while keeping the card "
                    . "dimensions unchanged."
                );
            }

            $marginLeft = $remainingWidth / 2;
            $marginRight = $remainingWidth / 2;
        } else {
            $marginLeft = (float) (
                $options['margin_left_mm'] ?? 10
            );

            $marginRight = (float) (
                $options['margin_right_mm'] ?? 10
            );
        }

        $usableWidth = $pageWidth - $marginLeft - $marginRight;
        $usableHeight = $pageHeight - $marginTop - $marginBottom;

        if ($requiredWidth > $usableWidth + 0.001) {
            throw new \RuntimeException(
                "The enabled card layout ({$requiredWidth} mm) does not fit "
                . "inside the available page width ({$usableWidth} mm)."
            );
        }

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

        $pdf = new Fpdi(
            $orientation === 'landscape' ? 'L' : 'P',
            'mm',
            [$pageWidth, $pageHeight]
        );

        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        $cardRenderer = app(CardRenderingService::class);

        $temporaryFiles = [];

        try {
            foreach ($students as $studentIndex => $student) {
                $positionOnPage = $studentIndex % $studentsPerPage;

                if ($positionOnPage === 0) {
                    $pdf->AddPage(
                        $orientation === 'landscape' ? 'L' : 'P',
                        [$pageWidth, $pageHeight]
                    );
                }

                $y = $marginTop + ($positionOnPage * $rowHeight);

                // Enabled sides are placed independently.
                // Disabled sides do not create blank cards or reserve space.
                if ($hasFront) {
                    $this->placeRenderedCard(
                        $pdf,
                        $cardRenderer->render(
                            $template,
                            $student,
                            'front'
                        ),
                        $marginLeft,
                        $y,
                        $cardWidth,
                        $cardHeight,
                        'front',
                        $temporaryFiles
                    );
                }

                if ($hasBack) {
                    $backX = $hasFront
                        ? $marginLeft + $cardWidth + $pairGap
                        : $marginLeft;

                    $this->placeRenderedCard(
                        $pdf,
                        $cardRenderer->render(
                            $template,
                            $student,
                            'back'
                        ),
                        $backX,
                        $y,
                        $cardWidth,
                        $cardHeight,
                        'back',
                        $temporaryFiles
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Centre separator and page number
                |--------------------------------------------------------------------------
                |
                | The separator is drawn only when front and back cards are present.
                | It sits exactly in the 8.1 mm centre gap and does not overlap either
                | card because the line is positioned at the centre of that gap.
                |
                | The current A4 page number is repeated beside each student pair,
                | matching the physical manufacturing-sheet style shown in the
                | reference layout.
                |
                */

                if ($hasFront && $hasBack) {
                    $centerX = $marginLeft
                        + $cardWidth
                        + ($pairGap / 2);

                    $this->drawPairSeparator(
                        $pdf,
                        $centerX,
                        $y,
                        $cardHeight,
                        $studentIndex,
                        $studentsPerPage
                    );
                }
            }

            return $pdf->Output('S');
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (File::exists($temporaryFile)) {
                    File::delete($temporaryFile);
                }
            }
        }
    }

    /**
     * Draw the vertical separator and page number in the centre gap.
     *
     * The card dimensions are never modified by this method.
     */
    private function drawPairSeparator(
        Fpdi $pdf,
        float $centerX,
        float $y,
        float $cardHeight,
        int $studentIndex,
        int $studentsPerPage
    ): void {
        $pageNumber = (int) floor(
            $studentIndex / max(1, $studentsPerPage)
        ) + 1;

        // Use a thin manufacturing-style separator line.
        $pdf->SetDrawColor(120, 120, 120);
        $pdf->SetLineWidth(0.15);
        $pdf->Line(
            $centerX,
            $y,
            $centerX,
            $y + $cardHeight
        );

        // Place the page number near the top of the centre gap.
        // A small white rectangle keeps the number readable over the line.
        $labelWidth = 5.5;
        $labelHeight = 3.5;
        $labelX = $centerX - ($labelWidth / 2);
        $labelY = $y + 1.2;

        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(
            $labelX,
            $labelY,
            $labelWidth,
            $labelHeight,
            'F'
        );

        $pdf->SetTextColor(45, 45, 45);
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetXY($labelX, $labelY + 0.15);
        $pdf->Cell(
            $labelWidth,
            $labelHeight,
            (string) $pageNumber,
            0,
            0,
            'C'
        );
    }

    /**
     * Import one rendered card PDF into the current A4 page.
     *
     * The CardRenderingService generates:
     * - Page 1 for the front side
     * - Page 2 for the back side
     *
     * Therefore, the correct source page must be selected based
     * on the side being placed onto the A4 sheet.
     */
    private function placeRenderedCard(
        Fpdi $pdf,
        string $cardPdf,
        float $x,
        float $y,
        float $width,
        float $height,
        string $side,
        array &$temporaryFiles
    ): void {
        $temporaryFile = $this->createTemporaryPdf(
            $cardPdf,
            $side
        );

        $temporaryFiles[] = $temporaryFile;

        $pageCount = $pdf->setSourceFile($temporaryFile);

        // Each rendered card side should be a single-page PDF.
        if ($pageCount < 1) {
            throw new \RuntimeException(
                "Unable to read rendered {$side} card PDF."
            );
        }

        // Front and back are each rendered as one complete card page.
        $cardTemplate = $pdf->importPage(
            1,
            \setasign\Fpdi\PdfReader\PageBoundaries::CROP_BOX,
            false
        );

        $pdf->useTemplate(
            $cardTemplate,
            $x,
            $y,
            $width,
            $height
        );
    }
    /**
     * Store a temporary rendered card for FPDI import.
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

        $path = $directory
            . DIRECTORY_SEPARATOR
            . $prefix
            . '_'
            . uniqid('', true)
            . '.pdf';

        File::put(
            $path,
            $pdfContent
        );

        return $path;
    }

    /**
     * Calculate the A4 layout without rendering a PDF.
     */
    public function calculateLayout(
        Template $template,
        int $studentCount,
        array $options = []
    ): array {
        $pageWidth = (float) (
            $options['page_width_mm'] ?? self::DEFAULT_PAGE_WIDTH
        );

        $pageHeight = (float) (
            $options['page_height_mm'] ?? self::DEFAULT_PAGE_HEIGHT
        );

        $orientation = $options['orientation'] ?? 'portrait';

        if ($orientation === 'landscape') {
            [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
        }

        // Keep five 57 mm rows centred vertically on A4 by default.
        $marginTop = (float) (
            $options['margin_top_mm'] ?? 6
        );

        $marginBottom = (float) (
            $options['margin_bottom_mm'] ?? 6
        );

        // The fixed centre gap between the front and back cards.
        $pairGap = (float) (
            $options['pair_gap_mm'] ?? 8.1
        );

        $rowGap = (float) (
            $options['row_gap_mm'] ?? 0
        );

        $cardWidth = (float) $template->width_mm;
        $cardHeight = (float) $template->height_mm;

        $hasFront = (bool) $template->has_front;
        $hasBack = (bool) $template->has_back;

        if (!$hasFront && !$hasBack) {
            throw new \RuntimeException(
                'The template must have at least one enabled card side.'
            );
        }

        $bothSides = $hasFront && $hasBack;

        $pairWidth = $bothSides
            ? ($cardWidth * 2) + $pairGap
            : $cardWidth;

        if ($bothSides) {
            // Equal outer whitespace when the card dimensions are fixed.
            $outerMargin = ($pageWidth - $pairWidth) / 2;

            if ($outerMargin < 0) {
                throw new \RuntimeException(
                    "The fixed card pair ({$pairWidth} mm) does not fit "
                    . "inside the page width ({$pageWidth} mm)."
                );
            }

            $marginLeft = $outerMargin;
            $marginRight = $outerMargin;
        } else {
            $marginLeft = (float) (
                $options['margin_left_mm'] ?? 10
            );

            $marginRight = (float) (
                $options['margin_right_mm'] ?? 10
            );
        }

        $usableHeight = $pageHeight
            - $marginTop
            - $marginBottom;

        $studentsPerPage = (int) floor(
            ($usableHeight + $rowGap)
            / ($cardHeight + $rowGap)
        );

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
                'gap_mm' => $bothSides ? $pairGap : 0,
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

            'page_count' => $studentsPerPage > 0
                ? (int) ceil(
                    $studentCount / $studentsPerPage
                )
                : 0,
        ];
    }

}
