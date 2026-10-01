<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentAcknowledged extends Notification
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $school = $this->payment->account->school ?? $notifiable->school;

        return (new MailMessage)
            ->subject('Fee payment acknowledged — '.$this->payment->receipt_number)
            ->greeting('Dear '.$notifiable->name)
            ->line('We have received a payment of KES '.number_format((float) $this->payment->amount, 2).'.')
            ->line('Receipt number: '.$this->payment->receipt_number)
            ->line('Method: '.strtoupper($this->payment->method))
            ->line($school?->name.' thanks you. Keep this receipt as your tracking reference.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'receipt_number' => $this->payment->receipt_number,
            'amount' => $this->payment->amount,
        ];
    }
}
