<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\CostAdjustment;
use ESolution\Inventory\Models\CostLayer;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Serial;
use ESolution\Inventory\Models\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PostingEngine
{
    public function __construct(
        private readonly DocumentTypeRegistry $documentTypes,
        private readonly WorkflowEngine $workflow,
        private readonly PolicyEngine $policies,
        private readonly ConfigurationDepthResolver $depth,
        private readonly StockCardManager $stockCards,
        private readonly AccountingBridge $accounting,
    ) {}

    public function post(DocumentData $data): Document
    {
        $hash = $this->payloadHash($data);

        return DB::transaction(function () use ($data, $hash): Document {
            $existing = $this->findIdempotentDocument($data);
            if ($existing !== null) {
                if (! hash_equals((string) $existing->idempotency_hash, $hash)) {
                    throw new \DomainException('Idempotency key was already used with a different payload.');
                }

                if (config('inventory.idempotency.mode', 'return_existing') !== 'return_existing') {
                    throw new \DomainException('Duplicate document submission is not allowed.');
                }

                return $existing->load('lines');
            }

            if (! $this->policies->evaluate('posting', $data)) {
                throw new \DomainException('Inventory posting is disabled by policy.');
            }

            $definition = $this->documentTypes->get($data->type);
            $meta = $data->meta;
            $meta['_accounting_context'] = [
                'additional_journal_lines' => $data->additionalJournalLines,
                'service_code' => $data->accountingServiceCode,
                'tenant_identity' => $data->tenantIdentity,
            ];
            $document = Document::create([
                'document_type' => $data->type,
                'organization_id' => $data->organizationId,
                'external_id' => $data->externalId,
                'idempotency_hash' => $hash,
                'party_type' => $data->partyType,
                'party_id' => $data->partyId,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'trx_date' => $data->trxDate,
                'status' => DocumentStatus::DRAFT,
                'meta' => $meta,
            ]);

            foreach ($data->lines as $index => $lineData) {
                $this->createAndValidateLine($document, $lineData, $index + 1, $definition->direction);
            }

            if ($document->lines()->count() === 0) {
                throw new \DomainException('An inventory document must contain at least one line.');
            }

            $this->workflow->transition($document, DocumentStatus::SUBMITTED);

            if ((bool) config('inventory.approval.enabled', false)) {
                $this->workflow->transition($document, DocumentStatus::WAITING_APPROVAL);

                return $document->load('lines');
            }

            $this->completePosting($document, $definition->direction, $definition->costing);

            return $document->refresh()->load('lines');
        }, 3);
    }

    public function resumeApproved(int $documentId): Document
    {
        return DB::transaction(function () use ($documentId): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($documentId);

            if ($document->posting_completed_at !== null || $document->status === DocumentStatus::POSTED) {
                return $document->load('lines');
            }

            if ($document->status !== DocumentStatus::APPROVED) {
                throw new \DomainException('Only an approved document can resume posting.');
            }

            $definition = $this->documentTypes->get($document->document_type);
            $this->completePosting($document, $definition->direction, $definition->costing);

            return $document->refresh()->load('lines');
        }, 3);
    }

    private function completePosting(Document $document, string $direction, bool $costing): void
    {
        $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
        if ($document->posting_completed_at !== null) {
            return;
        }

        $document->forceFill([
            'posting_started_at' => now(),
            'posting_marker' => 'document:' . $document->getKey(),
        ])->save();

        $stockLines = [];
        foreach (DocumentLine::query()->where('document_id', $document->getKey())->orderBy('line_no')->get() as $line) {
            if (Item::query()->findOrFail($line->item_id)->item_type !== 'stock' || $direction === 'none') {
                continue;
            }

            $direction === 'in'
                ? $this->receive($line, $costing)
                : $this->issue($line, $costing);

            $stockLines[] = $line;
        }

        $context = (array) ($document->meta['_accounting_context'] ?? []);
        $totalCost = (float) StockLedger::query()
            ->whereIn('document_line_id', $document->lines()->select('id'))
            ->sum('amount');
        $this->accounting->post($document, new AccountingPostingData(
            totalCost: $totalCost,
            direction: $direction,
            additionalJournalLines: (array) ($context['additional_journal_lines'] ?? []),
            serviceCode: isset($context['service_code']) ? (string) $context['service_code'] : null,
            tenantIdentity: $context['tenant_identity'] ?? null,
        ));

        foreach ($stockLines as $line) {
            $this->stockCards->refresh($line);
        }

        $this->workflow->transition($document, DocumentStatus::POSTED);
        $document->forceFill([
            'posted_at' => now(),
            'posting_completed_at' => now(),
        ])->save();
    }

    private function createAndValidateLine(
        Document $document,
        LineData $data,
        int $lineNo,
        string $direction,
    ): DocumentLine {
        if ($data->qty <= 0 || $data->qtyBonus < 0) {
            throw new \DomainException("Line {$lineNo}: quantity must be positive and bonus cannot be negative.");
        }

        $item = Item::query()->findOrFail($data->itemId);
        if (! $item->is_active) {
            throw new \DomainException("Line {$lineNo}: item is inactive.");
        }

        if ($direction === 'in' && $item->item_type === 'stock' && ($data->unitCost === null || $data->unitCost < 0)) {
            throw new \DomainException("Line {$lineNo}: inbound stock requires a non-negative unit cost.");
        }

        $batch = $data->batchId === null ? null : Batch::query()->findOrFail($data->batchId);
        if ($batch !== null && (int) $batch->item_id !== $data->itemId) {
            throw new \DomainException("Line {$lineNo}: batch belongs to a different item.");
        }

        if ($batch !== null && $direction === 'out') {
            if (in_array($batch->status, ['recalled', 'blocked'], true)) {
                throw new \DomainException("Line {$lineNo}: batch is not available for issue.");
            }
            if ($batch->expires_at !== null && $batch->expires_at->isBefore(now()->startOfDay())) {
                throw new \DomainException("Line {$lineNo}: expired batch cannot be issued.");
            }
        }

        $serial = $data->serialId === null ? null : Serial::query()->findOrFail($data->serialId);
        if ($serial !== null) {
            if ((int) $serial->item_id !== $data->itemId) {
                throw new \DomainException("Line {$lineNo}: serial belongs to a different item.");
            }
            if (($data->qty + $data->qtyBonus) !== 1.0) {
                throw new \DomainException("Line {$lineNo}: a serialized line must represent exactly one unit.");
            }
            if ($direction === 'out' && ($serial->status !== 'in_stock'
                || (int) $serial->warehouse_id !== $data->warehouseId
                || ($serial->storage_location_id !== null && (int) $serial->storage_location_id !== $data->storageLocationId))) {
                throw new \DomainException("Line {$lineNo}: serial is unavailable at the requested location.");
            }
        }

        $line = new DocumentLine([
            'line_no' => $lineNo,
            'item_id' => $data->itemId,
            'uom_id' => $data->uomId,
            'warehouse_id' => $data->warehouseId,
            'storage_location_id' => $data->storageLocationId,
            'qty' => $data->qty,
            'qty_bonus' => $data->qtyBonus,
            'unit_cost' => $data->unitCost,
            'batch_id' => $data->batchId,
            'serial_id' => $data->serialId,
            'meta' => $data->meta,
        ]);
        $line->document()->associate($document);
        $line->save();

        return $line;
    }

    private function receive(DocumentLine $line, bool $costing): void
    {
        [$scopeType, $scopeId] = $this->depth->costingScope(
            (int) $line->warehouse_id,
            $line->storage_location_id === null ? null : (int) $line->storage_location_id,
        );
        $quantity = (float) $line->qty + (float) $line->qty_bonus;
        $purchaseAmount = (float) $line->qty * (float) $line->unit_cost;
        $blendedUnitCost = $costing ? $purchaseAmount / $quantity : 0.0;

        $layer = CostLayer::create([
            'item_id' => $line->item_id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'received_qty' => $quantity,
            'remaining_qty' => $quantity,
            'unit_cost' => $blendedUnitCost,
            'received_at' => Document::query()->findOrFail($line->document_id)->trx_date,
            'source_document_id' => $line->document_id,
            'batch_id' => $line->batch_id,
        ]);

        $this->settleNegativeLayers($layer);

        $this->appendLedger($line, 'in', $quantity, $blendedUnitCost, $layer->getKey(), (float) $line->qty_bonus);
    }

    private function settleNegativeLayers(CostLayer $receiptLayer): void
    {
        $available = (float) $receiptLayer->remaining_qty;
        $negativeLayers = CostLayer::query()
            ->where('item_id', $receiptLayer->item_id)
            ->where('scope_type', $receiptLayer->scope_type)
            ->where('scope_id', $receiptLayer->scope_id)
            ->where('is_negative', true)
            ->where('remaining_qty', '<', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($negativeLayers as $negativeLayer) {
            if ($available <= 0) {
                break;
            }

            $settled = min($available, abs((float) $negativeLayer->remaining_qty));
            $negativeLayer->remaining_qty = (float) $negativeLayer->remaining_qty + $settled;
            $negativeLayer->save();
            $available -= $settled;

            CostAdjustment::create([
                'item_id' => $receiptLayer->item_id,
                'scope_type' => $receiptLayer->scope_type,
                'scope_id' => $receiptLayer->scope_id,
                'negative_layer_id' => $negativeLayer->getKey(),
                'receipt_layer_id' => $receiptLayer->getKey(),
                'settled_qty' => $settled,
                'provisional_unit_cost' => $negativeLayer->unit_cost,
                'actual_unit_cost' => $receiptLayer->unit_cost,
                'amount_delta' => ((float) $receiptLayer->unit_cost - (float) $negativeLayer->unit_cost) * $settled,
            ]);
        }

        $receiptLayer->remaining_qty = $available;
        $receiptLayer->save();
    }

    private function issue(DocumentLine $line, bool $costing): void
    {
        [$scopeType, $scopeId] = $this->depth->costingScope(
            (int) $line->warehouse_id,
            $line->storage_location_id === null ? null : (int) $line->storage_location_id,
        );
        $remaining = (float) $line->qty + (float) $line->qty_bonus;
        $totalQuantity = $remaining;

        $layers = CostLayer::query()
            ->where('item_id', $line->item_id)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('remaining_qty', '>', 0)
            ->when($line->batch_id !== null, fn(Builder $query) => $query->where('batch_id', $line->batch_id))
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $taken = min($remaining, (float) $layer->remaining_qty);
            $layer->remaining_qty = (float) $layer->remaining_qty - $taken;
            $layer->save();
            $bonus = (float) $line->qty_bonus * ($taken / $totalQuantity);
            $this->appendLedger($line, 'out', $taken, $costing ? (float) $layer->unit_cost : 0.0, $layer->getKey(), $bonus);
            $remaining -= $taken;
        }

        if ($remaining <= 0) {
            return;
        }

        if (config('inventory.policies.negative_stock.mode', 'block') !== 'allow') {
            throw new \DomainException('Insufficient stock for inventory issue.');
        }

        $lastKnownCost = CostLayer::query()
            ->where('item_id', $line->item_id)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('unit_cost', '>', 0)
            ->latest('received_at')
            ->latest('id')
            ->value('unit_cost');
        if ($lastKnownCost === null) {
            throw new \DomainException('Negative stock requires a valid last-known cost.');
        }

        $negativeLayer = CostLayer::create([
            'item_id' => $line->item_id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'received_qty' => 0,
            'remaining_qty' => -$remaining,
            'unit_cost' => $lastKnownCost,
            'received_at' => now(),
            'source_document_id' => $line->document_id,
            'batch_id' => $line->batch_id,
            'is_negative' => true,
        ]);
        $bonus = (float) $line->qty_bonus * ($remaining / $totalQuantity);
        $this->appendLedger($line, 'out', $remaining, $costing ? (float) $lastKnownCost : 0.0, $negativeLayer->getKey(), $bonus);
    }

    private function appendLedger(
        DocumentLine $line,
        string $direction,
        float $quantity,
        float $unitCost,
        int $layerId,
        float $bonusQuantity = 0.0,
    ): void {
        StockLedger::create([
            'document_line_id' => $line->getKey(),
            'item_id' => $line->item_id,
            'warehouse_id' => $line->warehouse_id,
            'storage_location_id' => $line->storage_location_id,
            'direction' => $direction,
            'qty' => $quantity,
            'qty_bonus' => $bonusQuantity,
            'unit_cost' => $unitCost,
            'amount' => $quantity * $unitCost,
            'cost_layer_id' => $layerId,
        ]);
    }

    private function findIdempotentDocument(DocumentData $data): ?Document
    {
        if ($data->externalId === null) {
            return null;
        }

        return Document::query()
            ->where('organization_id', $data->organizationId)
            ->where('source_type', $data->sourceType)
            ->where('external_id', $data->externalId)
            ->lockForUpdate()
            ->first();
    }

    private function payloadHash(DocumentData $data): string
    {
        $payload = get_object_vars($data);
        $payload['lines'] = array_map(static fn(LineData $line): array => get_object_vars($line), $data->lines);

        return hash('sha256', (string) json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
