<?php

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
});

it('posts positive and negative adjustments through standard document definitions', function (): void {
    $manager = app(InventoryManager::class);
    $positive = $manager->post(new DocumentData(
        type: 'positive_adjustment',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'ADJ-IN',
        lines: [new LineData(1, 1, 1, 5, unitCost: 4)],
    ));
    $negative = $manager->post(new DocumentData(
        type: 'negative_adjustment',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'ADJ-OUT',
        lines: [new LineData(1, 1, 1, 2)],
    ));

    expect($positive->status)->toBe(DocumentStatus::POSTED)
        ->and($negative->status)->toBe(DocumentStatus::POSTED)
        ->and((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(3.0);
});

it('rejects an expired batch on outbound posting', function (): void {
    DB::table('inv_batches')->insert([
        'id' => 20,
        'item_id' => 1,
        'batch_no' => 'EXPIRED',
        'expires_at' => '2026-08-30',
        'status' => 'available',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'EXPIRED-ISSUE',
        lines: [new LineData(1, 1, 1, 1, batchId: 20)],
    )))->toThrow(DomainException::class, 'expired batch');
});

it('rejects reservation over-consumption and over-release', function (): void {
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 5, 1, 'sales_order', 'SO-LIMIT');

    expect(fn() => $manager->consume($reservation->getKey(), 6, 'OVER-CONSUME'))
        ->toThrow(DomainException::class);
    expect(fn() => $manager->release($reservation->getKey(), 6))
        ->toThrow(DomainException::class);
    expect(StockLedger::query()->count())->toBe(0);
});

test('standard transfer posts balanced outbound and inbound legs atomically')->todo('Transfer orchestration is not implemented.');
test('stock count posts only the calculated variance')->todo('Stock-count variance orchestration is not implemented.');
test('reversal creates opposite immutable effects')->todo('Reversal orchestration is not implemented.');
