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
    private function drawBarcode(
        Fpdi $pdf,
        StudentRecord $student,
        $element
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Get barcode from student record.
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | The barcode is treated as TEXT.
        |
        | Therefore:
        |
        | 00000001
        |
        | remains:
        |
        | 00000001
        |
        | and is NOT converted to:
        |
        | 1
        |
        */

        // A barcode is rendered only when the template explicitly maps it
        // to a student field. Never infer it from the record automatically.
        $barcodeValue = $this->resolveElementValue($student, $element);


        /*
        |--------------------------------------------------------------------------
        | Don't render an empty barcode.
        |--------------------------------------------------------------------------
        */

        if ($barcodeValue === null || $barcodeValue === '') {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Get configured element dimensions.
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
        | Read optional barcode properties.
        |--------------------------------------------------------------------------
        */

        $properties =
            is_array($element->properties)
                ? $element->properties
                : [];


        /*
        |--------------------------------------------------------------------------
        | Human-readable value setting.
        |--------------------------------------------------------------------------
        */

        $showValue =
            $properties['show_value']
                ?? true;


        // Reuse the same private barcode graphic generated by the barcode
        // endpoint. Rendering also creates it on demand for older records.
        $barcodePath = app(BarcodeImageService::class)->generate(
            $student,
            $barcodeValue
        );
        $barcodeFile = Storage::disk('local')->path($barcodePath);

        $valueHeight =
            $showValue
                ? min(6, $height * 0.25)
                : 0;

        $barcodeHeight =
            $height - $valueHeight;

        // Preserve the editor's barcode-box background setting. A white box
        // keeps the bars scannable even when the artwork underneath is dark.
        if (($properties['show_background'] ?? false) === true) {
            $backgroundColor = $this->hexToRgb(
                (string) ($properties['background_color'] ?? '#ffffff')
            );
            $pdf->SetFillColor(
                $backgroundColor[0],
                $backgroundColor[1],
                $backgroundColor[2]
            );
            $pdf->Rect($x, $y, $width, $height, 'F');
        }


        /*
        |--------------------------------------------------------------------------
        | Render barcode.
        |--------------------------------------------------------------------------
        |
        | The barcode is scaled into the configured
        | element box.
        |
        */

        if ($barcodeHeight > 0) {
            $imageInfo = getimagesize($barcodeFile);
            $imageWidth = (float) ($imageInfo[0] ?? 1);
            $imageHeight = (float) ($imageInfo[1] ?? 1);
            $scale = min($width / $imageWidth, $barcodeHeight / $imageHeight);
            $renderWidth = $imageWidth * $scale;
            $renderHeight = $imageHeight * $scale;
            $renderX = $x + (($width - $renderWidth) / 2);

            $pdf->Image(
                $barcodeFile,
                $renderX,
                $y,
                $renderWidth,
                $renderHeight
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Draw human-readable barcode value.
        |--------------------------------------------------------------------------
        */

        if ($showValue) {

            $fontSize =
                (float) ($properties['text_size'] ?? 4);


            $pdf->SetFont(
                'Arial',
                '',
                $fontSize
            );


            /*
            |--------------------------------------------------------------------------
            | Center the value under the barcode.
            |--------------------------------------------------------------------------
            */

            $textWidth =
                $pdf->GetStringWidth(
                    $barcodeValue
                );


            $textX =
                $x +
                (($width - $textWidth) / 2);


            /*
            |--------------------------------------------------------------------------
            | Make sure text doesn't start outside the box.
            |--------------------------------------------------------------------------
            */

            $textX =
                max(
                    $x,
                    $textX
                );


            $textY =
                $y +
                $barcodeHeight +
                1;


            $pdf->Text(
                $textX,
                $textY,
                $barcodeValue
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Delete temporary barcode file.
        |--------------------------------------------------------------------------
        */

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

            $pdf->Text(
                $textX,
                $textY,
                $textValue
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Existing rendering for all other text elements
        |--------------------------------------------------------------------------
        |
        | Keep the existing behaviour unchanged for student names, generic
        | text and static text.
        |
        */

        $pdf->SetXY(
            $x,
            $y
        );

        $pdf->Cell(
            $width,
            $height,
            (string) $value,
            0,
            $alignment
        );
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

            return isset($properties['text'])
                ? (string) $properties['text']
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
            return (string) $student->student_name;
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

        if (array_key_exists($field, $data)) {
            return (string) $data[$field];
        }


        /*
        |--------------------------------------------------------------------------
        | Unknown field
        |--------------------------------------------------------------------------
        */

        return null;
    }
}
