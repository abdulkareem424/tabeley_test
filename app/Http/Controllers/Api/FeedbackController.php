<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserFeedback;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    // CREATE FEEDBACK (customer)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|min:5|max:2000',
        ]);

        $feedback = UserFeedback::create([
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
        ]);

        return response()->json([
            'message' => 'Feedback sent successfully',
            'data' => [
                'id' => $feedback->id,
                'created_at' => $feedback->created_at,
            ],
        ], 201);
    }

    // LIST FEEDBACK (admin)
    public function adminIndex(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = $validated['per_page'] ?? 20;
        $paginator = UserFeedback::query()
            ->with(['user:id,first_name,last_name,email'])
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = collect($paginator->items())->map(function (UserFeedback $item) {
            return [
                'id' => $item->id,
                'message' => $item->message,
                'created_at' => $item->created_at,
                'user' => [
                    'id' => $item->user?->id,
                    'first_name' => $item->user?->first_name,
                    'last_name' => $item->user?->last_name,
                    'email' => $item->user?->email,
                ],
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
