<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImport extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'original_filename',
        'file_path',
        'headers',
        'row_count',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'headers'   => 'array',
            'row_count' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
