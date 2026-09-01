<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\StockAvailability;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Reservation;

final class InventoryManager
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly ReservationService $reservations,
        private readonly ResumeApprovedDocument $approvalResume,
        private readonly StockAvailabilityService $availability,
    ) {}

    public function post(DocumentData $data): Document
    {
        return $this->posting->post($data);
    }

    public function resumeApproved(int $documentId): Document
    {
        return $this->approvalResume->handle($documentId);
    }

    public function reserve(int $itemId, float $qty, int $warehouseId, string $sourceType, string $sourceId): Reservation
    {
        return $this->reservations->reserve($itemId, $qty, $warehouseId, $sourceType, $sourceId);
    }

    public function release(int $id, ?float $qty = null): Reservation
    {
        return $this->reservations->release($id, $qty);
    }

    public function consume(int $id, float $qty, string $key, ?int $lineId = null): Reservation
    {
        return $this->reservations->consume($id, $qty, $key, $lineId);
    }

    public function availability(int $itemId, int $warehouseId): StockAvailability
    {
        return $this->availability->forItem($itemId, $warehouseId);
    }
}
