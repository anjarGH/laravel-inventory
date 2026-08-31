<?php

return [
    'organization' => ['levels' => ['holding' => false, 'company' => false, 'business_unit' => false, 'branch' => true, 'outlet' => false, 'department' => false, 'warehouse' => true]],
    'storage' => ['levels' => ['warehouse' => true, 'zone' => false, 'aisle' => false, 'rack' => true, 'shelf' => false, 'bin' => false, 'pallet' => false]],
    'costing' => ['default_method' => 'fifo', 'scope' => 'warehouse', 'negative_stock_cost' => 'last_known'],
    'idempotency' => ['mode' => 'return_existing'],
    'policies' => [
        'posting' => ['enabled' => true],
        'negative_stock' => ['mode' => 'block'],
        'certificate' => ['enabled' => false, 'categories' => []],
        'reservation' => ['enabled' => true],
    ],
    'accounting' => ['enabled' => false, 'service_code_map' => []],
    'approval' => ['enabled' => false, 'rejection_status_map' => []],
    'events' => ['after_commit' => true],
];
