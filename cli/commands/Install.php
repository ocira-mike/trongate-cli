<?php

/**
 * Trongate CLI — Framework Installer Command
 *
 * Creates a new Trongate project from a registered framework edition.
 */

require_once __DIR__ . '/../Support/Installer/Installer.php';

final class Install
{
    /**
     * Execute the installer.
     *
     * @param array<int, string> $arguments
     */
    public function handle(array $arguments = []): int
    {
        if (!function_exists('run')) {
            fwrite(STDERR, "Error: Installer run() function was not found.\n");
            return 1;
        }

        return \run($arguments);
    }
}
