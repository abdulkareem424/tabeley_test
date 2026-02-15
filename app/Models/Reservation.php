<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\ReservationStatusHistory;
use App\Models\ReservationFee;
use App\Models\ReservationTableAssignment;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'customer_id',
        'venue_id',
        'reservation_date',
        'reservation_time',
        'party_size',
        'status',
        'rejection_reason',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(ReservationStatusHistory::class);
    }

    public function fee()
    {
        return $this->hasOne(ReservationFee::class);
    }

    public function tableAssignment()
    {
        return $this->hasOne(ReservationTableAssignment::class);
    }
}
