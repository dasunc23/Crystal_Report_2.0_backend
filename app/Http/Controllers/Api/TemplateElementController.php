<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\TemplateElement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateElementController extends Controller
{
    /**
     * Display all elements belonging to a template.
     */
    public function index(Template $template): JsonResponse
    {
        $elements = $template->elements()
            ->orderBy('z_index')
            ->get();

        return response()->json([
            'elements' => $elements,
        ]);
    }

    /**
     * Create a new element inside a template.
     */
    public function store(Request $request, Template $template): JsonResponse
    {
        // Validate the basic visual properties of the element.
        $validated = $request->validate([
            'side' => ['required', 'in:front,back'],
            'type' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'field_name' => ['nullable', 'string', 'max:255'],

            // Element position in millimetres.
            'x_mm' => ['nullable', 'numeric', 'min:0'],
            'y_mm' => ['nullable', 'numeric', 'min:0'],

            // Element size in millimetres.
            'width_mm' => ['nullable', 'numeric', 'min:0.1'],
            'height_mm' => ['nullable', 'numeric', 'min:0.1'],

            'visible' => ['nullable', 'boolean'],
            'properties' => ['nullable', 'array'],
            'z_index' => ['nullable', 'integer'],
        ]);

        $element = $template->elements()->create([
            'side' => $validated['side'],
            'type' => $validated['type'],
            'name' => $validated['name'] ?? null,
            'field_name' => $validated['field_name'] ?? null,

            // Default position.
            'x_mm' => $validated['x_mm'] ?? 0,
            'y_mm' => $validated['y_mm'] ?? 0,

            // Default element size.
            'width_mm' => $validated['width_mm'] ?? 10,
            'height_mm' => $validated['height_mm'] ?? 10,

            'visible' => $validated['visible'] ?? true,
            'properties' => $validated['properties'] ?? null,
            'z_index' => $validated['z_index'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Template element created successfully.',
            'element' => $element,
        ], 201);
    }

    /**
     * Display one specific template element.
     */
    public function show(Template $template, TemplateElement $element): JsonResponse
    {
        // Make sure the element actually belongs to this template.
        $element = $template->elements()->findOrFail($element->id);

        return response()->json([
            'element' => $element,
        ]);
    }

    /**
     * Update an existing template element.
     */
    public function update(
        Request $request,
        Template $template,
        TemplateElement $element
    ): JsonResponse {
        // Make sure the element belongs to this template.
        $element = $template->elements()->findOrFail($element->id);

        $validated = $request->validate([
            'side' => ['required', 'in:front,back'],
            'type' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'field_name' => ['nullable', 'string', 'max:255'],

            'x_mm' => ['required', 'numeric', 'min:0'],
            'y_mm' => ['required', 'numeric', 'min:0'],

            'width_mm' => ['required', 'numeric', 'min:0.1'],
            'height_mm' => ['required', 'numeric', 'min:0.1'],

            'visible' => ['required', 'boolean'],
            'properties' => ['nullable', 'array'],
            'z_index' => ['required', 'integer'],
        ]);

        $element->update($validated);

        return response()->json([
            'message' => 'Template element updated successfully.',
            'element' => $element->fresh(),
        ]);
    }

    /**
     * Delete a template element.
     */
    public function destroy(
        Template $template,
        TemplateElement $element
    ): JsonResponse {
        // Make sure the element belongs to this template.
        $element = $template->elements()->findOrFail($element->id);

        $element->delete();

        return response()->json([
            'message' => 'Template element deleted successfully.',
        ]);
    }
}
