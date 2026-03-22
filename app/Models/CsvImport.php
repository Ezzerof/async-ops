<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

    public function resolveRouteBinding($value, $field = null): static
    {
        $import = static::whereHas(
            'task',
            fn ($q) => $q->where('uuid', $value)
        )->first();

        if (! $import) {
            throw (new ModelNotFoundException())->setModel(static::class);
        }

        return $import;
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
