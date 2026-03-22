<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Jobs\AnalyseDataJob;
use App\Models\CsvImport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportAnalyseTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedImport(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $task = Task::factory()->completed()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
        ]);
        $importPath = 'imports/' . $task->uuid . '/data.csv';
        Storage::disk('local')->put($importPath, "name,age\nAlice,30\n");
        $import = CsvImport::create([
            'task_id'           => $task->id,
            'user_id'           => $user->id,
            'original_filename' => 'data.csv',
            'file_path'         => $importPath,
            'headers'           => ['name', 'age'],
            'row_count'         => 1,
        ]);

        return [$user, $task, $import];
    }

    // -------------------------------------------------------------------------
    // Group A — Happy path
    // -------------------------------------------------------------------------

    public function test_owner_can_trigger_analysis_and_gets_201(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $task] = $this->makeCompletedImport();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports/' . $task->uuid . '/analyse');

        $response->assertStatus(201)
            ->assertJsonFragment([
                'type'   => TaskType::DataAnalysis->value,
                'status' => 'pending',
            ]);
    }

    public function test_analyse_data_job_is_dispatched(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $task] = $this->makeCompletedImport();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports/' . $task->uuid . '/analyse');

        Queue::assertPushed(AnalyseDataJob::class, 1);
    }

    public function test_analysis_task_payload_points_to_import_file(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $importTask, $import] = $this->makeCompletedImport();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports/' . $importTask->uuid . '/analyse');

        $expectedFilePath = 'imports/' . $importTask->uuid . '/data.csv';
        $this->assertSame($expectedFilePath, $response->json('payload.file'));
    }

    public function test_double_analyse_creates_two_independent_tasks(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $task] = $this->makeCompletedImport();

        $responseA = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports/' . $task->uuid . '/analyse');
        $responseB = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports/' . $task->uuid . '/analyse');

        $responseA->assertStatus(201);
        $responseB->assertStatus(201);
        $this->assertNotSame($responseA->json('uuid'), $responseB->json('uuid'));
        Queue::assertPushed(AnalyseDataJob::class, 2);
    }

    // -------------------------------------------------------------------------
    // Group B — Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        Storage::fake('local');
        [, $task] = $this->makeCompletedImport();

        $this->postJson('/api/imports/' . $task->uuid . '/analyse')
            ->assertStatus(401);
    }

    public function test_other_user_cannot_trigger_analysis_and_gets_403(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $task] = $this->makeCompletedImport();
        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/imports/' . $task->uuid . '/analyse')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Group C — Business rules
    // -------------------------------------------------------------------------

    public function test_analyse_pending_import_returns_422(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
        ]);
        $import = CsvImport::create([
            'task_id'           => $task->id,
            'user_id'           => $user->id,
            'original_filename' => 'data.csv',
            'file_path'         => 'imports/' . $task->uuid . '/data.csv',
            'headers'           => [],
            'row_count'         => 0,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports/' . $task->uuid . '/analyse');

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot analyse an import that has not completed successfully.']);
    }

    public function test_analyse_failed_import_returns_422(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $task = Task::factory()->failed()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
        ]);
        $import = CsvImport::create([
            'task_id'           => $task->id,
            'user_id'           => $user->id,
            'original_filename' => 'data.csv',
            'file_path'         => 'imports/' . $task->uuid . '/data.csv',
            'headers'           => [],
            'row_count'         => 0,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports/' . $task->uuid . '/analyse')
            ->assertStatus(422);
    }
}
