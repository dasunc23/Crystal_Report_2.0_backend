<?php

namespace Tests\Unit;

use App\Models\StudentRecord;
use App\Models\Template;
use App\Models\TemplateElement;
use App\Services\TemplateGenerationValidationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TemplateGenerationValidationServiceTest extends TestCase
{
    public function test_photo_is_not_required_without_a_photo_element(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('designs/front.pdf', 'pdf');

        $template = new Template([
            'has_front' => true,
            'has_back' => false,
            'front_design_path' => 'designs/front.pdf',
        ]);
        $template->setRelation('elements', collect());

        $student = new StudentRecord([
            'barcode' => '00000001',
            'student_name' => 'No Photo Needed',
        ]);

        self::assertSame([], app(TemplateGenerationValidationService::class)
            ->validate($template, [$student]));
    }

    public function test_photo_is_required_when_profile_photo_element_is_visible(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('designs/front.pdf', 'pdf');

        $template = new Template([
            'has_front' => true,
            'has_back' => false,
            'front_design_path' => 'designs/front.pdf',
        ]);
        $element = new TemplateElement([
            'id' => 10,
            'type' => 'profile_photo',
            'side' => 'front',
            'visible' => true,
        ]);
        $element->setRelation('dataMapping', null);
        $template->setRelation('elements', collect([$element]));

        $student = new StudentRecord([
            'id' => 12,
            'barcode' => '00000001',
            'student_name' => 'Photo Required',
        ]);

        $errors = app(TemplateGenerationValidationService::class)
            ->validate($template, [$student]);

        self::assertCount(1, $errors);
        self::assertSame('A matched profile photo is required by the template.', $errors[0]['reason']);
    }
}
