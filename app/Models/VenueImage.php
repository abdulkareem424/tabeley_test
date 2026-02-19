<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VenueImage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'venue_images';

    protected $fillable = [
        'venue_id',
        'url',
        'sort_order',
    ];
}
