# Laravel Inventory

`elgibor-solution/laravel-inventory` provides an extensible Laravel Inventory
Core with scoped stock, append-only ledger, costing layers, tracking, document
workflow, Stock Cards, and reservations. The package keeps its public namespace
at `ESolution\Inventory`.

Accounting and approval are optional bridges. Inventory Core does not implement
a General Ledger and does not own journal tables.

## Installation

```bash
composer require elgibor-solution/laravel-inventory
php artisan vendor:publish --tag=inventory-config
php artisan migrate
php artisan inventory:validate-config
```

For a local path repository, require the development version after registering
the repository in the host project's `composer.json`:

```bash
composer require elgibor-solution/laravel-inventory:@dev
```

## Configuration

The published `config/inventory.php` controls organization/storage depth,
costing scope, negative-stock policy, idempotency, optional bridges, and
after-commit behavior. Republish with `--force` when intentionally replacing an
older published config, after backing up project-specific values.

Accounting is off by default:

```php
'accounting' => [
    'enabled' => false,
    'connection' => null,
    'tenant_payload_key' => null,
    'service_code_map' => [
        'purchase_receipt' => 'PURCHASE_CREDIT',
        'warehouse_transfer.intra_company' => null,
    ],
],
```

When enabling it, install `elgibor-solution/laravel-accounting` in the host
project and validate deployment prerequisites:

```bash
php artisan inventory:accounting:validate
```

See [Accounting Bridge](docs/ACCOUNTING_BRIDGE.md) for mapping, transaction,
tenant, and reversal behavior.

## Posting example

```php
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Facades\Inventory;

$document = Inventory::post(new DocumentData(
    type: 'purchase_receipt',
    organizationId: $warehouseId,
    trxDate: now()->toDateString(),
    externalId: 'GR-001',
    lines: [
        new LineData(
            itemId: $itemId,
            uomId: $uomId,
            warehouseId: $warehouseId,
            qty: 10,
            unitCost: 5000,
        ),
    ],
));
```

With Accounting Bridge enabled, caller-owned financial lines can be forwarded
without Core calculating revenue or tax:

```php
additionalJournalLines: [
    ['mapping_key' => 'purchase_credit_ap_k', 'amount' => 50000],
],
```

## Development checks

```bash
composer check
composer audit
composer validate --strict
```

The fresh baseline migrations are intended for new installations. Migration of
legacy production data is outside the baseline package.

## License

Apache-2.0
