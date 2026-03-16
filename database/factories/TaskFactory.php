<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'uuid'          => (string) Str::uuid(),
            'type'          => TaskType::UserExport->value,
            'payload'       => null,
            'status'        => TaskStatus::Pending,
            'progress'      => 0,
            'result_path'   => null,
            'error_message' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status'   => TaskStatus::Pending,
            'progress' => 0,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status'   => TaskStatus::Processing,
            'progress' => 50,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'      => TaskStatus::Completed,
            'progress'    => 100,
            'result_path' => 'reports/completed-task.csv',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status'        => TaskStatus::Failed,
            'error_message' => 'Something went wrong during report generation.',
        ]);
    }
}
