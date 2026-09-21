<?php

namespace Tests\Unit;

use App\Models\Template;
use App\Services\A4PrintSheetRenderingService;
use RuntimeException;
use Tests\TestCase;

class A4PrintSheetRenderingServiceTest extends TestCase
{
    public function test_front_only_layout_does_not_reserve_back_card_space(): void
    {
        $template = new Template([
            'width_mm' => 92,
            'height_mm' => 57,
            'has_front' => true,
            'has_back' => false,
        ]);

        $layout = app(A4PrintSheetRenderingService::class)
            ->calculateLayout($template, 1);

        self::assertSame(92.0, $layout['pair']['width_mm']);
        self::assertEquals(0, $layout['pair']['gap_mm']);
    }

    public function test_two_sided_layout_keeps_front_back_pair_space(): void
    {
        $template = new Template([
            'width_mm' => 92,
            'height_mm' => 57,
            'has_front' => true,
            'has_back' => true,
        ]);

        $layout = app(A4PrintSheetRenderingService::class)
            ->calculateLayout($template, 1);

        self::assertSame(194.0, $layout['pair']['width_mm']);
        self::assertSame(10.0, $layout['pair']['gap_mm']);
    }

    public function test_template_with_no_enabled_sides_is_rejected(): void
    {
        $template = new Template([
            'width_mm' => 92,
            'height_mm' => 57,
            'has_front' => false,
            'has_back' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one enabled card side');

        app(A4PrintSheetRenderingService::class)->render($template, []);
    }
}
