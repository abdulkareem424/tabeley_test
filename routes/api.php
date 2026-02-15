<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\AdminController;
use Illuminate\Http\Request;

Route::any('/register', function (Request $request) {
    if ($request->isMethod('post')) {
        return app(AuthController::class)->register($request);
    }

    return response()->json([
        'message' => 'Method Not Allowed. Use POST /api/register.',
        'allowed' => ['POST'],
    ], 405);
});

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);
Route::middleware('auth:sanctum')->patch('/me', [AuthController::class, 'updateProfile']);

Route::middleware(['auth:sanctum', 'role:customer'])->get('/customer-only', function () {
    return response()->json(['ok' => true]);
});

Route::get('/venues', [VenueController::class, 'index']);
Route::get('/venues/{id}', [VenueController::class, 'show']);
Route::middleware(['auth:sanctum', 'role:vendor'])->post('/venues', [VenueController::class, 'store']);
Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin/venues', [VenueController::class, 'adminIndex']);
Route::middleware(['auth:sanctum', 'role:admin'])->post('/admin/venues', [VenueController::class, 'adminStore']);
Route::middleware(['auth:sanctum', 'role:admin'])->put('/admin/venues/{id}', [VenueController::class, 'adminUpdate']);
Route::middleware(['auth:sanctum', 'role:admin'])->delete('/admin/venues/{id}', [VenueController::class, 'adminDestroy']);

Route::middleware(['auth:sanctum', 'role:customer'])->post('/reservations', [ReservationController::class, 'store']);
Route::middleware(['auth:sanctum', 'role:customer'])->get('/reservations', [ReservationController::class, 'myReservations']);

Route::middleware(['auth:sanctum', 'role:vendor'])->get('/vendor-reservations', [ReservationController::class, 'vendorReservations']);
Route::middleware(['auth:sanctum', 'role:vendor'])->patch('/reservations/{id}/approve', [ReservationController::class, 'approve']);
Route::middleware(['auth:sanctum', 'role:vendor'])->patch('/reservations/{id}/reject', [ReservationController::class, 'reject']);
Route::middleware(['auth:sanctum', 'role:customer'])->patch('/reservations/{id}/cancel', [ReservationController::class, 'cancelByCustomer']);
Route::middleware(['auth:sanctum', 'role:vendor'])->patch('/reservations/{id}/cancel-by-venue', [ReservationController::class, 'cancelByVendor']);
Route::middleware(['auth:sanctum', 'role:vendor'])->patch('/reservations/{id}/no-show', [ReservationController::class, 'markNoShow']);
Route::middleware(['auth:sanctum', 'role:vendor'])->patch('/reservations/{id}/complete', [ReservationController::class, 'markCompleted']);

Route::middleware(['auth:sanctum'])->get('/notifications', [NotificationController::class, 'index']);
Route::middleware(['auth:sanctum', 'role:customer'])->post('/feedback', [FeedbackController::class, 'store']);
Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin/feedback', [FeedbackController::class, 'adminIndex']);
Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin/users', [AdminController::class, 'users']);
Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin/reservations', [AdminController::class, 'reservations']);
