<?php

namespace Tests\Unit;

use App\Mail\BulkEmailMailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BulkEmailMailableTest extends TestCase
{
    public function test_subject_is_set_correctly_on_envelope(): void
    {
        $mailable = new BulkEmailMailable('Hello World', '<p>body</p>', null);

        $this->assertSame('Hello World', $mailable->envelope()->subject);
    }

    public function test_body_appears_in_rendered_output(): void
    {
        $mailable = new BulkEmailMailable('Subject', '<p>my content</p>', null);

        $rendered = $mailable->render();

        $this->assertStringContainsString('<p>my content</p>', $rendered);
    }

    public function test_attachments_returns_one_item_when_path_is_set(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('emails/test-uuid/attachment_abc.pdf', 'pdf-content');

        $mailable = new BulkEmailMailable('Subject', 'body', 'emails/test-uuid/attachment_abc.pdf');

        $attachments = $mailable->attachments();

        $this->assertCount(1, $attachments);
        $this->assertInstanceOf(Attachment::class, $attachments[0]);
    }

    public function test_attachments_returns_empty_array_when_path_is_null(): void
    {
        $mailable = new BulkEmailMailable('Subject', 'body', null);

        $this->assertSame([], $mailable->attachments());
    }
}
