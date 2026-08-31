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
