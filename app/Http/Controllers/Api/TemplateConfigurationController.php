<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Template;

class TemplateConfigurationController extends Controller
{
    /**
     * Return the complete configuration of a template.
     *
     * This endpoint is designed for the React visual editor.
     *
     * It returns:
     * - Template information
     * - Card dimensions
     * - Front/back design information
     * - All template elements
     * - Data mappings for each element
     */
    public function show(Project $project, Template $template)
    {
        /*
         * Make sure the selected template belongs to
         * the selected project.
         *
         * Example:
         * /projects/2/templates/1
         *
         * Template 1 must belong to Project 2.
         */
        if ($template->project_id !== $project->id) {
            return response()->json([
                'message' => 'Template does not belong to this project.',
            ], 404);
        }

        /*
         * Load all elements and their data mappings
         * in a small number of database queries.
         */
        $template->load([
            'elements.dataMapping',
        ]);

        /*
         * Prepare the template elements for the API response.
         *
         * We explicitly select the fields that the frontend
         * needs instead of returning unnecessary database data.
         */
        $elements = $template->elements
            ->map(function ($element) {
                return [
                    'id' => $element->id,

                    'side' => $element->side,

                    'type' => $element->type,

                    'name' => $element->name,

                    'field_name' => $element->field_name,

                    /*
                     * Physical position and dimensions.
                     *
                     * These values are stored in millimetres.
                     */
                    'position' => [
                        'x_mm' => (float) $element->x_mm,
                        'y_mm' => (float) $element->y_mm,
                    ],

                    'size' => [
                        'width_mm' => (float) $element->width_mm,
                        'height_mm' => (float) $element->height_mm,
                    ],

                    'visible' => (bool) $element->visible,

                    /*
                     * Element-specific configuration.
                     *
                     * Examples:
                     * - Student ID grid settings
                     * - Font settings
                     * - Barcode settings
                     */
                    'properties' => $element->properties ?? [],

                    'z_index' => (int) $element->z_index,

                    /*
                     * Data mapping tells the frontend/backend
                     * which student data field supplies this element.
                     */
                    'mapping' => $element->dataMapping
                        ? [
                            'id' => $element->dataMapping->id,
                            'source_field' =>
                                $element->dataMapping->source_field,
                            'is_required' =>
                                (bool) $element->dataMapping->is_required,
                        ]
                        : null,
                ];
            })
            ->values();

        /*
         * Return the complete template configuration.
         */
        return response()->json([
            'message' => 'Template configuration retrieved successfully.',

            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
            ],

            'template' => [
                'id' => $template->id,
                'name' => $template->name,

                /*
                 * Physical card dimensions in millimetres.
                 *
                 * Default:
                 * 92mm × 57mm
                 */
                'width_mm' => (float) $template->width_mm,
                'height_mm' => (float) $template->height_mm,

                'has_front' => (bool) $template->has_front,
                'has_back' => (bool) $template->has_back,

                'version' => (int) $template->version,

                /*
                 * Design information.
                 *
                 * The actual files remain private.
                 * We will create secure design preview/download
                 * endpoints separately.
                 */
                'design' => [
                    'front' => [
                        'available' =>
                            (bool) $template->front_design_path,
                        'path' =>
                            $template->front_design_path,
                        'page' => $this->getDesignPage(
                            $template,
                            'front'
                        ),
                    ],

                    'back' => [
                        'available' =>
                            (bool) $template->back_design_path,
                        'path' =>
                            $template->back_design_path,
                        'page' => $this->getDesignPage(
                            $template,
                            'back'
                        ),
                    ],
                ],
            ],

            'elements' => $elements,
        ]);
    }


    /**
     * Determine which PDF page represents each side.
     *
     * For our two-page PDF:
     *
     * Page 1 = Front
     * Page 2 = Back
     *
     * For now, image/separate designs do not require
     * a PDF page number.
     */
    private function getDesignPage(
        Template $template,
        string $side
    ): ?int {
        /*
         * A combined two-page PDF stores the same path
         * in both front_design_path and back_design_path.
         */
        if (
            $template->front_design_path &&
            $template->front_design_path ===
            $template->back_design_path
        ) {
            return $side === 'front' ? 1 : 2;
        }

        return null;
    }
}
