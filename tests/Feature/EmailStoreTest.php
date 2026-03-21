<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\SendBulkEmailJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailStoreTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'subject'    => 'Hello from AsyncOps',
            'body'       => '<p>This is the email body.</p>',
            'recipients' => ['alice@example.com', 'bob@example.com'],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Group A — Happy path
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_create_bulk_email_task_and_gets_201(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonFragment([
                'type'   => TaskType::BulkEmail->value,
                'status' => 'pending',
            ]);
    }

    public function test_response_contains_valid_uuid(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload());

        $this->assertTrue(Str::isUuid($response->json('uuid')));
    }

    public function test_task_record_is_persisted_with_correct_fields(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload());

        $this->assertDatabaseHas('tasks', [
            'user_id'  => $user->id,
            'type'     => TaskType::BulkEmail->value,
            'status'   => 'pending',
            'progress' => 0,
        ]);
    }

    public function test_task_belongs_to_the_authenticated_user(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user     = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload());

        $task = Task::where('uuid', $response->json('uuid'))->first();
        $this->assertSame($user->id, $task->user_id);
    }

    public function test_response_payload_contains_subject_body_and_recipients(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'subject'    => 'My Subject',
                'recipients' => ['alice@example.com'],
            ]));

        $response->assertJsonPath('payload.subject', 'My Subject');
        $response->assertJsonPath('payload.recipients.0', 'alice@example.com');
        $this->assertNotNull($response->json('payload.body'));
    }

    public function test_happy_path_with_pdf_attachment_returns_201(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', array_merge($this->validPayload(), [
                'attachment' => UploadedFile::fake()->create('invoice.pdf', 500, 'application/pdf'),
            ]));

        $response->assertStatus(201);
        $this->assertNotNull($response->json('payload.attachment_path'));
    }

    public function test_body_is_sanitised_and_script_tags_are_stripped(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'body' => '<script>alert(1)</script><p>Hello</p>',
            ]));

        $response->assertStatus(201);
        $this->assertStringNotContainsString('<script>', $response->json('payload.body'));
        $this->assertStringContainsString('<p>Hello</p>', $response->json('payload.body'));
    }

    // -------------------------------------------------------------------------
    // Group B — Batch dispatch
    // -------------------------------------------------------------------------

    public function test_one_send_bulk_email_job_dispatched_per_recipient(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'recipients' => ['alice@example.com', 'bob@example.com'],
            ]));

        Bus::assertBatched(fn ($batch) =>
            $batch->jobs->count() === 2 &&
            $batch->jobs->every(fn ($job) => $job instanceof SendBulkEmailJob)
        );
    }

    public function test_batch_id_is_present_in_response_payload(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload());

        $this->assertNotNull($response->json('payload.batch_id'));
    }

    // -------------------------------------------------------------------------
    // Group C — Auth
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/emails', $this->validPayload())
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Group D — Validation
    // -------------------------------------------------------------------------

    public function test_missing_subject_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload(['subject' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);
    }

    public function test_subject_exceeding_255_chars_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload(['subject' => str_repeat('a', 256)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);
    }

    public function test_missing_body_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload(['body' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_whitespace_only_body_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload(['body' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_body_exceeding_10000_chars_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload(['body' => str_repeat('a', 10001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_missing_recipients_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload(['recipients' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients']);
    }

    public function test_recipients_not_array_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload(['recipients' => 'alice@example.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients']);
    }

    public function test_recipients_exceeding_max_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'recipients' => ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients']);
    }

    public function test_duplicate_recipients_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'recipients' => ['alice@example.com', 'alice@example.com'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients.0']);
    }

    public function test_case_insensitive_duplicate_recipients_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'recipients' => ['Alice@example.com', 'alice@example.com'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients.0']);
    }

    public function test_invalid_email_in_recipients_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'recipients' => ['not-an-email'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients.0']);
    }

    public function test_email_with_display_name_in_recipients_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', $this->validPayload([
                'recipients' => ['"Alice Smith" <alice@example.com>'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients.0']);
    }

    public function test_non_pdf_attachment_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', array_merge($this->validPayload(), [
                'attachment' => UploadedFile::fake()->create('document.txt', 10, 'text/plain'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);
    }

    public function test_attachment_over_10mb_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/emails', array_merge($this->validPayload(), [
                'attachment' => UploadedFile::fake()->create('large.pdf', 11000, 'application/pdf'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);
    }

    // -------------------------------------------------------------------------
    // Group E — Authorization
    // -------------------------------------------------------------------------

    public function test_another_user_cannot_view_someone_elses_email_task(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $task = Task::factory()->pending()->create([
            'user_id' => $owner->id,
            'type'    => TaskType::BulkEmail->value,
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/tasks/' . $task->uuid)
            ->assertStatus(403);
    }

    public function test_another_user_cannot_download_someone_elses_email_report(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $task = Task::factory()->completed()->create([
            'user_id'     => $owner->id,
            'type'        => TaskType::BulkEmail->value,
            'result_path' => 'emails/some-uuid/report.csv',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('emails/some-uuid/report.csv', "recipient,status\nalice@example.com,sent\n");

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/tasks/' . $task->uuid . '/download')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Group F — Download metadata
    // -------------------------------------------------------------------------

    public function test_download_returns_csv_content_type_and_correct_filename(): void
    {
        $user = User::factory()->create();

        $task = Task::factory()->completed()->create([
            'user_id'     => $user->id,
            'type'        => TaskType::BulkEmail->value,
            'result_path' => 'emails/' . 'some-uuid' . '/report.csv',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('emails/some-uuid/report.csv', "recipient,status\nalice@example.com,sent\n");

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/tasks/' . $task->uuid . '/download');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'email-report-' . $task->uuid . '.csv',
            $response->headers->get('Content-Disposition')
        );
    }
}
