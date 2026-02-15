<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VenueTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'seating_area_id',
        'name',
        'capacity',
        'is_active',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function seatingArea()
    {
        return $this->belongsTo(SeatingArea::class);
    }

    public function assignments()
    {
        return $this->hasMany(ReservationTableAssignment::class);
    }
}
