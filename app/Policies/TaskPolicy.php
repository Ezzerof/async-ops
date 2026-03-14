<?php

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $user->id === $task->user_id;
    }

    public function download(User $user, Task $task): bool
    {
        return $user->id === $task->user_id
            && $task->status === TaskStatus::Completed
            && $task->result_path !== null;
    }
}
