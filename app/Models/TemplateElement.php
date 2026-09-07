<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TemplateElement extends Model
{
    use HasFactory;

    // Custom table name used by this application.
    protected $table = 'ecls_template_elements';

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

    protected $casts = [
        'x_mm' => 'decimal:2',
        'y_mm' => 'decimal:2',
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'visible' => 'boolean',
        'properties' => 'array',
        'z_index' => 'integer',
    ];

    /**
     * The template that owns this element.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Each visual element can have one data mapping.
     *
     * Example:
     * Student Name element → student_name Excel column.
     */
    public function dataMapping(): HasOne
    {
        return $this->hasOne(DataMapping::class);
    }
}
