<?php

namespace App\Services;

use App\Models\StudentRecord;
use App\Models\Template;
use App\Services\BarcodeImageService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class CardRenderingService
{
    /**
     * Render one student's card.
     *
     * The returned PDF contains the requested side:
     *
     * front
     * OR
     * back
     */
    public function render(
        Template $template,
        StudentRecord $student,
        string $side = 'front'
    ): string {

        /*
        |--------------------------------------------------------------------------
        | Validate side
        |--------------------------------------------------------------------------
        */

        if (!in_array($side, ['front', 'back'], true)) {
            throw new RuntimeException(
                'Invalid card side. Use front or back.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Get physical card dimensions
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Everything is handled in millimetres.
        |
        */

        $width = (float) $template->width_mm;

        $height = (float) $template->height_mm;


        /*
        |--------------------------------------------------------------------------
        | Determine PDF orientation
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | 92 × 57 mm = Landscape
        |
        | 57 × 92 mm = Portrait
        |
        */

        $orientation = $width >= $height
            ? 'L'
            : 'P';


        /*
        |--------------------------------------------------------------------------
        | Create PDF using physical dimensions.
        |--------------------------------------------------------------------------
        */

        $pdf = new Fpdi(
            $orientation,
            'mm',
            [$width, $height]
        );


        /*
        |--------------------------------------------------------------------------
        | Add card page.
        |--------------------------------------------------------------------------
        */

        $pdf->AddPage(
            $orientation,
            [$width, $height]
        );

        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);


        /*
        |--------------------------------------------------------------------------
        | Draw locked base design.
        |--------------------------------------------------------------------------
        */

        $this->drawBaseDesign(
            $pdf,
            $template,
            $side,
            $width,
            $height
        );


        /*
        |--------------------------------------------------------------------------
        | Load elements for this side.
        |--------------------------------------------------------------------------
        */

        $elements = $template
            ->elements()
            ->where('side', $side)
            ->where('visible', true)
            ->orderBy('z_index')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Render every dynamic element.
        |--------------------------------------------------------------------------
        */

        foreach ($elements as $element) {

            switch ($element->type) {

                /*
                |--------------------------------------------------------------------------
                | Profile photo
                |--------------------------------------------------------------------------
                */

                // The editor currently saves this element as "photo", while
                // older templates use "profile_photo". Both mean the same
                // template-controlled photo box.
                case 'photo':
                case 'profile_photo':

                    $this->drawProfilePhoto(
                        $pdf,
                        $student,
                        $element
                    );

                    break;


                /*
                |--------------------------------------------------------------------------
                | Barcode
                |--------------------------------------------------------------------------
                */

                case 'barcode':

                    $this->drawBarcode(
                        $pdf,
                        $student,
                        $element
                    );

                    break;


                /*
                |--------------------------------------------------------------------------
                | Student name
                |--------------------------------------------------------------------------
                */

                case 'student_name':

                /*
                |--------------------------------------------------------------------------
                | NIC
                |--------------------------------------------------------------------------
                |
                | Newer TemplateEditor versions may save NIC as its own element type
                | (`nic`). It is still a normal dynamic text element, so it must use
                | the same renderer as Student Name and other text fields.
                |
                */
                case 'nic':

                    $this->drawTextElement(
                        $pdf,
                        $student,
                        $element
                    );

                    break;

                case 'student_id':

                    $this->drawStudentIdGrid(
                        $pdf,
                        $student,
                        $element
                    );

                    break;


                /*
                |--------------------------------------------------------------------------
                | Generic dynamic text
                |--------------------------------------------------------------------------
                */

                case 'generic_text':
                case 'text':
                case 'manufacture_date':

                    $this->drawTextElement(
                        $pdf,
                        $student,
                        $element
                    );

                    break;


                /*
                |--------------------------------------------------------------------------
                | Static text
                |--------------------------------------------------------------------------
                */

                case 'static_text':

                    $this->drawTextElement(
                        $pdf,
                        $student,
                        $element
                    );

                    break;


                /*
                |--------------------------------------------------------------------------
                | Student ID grid
                |--------------------------------------------------------------------------
                |
                | This will be implemented in the next step.
                |
                */

                case 'student_id_grid':

                    $this->drawStudentIdGrid(
                        $pdf,
                        $student,
                        $element
                    );

                    break;


                /*
                |--------------------------------------------------------------------------
                | Unsupported element
                |--------------------------------------------------------------------------
                */

                default:

                    // Ignore unsupported elements for now.

                    break;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Return PDF as string.
        |--------------------------------------------------------------------------
        */

        return $pdf->Output(
            'S'
        );
    }


    /**
     * Draw the locked base card design.
     */
    private function drawBaseDesign(
        Fpdi $pdf,
        Template $template,
        string $side,
        float $width,
        float $height
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Determine design path.
        |--------------------------------------------------------------------------
        */

        $path = $side === 'front'
            ? $template->front_design_path
            : $template->back_design_path;


        /*
        |--------------------------------------------------------------------------
        | No design uploaded.
        |--------------------------------------------------------------------------
        */

        if (empty($path)) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Make sure the private file exists.
        |--------------------------------------------------------------------------
        */

        $disk = Storage::disk('local');


        if (!$disk->exists($path)) {

            throw new RuntimeException(
                'Base design file was not found: ' . $path
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Get absolute file path.
        |--------------------------------------------------------------------------
        */

        $fullPath = $disk->path($path);


        /*
        |--------------------------------------------------------------------------
        | Determine file extension.
        |--------------------------------------------------------------------------
        */

        $extension = strtolower(
            pathinfo($fullPath, PATHINFO_EXTENSION)
        );


        /*
        |--------------------------------------------------------------------------
        | PDF base design.
        |--------------------------------------------------------------------------
        */

        if ($extension === 'pdf') {

            /*
            |--------------------------------------------------------------------------
            | Import the correct page.
            |--------------------------------------------------------------------------
            |
            | Combined design:
            |
            | Page 1 = Front
            | Page 2 = Back
            |
            */

            $pageNumber = $side === 'front'
                ? 1
                : 2;


            $pageCount = $pdf->setSourceFile(
                $fullPath
            );


            if ($pageNumber > $pageCount) {

                throw new RuntimeException(
                    'Requested design page does not exist.'
                );
            }


            $page = $pdf->importPage(
                $pageNumber
            );


            $pdf->useTemplate(
                $page,
                0,
                0,
                $width,
                $height
            );


            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Raster image design.
        |--------------------------------------------------------------------------
        |
        | Supported:
        |
        | JPG
        | JPEG
        | PNG
        | WEBP
        |
        */

        $pdf->Image(
            $fullPath,
            0,
            0,
            $width,
            $height
        );
    }


    /**
     * Draw a profile photo inside the exact configured box.
     */
    private function drawProfilePhoto(
        Fpdi $pdf,
        StudentRecord $student,
        $element
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Student must have a matched photo.
        |--------------------------------------------------------------------------
        */

        $photo = $student->photo;


        if (!$photo) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Get private photo path.
        |--------------------------------------------------------------------------
        */

        $disk = Storage::disk('local');


        if (!$disk->exists($photo->file_path)) {
            return;
        }


        $sourcePath = $disk->path(
            $photo->file_path
        );


        /*
        |--------------------------------------------------------------------------
        | Exact element dimensions.
        |--------------------------------------------------------------------------
        */

        $x = (float) $element->x_mm;

        $y = (float) $element->y_mm;

        $boxWidth = (float) $element->width_mm;

        $boxHeight = (float) $element->height_mm;


        /*
        |--------------------------------------------------------------------------
        | Get source image information.
        |--------------------------------------------------------------------------
        */

        $imageInfo = getimagesize(
            $sourcePath
        );


        if (!$imageInfo) {
            return;
        }


        $sourceWidth = $imageInfo[0];

        $sourceHeight = $imageInfo[1];


        /*
        |--------------------------------------------------------------------------
        | Create source GD image.
        |--------------------------------------------------------------------------
        */

        $sourceImage = $this->createGdImage(
            $sourcePath,
            $imageInfo['mime']
        );


        if (!$sourceImage) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Calculate aspect ratios.
        |--------------------------------------------------------------------------
        */

        $sourceRatio =
            $sourceWidth / $sourceHeight;


        $boxRatio =
            $boxWidth / $boxHeight;


        /*
        |--------------------------------------------------------------------------
        | Calculate center crop.
        |--------------------------------------------------------------------------
        */

        if ($sourceRatio > $boxRatio) {

            /*
            |--------------------------------------------------------------------------
            | Source is wider.
            |--------------------------------------------------------------------------
            */

            $cropHeight =
                $sourceHeight;

            $cropWidth =
                (int) round(
                    $sourceHeight * $boxRatio
                );

            $srcX =
                (int) round(
                    ($sourceWidth - $cropWidth) / 2
                );

            $srcY = 0;

        } else {

            /*
            |--------------------------------------------------------------------------
            | Source is taller.
            |--------------------------------------------------------------------------
            */

            $cropWidth =
                $sourceWidth;

            $cropHeight =
                (int) round(
                    $sourceWidth / $boxRatio
                );

            $srcX = 0;

            $srcY =
                (int) round(
                    ($sourceHeight - $cropHeight) / 2
                );
        }


        /*
        |--------------------------------------------------------------------------
        | Temporary output dimensions.
        |--------------------------------------------------------------------------
        |
        | 300 DPI equivalent rendering.
        |
        */

        $outputWidth =
            max(
                1,
                (int) round(
                    $boxWidth / 25.4 * 300
                )
            );


        $outputHeight =
            max(
                1,
                (int) round(
                    $boxHeight / 25.4 * 300
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Create destination image.
        |--------------------------------------------------------------------------
        */

        $destination =
            imagecreatetruecolor(
                $outputWidth,
                $outputHeight
            );


        /*
        |--------------------------------------------------------------------------
        | Preserve transparency.
        |--------------------------------------------------------------------------
        */

        imagealphablending(
            $destination,
            false
        );

        imagesavealpha(
            $destination,
            true
        );


        /*
        |--------------------------------------------------------------------------
        | Copy/crop source into exact box.
        |--------------------------------------------------------------------------
        */

        imagecopyresampled(
            $destination,
            $sourceImage,

            0,
            0,

            $srcX,
            $srcY,

            $outputWidth,
            $outputHeight,

            $cropWidth,
            $cropHeight
        );


        /*
        |--------------------------------------------------------------------------
        | Create temporary PNG.
        |--------------------------------------------------------------------------
        */

        $temporaryPath =
            storage_path(
                'app/temp_profile_' .
                uniqid() .
                '.png'
            );


        imagepng(
            $destination,
            $temporaryPath,
            6
        );


        /*
        |--------------------------------------------------------------------------
        | Clean up GD memory.
        |--------------------------------------------------------------------------
        */

        imagedestroy(
            $sourceImage
        );

        imagedestroy(
            $destination
        );


        /*
        |--------------------------------------------------------------------------
        | Place image at exact physical position.
        |--------------------------------------------------------------------------
        */

        $pdf->Image(
            $temporaryPath,
            $x,
            $y,
            $boxWidth,
            $boxHeight
        );


        /*
        |--------------------------------------------------------------------------
        | Delete temporary image.
        |--------------------------------------------------------------------------
        */

        if (file_exists($temporaryPath)) {
            unlink($temporaryPath);
        }
    }


    /**
     * Create GD image from source image.
     */
    private function createGdImage(
        string $path,
        string $mime
    ) {

        return match ($mime) {

            'image/jpeg' =>
                imagecreatefromjpeg($path),

            'image/png' =>
                imagecreatefrompng($path),

            'image/webp' =>
                imagecreatefromwebp($path),

            default =>
                false,
        };
    }


    /**
     * Draw barcode inside the configured barcode element.
     */
    /**
     * Draw barcode inside the configured barcode element.
     *
     * The barcode graphic is cropped to its real black-bar bounds before
     * rendering. This removes the large white margins produced by the
     * barcode image generator without changing the stored source file.
     *
     * Layout intentionally follows the physical barcode layout:
     *
     *   - small top quiet space
     *   - small left/right quiet space
     *   - barcode bars
     *   - visible gap
     *   - human-readable barcode value
     *
     * All measurements are in millimetres.
     */
    private function drawBarcode(
        Fpdi $pdf,
        StudentRecord $student,
        $element
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Resolve barcode value
        |--------------------------------------------------------------------------
        */

        $barcodeValue = $this->resolveElementValue(
            $student,
            $element
        );

        if ($barcodeValue === null || $barcodeValue === '') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Element dimensions / properties
        |--------------------------------------------------------------------------
        */

        $x = (float) $element->x_mm;
        $y = (float) $element->y_mm;
        $width = max(1.0, (float) $element->width_mm);
        $height = max(1.0, (float) $element->height_mm);

        $properties = is_array($element->properties)
            ? $element->properties
            : [];

        $showValue = filter_var(
            $properties['show_value'] ?? true,
            FILTER_VALIDATE_BOOLEAN
        );

        /*
        |--------------------------------------------------------------------------
        | Generate barcode source image
        |--------------------------------------------------------------------------
        */

        $barcodePath = app(BarcodeImageService::class)->generate(
            $student,
            $barcodeValue
        );

        $barcodeFile = Storage::disk('local')->path($barcodePath);

        if (!file_exists($barcodeFile)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Optional background
        |--------------------------------------------------------------------------
        */

        if (($properties['show_background'] ?? false) === true) {

            $backgroundColor = $this->hexToRgb(
                (string) ($properties['background_color'] ?? '#ffffff')
            );

            $pdf->SetFillColor(
                $backgroundColor[0],
                $backgroundColor[1],
                $backgroundColor[2]
            );

            $pdf->Rect(
                $x,
                $y,
                $width,
                $height,
                'F'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive physical barcode layout
        |--------------------------------------------------------------------------
        |
        | The barcode box saved by TemplateEditor is the source of truth.
        |
        | We deliberately use percentages for the spacing so the layout scales
        | correctly when the user makes the barcode element larger or smaller.
        |
        | Reference layout:
        |
        |   small top space
        |   small left/right quiet space
        |   WIDE barcode bars
        |   clear gap
        |   barcode number
        |
        | IMPORTANT:
        |
        | The barcode bars are allowed to scale horizontally and vertically
        | independently. This is intentional here. The previous implementation
        | preserved the source image aspect ratio, which caused the barcode to
        | become narrow whenever the source bar image was relatively tall.
        |
        | Horizontal expansion is what gives the reference card its wide,
        | readable barcode appearance while the bar height remains controlled by
        | the physical barcode-box height.
        |
        */

        $sidePaddingRatio = 0.045; // 4.5% of barcode-box width
        $topPaddingRatio = 0.10;    // 10% of barcode-box height
        $gapRatio = 0.075;          // 7.5% gap before number
        $numberAreaRatio = 0.20;    // 20% reserved for number

        $sidePadding = max(
            0.8,
            min(3.0, $width * $sidePaddingRatio)
        );

        $topPadding = max(
            0.8,
            min(3.0, $height * $topPaddingRatio)
        );

        $barcodeNumberGap = $showValue
            ? max(
                0.7,
                min(2.0, $height * $gapRatio)
            )
            : 0;

        $numberAreaHeight = $showValue
            ? max(
                2.8,
                min(5.0, $height * $numberAreaRatio)
            )
            : 0;

        /*
        | Keep the barcode inside the physical box even for very small elements.
        */

        $barcodeRenderWidth = max(
            1.0,
            $width - ($sidePadding * 2)
        );

        $barcodeRenderHeight = max(
            1.0,
            $height
                - $topPadding
                - $barcodeNumberGap
                - $numberAreaHeight
        );

        /*
        |--------------------------------------------------------------------------
        | Crop source image to BAR ONLY
        |--------------------------------------------------------------------------
        |
        | BarcodeImageService can return:
        |
        |   white margin
        |   barcode bars
        |   human-readable digits
        |
        | We detect the dense bar band and crop only that band. The digits from
        | the source image are discarded because the number is rendered separately
        | below. This prevents the digits from changing the barcode's proportions.
        |
        */

        $barcodeRenderFile = $barcodeFile;
        $temporaryBarcodeFile = null;

        $barcodeImageInfo = getimagesize($barcodeFile);

        if ($barcodeImageInfo) {

            $barcodeSourceImage = $this->createGdImage(
                $barcodeFile,
                $barcodeImageInfo['mime'] ?? ''
            );

            if ($barcodeSourceImage) {

                $sourceWidth = imagesx($barcodeSourceImage);
                $sourceHeight = imagesy($barcodeSourceImage);

                $barTop = $sourceHeight;
                $barBottom = -1;

                /*
                | Find rows containing enough dark pixels to be part of the bars.
                | The human-readable digits below are too sparse to pass this
                | threshold.
                */
                $rowDensityThreshold = max(
                    3,
                    (int) round($sourceWidth * 0.10)
                );

                for ($row = 0; $row < $sourceHeight; $row++) {

                    $darkPixels = 0;

                    for ($column = 0; $column < $sourceWidth; $column++) {

                        $rgb = imagecolorat(
                            $barcodeSourceImage,
                            $column,
                            $row
                        );

                        $red = ($rgb >> 16) & 0xFF;
                        $green = ($rgb >> 8) & 0xFF;
                        $blue = $rgb & 0xFF;

                        if (
                            $red < 245
                            || $green < 245
                            || $blue < 245
                        ) {
                            $darkPixels++;
                        }
                    }

                    if ($darkPixels >= $rowDensityThreshold) {
                        $barTop = min($barTop, $row);
                        $barBottom = max($barBottom, $row);
                    }
                }

                if (
                    $barBottom >= $barTop
                    && $barTop >= 0
                    && $barBottom >= 0
                ) {

                    /*
                    | Find the left/right bar edges using only the detected bar
                    | rows. This removes the generator's large side margins.
                    */
                    $barLeft = $sourceWidth;
                    $barRight = -1;

                    $columnThreshold = max(
                        1,
                        (int) round(
                            ($barBottom - $barTop + 1) * 0.06
                        )
                    );

                    for ($column = 0; $column < $sourceWidth; $column++) {

                        $darkPixels = 0;

                        for ($row = $barTop; $row <= $barBottom; $row++) {

                            $rgb = imagecolorat(
                                $barcodeSourceImage,
                                $column,
                                $row
                            );

                            $red = ($rgb >> 16) & 0xFF;
                            $green = ($rgb >> 8) & 0xFF;
                            $blue = $rgb & 0xFF;

                            if (
                                $red < 245
                                || $green < 245
                                || $blue < 245
                            ) {
                                $darkPixels++;
                            }
                        }

                        if ($darkPixels >= $columnThreshold) {
                            $barLeft = min($barLeft, $column);
                            $barRight = max($barRight, $column);
                        }
                    }

                    if (
                        $barRight >= $barLeft
                        && $barLeft >= 0
                    ) {

                        /*
                        | Keep only a tiny amount of quiet space around the bars.
                        | The main side spacing comes from the physical element
                        | layout above, not from the source PNG.
                        */
                        $horizontalQuietPixels = max(
                            1,
                            (int) round(
                                ($barRight - $barLeft + 1) * 0.01
                            )
                        );

                        $verticalQuietPixels = max(
                            1,
                            (int) round(
                                ($barBottom - $barTop + 1) * 0.01
                            )
                        );

                        $cropX = max(
                            0,
                            $barLeft - $horizontalQuietPixels
                        );

                        $cropY = max(
                            0,
                            $barTop - $verticalQuietPixels
                        );

                        $cropRight = min(
                            $sourceWidth - 1,
                            $barRight + $horizontalQuietPixels
                        );

                        $cropBottom = min(
                            $sourceHeight - 1,
                            $barBottom + $verticalQuietPixels
                        );

                        $cropWidth = $cropRight - $cropX + 1;
                        $cropHeight = $cropBottom - $cropY + 1;

                        if ($cropWidth > 0 && $cropHeight > 0) {

                            $croppedImage = imagecreatetruecolor(
                                $cropWidth,
                                $cropHeight
                            );

                            $white = imagecolorallocate(
                                $croppedImage,
                                255,
                                255,
                                255
                            );

                            imagefill(
                                $croppedImage,
                                0,
                                0,
                                $white
                            );

                            imagecopy(
                                $croppedImage,
                                $barcodeSourceImage,
                                0,
                                0,
                                $cropX,
                                $cropY,
                                $cropWidth,
                                $cropHeight
                            );

                            $temporaryBarcodeFile = storage_path(
                                'app/temp_barcode_' . uniqid() . '.png'
                            );

                            imagepng(
                                $croppedImage,
                                $temporaryBarcodeFile,
                                6
                            );

                            imagedestroy($croppedImage);

                            if (file_exists($temporaryBarcodeFile)) {
                                $barcodeRenderFile = $temporaryBarcodeFile;
                            }
                        }
                    }
                }

                imagedestroy($barcodeSourceImage);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Render WIDE barcode
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Do not use proportional fitting here.
        |
        | The requested physical barcode box controls the final dimensions:
        |
        |   render width  = available box width
        |   render height = available bar height
        |
        | This makes a larger barcode element automatically produce a larger,
        | wider barcode while preserving the designed top/side/gap spacing.
        |
        */

        if (
            file_exists($barcodeRenderFile)
            && $barcodeRenderWidth > 0
            && $barcodeRenderHeight > 0
        ) {

            $pdf->Image(
                $barcodeRenderFile,
                $x + $sidePadding,
                $y + $topPadding,
                $barcodeRenderWidth,
                $barcodeRenderHeight
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Draw human-readable barcode value
        |--------------------------------------------------------------------------
        */

        if ($showValue) {

            $fontSize = max(
                4,
                (float) ($properties['text_size'] ?? 5.5)
            );

            $pdf->SetFont(
                'Arial',
                '',
                $fontSize
            );

            $pdf->SetTextColor(
                0,
                0,
                0
            );

            $textWidth = $pdf->GetStringWidth(
                (string) $barcodeValue
            );

            $textX =
                $x
                + (($width - $textWidth) / 2);

            $textX = max(
                $x,
                min(
                    $x + max(0, $width - $textWidth),
                    $textX
                )
            );

            /*
            | Baseline is calculated from the physical bottom of the barcode box.
            | This keeps the number aligned even when the element height changes.
            */
            $textY =
                $y
                + $height
                - 0.8;

            $pdf->Text(
                $textX,
                $textY,
                (string) $barcodeValue
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Remove temporary crop file
        |--------------------------------------------------------------------------
        */

        if (
            $temporaryBarcodeFile !== null
            && file_exists($temporaryBarcodeFile)
        ) {
            unlink($temporaryBarcodeFile);
        }
    }

    /**
     * Draw dynamic/static text.
     */
    private function drawTextElement(
        Fpdi $pdf,
        StudentRecord $student,
        $element
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Resolve the actual value.
        |--------------------------------------------------------------------------
        */

        $value =
            $this->resolveElementValue(
                $student,
                $element
            );


        if ($value === null || $value === '') {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Position and dimensions.
        |--------------------------------------------------------------------------
        */

        $x =
            (float) $element->x_mm;

        $y =
            (float) $element->y_mm;

        $width =
            (float) $element->width_mm;

        $height =
            (float) $element->height_mm;


        /*
        |--------------------------------------------------------------------------
        | Element properties.
        |--------------------------------------------------------------------------
        */

        $properties =
            is_array($element->properties)
                ? $element->properties
                : [];


        /*
        |--------------------------------------------------------------------------
        | Font settings.
        |--------------------------------------------------------------------------
        */

        $fontFamily =
            $properties['font_family']
                ?? 'Arial';

        /*
        |--------------------------------------------------------------------------
        | Font size
        |--------------------------------------------------------------------------
        |
        | Normal text elements keep the existing CSS-pixel -> PDF-point
        | conversion used by this renderer.
        |
        | Manufacture Date is different because the Template Editor displays
        | its font size in CSS pixels inside the scaled card preview. A direct
        | 0.75 conversion makes the PDF date visibly larger than the date shown
        | in the editor.
        |
        | For Manufacture Date only, use the calibrated editor-preview scale.
        | This keeps a saved font size such as 7 visually consistent between
        | the editor and the generated PDF while leaving every other element
        | unchanged.
        |
        */
        $editorFontSize = (float) (
            $properties['font_size']
                ?? 10
        );

        if ($element->type === 'manufacture_date') {

            /*
            | The editor's 92 mm card is displayed at roughly 620 px wide.
            | Therefore one editor CSS pixel is approximately:
            |
            |     92 / 620 = 0.1484 mm
            |
            | Convert that physical height to PDF points:
            |
            |     0.1484 * 72 / 25.4 ≈ 0.42 pt
            |
            | This is intentionally applied only to Manufacture Date.
            */
            $fontSize = $editorFontSize * 0.42;

        } else {

            $fontSize = $editorFontSize * 0.75;
        }

        $fontWeight =
            $properties['font_weight']
                ?? 'normal';


        /*
        |--------------------------------------------------------------------------
        | FPDF standard font mapping.
        |--------------------------------------------------------------------------
        */

        $fontStyle =
            strtolower($fontWeight) === 'bold'
                ? 'B'
                : '';


        /*
        |--------------------------------------------------------------------------
        | Set font.
        |--------------------------------------------------------------------------
        */

        $pdf->SetFont(
            $fontFamily,
            $fontStyle,
            $fontSize
        );


        /*
        |--------------------------------------------------------------------------
        | Alignment.
        |--------------------------------------------------------------------------
        */

        $alignment =
            $properties['text_alignment']
                ?? 'left';


        $alignment =
            match ($alignment) {

                'center' => 'C',

                'right' => 'R',

                default => 'L',
            };


        /*
        |--------------------------------------------------------------------------
        | Text color
        |--------------------------------------------------------------------------
        |
        | The Template Editor stores the selected text color inside the element
        | properties as a HEX value, for example:
        |
        | #000000
        | #ffffff
        | #1d4ed8
        |
        | Apply that saved color to the PDF so the generated card matches the
        | color selected in the Template Editor.
        |
        */

        $textColor = $this->hexToRgb(
            (string) ($properties['text_color'] ?? '#111827')
        );

        $pdf->SetTextColor(
            $textColor[0],
            $textColor[1],
            $textColor[2]
        );


        /*
        |--------------------------------------------------------------------------
        | Draw text.
        |--------------------------------------------------------------------------
        |
        | Manufacture date is handled separately so it is always rendered as
        | one line using the exact X, Y, width and height saved by the editor.
        | It must never wrap, and the date itself is resolved dynamically by
        | resolveElementValue().
        |
        */

        if ($element->type === 'manufacture_date') {

            /*
            |--------------------------------------------------------------------------
            | Manufacture Date background box
            |--------------------------------------------------------------------------
            |
            | Manufacture Date uses the same saved box settings as other text
            | elements. This keeps the editor and PDF behaviour consistent.
            |
            */

            $showBackground = filter_var(
                $properties['show_background'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            if ($showBackground) {

                $backgroundColor = $this->hexToRgb(
                    (string) ($properties['background_color'] ?? '#ffffff')
                );

                $opacity = $this->normaliseOpacity(
                    $properties['opacity'] ?? 100
                );

                $this->setPdfAlpha(
                    $pdf,
                    $opacity
                );

                $pdf->SetFillColor(
                    $backgroundColor[0],
                    $backgroundColor[1],
                    $backgroundColor[2]
                );

                $pdf->Rect(
                    $x,
                    $y,
                    $width,
                    $height,
                    'F'
                );

                $this->setPdfAlpha(
                    $pdf,
                    1
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Manufacture date - exact template box
            |--------------------------------------------------------------------------
            |
            | The editor stores dimensions in millimetres. Do not calculate a
            | new position or size for the date. Use the saved values directly.
            |
            | Manufacture Date uses the calibrated font conversion above so
            | the saved editor font size has the same visual scale in the PDF.
            |
            */

            $textValue = (string) $value;

            /*
            |--------------------------------------------------------------------------
            | Calculate the text width.
            |--------------------------------------------------------------------------
            |
            | Text() does not wrap, which prevents values such as 2026-09-23
            | from being split into multiple lines.
            |
            */

            $textWidth = $pdf->GetStringWidth(
                $textValue
            );

            /*
            |--------------------------------------------------------------------------
            | Horizontal alignment inside the saved element box.
            |--------------------------------------------------------------------------
            */

            if ($alignment === 'C') {

                $textX =
                    $x +
                    (($width - $textWidth) / 2);

            } elseif ($alignment === 'R') {

                $textX =
                    $x +
                    $width -
                    $textWidth;

            } else {

                $textX = $x;
            }

            /*
            |--------------------------------------------------------------------------
            | Keep the date inside the saved X/width boundaries.
            |--------------------------------------------------------------------------
            */

            $textX = max(
                $x,
                min(
                    $x + max(0, $width - $textWidth),
                    $textX
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Vertically centre the date inside the saved Y/height box.
            |--------------------------------------------------------------------------
            |
            | FPDF Text() uses a baseline rather than the top-left corner.
            | Convert the effective font size from points to millimetres and
            | calculate the baseline so the date sits inside the same box that
            | the user positioned in the editor.
            |
            */

            $fontHeightMm =
                $fontSize * (25.4 / 72);

            $textY =
                $y +
                (($height - $fontHeightMm) / 2) +
                ($fontHeightMm * 0.80);

            /*
            |--------------------------------------------------------------------------
            | Protect very small boxes.
            |--------------------------------------------------------------------------
            */

            $minimumBaseline =
                $y + ($fontHeightMm * 0.80);

            $maximumBaseline =
                $y + $height;

            $textY = max(
                $minimumBaseline,
                min($maximumBaseline, $textY)
            );

            /*
            |--------------------------------------------------------------------------
            | Render current manufacture date as a single line.
            |--------------------------------------------------------------------------
            */

            $opacity = $this->normaliseOpacity(
                $properties['opacity'] ?? 100
            );

            $this->setPdfAlpha(
                $pdf,
                $opacity
            );

            $pdf->Text(
                $textX,
                $textY,
                $textValue
            );

            $this->setPdfAlpha(
                $pdf,
                1
            );

            /*
            |--------------------------------------------------------------------------
            | Reset text color
            |--------------------------------------------------------------------------
            |
            | FPDF keeps the current text color as global PDF state. Reset it
            | after this element so a later element (for example a barcode value)
            | does not accidentally inherit the manufacture-date color.
            |
            */

            $pdf->SetTextColor(0, 0, 0);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Existing rendering for all other text elements
        |--------------------------------------------------------------------------
        |
        | Normal text elements use the exact X, Y, width and height saved by
        | TemplateEditor. This includes Student Name, NIC, other Excel/JSON
        | fields, Generic Text and Static Text.
        |
        | The saved text colour, background colour, background visibility and
        | opacity are applied here so the PDF follows the template settings.
        |
        */

        $showBackground = filter_var(
            $properties['show_background'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $backgroundColor = $this->hexToRgb(
            (string) ($properties['background_color'] ?? '#ffffff')
        );

        $opacity = $this->normaliseOpacity(
            $properties['opacity'] ?? 100
        );

        $this->setPdfAlpha(
            $pdf,
            $opacity
        );

        $pdf->SetTextColor(
            $textColor[0],
            $textColor[1],
            $textColor[2]
        );

        /*
        |--------------------------------------------------------------------------
        | Draw the saved background box when enabled.
        |--------------------------------------------------------------------------
        |
        | show_background = false means the text is rendered directly over the
        | template artwork. This allows NIC/static text to work both with and
        | without a box.
        |
        */

        if ($showBackground) {
            $pdf->SetFillColor(
                $backgroundColor[0],
                $backgroundColor[1],
                $backgroundColor[2]
            );
        }

        $pdf->SetXY(
            $x,
            $y
        );

        $pdf->Cell(
            $width,
            $height,
            (string) $value,
            0,
            0,
            $alignment,
            $showBackground
        );

        /*
        |--------------------------------------------------------------------------
        | Reset transparency and text colour.
        |--------------------------------------------------------------------------
        |
        | FPDF keeps these values as global PDF drawing state. Reset them so the
        | selected colour/opacity of this element cannot affect later elements.
        |
        */

        $this->setPdfAlpha(
            $pdf,
            1
        );

        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Normalise a template opacity value to the 0.0 - 1.0 range used by
     * transparency-capable FPDF implementations.
     *
     * TemplateEditor normally stores opacity as a percentage such as 100 or 50.
     * Older data may contain a decimal value such as 1 or 0.5, so both forms are
     * supported.
     */
    private function normaliseOpacity($opacity): float
    {
        if (!is_numeric($opacity)) {
            return 1.0;
        }

        $opacity = (float) $opacity;

        if ($opacity >= 0 && $opacity <= 1) {
            return $opacity;
        }

        return max(
            0.0,
            min(
                1.0,
                $opacity / 100
            )
        );
    }

    /**
     * Apply PDF transparency when the installed FPDF/FPDI implementation
     * provides SetAlpha(). Some installations do not expose transparency;
     * in that case this safely does nothing instead of breaking generation.
     */
    private function setPdfAlpha(
        Fpdi $pdf,
        float $alpha
    ): void {

        $alpha = max(
            0.0,
            min(
                1.0,
                $alpha
            )
        );

        if (method_exists($pdf, 'SetAlpha')) {
            $pdf->SetAlpha($alpha);
        }
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0]
                . $hex[1] . $hex[1]
                . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [255, 255, 255];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Draw Student ID as individual character boxes.
     *
     * Example:
     *
     * F62948091
     *
     * ┌───┐ ┌───┐ ┌───┐ ...
     * │ F │ │ 6 │ │ 2 │ ...
     * └───┘ └───┘ └───┘ ...
     */
    private function drawStudentIdGrid(
        Fpdi $pdf,
        StudentRecord $student,
        $element
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Get Student ID
        |--------------------------------------------------------------------------
        */

        $studentId = $this->resolveElementValue(
            $student,
            $element
        );


        // Nothing to render.
        if ($studentId === null || $studentId === '') {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Get element position and size.
        |--------------------------------------------------------------------------
        */

        $x = (float) $element->x_mm;

        $y = (float) $element->y_mm;

        $width = (float) $element->width_mm;

        $height = (float) $element->height_mm;


        /*
        |--------------------------------------------------------------------------
        | Get configuration from properties.
        |--------------------------------------------------------------------------
        */

        $properties = is_array($element->properties)
            ? $element->properties
            : [];


        /*
        |--------------------------------------------------------------------------
        | Character count
        |--------------------------------------------------------------------------
        */

        $characterCount = (int) (
            $properties['character_count']
            ?? mb_strlen((string) $studentId)
        );

        if ($characterCount <= 0) {
            $characterCount = mb_strlen((string) $studentId);
        }


        /*
        |--------------------------------------------------------------------------
        | Limit the ID to the configured character count.
        |--------------------------------------------------------------------------
        */

        $characters = preg_split(
            '//u',
            (string) $studentId,
            -1,
            PREG_SPLIT_NO_EMPTY
        );


        /*
        |--------------------------------------------------------------------------
        | If the ID contains more characters than configured,
        | only render the configured number.
        |--------------------------------------------------------------------------
        */

        $characters = array_slice(
            $characters,
            0,
            $characterCount
        );


        /*
        |--------------------------------------------------------------------------
        | If fewer characters exist, don't invent characters.
        |--------------------------------------------------------------------------
        */

        $actualCharacterCount = count($characters);


        if ($actualCharacterCount === 0) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Box spacing
        |--------------------------------------------------------------------------
        */

        $spacing = (float) (
            $properties['box_spacing']
            ?? 0
        );


        /*
        |--------------------------------------------------------------------------
        | Border width
        |--------------------------------------------------------------------------
        */

        $borderWidth = (float) (
            $properties['border_width']
            ?? 0.5
        );



        /*
        |--------------------------------------------------------------------------
        | Font size
        |--------------------------------------------------------------------------
        */

        $fontSize = (float) (
            $properties['font_size']
            ?? 10
        );


        /*
        |--------------------------------------------------------------------------
        | Font weight
        |--------------------------------------------------------------------------
        */

        $fontWeight =
            $properties['font_weight']
            ?? 'normal';


        $fontStyle =
            strtolower($fontWeight) === 'bold'
                ? 'B'
                : '';


        /*
        |--------------------------------------------------------------------------
        | Horizontal alignment
        |--------------------------------------------------------------------------
        */

        $textAlignment =
            $properties['text_alignment']
            ?? 'center';


        /*
        |--------------------------------------------------------------------------
        | Vertical alignment
        |--------------------------------------------------------------------------
        */

        $verticalAlignment =
            $properties['vertical_alignment']
            ?? 'middle';

        $borderColor = $this->hexToRgb(
            (string) ($properties['border_color']
                ?? $properties['text_color']
                ?? '#111827')
        );
        $textColor = $this->hexToRgb(
            (string) ($properties['text_color'] ?? '#111827')
        );
        $backgroundColor = $this->hexToRgb(
            (string) ($properties['background_color'] ?? '#ffffff')
        );


        /*
        |--------------------------------------------------------------------------
        | Calculate box width.
        |--------------------------------------------------------------------------
        |
        | All boxes must fit inside the configured element width.
        |
        | Example:
        |
        | Element width = 55 mm
        | Characters    = 9
        | Spacing       = 1.2 mm
        |
        */

        $totalSpacing =
            $spacing * ($actualCharacterCount - 1);


        $boxWidth =
            (
                $width - $totalSpacing
            )
            /
            $actualCharacterCount;


        /*
        |--------------------------------------------------------------------------
        | Prevent invalid dimensions.
        |--------------------------------------------------------------------------
        */

        if ($boxWidth <= 0) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Box height.
        |--------------------------------------------------------------------------
        |
        | The boxes use the full configured element height.
        |
        */

        $boxHeight = $height;


        /*
        |--------------------------------------------------------------------------
        | Configure font.
        |--------------------------------------------------------------------------
        */

        $pdf->SetFont(
            'Arial',
            $fontStyle,
            $fontSize
        );
        $pdf->SetDrawColor(
            $borderColor[0],
            $borderColor[1],
            $borderColor[2]
        );
        $pdf->SetTextColor(
            $textColor[0],
            $textColor[1],
            $textColor[2]
        );


        /*
        |--------------------------------------------------------------------------
        | Draw each character box.
        |--------------------------------------------------------------------------
        */

        foreach ($characters as $index => $character) {

            /*
            |--------------------------------------------------------------------------
            | Calculate X position.
            |--------------------------------------------------------------------------
            */

            $boxX =
                $x +
                (
                    $index *
                    ($boxWidth + $spacing)
                );


            /*
            |--------------------------------------------------------------------------
            | Draw border.
            |--------------------------------------------------------------------------
            */

            $pdf->SetLineWidth(
                $borderWidth
            );


            /*
            |--------------------------------------------------------------------------
            | Draw a simple rectangular box.
            |--------------------------------------------------------------------------
            |
            | Student ID boxes are intentionally rectangular.
            | This is compatible with the installed FPDF/FPDI version.
            */

            $pdf->SetFillColor(
                $backgroundColor[0],
                $backgroundColor[1],
                $backgroundColor[2]
            );
            $pdf->Rect(
                $boxX,
                $y,
                $boxWidth,
                $boxHeight,
                ($properties['show_background'] ?? true) ? 'DF' : 'D'
            );


            /*
            |--------------------------------------------------------------------------
            | Calculate text width.
            |--------------------------------------------------------------------------
            */

            $textWidth =
                $pdf->GetStringWidth(
                    $character
                );


            /*
            |--------------------------------------------------------------------------
            | Horizontal text position.
            |--------------------------------------------------------------------------
            */

            if ($textAlignment === 'left') {

                $textX =
                    $boxX + 1;

            } elseif ($textAlignment === 'right') {

                $textX =
                    $boxX +
                    $boxWidth -
                    $textWidth -
                    1;

            } else {

                /*
                |--------------------------------------------------------------------------
                | Center
                |--------------------------------------------------------------------------
                */

                $textX =
                    $boxX +
                    (
                        ($boxWidth - $textWidth)
                        / 2
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Vertical text position.
            |--------------------------------------------------------------------------
            |
            | FPDF uses the baseline when using Text().
            |
            */

            $textHeight =
                $fontSize * 0.3528;


            if ($verticalAlignment === 'top') {

                $textY =
                    $y +
                    $textHeight;

            } elseif ($verticalAlignment === 'bottom') {

                $textY =
                    $y +
                    $boxHeight -
                    1;

            } else {

                /*
                |--------------------------------------------------------------------------
                | Middle
                |--------------------------------------------------------------------------
                */

                $textY =
                    $y +
                    (
                        ($boxHeight - $textHeight)
                        / 2
                    ) +
                    $textHeight;
            }


            /*
            |--------------------------------------------------------------------------
            | Draw character.
            |--------------------------------------------------------------------------
            */

            $pdf->Text(
                $textX,
                $textY,
                $character
            );
        }
    }


/**
 * Resolve a dynamic element value from the student.
 *
 * Manufacture date is a template-controlled value.
 * It does NOT come from Excel. When the template contains
 * a manufacture_date element, today's date is generated
 * automatically for every generated card.
 */
    /**
     * Normalise a data field key for case/spacing/hyphen-insensitive matching.
     *
     * Examples:
     *     NIC          -> nic
     *     Student ID   -> student_id
     *     student-id   -> student_id
     */
    private function normaliseDataKey(string $key): string
    {
        $key = strtolower(trim($key));

        $key = preg_replace(
            '/[\s\-]+/',
            '_',
            $key
        );

        return trim((string) $key, '_');
    }


    /**
     * Find a value inside the student's JSON data using a normalised key.
     * The stored JSON data itself is not changed.
     */
    private function findDataValue(
        array $data,
        string $requestedKey
    )
    {
        $normalisedRequestedKey = $this->normaliseDataKey(
            $requestedKey
        );

        foreach ($data as $key => $value) {
            if (
                $this->normaliseDataKey((string) $key)
                === $normalisedRequestedKey
            ) {
                return $value;
            }
        }

        return null;
    }


    private function resolveElementValue(
        StudentRecord $student,
        $element
    ): ?string {

        /*
        |--------------------------------------------------------------------------
        | Manufacture Date
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Manufacture date is NOT an Excel field.
        |
        | It is added to the template editor and saved as an element
        | with type "manufacture_date".
        |
        | Therefore, always resolve it directly from the current date.
        |
        */

        if ($element->type === 'manufacture_date') {
            return now()->format('d/m/Y');
        }


        /*
        |--------------------------------------------------------------------------
        | Static text
        |--------------------------------------------------------------------------
        */

        if ($element->type === 'static_text') {

            $properties =
                is_array($element->properties)
                    ? $element->properties
                    : [];

            $staticText = $element->text
                ?? ($properties['text'] ?? null);

            return $staticText !== null
                ? (string) $staticText
                : null;
        }


        /*
        |--------------------------------------------------------------------------
        | Field name
        |--------------------------------------------------------------------------
        */

        $field = $element->field_name;


        /*
        |--------------------------------------------------------------------------
        | If no field is directly assigned,
        | try the data mapping.
        |--------------------------------------------------------------------------
        */

        if (empty($field) && $element->dataMapping) {
            $field =
                $element
                    ->dataMapping
                    ->source_field;
        }


        /*
        |--------------------------------------------------------------------------
        | Older saved editor elements
        |--------------------------------------------------------------------------
        */

        if (empty($field)) {

            $field = $element->exists && $element->side
                ? match ($element->type) {

                    'barcode' => 'barcode',

                    'student_id',
                    'student_id_grid' => 'student_id',

                    'student_name' => 'student_name',

                    // Support templates where NIC was saved as a dedicated
                    // element type but no explicit field_name was stored.
                    'nic' => 'nic',

                    default => null,
                }
                : null;
        }


        /*
        |--------------------------------------------------------------------------
        | No field means no value
        |--------------------------------------------------------------------------
        */

        if (empty($field)) {
            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Barcode
        |--------------------------------------------------------------------------
        */

        if ($field === 'barcode') {
            return (string) $student->barcode;
        }


        /*
        |--------------------------------------------------------------------------
        | Student ID
        |--------------------------------------------------------------------------
        */

        if ($field === 'student_id') {

            $studentId = $student->student_id;

            if ($studentId === null || $studentId === '') {

                $data = is_array($student->data)
                    ? $student->data
                    : [];

                $studentId =
                    $data['student_id']
                    ?? $data['Student ID']
                    ?? $data['studentId']
                    ?? null;
            }

            if ($studentId === null || $studentId === '') {
                return null;
            }

            return (string) $studentId;
        }


        /*
        |--------------------------------------------------------------------------
        | Student Name
        |--------------------------------------------------------------------------
        */

        if ($field === 'student_name') {

            /*
            |--------------------------------------------------------------------------
            | Student Name
            |--------------------------------------------------------------------------
            |
            | The importer now builds student_name from:
            |
            |     first_name + last_name
            |
            | Therefore existing imported records normally already contain the
            | complete name in the dedicated student_name column.
            |
            | The fallback below also supports older records where the dedicated
            | student_name column may be empty but first_name / last_name are
            | still available inside the JSON data column.
            |
            */

            $studentName = trim(
                (string) ($student->student_name ?? '')
            );

            if ($studentName !== '') {
                return $studentName;
            }

            $data = is_array($student->data)
                ? $student->data
                : [];

            $firstName = trim(
                (string) (
                    $data['first_name']
                    ?? $data['firstname']
                    ?? $data['First Name']
                    ?? ''
                )
            );

            $lastName = trim(
                (string) (
                    $data['last_name']
                    ?? $data['lastname']
                    ?? $data['Last Name']
                    ?? ''
                )
            );

            $combinedName = trim(
                $firstName . ' ' . $lastName
            );

            return $combinedName !== ''
                ? $combinedName
                : null;
        }


        /*
        |--------------------------------------------------------------------------
        | Manufacture Date
        |--------------------------------------------------------------------------
        |
        | This is kept as a second safety check in case an older template
        | has field_name = manufacture_date but its element type is different.
        |
        */

        if ($field === 'manufacture_date') {
            return now()->format('d/m/Y');
        }


        /*
        |--------------------------------------------------------------------------
        | Extra Excel fields
        |--------------------------------------------------------------------------
        */

        $data =
            is_array($student->data)
                ? $student->data
                : [];

        $extraValue = $this->findDataValue(
            $data,
            $field
        );

        if ($extraValue === null) {
            return null;
        }

        if (is_scalar($extraValue)) {
            return (string) $extraValue;
        }

        // Do not render arrays/objects as the literal words "Array" or "Object".
        return null;


        /*
        |--------------------------------------------------------------------------
        | Unknown field
        |--------------------------------------------------------------------------
        */

        return null;
    }
}
