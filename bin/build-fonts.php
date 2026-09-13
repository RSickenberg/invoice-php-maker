#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds the standard PDF fonts (helvetica.json and friends) that
 * tecnickcom/tc-lib-pdf-font ships without by default.
 *
 * tecnickcom/tcpdf's own composer.json declares this font build as a
 * post-install-cmd/post-update-cmd, but Composer only ever runs the ROOT
 * project's own lifecycle scripts, never a dependency's, so it never
 * fires when tcpdf is just a "require" here. We run it ourselves, from
 * our own composer.json, so a fresh install on any machine ends up with
 * usable fonts instead of throwing "unable to read file: helvetica.json"
 * on the first SetFont() call. See DECISIONS.md D-012.
 *
 * Safe to run repeatedly: does nothing if the fonts already exist, or if
 * tc-lib-pdf-font isn't installed at all (e.g. tcpdf was removed).
 */

$projectRoot = dirname(__DIR__);
$fontPackageDir = $projectRoot . '/vendor/tecnickcom/tc-lib-pdf-font';
$builtMarker = $fontPackageDir . '/target/fonts/core/helvetica.json';

if (is_file($builtMarker)) {
    fwrite(STDOUT, "Fonts already built, skipping.\n");
    exit(0);
}

if (!is_dir($fontPackageDir)) {
    // tecnickcom/tcpdf isn't installed -- nothing to build.
    exit(0);
}

fwrite(STDOUT, "Building TCPDF standard fonts (tecnickcom/tc-lib-pdf-font ships none by default)...\n");

$composerBin = getenv('COMPOSER_BINARY') ?: 'composer';
$utilDir = $fontPackageDir . '/util';

$commands = [
    sprintf(
        '%s install --no-interaction --working-dir=%s',
        escapeshellarg($composerBin),
        escapeshellarg($utilDir),
    ),
    sprintf(
        '%s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($utilDir . '/bulk_convert.php'),
    ),
];

foreach ($commands as $command) {
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "Font build failed (exit {$exitCode}) while running: {$command}\n");
        exit($exitCode);
    }
}

fwrite(STDOUT, "Fonts built successfully.\n");
