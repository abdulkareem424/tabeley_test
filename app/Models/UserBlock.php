<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserBlock extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'user_blocks';

    protected $fillable = [
        'user_id',
        'level',
        'reason',
        'blocked_until',
        'created_by',
        'created_by_user_id',
        'trigger_venue_id',
        'is_active',
        'created_at',
    ];

    protected $casts = [
        'blocked_until' => 'datetime',
        'is_active' => 'boolean',
    ];
}
