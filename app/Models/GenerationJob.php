<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationJob extends Model
{
    use HasFactory;

    // Use our custom E-CLASS table.
    protected $table = 'ecls_generation_jobs';

    // Fields that can be mass assigned.
    protected $fillable = [
        'project_id',
        'template_id',
        'status',
        'total_records',
        'processed_records',
        'output_path',
        'error_message',
        'started_at',
        'completed_at',
    ];

    // Convert database values into useful PHP types.
    protected $casts = [
        'total_records' => 'integer',
        'processed_records' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Each generation job belongs to one project.
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // Each generation job uses one selected template.
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
