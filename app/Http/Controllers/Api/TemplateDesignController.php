<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

class TemplateDesignController extends Controller
{
    /**
     * Upload template base designs.
     *
     * Supported options:
     *
     * 1. One 2-page PDF:
     *      Page 1 = Front
     *      Page 2 = Back
     *
     * 2. Separate front/back files:
     *      front_design
     *      back_design
     *
     * Uploaded artwork is treated as the locked base design.
     */
    public function upload(
        Request $request,
        Project $project,
        Template $template
    ) {
        /*
         * Make sure the template belongs to this project.
         */
        if ($template->project_id !== $project->id) {
            return response()->json([
                'message' => 'Template does not belong to this project.',
            ], 404);
        }

        /*
         * Validate uploaded files.
         */
        $request->validate([
            'design_pdf' => [
                'nullable',
                'file',
                'mimes:pdf',
                'max:51200', // 50 MB
            ],

            'front_design' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:51200',
            ],

            'back_design' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:51200',
            ],
        ]);

        /*
         * Do not allow combined PDF and separate files
         * in the same request.
         */
        if (
            $request->hasFile('design_pdf') &&
            (
                $request->hasFile('front_design') ||
                $request->hasFile('back_design')
            )
        ) {
            return response()->json([
                'message' =>
                    'Use either design_pdf OR separate front/back designs, not both.',
            ], 422);
        }

        /*
         * At least one design must be uploaded.
         */
        if (
            !$request->hasFile('design_pdf') &&
            !$request->hasFile('front_design') &&
            !$request->hasFile('back_design')
        ) {
            return response()->json([
                'message' =>
                    'Please upload a design PDF, front design, or back design.',
            ], 422);
        }

        /*
         * Storage directory for this template's artwork.
         */
        $designDirectory =
            'ecls/projects/' .
            $project->id .
            '/templates/' .
            $template->id .
            '/designs';

        /*
         * =========================================================
         * OPTION 1
         * One PDF containing both front and back.
         * =========================================================
         */
        if ($request->hasFile('design_pdf')) {

            $pdf = $request->file('design_pdf');

            /*
             * Use FPDI to inspect the PDF.
             *
             * setSourceFile() reads the PDF and returns the
             * number of pages contained in it.
             */
            try {
                $fpdi = new Fpdi();

                $pageCount = $fpdi->setSourceFile(
                    $pdf->getRealPath()
                );
            } catch (\Throwable $e) {

                return response()->json([
                    'message' => 'The uploaded PDF could not be read.',
                    'error' => $e->getMessage(),
                ], 422);
            }

            /*
             * We require exactly two pages:
             *
             * Page 1 = Front
             * Page 2 = Back
             */
            if ($pageCount !== 2) {
                return response()->json([
                    'message' =>
                        'The design PDF must contain exactly 2 pages.',
                    'page_count' => $pageCount,
                    'expected' => 2,
                ], 422);
            }

            /*
             * Remove the previous design files.
             */
            $this->deleteExistingDesigns($template);

            /*
             * Store the original PDF privately.
             */
            $pdfPath = $pdf->storeAs(
                $designDirectory,
                'base_design.pdf',
                'local'
            );

            /*
             * Both database fields point to the same source PDF.
             *
             * The renderer will later use:
             *
             * Page 1 → Front
             * Page 2 → Back
             */
            $template->front_design_path = $pdfPath;
            $template->back_design_path = $pdfPath;

            $template->has_front = true;
            $template->has_back = true;

            $template->save();

            return response()->json([
                'message' =>
                    'Two-page template design uploaded successfully.',

                'template' => [
                    'id' => $template->id,
                    'project_id' => $template->project_id,
                    'name' => $template->name,

                    'width_mm' => $template->width_mm,
                    'height_mm' => $template->height_mm,

                    'has_front' => $template->has_front,
                    'has_back' => $template->has_back,

                    'design_type' => 'two_page_pdf',

                    'front_page' => 1,
                    'back_page' => 2,

                    'front_design_path' =>
                        $template->front_design_path,

                    'back_design_path' =>
                        $template->back_design_path,
                ],
            ]);
        }

        /*
         * =========================================================
         * OPTION 2
         * Separate front/back files.
         * =========================================================
         */

        /*
         * Upload FRONT design.
         */
        if ($request->hasFile('front_design')) {

            $frontFile = $request->file('front_design');

            /*
             * If the front design is a PDF, it must contain
             * exactly one page.
             */
            if (
                strtolower(
                    $frontFile->getClientOriginalExtension()
                ) === 'pdf'
            ) {
                try {
                    $fpdi = new Fpdi();

                    $pageCount = $fpdi->setSourceFile(
                        $frontFile->getRealPath()
                    );
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' =>
                            'The front PDF could not be read.',
                        'error' => $e->getMessage(),
                    ], 422);
                }

                if ($pageCount !== 1) {
                    return response()->json([
                        'message' =>
                            'The separate front PDF must contain exactly 1 page.',
                        'page_count' => $pageCount,
                    ], 422);
                }
            }

            /*
             * Delete the previous front design.
             */
            if ($template->front_design_path) {
                Storage::disk('local')->delete(
                    $template->front_design_path
                );
            }

            /*
             * Store the new front design.
             */
            $frontPath = $frontFile->store(
                $designDirectory,
                'local'
            );

            $template->front_design_path = $frontPath;
            $template->has_front = true;
        }

        /*
         * Upload BACK design.
         */
        if ($request->hasFile('back_design')) {

            $backFile = $request->file('back_design');

            /*
             * If the back design is a PDF, it must contain
             * exactly one page.
             */
            if (
                strtolower(
                    $backFile->getClientOriginalExtension()
                ) === 'pdf'
            ) {
                try {
                    $fpdi = new Fpdi();

                    $pageCount = $fpdi->setSourceFile(
                        $backFile->getRealPath()
                    );
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' =>
                            'The back PDF could not be read.',
                        'error' => $e->getMessage(),
                    ], 422);
                }

                if ($pageCount !== 1) {
                    return response()->json([
                        'message' =>
                            'The separate back PDF must contain exactly 1 page.',
                        'page_count' => $pageCount,
                    ], 422);
                }
            }

            /*
             * Delete the previous back design.
             */
            if ($template->back_design_path) {
                Storage::disk('local')->delete(
                    $template->back_design_path
                );
            }

            /*
             * Store the new back design.
             */
            $backPath = $backFile->store(
                $designDirectory,
                'local'
            );

            $template->back_design_path = $backPath;
            $template->has_back = true;
        }

        /*
         * Save template changes.
         */
        $template->save();

        return response()->json([
            'message' =>
                'Template design uploaded successfully.',

            'template' => [
                'id' => $template->id,
                'project_id' => $template->project_id,
                'name' => $template->name,

                'width_mm' => $template->width_mm,
                'height_mm' => $template->height_mm,

                'has_front' => $template->has_front,
                'has_back' => $template->has_back,

                'design_type' => 'separate_files',

                'front_design_path' =>
                    $template->front_design_path,

                'back_design_path' =>
                    $template->back_design_path,
            ],
        ]);
    }


    /**
     * Delete existing design files.
     *
     * A two-page PDF can be referenced by both front_design_path
     * and back_design_path, so array_unique() prevents attempting
     * to delete the same file twice.
     */
    private function deleteExistingDesigns(Template $template): void
    {
        $paths = array_unique([
            $template->front_design_path,
            $template->back_design_path,
        ]);

        foreach ($paths as $path) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
