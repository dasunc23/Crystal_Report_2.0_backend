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

        $marginTop = (float) (
            $options['margin_top_mm'] ?? 5
        );

        $marginRight = (float) (
            $options['margin_right_mm'] ?? 5
        );

        $marginBottom = (float) (
            $options['margin_bottom_mm'] ?? 5
        );

        $marginLeft = (float) (
            $options['margin_left_mm'] ?? 5
        );

        // Keep a physical 1 cm gutter between parallel front/back cards.
        $pairGap = (float) (
            $options['pair_gap_mm'] ?? 10
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

        $usableWidth = $pageWidth - $marginLeft - $marginRight;
        $usableHeight = $pageHeight - $marginTop - $marginBottom;

        if ($requiredWidth > $usableWidth) {
            throw new \RuntimeException(
                "The enabled card layout ({$requiredWidth} mm) does not fit "
                . "inside the available A4 width ({$usableWidth} mm)."
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

        $marginTop = (float) (
            $options['margin_top_mm'] ?? 5
        );

        $marginRight = (float) (
            $options['margin_right_mm'] ?? 5
        );

        $marginBottom = (float) (
            $options['margin_bottom_mm'] ?? 5
        );

        $marginLeft = (float) (
            $options['margin_left_mm'] ?? 5
        );

        $pairGap = (float) (
            $options['pair_gap_mm'] ?? 10
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
