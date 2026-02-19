<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Schema;

class ReservationTableAssignment extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected static ?string $resolvedTable = null;
    protected static ?string $resolvedTableForeignKey = null;

    protected $fillable = [
        'reservation_id',
        'table_id',
        'venue_table_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = self::tableName();
    }

    public static function tableName(): string
    {
        if (self::$resolvedTable !== null) {
            return self::$resolvedTable;
        }

        self::$resolvedTable = Schema::hasTable('reservation_table_assignments')
            ? 'reservation_table_assignments'
            : 'reservation_tables';

        return self::$resolvedTable;
    }

    public static function tableForeignKey(): string
    {
        if (self::$resolvedTableForeignKey !== null) {
            return self::$resolvedTableForeignKey;
        }

        self::$resolvedTableForeignKey = Schema::hasColumn(self::tableName(), 'venue_table_id')
            ? 'venue_table_id'
            : 'table_id';

        return self::$resolvedTableForeignKey;
    }

    public function setVenueTableIdAttribute($value): void
    {
        $this->attributes[self::tableForeignKey()] = $value;
    }

    public function getVenueTableIdAttribute()
    {
        $column = self::tableForeignKey();
        return $this->attributes[$column] ?? null;
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function table()
    {
        return $this->belongsTo(VenueTable::class, self::tableForeignKey());
    }
}
