<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model {
    protected $table = 'inv_items';
    protected $fillable = ['sku','name','item_type_id','valuation'];

    public function itemType()
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }
}
