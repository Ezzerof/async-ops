<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BulkEmailMailable extends Mailable
{
    public function __construct(
        string                  $subject,
        public readonly string  $body,
        public readonly ?string $attachmentPath,
    ) {
        $this->subject = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bulk',
            with: ['subject' => $this->subject],
        );
    }

    public function attachments(): array
    {
        if ($this->attachmentPath === null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->attachmentPath),
        ];
    }
}
