<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Reservation;
use App\Models\SeatingArea;
use App\Models\VenueTable;

class Venue extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'name',
        'type',
        'description',
        'address_text',
        'lat',
        'lng',
        'is_active',
        'address',
        'phone',
        'amenities',
        'image_urls',
        'offers',
    ];

    protected $casts = [
        'amenities' => 'array',
        'image_urls' => 'array',
        'offers' => 'array',
    ];

    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function seatingAreas()
    {
        return $this->hasMany(SeatingArea::class);
    }

    public function tables()
    {
        return $this->hasMany(VenueTable::class);
    }
}
