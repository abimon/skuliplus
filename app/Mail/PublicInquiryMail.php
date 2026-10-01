<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PublicInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $kind,
        public array $inquiry,
    ) {}

    public function build(): static
    {
        return $this->subject('SchoolMS '.$this->kind)
            ->view('emails.public-inquiry')
            ->with(['kind' => $this->kind, 'inquiry' => $this->inquiry]);
    }
}
