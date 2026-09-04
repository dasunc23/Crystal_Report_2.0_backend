<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $table = 'ecls_projects';

    protected $fillable = [
        'name',
        'status',
    ];

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }
    // A project can contain many student records imported from Excel.
    public function studentRecords(): HasMany
    {
        return $this->hasMany(StudentRecord::class);
    }
}
