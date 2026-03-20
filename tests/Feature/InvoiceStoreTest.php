<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Jobs\GenerateInvoiceJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceStoreTest extends TestCase
{
    use RefreshDatabase;

    private function validCsv(): UploadedFile
    {
        $content = "description,quantity,unit_price\nWidget A,2,9.99\nWidget B,1,4.50\n";

        return UploadedFile::fake()->createWithContent('invoice.csv', $content);
    }

    // -------------------------------------------------------------------------
    // Group A — Happy path
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_upload_csv_and_gets_201(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $this->validCsv()]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'type'   => TaskType::InvoiceGeneration->value,
                'status' => 'pending',
            ]);
    }

    public function test_response_uuid_is_a_valid_uuid(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $this->validCsv()]);

        $this->assertTrue(Str::isUuid($response->json('uuid')));
    }

    public function test_task_record_is_persisted_with_correct_fields(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $this->validCsv()]);

        $this->assertDatabaseHas('tasks', [
            'user_id'  => $user->id,
            'type'     => TaskType::InvoiceGeneration->value,
            'status'   => 'pending',
            'progress' => 0,
        ]);
    }

    public function test_task_belongs_to_the_authenticated_user(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $this->validCsv()]);

        $task = Task::where('uuid', $response->json('uuid'))->first();

        $this->assertSame($user->id, $task->user_id);
    }

    public function test_uploaded_file_is_stored_under_task_uuid_directory(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $this->validCsv()]);

        $taskUuid   = $response->json('uuid');
        $storedPath = $response->json('payload.file');

        $this->assertStringStartsWith('uploads/' . $taskUuid . '/', $storedPath);
        Storage::disk('local')->assertExists($storedPath);
    }

    public function test_generate_invoice_job_is_dispatched_exactly_once(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $this->validCsv()]);

        Queue::assertPushed(GenerateInvoiceJob::class, 1);
    }

    public function test_dispatched_job_carries_the_correct_task(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $this->validCsv()]);

        $taskUuid = $response->json('uuid');

        Queue::assertPushed(GenerateInvoiceJob::class, function (GenerateInvoiceJob $job) use ($taskUuid) {
            return $job->task->uuid === $taskUuid;
        });
    }

    public function test_csv_with_extra_columns_is_accepted(): void
    {
        Queue::fake();
        Storage::fake('local');

        $content = "description,quantity,unit_price,notes\nWidget A,2,9.99,urgent\n";
        $file    = UploadedFile::fake()->createWithContent('invoice.csv', $content);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $file])
            ->assertStatus(201);
    }

    // -------------------------------------------------------------------------
    // Group B — Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/invoices', ['file' => $this->validCsv()])
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Group C — Form request validation
    // -------------------------------------------------------------------------

    public function test_missing_file_field_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_non_csv_file_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', [
                'file' => UploadedFile::fake()->create('document.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_file_exceeding_5mb_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', [
                'file' => UploadedFile::fake()->create('invoice.csv', 6000, 'text/csv'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    // -------------------------------------------------------------------------
    // Group D — Header validation (TaskService boundary, returns 422)
    // -------------------------------------------------------------------------

    public function test_csv_missing_unit_price_column_returns_422(): void
    {
        Storage::fake('local');

        $content = "description,quantity\nWidget A,2\n";
        $file    = UploadedFile::fake()->createWithContent('invoice.csv', $content);
        $user    = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_csv_missing_quantity_column_returns_422(): void
    {
        Storage::fake('local');

        $content = "description,unit_price\nWidget A,9.99\n";
        $file    = UploadedFile::fake()->createWithContent('invoice.csv', $content);
        $user    = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_csv_missing_description_column_returns_422(): void
    {
        Storage::fake('local');

        $content = "quantity,unit_price\n2,9.99\n";
        $file    = UploadedFile::fake()->createWithContent('invoice.csv', $content);
        $user    = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_no_task_record_created_when_header_validation_fails(): void
    {
        Storage::fake('local');

        $content = "description,quantity\nWidget A,2\n";
        $file    = UploadedFile::fake()->createWithContent('invoice.csv', $content);
        $user    = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $file]);

        $this->assertDatabaseMissing('tasks', [
            'user_id' => $user->id,
            'type'    => TaskType::InvoiceGeneration->value,
        ]);
    }

    public function test_no_file_stored_on_disk_when_header_validation_fails(): void
    {
        Storage::fake('local');

        $content = "description,quantity\nWidget A,2\n";
        $file    = UploadedFile::fake()->createWithContent('invoice.csv', $content);
        $user    = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices', ['file' => $file]);

        $this->assertEmpty(Storage::disk('local')->allFiles('uploads'));
    }
}
