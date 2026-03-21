<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_request_a_report(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'report']);

        $response->assertStatus(201)
            ->assertJsonFragment(['type' => 'report', 'user_id' => $user->id])
            ->assertJsonFragment(['status' => 'pending', 'progress' => 0]);
    }

    public function test_store_creates_task_record_in_database(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'report']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'type'    => 'report',
            'status'  => 'pending',
            'progress' => 0,
        ]);
    }

    public function test_store_dispatches_generate_report_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'report']);

        Queue::assertPushed(GenerateReportJob::class, function (GenerateReportJob $job) use ($user): bool {
            return $job->task->user_id === $user->id
                && $job->task->type === 'report';
        });
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/tasks', ['type' => 'report']);

        $response->assertStatus(401);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'invalid_type']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_missing_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_task_belongs_to_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'report']);

        $task = Task::first();

        $this->assertEquals($user->id, $task->user_id);
    }

    // High priority: UUID present and valid in response
    public function test_response_contains_a_valid_uuid(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'report']);

        $uuid = $response->json('uuid');

        $this->assertNotNull($uuid);
        $this->assertTrue(Str::isUuid($uuid));
    }

    // High priority: extra fields must not bleed into task state
    public function test_extra_fields_do_not_affect_task_state(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', [
                'type'     => 'report',
                'status'   => 'completed',
                'progress' => 100,
            ]);

        $this->assertDatabaseHas('tasks', [
            'user_id'  => $user->id,
            'status'   => 'pending',
            'progress' => 0,
        ]);
    }

    // Medium priority: dispatched job carries the UUID
    public function test_dispatched_job_carries_the_task_uuid(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'report']);

        Queue::assertPushed(GenerateReportJob::class, function (GenerateReportJob $job): bool {
            return Str::isUuid($job->task->uuid);
        });
    }

    // Medium priority: route key is UUID, not raw integer ID
    public function test_response_uuid_is_the_route_key(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', ['type' => 'report']);

        $uuid = $response->json('uuid');
        $task = Task::first();

        // The UUID in the response must resolve the task via route model binding
        $this->assertEquals($task->uuid, $uuid);
        $this->assertEquals('uuid', $task->getRouteKeyName());
    }
}
