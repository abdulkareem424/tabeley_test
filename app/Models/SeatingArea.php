<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SeatingArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'name',
        'description',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function tables()
    {
        return $this->hasMany(VenueTable::class);
    }
}
