<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Role;
use App\Models\Venue;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use App\Models\Notification;
use App\Models\UserFeedback;


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
    'first_name',
    'last_name',
    'email',
    'phone',
    'password_hash',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function venues()
    {
        return $this->hasMany(Venue::class, Venue::ownerColumn());
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'customer_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function feedbacks()
    {
        return $this->hasMany(UserFeedback::class, 'user_id');
    }

    public function isBlocked(): bool
    {
        if ($this->blocked_permanent) {
            return true;
        }

        if ($this->blocked_until && Carbon::parse($this->blocked_until)->isFuture()) {
            return true;
        }

        return false;
    }
}
