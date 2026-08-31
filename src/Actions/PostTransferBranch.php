<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\{Document, DocumentLine};

class PostTransferBranch extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            $entries = [];

            foreach ($doc->lines as $line){
                $qty = $line->qty;
                $unit = $this->costing($line)->consume($line, $qty);
                $this->pipeline()->move($line, $qty, 'gudang', 'intransit', $unit, 'out');

                // Optional: journal to inventory in-transit
                $this->mergeEntries($entries, [
                    ['account'=>inv_cfg('accounts.inventory_interbranch'),'dc'=>'D','amount'=>$qty*$unit],
                    ['account'=>inv_cfg('accounts.inventory'),'dc'=>'C','amount'=>$qty*$unit],
                ]);

                $dest = new DocumentLine([
                    'document_id'=>$line->document_id,
                    'item_id'=>$line->item_id,
                    'branch_id'=>$doc->meta['target_branch_id'],
                    'warehouse_id'=>$doc->meta['target_warehouse_id'],
                    'rack_id'=>$doc->meta['target_rack_id'] ?? null,
                    'qty'=>$qty,
                    'unit_cost'=>$unit,
                    'meta'=>['from_branch_id'=>$line->branch_id],
                ]);
                $dest->save();
                $this->costing($dest)->receive($dest, $qty, $unit);
                $this->pipeline()->move($dest, $qty, 'intransit', 'gudang', $unit, 'in');

                // arrival: clear in-transit
                $this->mergeEntries($entries, [
                    ['account'=>inv_cfg('accounts.inventory'),'dc'=>'D','amount'=>$qty*$unit],
                    ['account'=>inv_cfg('accounts.inventory_interbranch'),'dc'=>'C','amount'=>$qty*$unit],
                ]);
            }

            $this->journal()->post($doc->date, "Interbranch Transfer {$doc->ref}", $entries, $doc->id);

            return $doc;
        });
    }
}
