<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentRecord extends Model
{
    use HasFactory;

    // Use our custom E-CLASS table.
    protected $table = 'ecls_student_records';

    // Fields that can be mass assigned.
    protected $fillable = [
        'project_id',
        'barcode',
        'student_id',
        'student_name',
        'data',
    ];

    // Convert the JSON database field into a PHP array.
    protected $casts = [
        'data' => 'array',
    ];

    // Each student record belongs to one project.
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
    // A student record can have one matched photo.
    public function photo(): HasOne
    {
        return $this->hasOne(StudentPhoto::class);
    }
}
