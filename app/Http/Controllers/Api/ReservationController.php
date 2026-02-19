<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Notification;
use App\Models\ReservationStatusHistory;
use App\Models\ReservationFee;
use App\Models\PricingRule;
use App\Models\ReservationTableAssignment;
use App\Models\VenueTable;
use App\Models\ReservationReminder;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    // LIST VENDOR RESERVATIONS (vendor)
    public function vendorReservations(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $perPage = $validated['per_page'] ?? 10;

        $query = Reservation::query()
            ->whereHas('venue', function ($q) use ($request) {
                $q->where(Venue::ownerColumn(), $request->user()->id);
            })
            ->with([
                'venue:id,name,type,address_text',
                'customer:id,first_name,last_name,phone',
            ])
            ->orderByDesc('id');

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(function ($reservation) {
            return [
                'id' => $reservation->id,
                'code' => $reservation->code,
                'reservation_date' => $reservation->reservation_date,
                'reservation_time' => $reservation->reservation_time,
                'party_size' => $reservation->party_size,
                'status' => $reservation->status,
                'rejection_reason' => $reservation->rejection_reason,
                'venue' => $reservation->venue ? [
                    'id' => $reservation->venue->id,
                    'name' => $reservation->venue->name,
                    'type' => $reservation->venue->type,
                    'address_text' => $reservation->venue->address_text,
                ] : null,
                'customer' => $reservation->customer ? [
                    'id' => $reservation->customer->id,
                    'first_name' => $reservation->customer->first_name,
                    'last_name' => $reservation->customer->last_name,
                    'phone' => $reservation->customer->phone,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    // APPROVE RESERVATION (vendor)
    public function approve(Request $request, int $id)
    {
        $reservation = null;
        $table = null;

        try {
            DB::transaction(function () use ($request, $id, &$reservation, &$table) {
                $reservation = Reservation::query()
                    ->where('id', $id)
                    ->whereHas('venue', function ($q) use ($request) {
                        $q->where(Venue::ownerColumn(), $request->user()->id);
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $reservation) {
                    throw new \RuntimeException('Reservation not found.', 404);
                }

                $table = $reservation->tableAssignment?->table;
                if (! $table) {
                    $table = $this->findAvailableTable($reservation, true, true);
                    if (! $table) {
                        throw new \RuntimeException('No available table for this reservation.', 409);
                    }

                    ReservationTableAssignment::create([
                        'reservation_id' => $reservation->id,
                        'venue_table_id' => $table->id,
                    ]);
                }

                $this->updateStatus($reservation, 'approved', $request->user()->id);
                $this->notifyCustomer(
                    $reservation,
                    'reservation_approved',
                    'Reservation approved',
                    'Your reservation has been approved.'
                );
            });
        } catch (\RuntimeException $e) {
            $status = $e->getCode() >= 400 ? $e->getCode() : 409;
            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response()->json([
            'message' => 'Reservation approved successfully',
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'rejection_reason' => $reservation->rejection_reason,
                'table' => [
                    'id' => $table->id,
                    'name' => $table->name,
                    'capacity' => $table->capacity,
                ],
            ],
        ]);
    }

    // REJECT RESERVATION (vendor)
    public function reject(Request $request, int $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $reservation = $this->findVendorReservation($request, $id);
        if (! $reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        $this->updateStatus($reservation, 'rejected', $request->user()->id, $validated['reason']);
        $this->notifyCustomer(
            $reservation,
            'reservation_rejected',
            'Reservation rejected',
            $validated['reason']
        );

        return response()->json([
            'message' => 'Reservation rejected',
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'rejection_reason' => $reservation->rejection_reason,
            ],
        ]);
    }

    // CANCEL RESERVATION (customer)
    public function cancelByCustomer(Request $request, int $id)
    {
        $reservation = Reservation::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($id);

        if (! $this->canCancel($reservation)) {
            return response()->json([
                'message' => 'Reservation cannot be cancelled.',
            ], 422);
        }

        if (! in_array($reservation->status, ['pending', 'approved'], true)) {
            return response()->json([
                'message' => 'Reservation cannot be cancelled.',
            ], 422);
        }

        $this->updateStatus($reservation, 'cancelled_by_customer', $request->user()->id);

        return response()->json([
            'message' => 'Reservation cancelled successfully',
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
            ],
        ]);
    }

    // CANCEL RESERVATION (vendor)
    public function cancelByVendor(Request $request, int $id)
    {
        $reservation = $this->findVendorReservation($request, $id);
        if (! $reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        if ($reservation->status === 'completed') {
            return response()->json([
                'message' => 'Reservation cannot be cancelled.',
            ], 422);
        }

        $this->updateStatus($reservation, 'cancelled_by_venue', $request->user()->id);

        return response()->json([
            'message' => 'Reservation cancelled by venue',
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
            ],
        ]);
    }

    private function findVendorReservation(Request $request, int $id): ?Reservation
    {
        return Reservation::query()
            ->whereHas('venue', function ($q) use ($request) {
                $q->where(Venue::ownerColumn(), $request->user()->id);
            })
            ->find($id);
    }
    // LIST MY RESERVATIONS (customer)
    public function myReservations(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $perPage = $validated['per_page'] ?? 10;

        $query = Reservation::query()
            ->where('customer_id', $request->user()->id)
            ->with([
                'venue:id,name,type,description,address_text,lat,lng',
            ])
            ->orderByDesc('id');

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(function ($reservation) {
            return [
                'id' => $reservation->id,
                'code' => $reservation->code,
                'reservation_date' => $reservation->reservation_date,
                'reservation_time' => $reservation->reservation_time,
                'party_size' => $reservation->party_size,
                'status' => $reservation->status,
                'venue' => $reservation->venue ? [
                    'id' => $reservation->venue->id,
                    'name' => $reservation->venue->name,
                    'type' => $reservation->venue->type,
                    'address_text' => $reservation->venue->address_text,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    // CREATE RESERVATION (customer)
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->isBlocked()) {
            return response()->json(['message' => 'You are temporarily blocked from making reservations.'], 403);
        }

        $validated = $request->validate([
            'venue_id' => 'required|integer|exists:venues,id',
            'reservation_date' => 'required|date_format:Y-m-d',
            'reservation_time' => 'required|date_format:H:i',
            'party_size' => 'required|integer|min:1|max:50',
        ]);

        $venue = Venue::where('id', $validated['venue_id'])
            ->where('is_active', true)
            ->first();

        if (! $venue) {
            return response()->json(['message' => 'Venue not found.'], 404);
        }

        $reservation = null;
        $fee = null;
        $table = null;
        $reminder = null;

        try {
            DB::transaction(function () use ($validated, $user, &$reservation, &$fee, &$table, &$reminder) {
                $reservation = Reservation::create([
                    'code' => $this->generateCode(),
                    'customer_id' => $user->id,
                    'venue_id' => $validated['venue_id'],
                    'reservation_date' => $validated['reservation_date'],
                    'reservation_time' => $validated['reservation_time'],
                    'party_size' => $validated['party_size'],
                    'status' => 'pending',
                    'rejection_reason' => null,
                ]);

                $table = $this->findAvailableTable($reservation, true, true);
                if (! $table) {
                    throw new \RuntimeException('No available table for this reservation.');
                }

                ReservationTableAssignment::create([
                    'reservation_id' => $reservation->id,
                    'venue_table_id' => $table->id,
                ]);

                $fee = $this->createReservationFee($reservation);
                $reminder = $this->createReservationReminder($reservation);
                $this->logStatusChange($reservation, null, 'pending', $user->id);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'reservation' => [
                    'id' => $reservation->id,
                    'code' => $reservation->code,
                    'status' => $reservation->status,
                    'reservation_date' => $reservation->reservation_date,
                    'reservation_time' => $reservation->reservation_time,
                    'party_size' => $reservation->party_size,
                ],
                'fee' => [
                    'price_per_person' => $fee->price_per_person,
                    'party_size' => $fee->party_size,
                    'total_amount' => $fee->total_amount,
                    'currency' => $fee->currency,
                ],
                'table' => [
                    'id' => $table->id,
                    'name' => $table->name,
                    'capacity' => $table->capacity,
                ],
                'reminder' => [
                    'send_at' => $reminder->send_at,
                ],
            ],
        ], 201);
    }

    private function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Reservation::where('code', $code)->exists());

        return $code;
    }

    private function createReservationFee(Reservation $reservation): ReservationFee
    {
        $venue = $reservation->venue ?: Venue::find($reservation->venue_id);

        $rule = $this->getPricingRule($venue);
        $pricePerPerson = $rule ? (float) $rule->price_per_person : 0.0;
        $total = $pricePerPerson * (int) $reservation->party_size;
        $currency = env('APP_CURRENCY', 'USD');

        return ReservationFee::create([
            'reservation_id' => $reservation->id,
            'pricing_rule_id' => $rule?->id,
            'price_per_person' => $pricePerPerson,
            'party_size' => $reservation->party_size,
            'total_amount' => $total,
            'currency' => $currency,
        ]);
    }

    private function getPricingRule(?Venue $venue): ?PricingRule
    {
        if (! $venue) {
            return PricingRule::where('scope', 'global')->where('is_active', true)->latest('id')->first();
        }

        $venueRule = PricingRule::where('scope', 'venue')
            ->where('venue_id', $venue->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($venueRule) {
            return $venueRule;
        }

        $typeRule = PricingRule::where('scope', 'type')
            ->where('venue_type', $venue->type)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($typeRule) {
            return $typeRule;
        }

        return PricingRule::where('scope', 'global')->where('is_active', true)->latest('id')->first();
    }

    private function findAvailableTable(Reservation $reservation, bool $includePending, bool $lockRows = false): ?VenueTable
    {
        $durationMinutes = (int) env('RESERVATION_DURATION_MINUTES', 90);
        if ($durationMinutes < 30) {
            $durationMinutes = 90;
        }

        $start = Carbon::parse($reservation->reservation_date . ' ' . $reservation->reservation_time);
        $end = $start->copy()->addMinutes($durationMinutes);

        $tablesQuery = VenueTable::query()
            ->where('venue_id', $reservation->venue_id)
            ->where('is_active', true)
            ->where('capacity', '>=', $reservation->party_size)
            ->orderBy('capacity');

        if ($lockRows) {
            $tablesQuery->lockForUpdate();
        }

        $tables = $tablesQuery->get();

        if ($tables->isEmpty()) {
            return null;
        }

        $tableIds = $tables->pluck('id')->all();

        $assignedQuery = ReservationTableAssignment::query()
            ->whereIn('venue_table_id', $tableIds)
            ->whereHas('reservation', function ($q) use ($reservation, $includePending) {
                $statuses = ['approved', 'completed'];
                if ($includePending) {
                    $statuses[] = 'pending';
                }
                $q->where('venue_id', $reservation->venue_id)
                    ->whereIn('status', $statuses);
            })
            ->with('reservation');

        if ($lockRows) {
            $assignedQuery->lockForUpdate();
        }

        $assigned = $assignedQuery->get();

        foreach ($tables as $table) {
            $conflict = false;
            foreach ($assigned as $assign) {
                if ($assign->venue_table_id !== $table->id) {
                    continue;
                }

                $rStart = Carbon::parse($assign->reservation->reservation_date . ' ' . $assign->reservation->reservation_time);
                $rEnd = $rStart->copy()->addMinutes($durationMinutes);

                if ($start < $rEnd && $end > $rStart) {
                    $conflict = true;
                    break;
                }
            }

            if (! $conflict) {
                return $table;
            }
        }

        return null;
    }

    private function createReservationReminder(Reservation $reservation): ReservationReminder
    {
        $minutes = (int) env('RESERVATION_REMINDER_MINUTES', 60);
        if ($minutes < 1) {
            $minutes = 60;
        }

        $dateTime = Carbon::parse($reservation->reservation_date . ' ' . $reservation->reservation_time);
        $sendAt = $dateTime->copy()->subMinutes($minutes);

        return ReservationReminder::create([
            'reservation_id' => $reservation->id,
            'send_at' => $sendAt,
            'sent_at' => null,
        ]);
    }

    // MARK NO-SHOW (vendor)
    public function markNoShow(Request $request, int $id)
    {
        $reservation = $this->findVendorReservation($request, $id);
        if (! $reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        $this->updateStatus($reservation, 'no_show', $request->user()->id);

        return response()->json([
            'message' => 'Reservation marked as no_show',
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
            ],
        ]);
    }

    // MARK COMPLETED (vendor)
    public function markCompleted(Request $request, int $id)
    {
        $reservation = $this->findVendorReservation($request, $id);
        if (! $reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        $this->updateStatus($reservation, 'completed', $request->user()->id);

        return response()->json([
            'message' => 'Reservation marked as completed',
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
            ],
        ]);
    }

    private function updateStatus(Reservation $reservation, string $newStatus, int $changedByUserId, ?string $rejectionReason = null): void
    {
        $oldStatus = $reservation->status;

        $reservation->status = $newStatus;
        $reservation->rejection_reason = $newStatus === 'rejected' ? $rejectionReason : null;
        $reservation->save();

        $this->logStatusChange($reservation, $oldStatus, $newStatus, $changedByUserId);

        if ($this->isFakeBooking($newStatus)) {
            $this->applyStrike($reservation->customer);
        }
    }

    private function notifyCustomer(Reservation $reservation, string $type, string $title, string $body): void
    {
        Notification::create([
            'user_id' => $reservation->customer_id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data_json' => [
                'reservation_id' => $reservation->id,
                'venue_id' => $reservation->venue_id,
                'status' => $reservation->status,
            ],
        ]);
    }

    private function logStatusChange(Reservation $reservation, ?string $oldStatus, string $newStatus, int $changedByUserId): void
    {
        ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by_user_id' => $changedByUserId,
            'created_at' => now(),
        ]);
    }

    private function isFakeBooking(string $status): bool
    {
        return in_array($status, ['no_show'], true);
    }

    private function applyStrike($customer): void
    {
        if (! $customer) {
            return;
        }

        $customer->strike_count = $customer->strike_count + 1;

        if ($customer->strike_count >= 9) {
            $customer->blocked_permanent = true;
        } elseif ($customer->strike_count >= 6) {
            $customer->blocked_until = now()->addDays(30);
        } elseif ($customer->strike_count >= 3) {
            $customer->blocked_until = now()->addDays(7);
        }

        $customer->save();
    }

    private function canCancel(Reservation $reservation): bool
    {
        $dateTime = Carbon::parse($reservation->reservation_date . ' ' . $reservation->reservation_time);
        return now()->lt($dateTime->subHour());
    }
}
