<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Models\Reservation;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    public function reserve(int $itemId, float $qty, int $warehouseId, string $sourceType, string $sourceId): Reservation
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be positive.');
        }return Reservation::create(['item_id' => $itemId,'warehouse_id' => $warehouseId,'source_type' => $sourceType,'source_id' => $sourceId,'reserved_qty' => $qty,'status' => 'active']);
    }public function release(int $id, ?float $qty = null): Reservation
    {
        return DB::transaction(function () use ($id, $qty) {
            $r = Reservation::query()->lockForUpdate()->findOrFail($id);
            $amount = $qty ?? $r->remaining_qty;
            if ($amount <= 0 || $amount > $r->remaining_qty) {
                throw new \DomainException('Invalid reservation release quantity.');
            }$r->released_qty = (float) $r->released_qty + $amount;
            $r->status = $r->remaining_qty - $amount <= 0 ? 'released' : 'active';
            $r->save();
            return $r->refresh();
        });
    }public function consume(int $id, float $qty, string $key, ?int $lineId = null): Reservation
    {
        return DB::transaction(function () use ($id, $qty, $key, $lineId) {
            $r = Reservation::query()->lockForUpdate()->findOrFail($id);
            if (DB::table('inv_reservation_consumptions')->where('reservation_id', $id)->where('idempotency_key', $key)->exists()) {
                return $r;
            }if ($qty <= 0 || $qty > $r->remaining_qty) {
                throw new \DomainException('Reservation consumption exceeds remaining quantity.');
            }DB::table('inv_reservation_consumptions')->insert(['reservation_id' => $id,'document_line_id' => $lineId,'idempotency_key' => $key,'qty' => $qty,'created_at' => now()]);
            $r->consumed_qty = (float) $r->consumed_qty + $qty;
            $r->status = $r->remaining_qty - $qty <= 0 ? 'consumed' : 'active';
            $r->save();
            return $r->refresh();
        });
    }
}
