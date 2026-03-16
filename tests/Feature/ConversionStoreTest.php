<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Jobs\ConvertFileJob;
use App\Models\User;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversionStoreTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_submit_a_conversion_request(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'json',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'type'   => TaskType::FileConversion->value,
                'status' => 'pending',
            ]);
    }

    public function test_store_creates_task_record_in_database(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'json',
            ]);

        $this->assertDatabaseHas('tasks', [
            'user_id'  => $user->id,
            'type'     => TaskType::FileConversion->value,
            'status'   => 'pending',
            'progress' => 0,
        ]);
    }

    public function test_task_belongs_to_authenticated_user(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'json',
            ]);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
        ]);
    }

    public function test_response_contains_a_valid_uuid(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'json',
            ]);

        $this->assertTrue(Str::isUuid($response->json('uuid')));
    }

    public function test_payload_stores_target_format_and_file_paths(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'json',
            ]);

        $payload = $response->json('payload');

        $this->assertSame('json', $payload['target_format']);
        $this->assertNotEmpty($payload['files']);
        $this->assertIsArray($payload['output_files']);
    }

    // -------------------------------------------------------------------------
    // Batch dispatch
    // -------------------------------------------------------------------------

    public function test_dispatches_one_job_per_uploaded_file(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files' => [
                    UploadedFile::fake()->create('a.csv', 10, 'text/csv'),
                    UploadedFile::fake()->create('b.csv', 10, 'text/csv'),
                    UploadedFile::fake()->create('c.csv', 10, 'text/csv'),
                ],
                'target_format' => 'json',
            ]);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            return $batch->jobs->count() === 3
                && $batch->jobs->every(fn ($job) => $job instanceof ConvertFileJob);
        });
    }

    public function test_dispatched_jobs_carry_correct_task_and_format(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'json',
            ]);

        $taskUuid = $response->json('uuid');

        Bus::assertBatched(function (PendingBatch $batch) use ($taskUuid): bool {
            /** @var ConvertFileJob $job */
            $job = $batch->jobs->first();

            return $job->task->uuid === $taskUuid
                && $job->targetFormat->value === 'json';
        });
    }

    public function test_uploaded_files_are_stored_under_task_uuid_directory(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'json',
            ]);

        $taskUuid    = $response->json('uuid');
        $storedFiles = $response->json('payload.files');

        $this->assertCount(1, $storedFiles);
        $this->assertStringStartsWith('uploads/' . $taskUuid . '/', $storedFiles[0]);
        Storage::disk('local')->assertExists($storedFiles[0]);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/conversions', [
            'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
            'target_format' => 'json',
        ]);

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_missing_files_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', ['target_format' => 'json']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['files']);
    }

    public function test_missing_target_format_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files' => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target_format']);
    }

    public function test_invalid_target_format_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => 'mp4',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target_format']);
    }

    public function test_more_than_ten_files_is_rejected(): void
    {
        $user  = User::factory()->create();
        $files = array_fill(0, 11, UploadedFile::fake()->create('data.csv', 10, 'text/csv'));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => $files,
                'target_format' => 'json',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['files']);
    }

    public function test_file_exceeding_size_limit_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 6000, 'text/csv')],
                'target_format' => 'json',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);
    }

    public function test_unsupported_mime_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('video.mp4', 100, 'video/mp4')],
                'target_format' => 'json',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);
    }

    public function test_empty_files_array_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [],
                'target_format' => 'json',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['files']);
    }

    // -------------------------------------------------------------------------
    // Supported formats accepted
    // -------------------------------------------------------------------------

    /** @dataProvider validFormatProvider */
    public function test_all_supported_target_formats_are_accepted(string $format): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/conversions', [
                'files'         => [UploadedFile::fake()->create('data.csv', 10, 'text/csv')],
                'target_format' => $format,
            ]);

        $response->assertStatus(201);
    }

    public static function validFormatProvider(): array
    {
        return [
            'json' => ['json'],
            'csv'  => ['csv'],
            'xml'  => ['xml'],
            'pdf'  => ['pdf'],
            'docx' => ['docx'],
            'xlsx' => ['xlsx'],
            'yaml' => ['yaml'],
            'txt'  => ['txt'],
        ];
    }
}
