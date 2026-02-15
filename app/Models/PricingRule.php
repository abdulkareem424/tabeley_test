<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PricingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'venue_type',
        'venue_id',
        'price_per_person',
        'is_active',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
