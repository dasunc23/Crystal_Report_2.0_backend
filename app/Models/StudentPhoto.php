<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPhoto extends Model
{
    use HasFactory;

    // Use our custom E-CLASS table.
    protected $table = 'ecls_student_photos';

    // Fields that can be mass assigned.
    protected $fillable = [
        'student_record_id',
        'barcode',
        'file_path',
        'original_filename',
        'match_status',
        'mime_type',
        'file_size',
    ];

    // Cast the file size to an integer.
    protected $casts = [
        'file_size' => 'integer',
    ];

    // Each photo belongs to exactly one student record.
    public function studentRecord(): BelongsTo
    {
        return $this->belongsTo(StudentRecord::class);
    }
}
