<?php

/**
 * Protobuf/gRPC code generator for Azera RoadRunner.
 *
 * Discovers every `.proto` file under the configured source directories and
 * runs `protoc` (with the RoadRunner `protoc-gen-php-grpc` plugin) to emit
 * PHP message DTOs and gRPC service interfaces into `generated/`.
 *
 * Usage:
 *   php bin/proto.php
 *
 * The generated code is registered in composer.json under the `GRPC\` and
 * `GPBMetadata\` namespaces. Run `composer dump-autoload` after generating
 * new classes.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Directories to scan for .proto files (relative to project root).
$sourceDirs = ['proto', 'examples/proto'];

// Output directory for generated PHP.
$outDir = $root . '/generated';

// The protoc-gen-php-grpc plugin binary (downloaded via `rr download-protoc-binary`).
$plugin = $root . '/protoc-gen-php-grpc.exe';

// ---------------------------------------------------------------------------

if (!is_file($plugin)) {
    fwrite(STDERR, "protoc-gen-php-grpc binary not found at {$plugin}.\n");
    fwrite(STDERR, "Run: composer require spiral/roadrunner-cli --dev && vendor/bin/rr download-protoc-binary\n");
    exit(1);
}

$protoFiles = [];
foreach ($sourceDirs as $dir) {
    $abs = $root . '/' . $dir;
    if (!is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'proto') {
            $protoFiles[] = $file->getPathname();
        }
    }
}

if ($protoFiles === []) {
    fwrite(STDERR, "No .proto files found in: " . implode(', ', $sourceDirs) . "\n");
    exit(1);
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

// Build the include path from the source dirs so relative imports resolve.
$includePaths = [];
foreach ($sourceDirs as $dir) {
    $abs = $root . '/' . $dir;
    if (is_dir($abs)) {
        $includePaths[] = '-I ' . escapeshellarg($abs);
    }
}

$cmd = 'protoc '
    . '--plugin=protoc-gen-php-grpc=' . escapeshellarg($plugin) . ' '
    . implode(' ', $includePaths) . ' '
    . '--php_out=' . escapeshellarg($outDir) . ' '
    . '--php-grpc_out=' . escapeshellarg($outDir) . ' '
    . implode(' ', array_map('escapeshellarg', $protoFiles));

echo "Generating " . count($protoFiles) . " proto file(s)...\n";
passthru($cmd, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "protoc failed with exit code {$exitCode}\n");
    exit($exitCode);
}

echo "Done. Generated code is in {$outDir}\n";
echo "Run `composer dump-autoload` if new classes were added.\n";