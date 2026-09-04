<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateElement extends Model
{
    use HasFactory;

    // Tell Laravel to use our custom table name.
    protected $table = 'ecls_template_elements';

    // These fields can be safely mass assigned.
    protected $fillable = [
        'template_id',
        'side',
        'type',
        'name',
        'field_name',
        'x_mm',
        'y_mm',
        'width_mm',
        'height_mm',
        'visible',
        'properties',
        'z_index',
    ];

    // Automatically convert database values into the appropriate PHP types.
    protected $casts = [
        'x_mm' => 'decimal:2',
        'y_mm' => 'decimal:2',
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'visible' => 'boolean',
        'properties' => 'array',
        'z_index' => 'integer',
    ];

    // Each element belongs to one card template.
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
