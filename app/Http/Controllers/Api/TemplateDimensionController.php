<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Http\Request;

class TemplateDimensionController extends Controller
{
    /**
     * Update the physical dimensions of a card template.
     *
     * Dimensions are always stored in millimetres.
     *
     * Example:
     *
     * width_mm  = 92
     * height_mm = 57
     */
    public function update(
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
         * Validate the dimensions.
         *
         * We use numeric values because the card can have
         * decimal measurements such as 92.50 mm.
         *
         * We also prevent zero or negative dimensions.
         */
        $validated = $request->validate([
            'width_mm' => [
                'required',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'height_mm' => [
                'required',
                'numeric',
                'gt:0',
                'max:1000',
            ],
        ]);

        /*
         * Save the new physical dimensions.
         */
        $template->width_mm = $validated['width_mm'];
        $template->height_mm = $validated['height_mm'];

        $template->save();

        /*
         * Return the updated template dimensions.
         */
        return response()->json([
            'message' => 'Template dimensions updated successfully.',

            'template' => [
                'id' => $template->id,
                'project_id' => $template->project_id,
                'name' => $template->name,

                'width_mm' => (float) $template->width_mm,
                'height_mm' => (float) $template->height_mm,

                'has_front' => (bool) $template->has_front,
                'has_back' => (bool) $template->has_back,
            ],
        ]);
    }
}
