
<?php

/**
 * Centralised documentation for all CLI commands.
 * Usage:
 *   php trongate help
 *   php trongate help serve
 *   php trongate help create
 */

class Help
{
    /**
     * Application information.
     */
    private const APP_NAME = 'Trongate PHP Development CLI';

    /**
     * Registered command documentation.
     */
    private static $commands = [

        'serve' => [
            'description' => 'Start the Trongate development server.',
            'usage' => 'php trongate serve [host] [port]',
            'arguments' => [
                'host' => 'Server hostname or IP address.',
                'port' => 'Server port. Default: 8000.',
            ],
            'examples' => [
                'php trongate serve',
                'php trongate serve 127.0.0.1 8000',
                'php trongate serve 0.0.0.0 8080',
            ],
        ],

        'info' => [
            'description' => 'Display project and environment information.',
            'usage' => 'php trongate info',
        ],

        'check' => [
            'description' => 'Check project requirements and configuration.',
            'usage' => 'php trongate check',
            'examples' => [
                'php trongate check',
            ],
        ],

        'create' => [
            'description' => 'Generate application components.',
            'usage' => 'php trongate create:<type> [arguments]',
            'examples' => [
                'php trongate create:module blog',
                'php trongate create:controller blog blog',
                'php trongate create:model blog blog',
                'php trongate create:view blog posts',
                'php trongate create:crud blog',
            ],
        ],

        'learn' => [
            'description' => 'Explore documentation for the Trongate framework.',
            'usage' => 'php trongate learn',
            'examples' => [
                'php trongate learn',
            ],
        ],

        'install' => [
            'description' => 'Install Trongate framework or Trongate On Ice.',
            'usage' => 'php trongate install [options]',
            'options' => [
                '--framework=trongate',
                '--framework=trongate-on-ice',
            ],
            'examples' => [
                'php trongate install',
                'php trongate install --framework=trongate',
                'php trongate install --framework=trongate-on-ice',
            ],
        ],
    ];

    /**
     * Display help.
     */
    public static function render($command = null)
    {
        if ($command !== null) {
            self::renderCommand($command);
            return;
        }

        self::renderOverview();
    }

    /**
     * Display general help.
     */
    private static function renderOverview()
    {
        self::line();
        self::title(self::APP_NAME);
        self::line();

        echo PHP_EOL . "USAGE" . PHP_EOL;
        echo "  php trongate <command> [arguments]" . PHP_EOL;

        echo PHP_EOL . "AVAILABLE COMMANDS" . PHP_EOL;

        foreach (self::$commands as $name => $details) {
            self::commandRow($name, $details['description']);
        }

        echo PHP_EOL . "GLOBAL OPTIONS" . PHP_EOL;
        echo "  --help       Display help information." . PHP_EOL;
        echo "  --version    Display CLI version." . PHP_EOL;

        echo PHP_EOL . "EXAMPLES" . PHP_EOL;
        echo "  php trongate serve" . PHP_EOL;
        echo "  php trongate check" . PHP_EOL;
        echo "  php trongate info" . PHP_EOL;
        echo "  php trongate create:module blog" . PHP_EOL;

        echo PHP_EOL;
        echo "For command-specific help, run:" . PHP_EOL;
        echo "  php trongate help <command>" . PHP_EOL;
        echo PHP_EOL . "EXAMPLES" . PHP_EOL;
        echo "  php trongate help serve" . PHP_EOL;
        echo "  php trongate serve -h" . PHP_EOL;

        self::line();
    }

    /**
     * Display command-specific help.
     */
    private static function renderCommand($command)
    {
        $command = strtolower(trim($command));

        if (!isset(self::$commands[$command])) {
            echo PHP_EOL;
            echo "Unknown help topic: {$command}" . PHP_EOL;
            echo "Run 'php trongate help' to see available commands."
                . PHP_EOL;
            return;
        }

        $details = self::$commands[$command];

        self::line();
        self::title(strtoupper($command));
        self::line();

        echo PHP_EOL;
        echo $details['description'] . PHP_EOL;

        echo PHP_EOL . "USAGE" . PHP_EOL;
        echo "  " . $details['usage'] . PHP_EOL;

        if (!empty($details['arguments'])) {
            echo PHP_EOL . "ARGUMENTS" . PHP_EOL;

            foreach ($details['arguments'] as $name => $description) {
                echo "  {$name}" . PHP_EOL;
                echo "      {$description}" . PHP_EOL;
            }
        }

        if (!empty($details['options'])) {
            echo PHP_EOL . "OPTIONS" . PHP_EOL;

            foreach ($details['options'] as $option) {
                echo "  {$option}" . PHP_EOL;
            }
        }

        if (!empty($details['examples'])) {
            echo PHP_EOL . "EXAMPLES" . PHP_EOL;

            foreach ($details['examples'] as $example) {
                echo "  {$example}" . PHP_EOL;
            }
        }

        self::line();
    }

    /**
     * Print a command row.
     */
    private static function commandRow($name, $description)
    {
        printf("  %-12s %s" . PHP_EOL, $name, $description);
    }

    /**
     * Print a separator.
     */
    private static function line()
    {
        echo str_repeat('-', 72) . PHP_EOL;
    }

    /**
     * Print a title.
     */
    private static function title($text)
    {
        echo $text . PHP_EOL;
    }



    /**
     * Detect help arguments and render appropriate documentation.
     *
     * Supports:
     *   help
     *   -h
     *   --help
     *   help <command>
     *   <command> -h
     *   <command> --help
     */
    public static function handle(array $argv)
    {
        $command = $argv[1] ?? null;
    
        // General help
        if (in_array($command, ['help', '-h', '--help'], true)) {
            $topic = $argv[2] ?? null;
            self::render($topic);
            return true;
        }
    
        // Command-specific help
        if (
            $command !== null &&
            in_array('--help', $argv, true) ||
            $command !== null &&
            in_array('-h', $argv, true)
        ) {
            self::render($command);
            return true;
        }
    
        return false;
    }
}