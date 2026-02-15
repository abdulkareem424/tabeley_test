<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReservationFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'pricing_rule_id',
        'price_per_person',
        'party_size',
        'total_amount',
        'currency',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function pricingRule()
    {
        return $this->belongsTo(PricingRule::class);
    }
}
