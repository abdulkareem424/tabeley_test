<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserStrike extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'user_strikes';

    protected $fillable = [
        'user_id',
        'reservation_id',
        'venue_id',
        'type',
        'created_at',
    ];
}
