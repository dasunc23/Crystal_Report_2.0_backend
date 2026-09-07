<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Display all projects.
     */
    public function index(): JsonResponse
    {
        // Get all projects, newest projects first.
        $projects = Project::latest()->get();

        return response()->json([
            'projects' => $projects,
        ]);
    }

    /**
     * Create a new project.
     */
    public function store(Request $request): JsonResponse
    {
        // Validate the project information sent by React/Postman.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // Create the project with draft status.
        $project = Project::create([
            'name' => $validated['name'],
            'status' => 'draft',
        ]);

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => $project,
        ], 201);
    }

    /**
     * Display one specific project.
     */
    public function show(Project $project): JsonResponse
    {
        // Load the project's templates and student records.
        // This will be useful for the project dashboard later.
        $project->load([
            'templates',
            'studentRecords',
        ]);

        return response()->json([
            'project' => $project,
        ]);
    }

    /**
     * Update an existing project.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        // Validate the new project name.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $project->update([
            'name' => $validated['name'],
        ]);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);
    }

    /**
     * Delete a project.
     */
    public function destroy(Project $project): JsonResponse
    {
        // Deleting the project will also delete its related records
        // because our database foreign keys use cascadeOnDelete().
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }
}
