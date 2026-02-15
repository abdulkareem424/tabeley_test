<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // LIST USERS (admin)
    public function users(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $perPage = $validated['per_page'] ?? 50;

        $paginator = User::query()
            ->with(['roles:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = collect($paginator->items())->map(function (User $user) {
            return [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('name')->values(),
                'created_at' => $user->created_at,
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

    // LIST RESERVATIONS (admin)
    public function reservations(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $perPage = $validated['per_page'] ?? 50;

        $paginator = Reservation::query()
            ->with([
                'venue:id,name,type,address_text',
                'customer:id,first_name,last_name,email,phone',
            ])
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = collect($paginator->items())->map(function (Reservation $reservation) {
            return [
                'id' => $reservation->id,
                'code' => $reservation->code,
                'reservation_date' => $reservation->reservation_date,
                'reservation_time' => $reservation->reservation_time,
                'party_size' => $reservation->party_size,
                'status' => $reservation->status,
                'rejection_reason' => $reservation->rejection_reason,
                'created_at' => $reservation->created_at,
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
                    'email' => $reservation->customer->email,
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
}
