<?php

/**
 * Trongate CLI Application
 *
 * Handles command routing, logo rendering, help output,
 * and generator command execution.
 */

require_once __DIR__ . '/../Support/System.php';
require_once __DIR__ . '/Logo.php';
require_once __DIR__ . '/Serve.php';
require_once __DIR__ . '/Info.php';
require_once __DIR__ . '/Create.php';
require_once __DIR__ . '/Learn.php';
require_once __DIR__ . '/Check.php';
require_once __DIR__ . '/Help.php';

final class Cli
{
    /**
     * Start the CLI application.
     *
     * @param array<int, string> $argv Raw command-line arguments.
     * @return int Process exit code.
     */
    public static function run(array $argv): int
    {
        if (Help::handle($argv)) {
            return 0;
        }
        
        $command   = $argv[1] ?? 'help';
        $arguments = array_slice($argv, 2);

        switch ($command) {

            case 'install':
                require_once __DIR__ . '/Install.php';
                return (new Install())->handle($arguments);
                
            case 'serve':
                (new Serve())->handle($arguments); // exits with the server's code
                return 0;

            case 'info':
                Logo::render();
                Info::run();
                return 0;
                
            case 'learn':
                $sub = $_SERVER['argv'][2] ?? '';
                Learn::run($sub);
                return 0;

            case 'logo':
                Logo::render();
                return 0;
            
            case 'check':
                Logo::render();
                return (new Check())->run();

            case 'version':
            case '--version':
            case '-V':
                echo 'Trongate CLI ' . Info::CLI_VERSION . PHP_EOL;
                return 0;

            case '--help':
            case '-h':
                Help::render($argv[2] ?? null);
                break;
        }

        // "php trongate create module blog" / "php trongate make module blog"
        if (in_array($command, ['create', 'make'], true) && $arguments !== []) {
            $generator = array_shift($arguments);

            return (new Create())->handle(
                array_merge([$generator], $arguments)
            );
        }

        // "php trongate create:module blog" / "php trongate make:module blog"
        if (strpos($command, 'create:') === 0 || strpos($command, 'make:') === 0) {
            $generator = substr($command, strpos($command, ':') + 1);

            return (new Create())->handle(
                array_merge([$generator], $arguments)
            );
        }

        throw new InvalidArgumentException(
            "Unknown command: {$command}. Run 'php trongate help' to see available commands."
        );
    }
}