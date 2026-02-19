<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Schema;
use App\Models\Reservation;
use App\Models\SeatingArea;
use App\Models\VenueTable;
use App\Models\Offer;
use App\Models\VenueImage;
use App\Models\Amenity;

class Venue extends Model
{
    use HasFactory;
    protected static ?string $resolvedOwnerColumn = null;

    protected $fillable = [
        'vendor_id',
        'owner_user_id',
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
        'created_by_admin_id',
    ];

    protected $casts = [
        'amenities' => 'array',
        'image_urls' => 'array',
        'offers' => 'array',
    ];

    public static function ownerColumn(): string
    {
        if (self::$resolvedOwnerColumn !== null) {
            return self::$resolvedOwnerColumn;
        }

        self::$resolvedOwnerColumn = Schema::hasColumn('venues', 'vendor_id')
            ? 'vendor_id'
            : 'owner_user_id';

        return self::$resolvedOwnerColumn;
    }

    public function setVendorIdAttribute($value): void
    {
        $this->attributes[self::ownerColumn()] = $value;
    }

    public function getVendorIdAttribute()
    {
        $column = self::ownerColumn();
        return $this->attributes[$column] ?? null;
    }

    public function vendor()
    {
        return $this->belongsTo(User::class, self::ownerColumn());
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

    public function images()
    {
        return $this->hasMany(VenueImage::class);
    }

    public function offersRelation()
    {
        return $this->hasMany(Offer::class);
    }

    public function amenitiesRelation()
    {
        return $this->belongsToMany(Amenity::class, 'venue_amenities', 'venue_id', 'amenity_id');
    }
}
