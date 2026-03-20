<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedInvoiceTask(User $user): Task
    {
        $task = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::InvoiceGeneration->value,
            'result_path' => null,
        ]);

        $resultPath = 'invoices/' . $task->uuid . '/invoice.pdf';
        Storage::disk('local')->put($resultPath, '%PDF fake pdf content');
        $task->update(['result_path' => $resultPath]);

        return $task->fresh();
    }

    // -------------------------------------------------------------------------
    // Group A — Happy path
    // -------------------------------------------------------------------------

    public function test_owner_can_download_completed_invoice_task(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = $this->makeCompletedInvoiceTask($user);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_content_disposition_uses_invoice_prefix_and_uuid(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = $this->makeCompletedInvoiceTask($user);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename=invoice-' . $task->uuid . '.pdf'
        );
    }

    public function test_response_body_matches_stored_pdf(): void
    {
        Storage::fake('local');

        $user       = User::factory()->create();
        $task       = $this->makeCompletedInvoiceTask($user);
        $pdfContent = Storage::disk('local')->get($task->result_path);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $this->assertSame($pdfContent, $response->streamedContent());
    }

    public function test_filename_uses_uuid_not_integer_id(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = $this->makeCompletedInvoiceTask($user);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('invoice-' . $task->uuid . '.pdf', $disposition);
        $this->assertStringNotContainsString('invoice-' . $task->id . '.pdf', $disposition);
    }

    // -------------------------------------------------------------------------
    // Group B — Authentication
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = $this->makeCompletedInvoiceTask($user);

        $this->getJson("/api/tasks/{$task->uuid}/download")
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Group C — Authorization
    // -------------------------------------------------------------------------

    public function test_non_owner_receives_403(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $task  = $this->makeCompletedInvoiceTask($owner);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download")
            ->assertStatus(403);
    }

    public function test_non_owner_cannot_download_even_if_they_guess_the_uuid(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $task  = $this->makeCompletedInvoiceTask($owner);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download")
            ->assertStatus(403);
    }

    public function test_owner_with_pending_task_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::InvoiceGeneration->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download")
            ->assertStatus(403);
    }

    public function test_owner_with_processing_task_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->processing()->create([
            'user_id' => $user->id,
            'type'    => TaskType::InvoiceGeneration->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download")
            ->assertStatus(403);
    }

    public function test_owner_with_failed_task_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->failed()->create([
            'user_id' => $user->id,
            'type'    => TaskType::InvoiceGeneration->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download")
            ->assertStatus(403);
    }

    public function test_completed_task_with_null_result_path_receives_403(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::InvoiceGeneration->value,
            'result_path' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}/download")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Group D — 404 cases
    // -------------------------------------------------------------------------

    public function test_non_existent_uuid_returns_404(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks/00000000-0000-0000-0000-000000000000/download')
            ->assertStatus(404);
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
    // Group E — Edge cases
    // -------------------------------------------------------------------------

    public function test_file_missing_from_disk_returns_500(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $task = $this->makeCompletedInvoiceTask($user);

        // Remove the file after task creation
        Storage::disk('local')->delete($task->result_path);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(500);
    }
}
