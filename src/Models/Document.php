<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read Collection<int, DocumentLine> $lines */
class Document extends Model {
    protected $table = 'inv_documents';
    protected $fillable = ['external_id','type','date','ref','meta'];
    protected $casts = ['meta'=>'array'];
    public function lines(): HasMany { return $this->hasMany(DocumentLine::class,'document_id'); }
}
