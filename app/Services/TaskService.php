<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Task;
use App\Models\User;

class TaskService
{
    public function createAndDispatch(User $user, string $type, ?array $payload = null): Task
    {
        $task = Task::create([
            'user_id'  => $user->id,
            'type'     => $type,
            'status'   => TaskStatus::Pending,
            'progress' => 0,
            'payload'  => $payload,
        ]);

        GenerateReportJob::dispatch($task);

        return $task;
    }
}
