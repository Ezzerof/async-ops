<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Models\CsvImport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvImportShowTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $task = Task::factory()->completed()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
        ]);
        $import = CsvImport::create([
            'task_id'           => $task->id,
            'user_id'           => $user->id,
            'original_filename' => 'data.csv',
            'file_path'         => 'imports/' . $task->uuid . '/data.csv',
            'headers'           => ['name', 'age'],
            'row_count'         => 2,
        ]);

        return [$user, $task, $import];
    }

    // -------------------------------------------------------------------------
    // Group A — Happy path
    // -------------------------------------------------------------------------

    public function test_owner_can_view_import_and_gets_200(): void
    {
        [$user, , $import] = $this->makeImport();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/imports/' . $import->id);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id'                => $import->id,
                'original_filename' => 'data.csv',
                'row_count'         => 2,
            ]);
    }

    public function test_file_path_is_not_exposed_in_response(): void
    {
        [$user, , $import] = $this->makeImport();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/imports/' . $import->id);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('file_path', $response->json());
    }

    public function test_show_returns_correct_headers(): void
    {
        [$user, , $import] = $this->makeImport();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/imports/' . $import->id);

        $response->assertStatus(200)
            ->assertJsonFragment(['headers' => ['name', 'age']]);
    }

    public function test_show_returns_import_record_regardless_of_task_status(): void
    {
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

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/imports/' . $import->id)
            ->assertStatus(200)
            ->assertJsonFragment(['original_filename' => 'data.csv']);
    }

    // -------------------------------------------------------------------------
    // Group B — Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        [, , $import] = $this->makeImport();

        $this->getJson('/api/imports/' . $import->id)
            ->assertStatus(401);
    }

    public function test_other_user_cannot_view_import_and_gets_403(): void
    {
        [, , $import] = $this->makeImport();
        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/imports/' . $import->id)
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Group C — Not found
    // -------------------------------------------------------------------------

    public function test_non_existent_import_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/imports/99999')
            ->assertStatus(404);
    }
}
