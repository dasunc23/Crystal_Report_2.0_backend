<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMapping extends Model
{
    use HasFactory;

    protected $table = 'ecls_data_mappings';

    protected $fillable = [
        'template_element_id',
        'source_field',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function templateElement(): BelongsTo
    {
        return $this->belongsTo(
            TemplateElement::class,
            'template_element_id',
            'id'
        );
    }
}
