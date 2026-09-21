<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\StudentRecord;
use App\Models\Template;
use App\Models\TemplateElement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class BulkWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_barcodes_are_generated_without_changing_existing_values(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Test Project', 'status' => 'draft']);

        $existing = StudentRecord::create([
            'project_id' => $project->id,
            'barcode' => '00000007',
            'student_name' => 'Existing',
        ]);

        $missing = StudentRecord::create([
            'project_id' => $project->id,
            'barcode' => null,
            'student_name' => 'Missing',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/students/generate-barcodes"
        );

        $response->assertOk()
            ->assertJsonPath('summary.generated', 1)
            ->assertJsonPath('summary.already_existing', 1)
            ->assertJsonPath('generated_students.0.student_record_id', $missing->id)
            ->assertJsonPath('generated_students.0.barcode', '00000008');

        self::assertSame('00000007', $existing->fresh()->barcode);
        self::assertSame('00000008', $missing->fresh()->barcode);
    }

    public function test_new_excel_upload_replaces_the_previous_project_batch(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Import Project', 'status' => 'draft']);

        $firstFile = UploadedFile::fake()->createWithContent(
            'first.csv',
            "student_name,student_id,barcode,course,year\nOld Student,OLD-1,00000001,Science,2025\nSecond Student,OLD-2,00000002,Arts,2025\n"
        );

        $this->actingAs($user)
            ->post("/api/projects/{$project->id}/students/import", ['file' => $firstFile])
            ->assertOk()
            ->assertJsonPath('imported_count', 2)
            ->assertJsonPath('current_student_count', 2);

        $secondFile = UploadedFile::fake()->createWithContent(
            'second.csv',
            "student_name,student_id,barcode,course,year\nNew Student,NEW-1,00000009,Technology,2026\n"
        );

        $this->actingAs($user)
            ->post("/api/projects/{$project->id}/students/import", ['file' => $secondFile])
            ->assertOk()
            ->assertJsonPath('replace_existing', true)
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonPath('imported_count', 1)
            ->assertJsonPath('current_student_count', 1);

        self::assertDatabaseMissing('ecls_student_records', [
            'project_id' => $project->id,
            'barcode' => '00000001',
        ]);
        self::assertDatabaseHas('ecls_student_records', [
            'project_id' => $project->id,
            'barcode' => '00000009',
            'student_id' => 'NEW-1',
        ]);
    }

    public function test_print_sheet_rejects_a_student_from_another_project(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Project A', 'status' => 'draft']);
        $otherProject = Project::create(['name' => 'Project B', 'status' => 'draft']);
        $template = Template::create([
            'project_id' => $project->id,
            'name' => 'Template',
            'width_mm' => 92,
            'height_mm' => 57,
            'has_front' => true,
            'has_back' => false,
            'version' => 1,
        ]);
        $student = StudentRecord::create([
            'project_id' => $otherProject->id,
            'barcode' => '00000001',
            'student_name' => 'Other Project Student',
        ]);

        $this->actingAs($user)
            ->postJson(
                "/api/projects/{$project->id}/templates/{$template->id}/print-sheet",
                ['student_ids' => [$student->id], 'preview' => true]
            )
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more student IDs do not belong to this project.');
    }

    public function test_photo_upload_preview_and_final_pdf_workflow(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $project = Project::create(['name' => 'PDF Project', 'status' => 'draft']);
        $template = Template::create([
            'project_id' => $project->id,
            'name' => 'Front Template',
            'width_mm' => 92,
            'height_mm' => 57,
            'has_front' => true,
            'has_back' => false,
            'version' => 1,
            'front_design_path' => 'designs/front.pdf',
        ]);
        TemplateElement::create([
            'template_id' => $template->id,
            'side' => 'front',
            'type' => 'student_name',
            'field_name' => 'student_name',
            'x_mm' => 5,
            'y_mm' => 5,
            'width_mm' => 50,
            'height_mm' => 8,
            'visible' => true,
        ]);
        TemplateElement::create([
            'template_id' => $template->id,
            'side' => 'front',
            'type' => 'barcode',
            'field_name' => 'barcode',
            'x_mm' => 5,
            'y_mm' => 20,
            'width_mm' => 50,
            'height_mm' => 15,
            'visible' => true,
        ]);

        $student = StudentRecord::create([
            'project_id' => $project->id,
            'barcode' => '00000001',
            'student_id' => 'ST-1',
            'student_name' => 'PDF Student',
        ]);

        $barcodeResponse = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/students/generate-barcodes"
        );

        $barcodeResponse->assertOk()
            ->assertJsonPath('summary.barcode_images_generated', 1)
            ->assertJsonPath('summary.existing_values_preserved', 1)
            ->assertJsonPath('barcode_images.0.barcode', '00000001');

        $barcodePath = $barcodeResponse->json('barcode_images.0.path');
        self::assertNotEmpty($barcodePath);
        self::assertTrue(Storage::disk('local')->exists($barcodePath));
        self::assertSame('image/png', Storage::disk('local')->mimeType($barcodePath));

        $baseDesign = new Fpdi('P', 'mm', [92, 57]);
        $baseDesign->AddPage('P', [92, 57]);
        $baseDesign->SetFillColor(240, 240, 240);
        $baseDesign->Rect(0, 0, 92, 57, 'F');
        Storage::disk('local')->put('designs/front.pdf', $baseDesign->Output('S'));

        $photoResponse = $this->actingAs($user)->post(
            "/api/projects/{$project->id}/students/photos",
            ['photos' => [UploadedFile::fake()->image('00000001.jpg', 300, 400)]]
        );

        $photoResponse->assertOk()
            ->assertJsonPath('summary.matched', 1)
            ->assertJsonPath('summary.invalid', 0);

        $previewResponse = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/templates/{$template->id}/print-sheet",
            ['student_ids' => [$student->id], 'preview' => true]
        );

        $previewResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="student_cards_a4.pdf"');
        self::assertStringContainsString('/Subtype /Image', $previewResponse->getContent());

        $generationResponse = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/templates/{$template->id}/generate",
            ['confirm' => true]
        );

        $generationResponse->assertCreated()
            ->assertJsonPath('job.status', 'completed');

        $jobId = $generationResponse->json('job.id');
        $this->actingAs($user)
            ->get("/api/projects/{$project->id}/generation-jobs/{$jobId}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
