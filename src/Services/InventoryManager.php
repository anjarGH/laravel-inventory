<?php
namespace ESolution\Inventory\Services;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\DTO\{DocumentData, LineData};
use ESolution\Inventory\Models\{Document, DocumentLine};
use ESolution\Inventory\Enums\DocumentType;
use ESolution\Inventory\Services\{CostingManager, MovementPipeline, JournalManager};

class InventoryManager
{
    public function __construct(
        protected $app
    ){}

    public function post(DocumentData $docData)
    {
        return DB::transaction(function() use ($docData){
            if ($docData->external_id) {
                $existing = Document::query()
                    ->where('external_id', $docData->external_id)
                    ->with('lines')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $doc = Document::create([
                'external_id'=>$docData->external_id,
                'type'=>$docData->type,
                'date'=>$docData->date,
                'ref'=>$docData->ref,
                'meta'=>$docData->meta,
            ]);

            $lines = [];
            foreach ($docData->lines as $ld) {
                /** @var LineData $ld */
                $lines[] = DocumentLine::create([
                    'document_id'=>$doc->id,
                    'item_id'=>$ld->itemId,
                    'branch_id'=>$ld->branchId,
                    'warehouse_id'=>$ld->warehouseId,
                    'rack_id'=>$ld->rackId,
                    'qty'=>$ld->qty,
                    'unit_cost'=>$ld->unitCost,
                    'meta'=>$ld->meta,
                ]);
            }
            $doc->setRelation('lines', collect($lines));

            return match($docData->type){
                DocumentType::PURCHASE->value        => (new \ESolution\Inventory\Actions\PostPurchase($this))->handle($doc),
                DocumentType::SALE->value            => (new \ESolution\Inventory\Actions\PostSale($this))->handle($doc),
                DocumentType::PURCHASE_RETURN->value => (new \ESolution\Inventory\Actions\PostPurchaseReturn($this))->handle($doc),
                DocumentType::SALES_RETURN->value    => (new \ESolution\Inventory\Actions\PostSalesReturn($this))->handle($doc),
                DocumentType::STOCK_OPNAME->value    => (new \ESolution\Inventory\Actions\PostStockOpname($this))->handle($doc),
                DocumentType::CONSIGNMENT->value     => (new \ESolution\Inventory\Actions\PostConsignment($this))->handle($doc),
                DocumentType::TRANSFER_RACK->value   => (new \ESolution\Inventory\Actions\PostTransferRack($this))->handle($doc),
                DocumentType::TRANSFER_WAREHOUSE->value => (new \ESolution\Inventory\Actions\PostTransferWarehouse($this))->handle($doc),
                DocumentType::TRANSFER_BRANCH->value => (new \ESolution\Inventory\Actions\PostTransferBranch($this))->handle($doc),
                default => $doc,
            };
        });

        app(\ESolution\Inventory\Services\StockCardManager::class)->generateForDocument($document);

        return $document;
    }

    // quick helpers
    public function costingDriver($line){ return app(CostingManager::class)->driverFor($line); }
    public function pipeline(){ return app(MovementPipeline::class); }
    public function journal(){ return app(JournalManager::class); }

    // Transfer helpers (simple wrappers around post())
    public function transferRack(array $params)
    {
        return $this->post($this->documentFromParams(DocumentType::TRANSFER_RACK, $params));
    }

    public function transferWarehouse(array $params)
    {
        return $this->post($this->documentFromParams(DocumentType::TRANSFER_WAREHOUSE, $params));
    }

    public function transferBranch(array $params)
    {
        return $this->post($this->documentFromParams(DocumentType::TRANSFER_BRANCH, $params));
    }

    protected function documentFromParams(DocumentType $type, array $params): DocumentData
    {
        $lines = array_map(function (array $line): LineData {
            return new LineData(
                itemId: $line['item_id'],
                branchId: $line['branch_id'],
                warehouseId: $line['warehouse_id'],
                rackId: $line['rack_id'] ?? null,
                qty: (float) $line['qty'],
                unitCost: isset($line['unit_cost']) ? (float) $line['unit_cost'] : null,
                meta: $line['meta'] ?? [],
            );
        }, $params['lines'] ?? []);

        return new DocumentData(
            type: $type->value,
            date: $params['date'],
            ref: $params['ref'] ?? null,
            external_id: $params['external_id'] ?? null,
            lines: $lines,
            meta: $params['meta'] ?? [],
        );
    }
}
