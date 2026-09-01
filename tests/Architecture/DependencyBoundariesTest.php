<?php

function inventoryPhpFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('prevents Core from importing vertical namespaces', function (): void {
    $verticalNamespaces = [
        'ESolution\\InventoryRetail\\',
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];

    foreach (inventoryPhpFiles(dirname(__DIR__, 2) . '/src') as $file) {
        $source = file_get_contents($file);

        foreach ($verticalNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Core file {$file} imports {$namespace}");
        }
    }
});

it('prevents Core migrations from owning vertical or external tables', function (): void {
    $forbiddenPrefixes = ['invr_', 'invw_', 'invm_', 'invh_', 'invf_', 'inva_', 'invp_', 'invat_', 'invl_', 'acc_', 'approval_'];

    foreach (inventoryPhpFiles(dirname(__DIR__, 2) . '/database/migrations') as $file) {
        $source = file_get_contents($file);

        foreach ($forbiddenPrefixes as $prefix) {
            expect($source)->not->toContain("Schema::create('{$prefix}", "Core migration {$file} owns {$prefix} tables");
        }
    }
});

it('prevents Core Composer dependencies on vertical packages', function (): void {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependencies = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

    foreach ($dependencies as $dependency) {
        expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
    }
});

it('keeps the Retail package dependent on Core only and inside its namespace and table prefix', function (): void {
    $root = dirname(__DIR__, 2);
    $retailRoot = $root . '/packages/retail';
    $composer = json_decode(file_get_contents($retailRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependencies = array_keys($composer['require'] ?? []);

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }

    $siblingNamespaces = [
        'ESolution\\InventoryWms\\',
        'ESolution\\InventoryManufacturing\\',
        'ESolution\\InventoryHealthcare\\',
        'ESolution\\InventoryFood\\',
        'ESolution\\InventoryAsset\\',
        'ESolution\\InventoryProject\\',
        'ESolution\\InventoryAutomotive\\',
        'ESolution\\InventoryLibrary\\',
    ];
    foreach (inventoryPhpFiles($retailRoot . '/src') as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('namespace ESolution\\InventoryRetail');
        foreach ($siblingNamespaces as $namespace) {
            expect($source)->not->toContain($namespace, "Retail file {$file} imports sibling {$namespace}");
        }
    }

    foreach (inventoryPhpFiles($retailRoot . '/database/migrations') as $file) {
        preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toStartWith('invr_');
        }
    }
});

it('keeps Consignment settlement accounting project-owned', function (): void {
    $retailSource = file_get_contents(
        dirname(__DIR__, 2) . '/packages/retail/src/Services/SettlementRecorder.php',
    );

    expect($retailSource)->not->toContain('AccountingBridge')
        ->and($retailSource)->not->toContain('AccountingPostingData');
});
