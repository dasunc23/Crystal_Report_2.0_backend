<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    /**
     * Display all templates belonging to a project.
     */
    public function index(Project $project): JsonResponse
    {
        // Get only the templates that belong to this project.
        $templates = $project->templates()
            ->latest()
            ->get();

        return response()->json([
            'templates' => $templates,
        ]);
    }

    /**
     * Create a new reusable card template.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        // Validate the template information sent by Postman/React.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            // Physical card dimensions are stored in millimetres.
            // 92 x 57 mm are our default card dimensions.
            'width_mm' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'height_mm' => ['nullable', 'numeric', 'min:1', 'max:1000'],

            // A template can contain front, back, or both designs.
            'has_front' => ['nullable', 'boolean'],
            'has_back' => ['nullable', 'boolean'],
        ]);

        // Create the template under the selected project.
        $template = $project->templates()->create([
            'name' => $validated['name'],

            // Use our standard card size when no custom size is provided.
            'width_mm' => $validated['width_mm'] ?? 92.00,
            'height_mm' => $validated['height_mm'] ?? 57.00,

            // Normal student cards use both front and back.
            'has_front' => $validated['has_front'] ?? true,
            'has_back' => $validated['has_back'] ?? true,

            // Every new template starts at version 1.
            'version' => 1,
        ]);

        return response()->json([
            'message' => 'Template created successfully.',
            'template' => $template,
        ], 201);
    }

    /**
     * Display one template with its elements.
     */
    public function show(Project $project, Template $template): JsonResponse
    {
        // Make sure this template actually belongs to this project.
        $template = $project->templates()
            ->with('elements')
            ->findOrFail($template->id);

        return response()->json([
            'template' => $template,
        ]);
    }

    /**
     * Update an existing template.
     */
    public function update(
        Request $request,
        Project $project,
        Template $template
    ): JsonResponse {
        // Only allow modification of templates belonging to this project.
        $template = $project->templates()
            ->findOrFail($template->id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'width_mm' => ['required', 'numeric', 'min:1', 'max:1000'],
            'height_mm' => ['required', 'numeric', 'min:1', 'max:1000'],
            'has_front' => ['required', 'boolean'],
            'has_back' => ['required', 'boolean'],
        ]);

        // Update the template.
        $template->update($validated);

        return response()->json([
            'message' => 'Template updated successfully.',
            'template' => $template->fresh(),
        ]);
    }

    /**
     * Delete a template.
     */
    public function destroy(
        Project $project,
        Template $template
    ): JsonResponse {
        // Prevent deleting a template from another project.
        $template = $project->templates()
            ->findOrFail($template->id);

        // Related template elements will be deleted automatically
        // because the foreign key uses cascadeOnDelete().
        $template->delete();

        return response()->json([
            'message' => 'Template deleted successfully.',
        ]);
    }
}
