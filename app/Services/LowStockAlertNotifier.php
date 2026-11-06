<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\FuelTank;
use App\Models\User;
use App\Notifications\LowStockThresholdReached;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class LowStockAlertNotifier
{
    public function notify(FuelTank $tank, float $stock, float $threshold): void
    {
        $tank->loadMissing(['branch', 'fuelProduct']);
        $details = [
            'event' => 'low_stock_threshold_reached',
            'branch_id' => $tank->branch_id,
            'branch' => $tank->branch->name,
            'tank_id' => $tank->id,
            'tank_code' => $tank->code,
            'fuel' => $tank->fuelProduct->name,
            'stock_liters' => round($stock, 3),
            'threshold_liters' => round($threshold, 3),
        ];

        $recipients = User::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where(function ($owner): void {
                    $owner->where('role', UserRole::Owner->value)->whereNull('branch_id');
                })->orWhere(function ($staff): void {
                    $staff->where('role', UserRole::Staff->value)->whereNotNull('branch_id');
                });
            })
            ->get();

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new LowStockThresholdReached($details));
            } catch (TransportExceptionInterface $exception) {
                Log::error('Email delivery failed for a low-stock alert; the in-app notification remains available.', [
                    'user_id' => $recipient->id,
                    'branch_id' => $tank->branch_id,
                    'tank_id' => $tank->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
