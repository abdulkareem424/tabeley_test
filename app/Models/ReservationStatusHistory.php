<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Schema;

class ReservationStatusHistory extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected static ?string $resolvedTable = null;

    protected $fillable = [
        'reservation_id',
        'old_status',
        'new_status',
        'changed_by_user_id',
        'note',
        'created_at',
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

        self::$resolvedTable = Schema::hasTable('reservation_status_histories')
            ? 'reservation_status_histories'
            : 'reservation_status_history';

        return self::$resolvedTable;
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
