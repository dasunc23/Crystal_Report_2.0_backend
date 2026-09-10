<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TemplateConfigurationController extends Controller
{
    /**
     * GET
     * /api/projects/{project}/templates/{template}/configuration
     *
     * Returns the complete configuration of a template.
     *
     */
    public function show(Project $project, Template $template)
    {
        // Make sure the template actually belongs to this project.
        if ($template->project_id !== $project->id) {
            return response()->json([
                'message' => 'Template does not belong to this project.',
            ], 404);
        }

        // Load template elements together with their mappings.
        $template->load([
            'elements.dataMapping',
        ]);

        // Convert every element into a clean API structure.
        $elements = $template->elements->map(function ($element) {

            return [
                'id' => $element->id,

                'side' => $element->side,

                'type' => $element->type,

                'name' => $element->name,

                'field_name' => $element->field_name,

                'position' => [
                    'x_mm' => (float) $element->x_mm,
                    'y_mm' => (float) $element->y_mm,
                ],

                'size' => [
                    'width_mm' => (float) $element->width_mm,
                    'height_mm' => (float) $element->height_mm,
                ],

                'visible' => (bool) $element->visible,

                'properties' => $element->properties ?? [],

                'z_index' => (int) $element->z_index,

                'mapping' => $element->dataMapping ? [
                    'id' => $element->dataMapping->id,
                    'source_field' => $element->dataMapping->source_field,
                    'is_required' => (bool) $element->dataMapping->is_required,
                ] : null,
            ];
        });

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
            ],

            'template' => [
                'id' => $template->id,
                'name' => $template->name,

                'width_mm' => (float) $template->width_mm,
                'height_mm' => (float) $template->height_mm,

                'has_front' => (bool) $template->has_front,
                'has_back' => (bool) $template->has_back,

                'version' => (int) $template->version,
            ],

            'design' => [
                'front' => [
                    'available' => !empty($template->front_design_path),
                    'path' => $template->front_design_path,
                    'page' => $this->getDesignPage(
                        $template,
                        'front'
                    ),
                ],

                'back' => [
                    'available' => !empty($template->back_design_path),
                    'path' => $template->back_design_path,
                    'page' => $this->getDesignPage(
                        $template,
                        'back'
                    ),
                ],
            ],

            'elements' => $elements,
        ]);
    }


    /**
     * PUT
     * /api/projects/{project}/templates/{template}/configuration
     *
     * Saves the complete visual editor configuration.
     *
     * React/Fabric.js will eventually send data to this endpoint.
     */
    public function update(
        Request $request,
        Project $project,
        Template $template
    ) {
        /*
        |--------------------------------------------------------------------------
        | 1. SECURITY CHECK
        |--------------------------------------------------------------------------
        |
        | Never allow a template from another project to be modified.
        |
        */

        if ($template->project_id !== $project->id) {
            return response()->json([
                'message' => 'Template does not belong to this project.',
            ], 404);
        }


        /*
        |--------------------------------------------------------------------------
        | 2. VALIDATE REQUEST
        |--------------------------------------------------------------------------
        |
        | Everything coming from React must be validated.
        |
        */

        $validated = $request->validate([

            // Template dimensions are optional.
            'width_mm' => [
                'sometimes',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'height_mm' => [
                'sometimes',
                'numeric',
                'gt:0',
                'max:1000',
            ],


            /*
            |--------------------------------------------------------------------------
            | Elements
            |--------------------------------------------------------------------------
            */

            'elements' => [
                'sometimes',
                'array',
            ],

            'elements.*.id' => [
                'nullable',
                'integer',
            ],

            'elements.*.side' => [
                'required',
                'string',
                'in:front,back',
            ],

            'elements.*.type' => [
                'required',
                'string',
                'max:100',
            ],

            'elements.*.name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'elements.*.field_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'elements.*.x_mm' => [
                'required',
                'numeric',
                'min:0',
                'max:1000',
            ],

            'elements.*.y_mm' => [
                'required',
                'numeric',
                'min:0',
                'max:1000',
            ],

            'elements.*.width_mm' => [
                'required',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'elements.*.height_mm' => [
                'required',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'elements.*.visible' => [
                'sometimes',
                'boolean',
            ],

            'elements.*.properties' => [
                'nullable',
                'array',
            ],

            'elements.*.z_index' => [
                'sometimes',
                'integer',
                'min:0',
            ],


            /*
            |--------------------------------------------------------------------------
            | Data mapping
            |--------------------------------------------------------------------------
            */

            'elements.*.mapping' => [
                'nullable',
                'array',
            ],

            'elements.*.mapping.source_field' => [
                'nullable',
                'string',
                'max:255',
            ],

            'elements.*.mapping.is_required' => [
                'sometimes',
                'boolean',
            ],


            /*
            |--------------------------------------------------------------------------
            | Elements to delete
            |--------------------------------------------------------------------------
            */

            'delete_element_ids' => [
                'sometimes',
                'array',
            ],

            'delete_element_ids.*' => [
                'integer',
            ],
        ]);


        /*
        |--------------------------------------------------------------------------
        | 3. START DATABASE TRANSACTION
        |--------------------------------------------------------------------------
        |
        | If something fails halfway through the save, everything is rolled back.
        |
        */

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 4. UPDATE TEMPLATE DIMENSIONS
            |--------------------------------------------------------------------------
            */

            if (
                array_key_exists('width_mm', $validated)
                ||
                array_key_exists('height_mm', $validated)
            ) {

                if (array_key_exists('width_mm', $validated)) {
                    $template->width_mm = $validated['width_mm'];
                }

                if (array_key_exists('height_mm', $validated)) {
                    $template->height_mm = $validated['height_mm'];
                }

                $template->save();
            }


            /*
            |--------------------------------------------------------------------------
            | 5. DELETE REQUESTED ELEMENTS
            |--------------------------------------------------------------------------
            |
            | Important:
            | We only delete elements belonging to this template.
            |
            */

            $deletedElementIds = [];

            if (!empty($validated['delete_element_ids'])) {

                $elementsToDelete = $template
                    ->elements()
                    ->whereIn(
                        'id',
                        $validated['delete_element_ids']
                    )
                    ->get();

                foreach ($elementsToDelete as $element) {

                    $deletedElementIds[] = $element->id;

                    /*
                    |--------------------------------------------------------------------------
                    | Delete mapping first.
                    |--------------------------------------------------------------------------
                    |
                    | The mapping belongs to the element.
                    |
                    */

                    $element->dataMapping()->delete();

                    // Delete the element itself.
                    $element->delete();
                }
            }


            /*
            |--------------------------------------------------------------------------
            | 6. SAVE / UPDATE ELEMENTS
            |--------------------------------------------------------------------------
            */

            $savedElementIds = [];

            foreach ($validated['elements'] ?? [] as $elementData) {

                /*
                |--------------------------------------------------------------------------
                | Existing element
                |--------------------------------------------------------------------------
                |
                | If React sends an ID, find that element ONLY inside this template.
                |
                */

                if (!empty($elementData['id'])) {

                    $element = $template
                        ->elements()
                        ->where('id', $elementData['id'])
                        ->first();

                    // Prevent modifying an element from another template.
                    if (!$element) {
                        throw new \RuntimeException(
                            'Element ' . $elementData['id'] .
                            ' does not belong to this template.'
                        );
                    }

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | New element
                    |--------------------------------------------------------------------------
                    */

                    $element = $template->elements()->make();
                }


                /*
                |--------------------------------------------------------------------------
                | 7. SET ELEMENT DATA
                |--------------------------------------------------------------------------
                */

                $element->side = $elementData['side'];

                $element->type = $elementData['type'];

                $element->name =
                    $elementData['name'] ?? null;

                $element->field_name =
                    $elementData['field_name'] ?? null;

                $element->x_mm =
                    $elementData['x_mm'];

                $element->y_mm =
                    $elementData['y_mm'];

                $element->width_mm =
                    $elementData['width_mm'];

                $element->height_mm =
                    $elementData['height_mm'];

                $element->visible =
                    $elementData['visible'] ?? true;

                $element->properties =
                    $elementData['properties'] ?? [];

                $element->z_index =
                    $elementData['z_index'] ?? 0;


                // Save the element.
                $element->save();


                /*
                |--------------------------------------------------------------------------
                | 8. SAVE DATA MAPPING
                |--------------------------------------------------------------------------
                */

                if (
                    array_key_exists(
                        'mapping',
                        $elementData
                    )
                ) {

                    $mapping =
                        $elementData['mapping'];


                    /*
                    |--------------------------------------------------------------------------
                    | If mapping exists and source_field is provided
                    |--------------------------------------------------------------------------
                    */

                    if (
                        is_array($mapping)
                        &&
                        !empty($mapping['source_field'])
                    ) {

                        $element->dataMapping()->updateOrCreate(
                            [
                                'template_element_id' =>
                                    $element->id,
                            ],
                            [
                                'source_field' =>
                                    $mapping['source_field'],

                                'is_required' =>
                                    $mapping['is_required'] ?? false,
                            ]
                        );

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | No valid mapping
                        |--------------------------------------------------------------------------
                        |
                        | Remove an old mapping if the user cleared it.
                        |
                        */

                        $element->dataMapping()->delete();
                    }
                }


                // Remember the saved element ID.
                $savedElementIds[] = $element->id;
            }


            /*
            |--------------------------------------------------------------------------
            | 9. INCREMENT TEMPLATE VERSION
            |--------------------------------------------------------------------------
            |
            | Every successful configuration save creates a new version.
            |
            */

            $template->increment('version');


            /*
            |--------------------------------------------------------------------------
            | 10. COMMIT EVERYTHING
            |--------------------------------------------------------------------------
            */

            DB::commit();


            /*
            |--------------------------------------------------------------------------
            | 11. RELOAD COMPLETE CONFIGURATION
            |--------------------------------------------------------------------------
            */

            $template->refresh();

            $template->load([
                'elements.dataMapping',
            ]);


            /*
            |--------------------------------------------------------------------------
            | 12. BUILD RESPONSE
            |--------------------------------------------------------------------------
            */

            $elements = $template->elements->map(
                function ($element) {

                    return [
                        'id' => $element->id,

                        'side' => $element->side,

                        'type' => $element->type,

                        'name' => $element->name,

                        'field_name' =>
                            $element->field_name,

                        'position' => [
                            'x_mm' =>
                                (float) $element->x_mm,

                            'y_mm' =>
                                (float) $element->y_mm,
                        ],

                        'size' => [
                            'width_mm' =>
                                (float) $element->width_mm,

                            'height_mm' =>
                                (float) $element->height_mm,
                        ],

                        'visible' =>
                            (bool) $element->visible,

                        'properties' =>
                            $element->properties ?? [],

                        'z_index' =>
                            (int) $element->z_index,

                        'mapping' =>
                            $element->dataMapping
                                ? [
                                    'id' =>
                                        $element
                                            ->dataMapping
                                            ->id,

                                    'source_field' =>
                                        $element
                                            ->dataMapping
                                            ->source_field,

                                    'is_required' =>
                                        (bool)
                                        $element
                                            ->dataMapping
                                            ->is_required,
                                ]
                                : null,
                    ];
                }
            );


            /*
            |--------------------------------------------------------------------------
            | 13. RETURN SUCCESS RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([

                'message' =>
                    'Template configuration saved successfully.',

                'saved_element_ids' =>
                    $savedElementIds,

                'deleted_element_ids' =>
                    $deletedElementIds,

                'template' => [
                    'id' =>
                        $template->id,

                    'name' =>
                        $template->name,

                    'width_mm' =>
                        (float) $template->width_mm,

                    'height_mm' =>
                        (float) $template->height_mm,

                    'has_front' =>
                        (bool) $template->has_front,

                    'has_back' =>
                        (bool) $template->has_back,

                    'version' =>
                        (int) $template->version,
                ],

                'elements' =>
                    $elements,
            ]);

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | 14. ROLLBACK IF SOMETHING FAILS
            |--------------------------------------------------------------------------
            */

            DB::rollBack();


            /*
            |--------------------------------------------------------------------------
            | Log the real error for debugging.
            |--------------------------------------------------------------------------
            */

            Log::error(
                'Template configuration save failed.',
                [
                    'project_id' =>
                        $project->id,

                    'template_id' =>
                        $template->id,

                    'error' =>
                        $e->getMessage(),
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Return safe error response.
            |--------------------------------------------------------------------------
            */

            return response()->json([

                'message' =>
                    'Template configuration could not be saved.',

                'error' =>
                    $e->getMessage(),

            ], 422);
        }
    }


    /**
     * Determine which PDF page represents a card side.
     *
     * Combined two-page PDF:
     *
     * Page 1 = Front
     * Page 2 = Back
     */
    private function getDesignPage(
        Template $template,
        string $side
    ): ?int {

        // No design uploaded.
        if (empty($template->front_design_path)) {
            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Same file path means it is the combined 2-page PDF.
        |--------------------------------------------------------------------------
        */

        if (
            !empty($template->back_design_path)
            &&
            $template->front_design_path ===
            $template->back_design_path
        ) {

            return $side === 'front'
                ? 1
                : 2;
        }


        /*
        |--------------------------------------------------------------------------
        | Separate front/back designs.
        |--------------------------------------------------------------------------
        |
        | A separate design file is treated as page 1.
        |
        */

        if (
            $side === 'front'
            &&
            !empty($template->front_design_path)
        ) {
            return 1;
        }


        if (
            $side === 'back'
            &&
            !empty($template->back_design_path)
        ) {
            return 1;
        }


        return null;
    }
}
