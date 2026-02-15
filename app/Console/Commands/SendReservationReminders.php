<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Reservation;
use App\Models\ReservationReminder;
use App\Models\Notification;
use Illuminate\Support\Carbon;

class SendReservationReminders extends Command
{
    protected $signature = 'reservations:send-reminders';
    protected $description = 'Send reservation reminders before the reservation time';

    public function handle(): int
    {
        $minutes = (int) env('RESERVATION_REMINDER_MINUTES', 60);
        if ($minutes < 1) {
            $minutes = 60;
        }

        $now = now();
        $windowEnd = $now->copy()->addMinutes($minutes);

        $reservations = Reservation::query()
            ->where('status', 'approved')
            ->whereRaw(
                "STR_TO_DATE(CONCAT(reservation_date,' ',reservation_time), '%Y-%m-%d %H:%i:%s') BETWEEN ? AND ?",
                [$now->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')]
            )
            ->get();

        foreach ($reservations as $reservation) {
            $exists = ReservationReminder::where('reservation_id', $reservation->id)->exists();
            if ($exists) {
                continue;
            }

            $reservationDateTime = Carbon::parse($reservation->reservation_date . ' ' . $reservation->reservation_time);
            $sendAt = $reservationDateTime->copy()->subMinutes($minutes);

            ReservationReminder::create([
                'reservation_id' => $reservation->id,
                'send_at' => $sendAt,
                'sent_at' => now(),
            ]);

            Notification::create([
                'user_id' => $reservation->customer_id,
                'type' => 'reservation_reminder',
                'title' => 'Reservation reminder',
                'body' => 'Your reservation is coming up in about ' . $minutes . ' minutes.',
                'data_json' => [
                    'reservation_id' => $reservation->id,
                    'venue_id' => $reservation->venue_id,
                    'reservation_date' => $reservation->reservation_date,
                    'reservation_time' => $reservation->reservation_time,
                ],
            ]);
        }

        return Command::SUCCESS;
    }
}
