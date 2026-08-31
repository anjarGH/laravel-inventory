<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\Document;

class PostConsignment extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            foreach ($doc->lines as $line){
                $qty = $line->qty;
                $actualUnit = $this->costing($line)->consume($line, $qty);

                // send to consignment stage (on-balance default)
                $this->pipeline()->move($line, $qty, 'gudang', 'konsinyasi', $actualUnit, 'out');
                // no journal by default; customize as needed
            }
            return $doc;
        });
    }
}
