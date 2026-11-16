
<?php

class Check
{
    private $projectRoot;

    private $passed = 0;
    private $warnings = 0;
    private $failed = 0;
    private $skipped = 0;

    private $frameworkInstalled = false;

    private $requiredPaths = [
        'config/config.php',
        'config/database.php',
        'engine',
        'modules',
        'public',
    ];

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 2);
    }

    /**
     * Run all diagnostic checks.
     */
    public function run(): int
    {
        $this->reset();

        $this->title('Trongate CLI - System Diagnostics');

        $this->section('PHP Environment');
        $this->checkPhpVersion();
        $this->checkSapi();
        $this->checkMemoryLimit();
        $this->checkExecutionTime();

        $this->section('PHP Extensions');
        $this->checkExtensions();

        $this->section('Project Structure');
        $this->checkProjectRoot();
        $this->checkFrameworkInstallation();

        if ($this->frameworkInstalled) {
            $this->section('Configuration');
            $this->checkConfigurationFiles();
            $this->checkConfigurationSyntax();

            $this->section('File Permissions');
            $this->checkPermissions();

            $this->section('Modules');
            $this->checkModules();

            $this->section('Database');
            $this->checkDatabase();
        } else {
            $this->section('Framework Diagnostics');
            $this->skip('Configuration diagnostics');
            $this->skip('Module diagnostics');
            $this->skip('Database connectivity');
        }

        $this->summary();

        return $this->failed > 0 ? 1 : 0;
    }

    private function reset(): void
    {
        $this->passed = 0;
        $this->warnings = 0;
        $this->failed = 0;
        $this->skipped = 0;
        $this->frameworkInstalled = false;
    }

    /*
    |--------------------------------------------------------------------------
    | PHP Environment
    |--------------------------------------------------------------------------
    */

    private function checkPhpVersion(): void
    {
        $version = PHP_VERSION;

        if (version_compare($version, '8.0.0', '>=')) {
            $this->success("PHP version: {$version}");
        } else {
            $this->failure(
                "PHP {$version} detected. PHP 8.0 or newer is required."
            );
        }
    }

    private function checkSapi(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->success('Running through PHP CLI');
        } else {
            $this->warning(
                'Running through ' . PHP_SAPI . ' instead of PHP CLI'
            );
        }
    }

    private function checkMemoryLimit(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            $this->success('PHP memory limit: Unlimited');
            return;
        }

        $bytes = $this->convertToBytes($limit);

        if ($bytes === 0 || $bytes >= 128 * 1024 * 1024) {
            $this->success("PHP memory limit: {$limit}");
        } else {
            $this->warning(
                "PHP memory limit is {$limit}. 128M or higher is recommended."
            );
        }
    }

    private function checkExecutionTime(): void
    {
        $time = ini_get('max_execution_time');

        if ($time === '0' || (int) $time >= 30) {
            $this->success("Maximum execution time: {$time}");
        } else {
            $this->warning(
                "Maximum execution time is {$time} seconds."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PHP Extensions
    |--------------------------------------------------------------------------
    */

    private function checkExtensions(): void
    {
        $required = [
            'PDO',
            'json',
            'openssl',
            'mbstring',
            'fileinfo',
            'tokenizer',
            'xml',
        ];

        $optional = [
            'pdo_mysql',
            'pdo_sqlite',
            'gd',
            'intl',
            'curl',
            'zip',
            'bcmath',
            'exif',
            'zlib',
            'ctype',
        ];

        foreach ($required as $extension) {
            if (extension_loaded($extension)) {
                $this->success("Extension: {$extension}");
            } else {
                $this->failure("Missing required extension: {$extension}");
            }
        }

        foreach ($optional as $extension) {
            if (extension_loaded($extension)) {
                $this->success("Extension: {$extension}");
            } else {
                $this->warning(
                    "Optional extension not installed: {$extension}"
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Project Structure
    |--------------------------------------------------------------------------
    */

    private function checkProjectRoot(): void
    {
        if (is_dir($this->projectRoot)) {
            $this->success('Project directory is accessible');
        } else {
            $this->failure('Project directory does not exist');
        }
    }

    private function checkFrameworkInstallation(): void
    {
        $missing = [];

        foreach ($this->requiredPaths as $path) {
            $fullPath = $this->projectRoot . DIRECTORY_SEPARATOR . $path;

            if (!file_exists($fullPath)) {
                $missing[] = $path;
            }
        }

        if (empty($missing)) {
            $this->frameworkInstalled = true;
            $this->success('Trongate Framework installation detected');
            return;
        }

        $this->warning('Trongate Framework is not fully installed');

        foreach ($missing as $path) {
            $this->line("Missing: {$path}");
        }

        $this->line(
            'Run php trongate install to install the framework.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    private function checkConfigurationFiles(): void
    {
        $files = [
            'config/config.php',
            'config/database.php',
        ];

        foreach ($files as $file) {
            $path = $this->projectRoot . '/' . $file;

            if (!is_file($path)) {
                $this->failure("Missing configuration file: {$file}");
                continue;
            }

            if (!is_readable($path)) {
                $this->failure("Configuration file is not readable: {$file}");
                continue;
            }

            $this->success("Configuration file found: {$file}");
        }
    }

    /**
     * Check PHP syntax without including or executing configuration files.
     */
    private function checkConfigurationSyntax(): void
    {
        $files = [
            'config/config.php',
            'config/database.php',
        ];

        if (!function_exists('proc_open')) {
            $this->warning(
                'Cannot check configuration syntax: proc_open is disabled'
            );
            return;
        }

        foreach ($files as $file) {
            $path = $this->projectRoot . '/' . $file;

            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $command = [
                PHP_BINARY,
                '-l',
                $path,
            ];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open(
                $command,
                $descriptors,
                $pipes,
                $this->projectRoot
            );

            if (!is_resource($process)) {
                $this->warning(
                    "Unable to check PHP syntax: {$file}"
                );
                continue;
            }

            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_close($process);

            if ($status === 0) {
                $this->success("Valid PHP syntax: {$file}");
            } else {
                $this->failure("PHP syntax error in: {$file}");

                $message = trim($stderr ?: $stdout);

                if ($message !== '') {
                    $this->line('  ' . $message);
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | File Permissions
    |--------------------------------------------------------------------------
    */

    private function checkPermissions(): void
    {
        $directories = [
            'config',
            'engine',
            'modules',
            'public',
        ];

        foreach ($directories as $directory) {
            $path = $this->projectRoot . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            if (is_readable($path)) {
                $this->success("Readable directory: {$directory}");
            } else {
                $this->failure("Directory is not readable: {$directory}");
            }
        }

        $uploadDirectory = $this->projectRoot . '/public/uploads';

        if (is_dir($uploadDirectory)) {
            if (is_writable($uploadDirectory)) {
                $this->success('Upload directory is writable');
            } else {
                $this->warning('Upload directory is not writable');
            }
        } else {
            $this->skip('Upload directory does not exist');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    */

    private function checkModules(): void
    {
        $modulesDirectory = $this->projectRoot . '/modules';

        if (!is_dir($modulesDirectory)) {
            $this->skip('Module discovery');
            return;
        }

        $modules = glob($modulesDirectory . '/*', GLOB_ONLYDIR);

        if ($modules === false || empty($modules)) {
            $this->warning('No modules found');
            return;
        }

        $this->success(
            count($modules) . ' module(s) detected'
        );

        foreach ($modules as $module) {
            $this->line('Module: ' . basename($module));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    private function checkDatabase(): void
    {
        $databaseFile = $this->projectRoot . '/config/database.php';

        if (!is_file($databaseFile) || !is_readable($databaseFile)) {
            $this->skip('Database connection test: configuration unavailable');
            return;
        }

        if (!extension_loaded('PDO')) {
            $this->skip('Database connection test: PDO is unavailable');
            return;
        }

        /*
         * The database configuration is loaded in an isolated scope.
         * It is not loaded unless the framework structure is detected.
         *
         * Only use this diagnostic against a trusted local project.
         */
        try {
            $databases = (static function ($file) {
                $databases = [];

                require $file;

                return $databases;
            })($databaseFile);
        } catch (Throwable $exception) {
            $this->failure(
                'Unable to load database configuration: ' .
                $this->safeExceptionMessage($exception)
            );
            return;
        }

        if (!is_array($databases) || !isset($databases['default'])) {
            $this->failure(
                'Database configuration must define $databases[\'default\']'
            );
            return;
        }

        $config = $databases['default'];

        if (!is_array($config)) {
            $this->failure('Default database configuration is invalid');
            return;
        }

        $driver = strtolower(
            $config['driver']
            ?? $config['db_type']
            ?? $config['database_type']
            ?? 'mysql'
        );

        if ($driver === 'sqlite') {
            $this->connectSqlite($config);
            return;
        }

        if ($driver === 'mysql') {
            $this->connectMysql($config);
            return;
        }

        $this->failure("Unsupported database driver: {$driver}");
    }

    private function connectMysql(array $config): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->failure(
                'MySQL connection unavailable: pdo_mysql extension is missing'
            );
            return;
        }

        $host = $config['hostname']
            ?? $config['host']
            ?? '127.0.0.1';

        $database = $config['database']
            ?? $config['dbname']
            ?? '';

        $username = $config['username']
            ?? $config['user']
            ?? '';

        $password = $config['password'] ?? '';

        $port = $config['port'] ?? 3306;
        $charset = $config['charset'] ?? 'utf8mb4';

        if ($database === '' || $username === '') {
            $this->failure(
                'MySQL configuration is missing the database name or username'
            );
            return;
        }

        if (!is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            $this->failure('Invalid MySQL port configuration');
            return;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            (int) $port,
            $database,
            $charset
        );

        try {
            $pdo = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );

            $pdo->query('SELECT 1');

            $this->success('MySQL database connection successful');

            $this->line("Host: {$host}");
            $this->line("Database: {$database}");
            $this->line("Port: {$port}");
        } catch (PDOException $exception) {
            $this->failure(
                'MySQL connection failed: ' .
                $this->databaseErrorHint($exception)
            );
        }
    }

    private function connectSqlite(array $config): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->failure(
                'SQLite connection unavailable: pdo_sqlite extension is missing'
            );
            return;
        }

        $database = $config['database']
            ?? $config['dbname']
            ?? '';

        if ($database === '') {
            $this->failure('SQLite database path is missing');
            return;
        }

        if ($database !== ':memory:' && !$this->isAbsolutePath($database)) {
            $database = $this->projectRoot . '/' . $database;
        }

        if ($database !== ':memory:' && !is_file($database)) {
            $this->failure("SQLite database file does not exist: {$database}");
            return;
        }

        try {
            $pdo = new PDO(
                'sqlite:' . $database,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );

            $pdo->query('SELECT 1');

            $this->success('SQLite database connection successful');
            $this->line("Database: {$database}");
        } catch (PDOException $exception) {
            $this->failure(
                'SQLite connection failed: ' .
                $this->databaseErrorHint($exception)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Database Error Diagnostics
    |--------------------------------------------------------------------------
    */

    private function databaseErrorHint(PDOException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (
            strpos($message, 'access denied') !== false ||
            strpos($message, 'authentication') !== false
        ) {
            return 'Check your database username and password.';
        }

        if (
            strpos($message, 'unknown database') !== false ||
            strpos($message, 'does not exist') !== false
        ) {
            return 'The configured database may not exist. Check its name.';
        }

        if (
            strpos($message, 'connection refused') !== false ||
            strpos($message, 'could not find driver') !== false
        ) {
            return 'Check that the database server is running and the PDO driver is installed.';
        }

        if (
            strpos($message, 'getaddrinfo') !== false ||
            strpos($message, 'name or service not known') !== false
        ) {
            return 'The database hostname could not be resolved. Check the host setting.';
        }

        if (
            strpos($message, 'no such table') !== false
        ) {
            return 'The connection works, but the expected database table is missing.';
        }

        if (
            strpos($message, 'unable to open database file') !== false
        ) {
            return 'Check the SQLite file path and directory permissions.';
        }

        return 'Check the database host, port, credentials and server status.';
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return 'Configuration could not be loaded.';
        }

        // Avoid printing configuration values or credentials.
        return 'Configuration file could not be loaded. Check its PHP syntax and contents.';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
                || strpos($path, '\\\\') === 0;
        }

        return isset($path[0]) && $path[0] === '/';
    }

    private function convertToBytes($value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        switch ($unit) {
            case 'g':
                $number *= 1024;
                // Fall through.
            case 'm':
                $number *= 1024;
                // Fall through.
            case 'k':
                $number *= 1024;
        }

        return (int) $number;
    }

    /*
    |--------------------------------------------------------------------------
    | Console Output
    |--------------------------------------------------------------------------
    */

    private function title(string $title): void
    {
        echo PHP_EOL;
        echo "\033[38;5;133m" . $title . "\033[0m";
        echo PHP_EOL;
        echo str_repeat('-', 55) . PHP_EOL;
    }

    private function section(string $name): void
    {
        echo PHP_EOL;
        echo "\033[97m{$name}\033[0m" . PHP_EOL;
    }

    private function success(string $message): void
    {
        $this->passed++;
        echo "\033[32m[PASS]\033[0m {$message}" . PHP_EOL;
    }

    private function warning(string $message): void
    {
        $this->warnings++;
        echo "\033[33m[WARNING]\033[0m {$message}" . PHP_EOL;
    }

    private function failure(string $message): void
    {
        $this->failed++;
        echo "\033[31m[FAIL]\033[0m {$message}" . PHP_EOL;
    }

    private function skip(string $message): void
    {
        $this->skipped++;
        echo "\033[90m[SKIP]\033[0m {$message}" . PHP_EOL;
    }

    private function line(string $message): void
    {
        echo "       {$message}" . PHP_EOL;
    }

    private function summary(): void
    {
        echo PHP_EOL;
        echo str_repeat('-', 55) . PHP_EOL;
        echo "\033[97mDiagnostic Summary\033[0m" . PHP_EOL;

        echo "\033[32mPassed:   {$this->passed}\033[0m" . PHP_EOL;
        echo "\033[33mWarnings: {$this->warnings}\033[0m" . PHP_EOL;
        echo "\033[31mFailed:   {$this->failed}\033[0m" . PHP_EOL;
        echo "\033[90mSkipped:  {$this->skipped}\033[0m" . PHP_EOL;

        echo str_repeat('-', 55) . PHP_EOL;

        if ($this->failed > 0) {
            echo "\033[31mDiagnostics completed with failures.\033[0m";
        } elseif ($this->warnings > 0) {
            echo "\033[33mDiagnostics completed with warnings.\033[0m";
        } else {
            echo "\033[32mAll diagnostic checks passed.\033[0m";
        }

        echo PHP_EOL . PHP_EOL;
    }
}
