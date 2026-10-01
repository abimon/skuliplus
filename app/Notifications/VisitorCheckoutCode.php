<?php

namespace App\Notifications;

use App\Models\Visitor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorCheckoutCode extends Notification
{
    use Queueable;

    public function __construct(public Visitor $visitor) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('School visit checkout code')
            ->greeting('Hello '.$this->visitor->full_name)
            ->line('Your unique checkout code is: '.$this->visitor->checkout_code)
            ->line('Present this code at the gate when leaving.');
    }
}
