<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataMapping;
use App\Models\TemplateElement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataMappingController extends Controller
{
    /**
     * Display the mapping for a template element.
     *
     * Each template element can have only one mapping.
     */
    public function show(TemplateElement $element): JsonResponse
    {
        $mapping = $element->dataMapping;

        return response()->json([
            'mapping' => $mapping,
        ]);
    }

    /**
     * Create or update the data mapping for a template element.
     */
    public function store(Request $request, TemplateElement $element): JsonResponse
    {
        // Validate the Excel/data field that should supply this element's value.
        $validated = $request->validate([
            'source_field' => ['required', 'string', 'max:255'],
            'is_required' => ['nullable', 'boolean'],
        ]);

        // updateOrCreate prevents duplicate mappings for the same element.
        $mapping = $element->dataMapping()->updateOrCreate(
            [
                'template_element_id' => $element->id,
            ],
            [
                'source_field' => $validated['source_field'],
                'is_required' => $validated['is_required'] ?? false,
            ]
        );

        return response()->json([
            'message' => 'Data mapping saved successfully.',
            'mapping' => $mapping,
        ], 201);
    }

    /**
     * Update an existing data mapping.
     */
    public function update(
        Request $request,
        TemplateElement $element
    ): JsonResponse {
        $mapping = $element->dataMapping;

        // Return 404 when this element does not have a mapping yet.
        if (!$mapping) {
            return response()->json([
                'message' => 'No data mapping exists for this element.',
            ], 404);
        }

        $validated = $request->validate([
            'source_field' => ['required', 'string', 'max:255'],
            'is_required' => ['required', 'boolean'],
        ]);

        $mapping->update($validated);

        return response()->json([
            'message' => 'Data mapping updated successfully.',
            'mapping' => $mapping->fresh(),
        ]);
    }

    /**
     * Delete the mapping from a template element.
     */
    public function destroy(TemplateElement $element): JsonResponse
    {
        $mapping = $element->dataMapping;

        if (!$mapping) {
            return response()->json([
                'message' => 'No data mapping exists for this element.',
            ], 404);
        }

        $mapping->delete();

        return response()->json([
            'message' => 'Data mapping deleted successfully.',
        ]);
    }
}
