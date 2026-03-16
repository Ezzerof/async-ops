<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ConversionTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Group A — show(): live batch progress
    // -------------------------------------------------------------------------

    public function test_show_returns_processing_status_when_batch_is_in_flight(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->shouldReceive('processedJobs')->andReturn(0);
        $batch->totalJobs = 3;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'live-batch-id', 'target_format' => 'json'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => TaskStatus::Processing->value]);
    }

    public function test_show_derives_progress_percentage_from_batch(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->shouldReceive('processedJobs')->andReturn(1);
        $batch->totalJobs = 2;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'live-batch-id'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}")
            ->assertStatus(200)
            ->assertJsonFragment(['progress' => 50]);
    }

    public function test_show_does_not_persist_live_progress_to_database(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(false);
        $batch->shouldReceive('processedJobs')->andReturn(1);
        $batch->totalJobs = 2;

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $user = User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id'  => $user->id,
            'type'     => TaskType::FileConversion->value,
            'payload'  => ['batch_id' => 'live-batch-id'],
            'progress' => 0,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}")
            ->assertStatus(200)
            ->assertJsonFragment(['progress' => 50]);

        // DB record must not be updated
        $this->assertSame(0, $task->fresh()->progress);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    public function test_show_returns_db_state_when_batch_is_finished(): void
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('finished')->andReturn(true);

        Bus::shouldReceive('findBatch')->andReturn($batch);

        $user = User::factory()->create();
        $task = Task::factory()->completed()->create([
            'user_id' => $user->id,
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'done-batch-id'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => TaskStatus::Completed->value, 'progress' => 100]);
    }

    public function test_show_returns_db_state_when_batch_record_expired(): void
    {
        Bus::shouldReceive('findBatch')->andReturn(null);

        $user = User::factory()->create();
        $task = Task::factory()->completed()->create([
            'user_id' => $user->id,
            'type'    => TaskType::FileConversion->value,
            'payload' => ['batch_id' => 'expired-batch-id'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/tasks/{$task->uuid}")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => TaskStatus::Completed->value]);
    }

    // -------------------------------------------------------------------------
    // Group B — download(): Content-Type and filename
    // -------------------------------------------------------------------------

    public function test_download_serves_single_json_result_with_correct_headers(): void
    {
        Storage::fake('local');

        $user        = User::factory()->create();
        $resultPath  = 'conversions/' . Str::uuid() . '/output_abc.json';
        $task        = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::FileConversion->value,
            'result_path' => $resultPath,
        ]);
        Storage::disk('local')->put($resultPath, '{"name":"Alice"}');

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString(
            'conversion-' . $task->uuid . '.json',
            $response->headers->get('Content-Disposition'),
        );
    }

    public function test_download_serves_zip_result_with_correct_headers(): void
    {
        Storage::fake('local');

        $user        = User::factory()->create();
        $resultPath  = 'conversions/' . Str::uuid() . '/result.zip';
        $task        = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::FileConversion->value,
            'result_path' => $resultPath,
        ]);
        Storage::disk('local')->put($resultPath, 'zip-binary-content');

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString(
            'conversion-' . $task->uuid . '.zip',
            $response->headers->get('Content-Disposition'),
        );
    }

    public function test_download_filename_uses_conversion_prefix_not_report_prefix(): void
    {
        Storage::fake('local');

        $user        = User::factory()->create();
        $resultPath  = 'conversions/' . Str::uuid() . '/output_abc.csv';
        $task        = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::FileConversion->value,
            'result_path' => $resultPath,
        ]);
        Storage::disk('local')->put($resultPath, 'name,age');

        $disposition = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download")
            ->headers->get('Content-Disposition');

        $this->assertStringContainsString('conversion-', $disposition);
        $this->assertStringNotContainsString('report-', $disposition);
    }

    public function test_download_file_content_matches_stored_conversion_output(): void
    {
        Storage::fake('local');

        $user        = User::factory()->create();
        $resultPath  = 'conversions/' . Str::uuid() . '/output_abc.json';
        $content     = '{"id":1,"name":"Alice"}';
        $task        = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::FileConversion->value,
            'result_path' => $resultPath,
        ]);
        Storage::disk('local')->put($resultPath, $content);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download");

        $this->assertSame($content, $response->streamedContent());
    }

    /** @dataProvider conversionDownloadMimeProvider */
    public function test_download_serves_correct_content_type_per_extension(
        string $extension,
        string $expectedContentType,
    ): void {
        Storage::fake('local');

        $user       = User::factory()->create();
        $resultPath = 'conversions/' . Str::uuid() . '/output_abc.' . $extension;
        $task       = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::FileConversion->value,
            'result_path' => $resultPath,
        ]);
        Storage::disk('local')->put($resultPath, 'content');

        $this->actingAs($user, 'sanctum')
            ->get("/api/tasks/{$task->uuid}/download")
            ->assertStatus(200)
            ->assertHeader('Content-Type', $expectedContentType);
    }

    public static function conversionDownloadMimeProvider(): array
    {
        return [
            'json' => ['json', 'application/json'],
            'xml'  => ['xml',  'application/xml'],
            'csv'  => ['csv',  'text/csv; charset=UTF-8'],
            'txt'  => ['txt',  'text/plain; charset=UTF-8'],
            'zip'  => ['zip',  'application/zip'],
        ];
    }
}
