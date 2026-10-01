<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentResultsReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $studentName,
        public string $schoolName,
        public string $reportPdf,
        public string $filename,
    ) {}

    public function build(): static
    {
        return $this->subject($this->schoolName.' results report · '.$this->studentName)
            ->view('academics.report-email')
            ->with(['studentName' => $this->studentName, 'schoolName' => $this->schoolName])
            ->attachData($this->reportPdf, $this->filename, ['mime' => 'application/pdf']);
    }
}
