<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_their_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}");

        $response->assertStatus(200)
            ->assertJsonFragment(['uuid' => $task->uuid, 'type' => $task->type]);
    }

    public function test_response_contains_correct_json_structure(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}");

        $response->assertJsonStructure([
            'id',
            'uuid',
            'user_id',
            'type',
            'payload',
            'status',
            'progress',
            'result_path',
            'error_message',
            'created_at',
            'updated_at',
        ]);
    }

    public function test_non_owner_receives_403(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $task  = Task::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $task = Task::factory()->create();

        $response = $this->getJson("/api/tasks/{$task->uuid}");

        $response->assertStatus(401);
    }

    public function test_non_existent_task_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    public function test_status_reflects_current_task_state(): void
    {
        $user = User::factory()->create();

        $pending    = Task::factory()->pending()->create(['user_id' => $user->id]);
        $processing = Task::factory()->processing()->create(['user_id' => $user->id]);
        $completed  = Task::factory()->completed()->create(['user_id' => $user->id]);
        $failed     = Task::factory()->failed()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$pending->uuid}")
            ->assertJsonFragment(['status' => 'pending', 'progress' => 0]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$processing->uuid}")
            ->assertJsonFragment(['status' => 'processing', 'progress' => 50]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$completed->uuid}")
            ->assertJsonFragment(['status' => 'completed', 'progress' => 100]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$failed->uuid}")
            ->assertJsonFragment(['status' => 'failed']);
    }

    // High #1 — malformed UUID must not cause a 500
    public function test_malformed_uuid_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks/not-a-uuid')
            ->assertStatus(404);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks/123')
            ->assertStatus(404);
    }

    // High #2 — failed task exposes a non-null error_message
    public function test_failed_task_exposes_error_message(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->failed()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('error_message'));
    }

    // High #3 — ownership boundary holds across multiple tasks
    public function test_user_can_access_own_tasks_but_not_others(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $taskA1 = Task::factory()->create(['user_id' => $userA->id]);
        $taskA2 = Task::factory()->create(['user_id' => $userA->id]);
        $taskB  = Task::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/tasks/{$taskA1->uuid}")
            ->assertStatus(200);

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/tasks/{$taskA2->uuid}")
            ->assertStatus(200);

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/tasks/{$taskB->uuid}")
            ->assertStatus(403);
    }

    // Medium #4 — result_path is null for non-completed tasks
    public function test_result_path_is_null_for_non_completed_tasks(): void
    {
        $user = User::factory()->create();

        $pending    = Task::factory()->pending()->create(['user_id' => $user->id]);
        $processing = Task::factory()->processing()->create(['user_id' => $user->id]);
        $failed     = Task::factory()->failed()->create(['user_id' => $user->id]);

        foreach ([$pending, $processing, $failed] as $task) {
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/tasks/{$task->uuid}")
                ->assertJsonFragment(['result_path' => null]);
        }
    }

    // Medium #5 — error_message is null for non-failed tasks
    public function test_error_message_is_null_for_non_failed_tasks(): void
    {
        $user = User::factory()->create();

        $pending   = Task::factory()->pending()->create(['user_id' => $user->id]);
        $completed = Task::factory()->completed()->create(['user_id' => $user->id]);

        foreach ([$pending, $completed] as $task) {
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/tasks/{$task->uuid}")
                ->assertJsonFragment(['error_message' => null]);
        }
    }

    // Medium #6 — user relationship is not exposed in the response
    public function test_response_does_not_expose_user_relationship(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}");

        $response->assertJsonMissingPath('user');
    }
}
