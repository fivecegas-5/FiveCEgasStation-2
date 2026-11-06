<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockThresholdReached extends Notification
{
    public function __construct(private readonly array $details) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("FiveCETrack low-stock alert: {$this->details['fuel']}")
            ->greeting('Low fuel stock alert')
            ->line("{$this->details['branch']} / {$this->details['tank_code']} ({$this->details['fuel']}) has reached its low-stock threshold.")
            ->line("Current stock: {$this->details['stock_liters']} L")
            ->line("Threshold: {$this->details['threshold_liters']} L");
    }

    public function toArray(object $notifiable): array
    {
        return $this->details;
    }
}
