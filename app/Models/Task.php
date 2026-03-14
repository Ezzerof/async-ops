<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'payload',
        'status',
        'progress',
        'result_path',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload'  => 'array',
            'status'   => TaskStatus::class,
            'progress' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task): void {
            $task->uuid = (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
