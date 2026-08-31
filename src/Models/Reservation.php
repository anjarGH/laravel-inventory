<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    protected $table = 'inv_reservations';
    protected $guarded = [];
    public function getRemainingQtyAttribute(): float
    {
        return (float) $this->reserved_qty - (float) $this->consumed_qty - (float) $this->released_qty;
    }
}
