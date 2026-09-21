<?php

namespace Tests\Unit;

use App\Models\DataMapping;
use App\Models\StudentRecord;
use App\Models\TemplateElement;
use App\Services\CardRenderingService;
use ReflectionMethod;
use Tests\TestCase;

class CardRenderingServiceTest extends TestCase
{
    public function test_unconfigured_barcode_does_not_fall_back_to_student_barcode(): void
    {
        $student = new StudentRecord([
            'barcode' => '00000123',
        ]);
        $element = new TemplateElement([
            'type' => 'barcode',
            'field_name' => null,
        ]);

        self::assertNull($this->resolveValue($student, $element));
    }

    public function test_configured_barcode_field_is_resolved(): void
    {
        $student = new StudentRecord([
            'barcode' => '00000123',
        ]);
        $element = new TemplateElement([
            'type' => 'barcode',
            'field_name' => 'barcode',
        ]);

        self::assertSame('00000123', $this->resolveValue($student, $element));
    }

    public function test_mapping_resolves_an_extra_student_field(): void
    {
        $student = new StudentRecord([
            'data' => ['course' => 'Science'],
        ]);
        $element = new TemplateElement([
            'type' => 'text',
            'field_name' => null,
        ]);
        $element->setRelation('dataMapping', new DataMapping([
            'source_field' => 'course',
        ]));

        self::assertSame('Science', $this->resolveValue($student, $element));
    }

    public function test_static_text_comes_from_element_properties(): void
    {
        $student = new StudentRecord();
        $element = new TemplateElement([
            'type' => 'static_text',
            'properties' => ['text' => 'Issued by ECLS'],
        ]);

        self::assertSame('Issued by ECLS', $this->resolveValue($student, $element));
    }

    public function test_manufacture_date_is_generated_without_student_data(): void
    {
        $student = new StudentRecord();
        $element = new TemplateElement([
            'type' => 'manufacture_date',
            'side' => 'back',
            'field_name' => 'manufacture_date',
        ]);

        self::assertSame(now()->toDateString(), $this->resolveValue($student, $element));
    }

    private function resolveValue(StudentRecord $student, TemplateElement $element): ?string
    {
        $method = new ReflectionMethod(CardRenderingService::class, 'resolveElementValue');
        $method->setAccessible(true);

        return $method->invoke(new CardRenderingService(), $student, $element);
    }
}
