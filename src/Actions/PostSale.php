<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\Document;

class PostSale extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            $entries = [];

            foreach ($doc->lines as $line){
                $qty = $line->qty;
                $unit = $this->costing($line)->consume($line, $qty);
                $finalStage = $this->resolveSaleStage($line->item_id);

                $this->pipeline()->move($line, $qty, 'gudang', $finalStage, $unit, 'out');

                $this->mergeEntries($entries, [
                    ['account'=>inv_cfg('accounts.cogs'),'dc'=>'D','amount'=>$qty*$unit],
                    ['account'=>inv_cfg('accounts.inventory'),'dc'=>'C','amount'=>$qty*$unit],
                ]);
            }

            $this->journal()->post($doc->date, "COGS {$doc->ref}", $entries, $doc->id);

            return $doc;
        });
    }

    protected function resolveSaleStage(int $itemId): string
    {
        $pipeline = $this->pipeline();
        $trigger = inv_cfg('stage_triggers.recognize_cogs_on', 'final');

        if ($trigger === 'custom') {
            return inv_cfg('stage_triggers.custom_stage') ?: ($pipeline->finalStageForItemId($itemId) ?? 'delivered');
        }

        return $pipeline->finalStageForItemId($itemId) ?? 'delivered';
    }
}
