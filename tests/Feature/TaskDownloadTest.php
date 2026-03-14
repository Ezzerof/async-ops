<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskDownloadTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Group 1 — Happy Path
    // -------------------------------------------------------------------------

    public function test_owner_can_download_completed_task(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->completed()->create(['user_id' => $user->id]);
        Storage::disk('local')->put($task->result_path, 'id,name' . PHP_EOL . '1,Alice');

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=report-' . $task->uuid . '.csv');
    }

    public function test_response_content_matches_stored_file(): void
    {
        Storage::fake('local');

        $user    = User::factory()->create();
        $task    = Task::factory()->completed()->create(['user_id' => $user->id]);
        $content = "id,name\n1,Alice\n2,Bob";
        Storage::disk('local')->put($task->result_path, $content);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(200);
        $this->assertSame($content, $response->streamedContent());
    }

    // -------------------------------------------------------------------------
    // Group 2 — Authentication
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        Storage::fake('local');

        $task = Task::factory()->completed()->create();

        $response = $this->getJson("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Group 3 — Authorization (Policy Boundary)
    // -------------------------------------------------------------------------

    public function test_non_owner_receives_403(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $task  = Task::factory()->completed()->create(['user_id' => $owner->id]);
        Storage::disk('local')->put($task->result_path, 'data');

        $response = $this->actingAs($other, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(403);
    }

    public function test_owner_with_pending_task_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(403);
    }

    public function test_owner_with_processing_task_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->processing()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(403);
    }

    public function test_owner_with_failed_task_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->failed()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(403);
    }

    public function test_completed_task_with_null_result_path_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'result_path' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Group 4 — Route Model Binding / 404 Cases
    // -------------------------------------------------------------------------

    public function test_non_existent_uuid_returns_404(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks/00000000-0000-0000-0000-000000000000/download');

        $response->assertStatus(404);
    }

    public function test_malformed_uuid_returns_404_not_500(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks/not-a-uuid/download')
            ->assertStatus(404);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks/123/download')
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Group 5 — Edge Cases & Security
    // -------------------------------------------------------------------------

    public function test_file_missing_from_disk_returns_500(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->completed()->create(['user_id' => $user->id]);
        // Intentionally do NOT seed the file — disk is empty

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(500);
    }

    public function test_non_owner_cannot_download_even_if_they_guess_the_uuid(): void
    {
        Storage::fake('local');

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $task  = Task::factory()->completed()->create(['user_id' => $userA->id]);
        Storage::disk('local')->put($task->result_path, 'sensitive data');

        $response = $this->actingAs($userB, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(403);
    }

    public function test_filename_in_content_disposition_uses_uuid_not_integer_id(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->completed()->create(['user_id' => $user->id]);
        Storage::disk('local')->put($task->result_path, 'data');

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(200);

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('report-' . $task->uuid . '.csv', $disposition);
        $this->assertStringNotContainsString('report-' . $task->id . '.csv', $disposition);
    }
}
