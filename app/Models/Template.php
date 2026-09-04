<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Template extends Model
{
    use HasFactory;

    protected $table = 'ecls_templates';

    protected $fillable = [
        'project_id',
        'name',
        'width_mm',
        'height_mm',
        'front_design_path',
        'back_design_path',
        'version',
    ];

    protected $casts = [
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'version' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    // A template can contain many elements,
    // such as photo, barcode, name, ID grid, text and images.
    public function elements(): HasMany
    {
        return $this->hasMany(TemplateElement::class);
    }

    // A dynamic template element can have one data mapping.
    // Example: Barcode Box -> barcode.
    public function dataMapping(): HasOne
    {
        return $this->hasOne(DataMapping::class);
    }
}
