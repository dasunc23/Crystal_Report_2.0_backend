<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    use HasFactory;

    // Custom table name.
    protected $table = 'ecls_templates';

    // Fields that can be inserted or updated.
    protected $fillable = [
        'project_id',
        'name',
        'width_mm',
        'height_mm',
        'has_front',
        'has_back',
        'front_design_path',
        'back_design_path',
        'version',
    ];

    // Automatically convert database values.
    protected $casts = [
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'has_front' => 'boolean',
        'has_back' => 'boolean',
        'version' => 'integer',
    ];

    // Every template belongs to one project.
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // A template contains many visual elements
    // such as photo box, barcode box, text, ID grid, logo, etc.
    public function elements(): HasMany
    {
        return $this->hasMany(TemplateElement::class);
    }

    // A template can be used multiple times for PDF generation.
    public function generationJobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }
}
