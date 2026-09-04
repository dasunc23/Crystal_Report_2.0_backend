<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMapping extends Model
{
    use HasFactory;

    // Use our custom E-CLASS table.
    protected $table = 'ecls_data_mappings';

    // Fields that can be mass assigned.
    protected $fillable = [
        'template_element_id',
        'source_field',
        'is_required',
    ];

    // Convert the database value into a PHP boolean.
    protected $casts = [
        'is_required' => 'boolean',
    ];

    // Each mapping belongs to one template element.
    public function templateElement(): BelongsTo
    {
        return $this->belongsTo(TemplateElement::class);
    }
}
