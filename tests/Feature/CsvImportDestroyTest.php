<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\CsvImport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportDestroyTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(?User $user = null, string $status = 'completed'): array
    {
        $user ??= User::factory()->create();
        $task = Task::factory()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
            'status'  => TaskStatus::from($status),
        ]);
        Storage::disk('local')->put('imports/' . $task->uuid . '/data.csv', 'name,age');
        $import = CsvImport::create([
            'task_id'           => $task->id,
            'user_id'           => $user->id,
            'original_filename' => 'data.csv',
            'file_path'         => 'imports/' . $task->uuid . '/data.csv',
            'headers'           => ['name', 'age'],
            'row_count'         => 0,
        ]);

        return [$user, $task, $import];
    }

    // -------------------------------------------------------------------------
    // Group A — Happy path
    // -------------------------------------------------------------------------

    public function test_owner_can_delete_import_and_gets_204(): void
    {
        Storage::fake('local');
        [$user, $task] = $this->makeImport();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid)
            ->assertStatus(204);
    }

    public function test_csv_import_record_is_deleted_from_database(): void
    {
        Storage::fake('local');
        [$user, $task, $import] = $this->makeImport();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid);

        $this->assertDatabaseMissing('csv_imports', ['id' => $import->id]);
    }

    public function test_associated_task_is_deleted_from_database(): void
    {
        Storage::fake('local');
        [$user, $task, $import] = $this->makeImport();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_import_files_are_deleted_from_disk(): void
    {
        Storage::fake('local');
        [$user, $task, $import] = $this->makeImport();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid);

        Storage::disk('local')->assertMissing('imports/' . $task->uuid . '/data.csv');
    }

    public function test_delete_returns_409_when_derived_analysis_task_is_pending(): void
    {
        Storage::fake('local');
        [$user, $task, $import] = $this->makeImport();

        // A pending analysis task that references the import's stored file
        Task::factory()->create([
            'user_id' => $user->id,
            'type'    => TaskType::DataAnalysis->value,
            'status'  => TaskStatus::Pending,
            'payload' => ['file' => $import->file_path],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid)
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'An analysis derived from this import is still running. Wait for it to complete before deleting.']);
    }

    public function test_delete_returns_409_when_derived_analysis_task_is_processing(): void
    {
        Storage::fake('local');
        [$user, $task, $import] = $this->makeImport();

        Task::factory()->create([
            'user_id' => $user->id,
            'type'    => TaskType::DataAnalysis->value,
            'status'  => TaskStatus::Processing,
            'payload' => ['file' => $import->file_path],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid)
            ->assertStatus(409);
    }

    public function test_delete_succeeds_when_derived_analysis_task_is_completed(): void
    {
        Storage::fake('local');
        [$user, $task, $import] = $this->makeImport();

        Task::factory()->create([
            'user_id' => $user->id,
            'type'    => TaskType::DataAnalysis->value,
            'status'  => TaskStatus::Completed,
            'payload' => ['file' => $import->file_path],
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid)
            ->assertStatus(204);
    }

    public function test_delete_succeeds_when_import_is_still_processing(): void
    {
        Storage::fake('local');
        [$user, $task, $import] = $this->makeImport(status: 'processing');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid)
            ->assertStatus(204);

        $this->assertDatabaseMissing('csv_imports', ['id' => $import->id]);
    }

    // -------------------------------------------------------------------------
    // Group B — Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        Storage::fake('local');
        [, $task] = $this->makeImport();

        $this->deleteJson('/api/imports/' . $task->uuid)
            ->assertStatus(401);
    }

    public function test_other_user_cannot_delete_import_and_gets_403(): void
    {
        Storage::fake('local');
        [, $task] = $this->makeImport();
        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/imports/' . $task->uuid)
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Group C — Not found
    // -------------------------------------------------------------------------

    public function test_non_existent_import_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/imports/99999')
            ->assertStatus(404);
    }
}
