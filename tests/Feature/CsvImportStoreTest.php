<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Jobs\ImportCsvJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CsvImportStoreTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Group A — Happy path
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_upload_csv_and_gets_201(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('data.csv', 10, 'text/csv'),
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'type'   => TaskType::CsvImport->value,
                'status' => 'pending',
            ]);

        $this->assertTrue(Str::isUuid($response->json('uuid')));
    }

    public function test_task_belongs_to_authenticated_user(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('data.csv', 10, 'text/csv'),
            ]);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
        ]);
    }

    public function test_task_record_is_persisted_with_correct_fields(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('data.csv', 10, 'text/csv'),
            ]);

        $this->assertDatabaseHas('tasks', [
            'user_id'  => $user->id,
            'type'     => TaskType::CsvImport->value,
            'status'   => 'pending',
            'progress' => 0,
        ]);
    }

    public function test_uploaded_file_is_stored_under_task_uuid_directory(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('data.csv', 10, 'text/csv'),
            ]);

        $taskUuid   = $response->json('uuid');
        $storedPath = $response->json('payload.file');

        $this->assertStringStartsWith('uploads/' . $taskUuid . '/', $storedPath);
        Storage::disk('local')->assertExists($storedPath);
    }

    public function test_import_csv_job_is_dispatched_exactly_once(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('data.csv', 10, 'text/csv'),
            ]);

        Queue::assertPushed(ImportCsvJob::class, 1);
    }

    // -------------------------------------------------------------------------
    // Group B — Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/imports', [
            'file' => UploadedFile::fake()->create('data.csv', 10, 'text/csv'),
        ]);

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Group C — Validation
    // -------------------------------------------------------------------------

    public function test_missing_file_field_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_non_csv_file_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('photo.png', 10, 'image/png'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_file_exceeding_10mb_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('data.csv', 11000, 'text/csv'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    // -------------------------------------------------------------------------
    // Group D — Rate limiting
    // -------------------------------------------------------------------------

    public function test_eleventh_request_returns_429(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/imports', [
                    'file' => UploadedFile::fake()->create('data.csv', 1, 'text/csv'),
                ])->assertStatus(201);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/imports', [
                'file' => UploadedFile::fake()->create('data.csv', 1, 'text/csv'),
            ])->assertStatus(429);
    }
}
