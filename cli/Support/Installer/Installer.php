<?php
/**
 * Trongate CLI Installer Engine
 *
 * Installs Trongate Framework or Trongate On Ice.
 *
 * Expected starting directory:
 *   project/
 *   ├── cli/
 *   └── trongate
 *
 * Usage:
 *   php trongate install
 *   php trongate install --framework=trongate
 *   php trongate install --framework=trongate-on-ice
 *   php trongate install --framework=trongate@master --base-url=http://127.0.0.1:8000/
 *
 */

declare(strict_types=1);


const TI_VERSION = '0.5.7';

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_CANCELLED = 2;
const EXIT_PREFLIGHT = 3;
const EXIT_NETWORK = 4;
const EXIT_INTEGRITY = 5;
const EXIT_FILESYSTEM = 6;

final class InstallException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $exitCode = EXIT_ERROR,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

final class Context
{
    public string $frameworkKey = '';
    public string $ref = '';
    public string $baseUrl = 'http://127.0.0.1:8000/';
    public string $expectedSha256 = '';
    public string $targetDir = '';
    public string $tempZipFile = '';
    public string $extractPath = '';
    public string $sourceDir = '';
    public string $configFile = '';
    public string $databaseConfigFile = '';
    public string $databaseName = '';
    public ?string $originalConfig = null;
    public ?string $originalDatabaseConfig = null;
    public ?string $originalSiteOwnerConfig = null;
    public ?string $originalCompleteView = null;
    public ?string $originalSetupController = null;
    public ?array $databaseSettings = null;
    public bool $databaseConfigChanged = false;
    public bool $databaseCreated = false;
    public ?array $framework = null;

    /** Absolute paths created by this installation, in creation order. */
    public array $createdPaths = [];

    public bool $dryRun = false;
    public bool $nonInteractive = false;
    public bool $quiet = false;
    public bool $verbose = false;
    public bool $gitUsed = false;
    public bool $configChanged = false;
    public bool $siteOwnerConfigChanged = false;
    public bool $completeViewInstalled = false;
    public bool $setupControllerUpdated = false;
}

interface Output
{
    public function line(string $message = ''): void;
    public function info(string $message): void;
    public function success(string $message): void;
    public function warn(string $message): void;
    public function error(string $message): void;
    public function bold(string $message): void;
    public function hr(): void;
    public function progress(float $ratio, string $label = ''): void;
    public function ask(string $question, string $default = ''): string;
    public function confirm(string $question, bool $default = true): bool;
    public function select(string $question, array $options, string $default): string;
    public function box(string $message, string $color = 'green'): void;
}

final class TerminalOutput implements Output
{
    private bool $color;
    private bool $windows;

    public function __construct(private bool $quiet = false)
    {
        $this->windows = PHP_OS_FAMILY === 'Windows';
        $this->color = getenv('NO_COLOR') === false
            && function_exists('stream_isatty')
            && @stream_isatty(STDOUT);
    }

    private function paint(string $text, string $style): string
    {
        if (!$this->color) {
            return $text;
        }

        $codes = [
            'green' => '32',
            'yellow' => '33',
            'cyan' => '36',
            'red' => '31',
            'bold' => '1',
            'dim' => '2',
        ];

        return isset($codes[$style])
            ? "\033[" . $codes[$style] . "m" . $text . "\033[0m"
            : $text;
    }

    public function line(string $message = ''): void
    {
        if (!$this->quiet) {
            echo $message . PHP_EOL;
        }
    }

    public function info(string $message): void
    {
        if (!$this->quiet) {
            echo $this->paint('  i ', 'cyan') . $message . PHP_EOL;
        }
    }

    public function success(string $message): void
    {
        if (!$this->quiet) {
            echo $this->paint('  OK ', 'green') . $message . PHP_EOL;
        }
    }

    public function warn(string $message): void
    {
        if (!$this->quiet) {
            echo $this->paint('  ! ', 'yellow') . $message . PHP_EOL;
        }
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $this->paint('  ERROR ', 'red') . $message . PHP_EOL);
    }

    public function bold(string $message): void
    {
        if (!$this->quiet) {
            echo $this->paint($message, 'bold') . PHP_EOL;
        }
    }

    public function hr(): void
    {
        if (!$this->quiet) {
            echo str_repeat($this->windows ? '-' : '─', 64) . PHP_EOL;
        }
    }

    public function progress(float $ratio, string $label = ''): void
    {
        if ($this->quiet) {
            return;
        }

        $ratio = max(0.0, min(1.0, $ratio));
        $filled = (int) round($ratio * 24);
        $bar = str_repeat('#', $filled) . str_repeat('-', 24 - $filled);

        printf(
            "\r  [%s] %3d%% %s   ",
            $bar,
            (int) round($ratio * 100),
            $label
        );

        if ($ratio >= 1.0) {
            echo PHP_EOL;
        }
    }

    public function ask(string $question, string $default = ''): string
    {
        $suffix = $default !== '' ? " [{$default}]" : '';
        echo $question . $suffix . ': ';

        $answer = fgets(STDIN);

        if ($answer === false) {
            return $default;
        }

        $answer = trim($answer);
        return $answer === '' ? $default : $answer;
    }

    public function confirm(string $question, bool $default = true): bool
    {
        $hint = $default ? 'Y/n' : 'y/N';

        while (true) {
            echo $question . " [{$hint}]: ";
            $answer = strtolower(trim((string) fgets(STDIN)));

            if ($answer === '') {
                return $default;
            }

            if (in_array($answer, ['y', 'yes'], true)) {
                return true;
            }

            if (in_array($answer, ['n', 'no'], true)) {
                return false;
            }

            $this->warn('Please answer yes or no.');
        }
    }

    public function select(string $question, array $options, string $default): string
    {
        $keys = array_keys($options);
        $this->bold($question);

        foreach ($options as $key => $label) {
            $suffix = $key === $default ? ' (default)' : '';
            $position = array_search($key, $keys, true) + 1;
            $this->line('  ' . $position . '. ' . $label . $suffix);
        }

        while (true) {
            $answer = $this->ask('Select an option', '1');

            if (ctype_digit($answer) && isset($keys[(int) $answer - 1])) {
                return $keys[(int) $answer - 1];
            }

            if (isset($options[$answer])) {
                return $answer;
            }

            $this->warn('Invalid selection.');
        }
    }

    public function box(string $message, string $color = 'green'): void
    {
        if ($this->quiet) {
            return;
        }

        $width = 60;
        $text = substr('  ' . $message, 0, $width);
        $pad = str_pad($text, $width);
        $border = '+' . str_repeat('-', $width) . '+';

        echo $this->paint($border, $color) . PHP_EOL;
        echo $this->paint('|' . $pad . '|', $color) . PHP_EOL;
        echo $this->paint($border, $color) . PHP_EOL;
    }
}

final class Logger
{
    private string $file;

    public function __construct(string $projectDirectory)
    {
        $cliDirectory = $projectDirectory . DIRECTORY_SEPARATOR . 'cli';

        if (!is_dir($cliDirectory) || !is_writable($cliDirectory)) {
            throw new InstallException(
                'The cli/ directory must exist and be writable.',
                EXIT_FILESYSTEM
            );
        }

        $this->file = $cliDirectory . DIRECTORY_SEPARATOR . 'cli.log';
    }

    public function log(string $message): void
    {
        $written = @file_put_contents(
            $this->file,
            '[' . date(DATE_ATOM) . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($written === false) {
            // Logging must not hide the original installation error.
            return;
        }
    }

    public function path(): string
    {
        return $this->file;
    }
}

/**
 * Confirms that the command is being run from the CLI project's root.
 * Existing root entries other than cli/ and the trongate launcher are rejected.
 */
final class ProjectValidator
{
    public function validate(): string
    {
        $cwd = getcwd();

        if ($cwd === false || !is_dir($cwd)) {
            throw new InstallException(
                'Could not determine the current working directory.',
                EXIT_FILESYSTEM
            );
        }

        $cliDirectory = $cwd . DIRECTORY_SEPARATOR . 'cli';
        $launcher = $cwd . DIRECTORY_SEPARATOR . 'trongate';

        if (is_link($cliDirectory) || !is_dir($cliDirectory)) {
            throw new InstallException(
                'Invalid installer location: the cli/ directory is missing or is a symbolic link.',
                EXIT_PREFLIGHT
            );
        }

        if (is_link($launcher) || !is_file($launcher)) {
            throw new InstallException(
                'Invalid installer location: the trongate launcher is missing or is a symbolic link.',
                EXIT_PREFLIGHT
            );
        }

        if (!is_writable($cwd) || !is_writable($cliDirectory)) {
            throw new InstallException(
                'The current directory and cli/ directory must be writable.',
                EXIT_FILESYSTEM
            );
        }

        $items = scandir($cwd);

        if ($items === false) {
            throw new InstallException(
                'Could not inspect the current directory.',
                EXIT_FILESYSTEM
            );
        }

        $allowed = ['.', '..', 'cli', 'trongate', 'complete.php'];
        $unexpected = array_values(array_diff($items, $allowed));

        if ($unexpected !== []) {
            sort($unexpected);

            throw new InstallException(
                'The current directory contains existing project files. '
                . 'Installation stopped to prevent overwriting: '
                . implode(', ', $unexpected),
                EXIT_FILESYSTEM
            );
        }

        return $cwd;
    }
}

final class FrameworkRegistry
{
    private array $frameworks = [];
    private string $default = 'trongate';

    public function __construct()
    {
        $this->register('trongate', [
            'label' => 'Trongate Framework',
            'description' => 'The standard, actively maintained Trongate framework.',
            'repo' => 'https://github.com/trongate/trongate-framework.git',
            'zip_template' => 'https://github.com/trongate/trongate-framework/archive/{ref}.zip',
            'default_ref' => 'master',
        ]);

        $this->register('trongate-on-ice', [
            'label' => 'Trongate On Ice',
            'description' => 'A stability-focused edition for tutorials, education and long-term projects.',
            'repo' => 'https://github.com/trongate/Trongate-On-Ice.git',
            'zip_template' => 'https://github.com/trongate/Trongate-On-Ice/archive/{ref}.zip',
            'default_ref' => 'main',
        ]);

        $extra = getenv('TRONGATE_FRAMEWORKS_FILE');

        if ($extra !== false && $extra !== '' && is_file($extra)) {
            $this->loadFile($extra);
        }
    }

    public function register(string $key, array $definition): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $key)) {
            throw new InstallException("Invalid framework registry key: {$key}");
        }

        foreach (['label', 'description', 'repo', 'zip_template', 'default_ref'] as $field) {
            if (
                !isset($definition[$field])
                || !is_string($definition[$field])
                || $definition[$field] === ''
            ) {
                throw new InstallException(
                    "Framework '{$key}' has an invalid or missing '{$field}' field."
                );
            }
        }

        if (
            !filter_var(
                str_replace('{ref}', 'main', $definition['zip_template']),
                FILTER_VALIDATE_URL
            )
        ) {
            throw new InstallException(
                "Framework '{$key}' has an invalid ZIP URL template."
            );
        }

        if (
            !filter_var($definition['repo'], FILTER_VALIDATE_URL)
            || parse_url($definition['repo'], PHP_URL_SCHEME) !== 'https'
        ) {
            throw new InstallException(
                "Framework '{$key}' has an invalid repository URL."
            );
        }

        $this->frameworks[$key] = $definition;
    }

    public function loadFile(string $file): void
    {
        $definitions = @include $file;

        if (!is_array($definitions)) {
            throw new InstallException(
                'Framework registry file must return an array.'
            );
        }

        foreach ($definitions as $key => $definition) {
            if (!is_string($key) || !is_array($definition)) {
                throw new InstallException('Invalid framework registry entry.');
            }

            $this->register($key, $definition);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->frameworks[$key]);
    }

    public function get(string $key): array
    {
        if (!$this->has($key)) {
            throw new InstallException("Unknown framework edition: {$key}");
        }

        return $this->frameworks[$key];
    }

    public function all(): array
    {
        return $this->frameworks;
    }

    public function defaultKey(): string
    {
        return $this->default;
    }

    public function zipUrl(string $key, string $ref): string
    {
        return str_replace(
            '{ref}',
            rawurlencode($ref),
            $this->get($key)['zip_template']
        );
    }
}

final class Preflight
{
    public function check(bool $requireZip = false): array
    {
        $issues = [];

        if (PHP_VERSION_ID < 80000) {
            $issues[] = 'PHP 8.0 or newer is required; found ' . PHP_VERSION . '.';
        }

        $zipAvailable = class_exists(ZipArchive::class);
        $gitAvailable = $this->gitAvailable();

        if (!$zipAvailable && ($requireZip || !$gitAvailable)) {
            $issues[] = $requireZip
                ? "The PHP 'zip' extension is required when --sha256 is used."
                : "The PHP 'zip' extension is required when Git is unavailable.";
        }

        if (
            !extension_loaded('curl')
            && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
        ) {
            $issues[] = 'Enable cURL or allow_url_fopen to download the framework.';
        }

        if (!extension_loaded('pdo_mysql')) {
            $issues[] = "The PHP 'pdo_mysql' extension is required to configure and create the MySQL/MariaDB database.";
        }

        if (!function_exists('token_get_all')) {
            $issues[] = 'The PHP tokenizer is required.';
        }

        return $issues;
    }

    /**
     * Detect Git without making it a mandatory dependency.
     */
    private function gitAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $output = [];
        $code = 1;

        @exec('git --version 2>&1', $output, $code);

        return $code === 0;
    }
}

final class Downloader
{
    private $progress = null;

    public function setProgress(?callable $callback): void
    {
        $this->progress = $callback;
    }

    /**
     * Test the actual GitHub host used for source retrieval.
     */
    public function checkConnectivity(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            return false;
        }

        $testUrl = 'https://' . $host . '/';

        if (extension_loaded('curl')) {
            $handle = curl_init($testUrl);

            if ($handle === false) {
                return false;
            }

            curl_setopt_array($handle, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Trongate-Installer/' . TI_VERSION,
            ]);

            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return $result !== false && $status >= 200 && $status < 400;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
                'header' => "Range: bytes=0-0\r\n",
                'user_agent' => 'Trongate-Installer/' . TI_VERSION,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $stream = @fopen($testUrl, 'rb', false, $context);

        if ($stream === false) {
            return false;
        }

        fclose($stream);
        return true;
    }

    public function download(string $url): string
    {
        if (
            !filter_var($url, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new InstallException(
                'Refusing to download from an invalid or non-HTTPS URL.',
                EXIT_NETWORK
            );
        }

        return extension_loaded('curl')
            ? $this->curl($url)
            : $this->streams($url);
    }

    private function curl(string $url): string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new InstallException(
                'Unable to initialise cURL.',
                EXIT_NETWORK
            );
        }

        $data = '';

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'Trongate-Installer/' . TI_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$data): int {
                $data .= $chunk;
                return strlen($chunk);
            },
        ]);

        if ($this->progress !== null && defined('CURLOPT_XFERINFOFUNCTION')) {
            curl_setopt($handle, CURLOPT_NOPROGRESS, false);
            curl_setopt(
                $handle,
                CURLOPT_XFERINFOFUNCTION,
                function ($curl, float $total, float $now): int {
                    if ($total > 0) {
                        ($this->progress)($now / $total);
                    }

                    return 0;
                }
            );
        }

        $ok = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($ok === false) {
            throw new InstallException(
                'Download failed: ' . $error,
                EXIT_NETWORK
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new InstallException(
                "Download returned HTTP {$status}.",
                EXIT_NETWORK
            );
        }

        if ($data === '') {
            throw new InstallException(
                'The downloaded archive is empty.',
                EXIT_INTEGRITY
            );
        }

        if ($this->progress !== null) {
            ($this->progress)(1.0);
        }

        return $data;
    }

    private function streams(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 300,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'Trongate-Installer/' . TI_VERSION,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Use a stream resource so response headers can be read from its metadata.
        // This avoids relying on PHP's legacy HTTP response-header variable.
        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new InstallException(
                'Unable to download the framework archive.',
                EXIT_NETWORK
            );
        }

        $metadata = stream_get_meta_data($stream);
        $responseHeaders = $metadata['wrapper_data'] ?? [];
        $statusLine = '';

        // Redirects can produce multiple HTTP status lines. Keep the final one.
        if (is_array($responseHeaders)) {
            foreach ($responseHeaders as $headerLine) {
                if (
                    is_string($headerLine)
                    && preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $headerLine)
                ) {
                    $statusLine = $headerLine;
                }
            }
        }

        $data = stream_get_contents($stream);
        fclose($stream);

        if ($data === false) {
            throw new InstallException(
                'Unable to read the downloaded framework archive.',
                EXIT_NETWORK
            );
        }

        if (!preg_match('/\s2\d\d\s/', $statusLine)) {
            throw new InstallException(
                'Download failed: ' . ($statusLine ?: 'unknown HTTP response'),
                EXIT_NETWORK
            );
        }

        if ($data === '') {
            throw new InstallException(
                'The downloaded archive is empty.',
                EXIT_INTEGRITY
            );
        }

        if ($this->progress !== null) {
            ($this->progress)(1.0);
        }

        return $data;
    }
}

final class FileSystem
{
    /**
     * Replace a file safely on Unix-like systems and Windows.
     * Windows may reject rename($temporary, $destination) when the destination
     * already exists, so retain a backup and restore it if the replacement fails.
     */
    public static function replaceFile(string $temporary, string $destination): bool
    {
        if (!is_file($temporary) || !is_writable(dirname($destination))) {
            return false;
        }

        if (!file_exists($destination) && !is_link($destination)) {
            return @rename($temporary, $destination);
        }

        // POSIX rename is atomic and replaces an existing regular file.
        if (PHP_OS_FAMILY !== 'Windows' && !is_link($destination)) {
            return @rename($temporary, $destination);
        }

        $backup = $destination . '.installer-backup-' . bin2hex(random_bytes(6));

        if (!@rename($destination, $backup)) {
            return false;
        }

        if (@rename($temporary, $destination)) {
            @unlink($backup);
            return true;
        }

        // Do not leave the user's original file displaced if replacement fails.
        if (!@rename($backup, $destination)) {
            // Keep the backup rather than deleting the only remaining original.
            return false;
        }

        return false;
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new InstallException(
                    'Could not remove file: ' . $path,
                    EXIT_FILESYSTEM
                );
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            throw new InstallException(
                'Could not read directory: ' . $path,
                EXIT_FILESYSTEM
            );
        }

        foreach (array_diff($items, ['.', '..']) as $item) {
            self::removeTree($path . DIRECTORY_SEPARATOR . $item);
        }

        if (!@rmdir($path)) {
            throw new InstallException(
                'Could not remove directory: ' . $path,
                EXIT_FILESYSTEM
            );
        }
    }

    public static function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * Build a recursive source iterator that excludes Git metadata.
     *
     * GitHub clones contain a .git/ directory with repository history,
     * object packs and internal metadata. These files are not part of the
     * installed framework and must never be copied into the user's project.
     * Filtering the directory itself also prevents traversal into its contents.
     */
    private static function frameworkIterator(string $source): RecursiveIteratorIterator
    {
        $directory = new RecursiveDirectoryIterator(
            $source,
            FilesystemIterator::SKIP_DOTS
        );

        $filtered = new RecursiveCallbackFilterIterator(
            $directory,
            static function ($item): bool {
                return $item->getFilename() !== '.git';
            }
        );

        return new RecursiveIteratorIterator(
            $filtered,
            RecursiveIteratorIterator::SELF_FIRST
        );
    }

    /**
     * Verify all source entries before writing any project files.
     * Existing paths are never overwritten or merged. Git metadata is ignored.
     */
    public static function assertNoConflicts(string $source, string $target): void
    {
        $iterator = self::frameworkIterator($source);

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathName();
            $destination = $target . DIRECTORY_SEPARATOR . $relative;

            if ($item->isLink()) {
                throw new InstallException(
                    'Framework source contains a symbolic link: ' . $relative,
                    EXIT_INTEGRITY
                );
            }

            if (file_exists($destination) || is_link($destination)) {
                throw new InstallException(
                    'Installation conflict: "' . $relative . '" already exists. '
                    . 'No existing files have been overwritten.',
                    EXIT_FILESYSTEM
                );
            }
        }
    }

    /**
     * Copy a staged framework tree into the project root.
     * Every created file and directory is recorded for rollback.
     */
    public static function copyIntoProject(
        string $source,
        string $target,
        Context $context,
        bool $dryRun = false
    ): void {
        self::assertNoConflicts($source, $target);

        if ($dryRun) {
            return;
        }

        $iterator = self::frameworkIterator($source);

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathName();
            $destination = $target . DIRECTORY_SEPARATOR . $relative;

            if (!self::isWithin($destination, $target)) {
                throw new InstallException(
                    'Unsafe destination path detected.',
                    EXIT_INTEGRITY
                );
            }

            if ($item->isLink()) {
                throw new InstallException(
                    'Symbolic links are not allowed: ' . $relative,
                    EXIT_INTEGRITY
                );
            }

            if ($item->isDir()) {
                if (!@mkdir($destination, 0775)) {
                    throw new InstallException(
                        'Could not create directory: ' . $relative,
                        EXIT_FILESYSTEM
                    );
                }

                $context->createdPaths[] = $destination;
                continue;
            }

            if (!@copy($item->getPathname(), $destination)) {
                throw new InstallException(
                    'Could not copy framework file: ' . $relative,
                    EXIT_FILESYSTEM
                );
            }

            $context->createdPaths[] = $destination;

            $permissions = @fileperms($item->getPathname());

            if ($permissions !== false) {
                @chmod($destination, $permissions & 0777);
            }
        }
    }

    public static function rollbackCreatedPaths(Context $context): void
    {
        foreach (array_reverse($context->createdPaths) as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                // Remove only directories made by this installer and only if empty.
                @rmdir($path);
            }
        }

        $context->createdPaths = [];
    }
}

final class Extractor
{
    public function extractSafely(string $archive, string $destination): string
    {
        $zip = new ZipArchive();
        $result = $zip->open($archive);

        if ($result !== true) {
            throw new InstallException(
                "Could not open ZIP archive (code {$result}).",
                EXIT_INTEGRITY
            );
        }

        try {
            $root = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (
                    !is_string($name)
                    || $name === ''
                    || str_contains($name, '\\')
                    || str_contains($name, "\0")
                ) {
                    throw new InstallException(
                        'Archive contains an invalid path.',
                        EXIT_INTEGRITY
                    );
                }

                $normal = ltrim($name, '/');
                $parts = explode('/', $normal);

                if (
                    str_starts_with($name, '/')
                    || preg_match('/^[A-Za-z]:/', $name)
                    || in_array('..', $parts, true)
                    || in_array('.', $parts, true)
                ) {
                    throw new InstallException(
                        'Archive contains an unsafe path: ' . $name,
                        EXIT_INTEGRITY
                    );
                }

                if ($normal !== '') {
                    $candidate = $parts[0];

                    if ($root === null) {
                        $root = $candidate;
                    } elseif ($root !== $candidate) {
                        throw new InstallException(
                            'Archive contains multiple top-level directories.',
                            EXIT_INTEGRITY
                        );
                    }
                }

                $opsys = 0;
                $attributes = 0;

                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    $unixMode = ($attributes >> 16) & 0170000;

                    if ($unixMode === 0120000) {
                        throw new InstallException(
                            'Symbolic links are not allowed in framework archives.',
                            EXIT_INTEGRITY
                        );
                    }
                }
            }

            if ($root === null) {
                throw new InstallException(
                    'The ZIP archive is empty.',
                    EXIT_INTEGRITY
                );
            }

            if (!$zip->extractTo($destination)) {
                throw new InstallException(
                    'Could not extract the ZIP archive.',
                    EXIT_INTEGRITY
                );
            }

            return $destination . DIRECTORY_SEPARATOR . $root;
        } finally {
            $zip->close();
        }
    }
}

final class ConfigPatcher
{
    public function patchBaseUrl(string $file, string $url): bool
    {
        if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
            return false;
        }

        $source = file_get_contents($file);

        if ($source === false) {
            return false;
        }

        $updated = $this->replaceDefine($source, 'BASE_URL', $url);

        if ($updated === null) {
            return false;
        }

        if ($updated === $source) {
            if (preg_match('/\bBASE_URL\b/', $source)) {
                return false;
            }

            $updated = rtrim($source)
                . "\n\n// Configured by Trongate Installer on "
                . date('Y-m-d H:i:s')
                . "\ndefine('BASE_URL', "
                . var_export($url, true)
                . ");\n";
        }

        $temp = $file . '.installer-' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temp, $updated, LOCK_EX) === false) {
            return false;
        }

        $permissions = @fileperms($file);

        if ($permissions !== false) {
            @chmod($temp, $permissions & 0777);
        }

        if (!FileSystem::replaceFile($temp, $file)) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    /**
     * Set the displayed site name for the selected framework edition.
     * The change is limited to the WEBSITE_NAME definition in site_owner.php.
     */
    public function patchWebsiteName(string $file, string $name): bool
    {
        if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
            return false;
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return false;
        }

        $updated = $this->replaceDefine($source, 'WEBSITE_NAME', $name);
        if ($updated === null) {
            return false;
        }
        if ($updated === $source) {
            // The requested value may already be configured.
            return true;
        }

        $temp = $file . '.installer-' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $updated, LOCK_EX) === false) {
            return false;
        }

        $permissions = @fileperms($file);
        if ($permissions !== false) {
            @chmod($temp, $permissions & 0777);
        }

        if (!FileSystem::replaceFile($temp, $file)) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    private function replaceDefine(
        string $source,
        string $constant,
        string $value
    ): ?string {
        $tokens = token_get_all($source);
        $output = '';
        $found = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (
                !is_array($token)
                || $token[0] !== T_STRING
                || strtolower($token[1]) !== 'define'
            ) {
                $output .= is_array($token) ? $token[1] : $token;
                continue;
            }

            $j = $i + 1;

            while (
                $j < $count
                && is_array($tokens[$j])
                && in_array(
                    $tokens[$j][0],
                    [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    true
                )
            ) {
                $j++;
            }

            if ($j >= $count || $tokens[$j] !== '(') {
                $output .= $token[1];
                continue;
            }

            $nameIndex = $j + 1;

            while (
                $nameIndex < $count
                && is_array($tokens[$nameIndex])
                && $tokens[$nameIndex][0] === T_WHITESPACE
            ) {
                $nameIndex++;
            }

            if (
                $nameIndex >= $count
                || !is_array($tokens[$nameIndex])
                || $tokens[$nameIndex][0] !== T_CONSTANT_ENCAPSED_STRING
            ) {
                $output .= $token[1];
                continue;
            }

            $name = stripcslashes(trim($tokens[$nameIndex][1], "'\""));

            if ($name !== $constant) {
                $output .= $token[1];
                continue;
            }

            $comma = $nameIndex + 1;

            while (
                $comma < $count
                && is_array($tokens[$comma])
                && $tokens[$comma][0] === T_WHITESPACE
            ) {
                $comma++;
            }

            if ($comma >= $count || $tokens[$comma] !== ',') {
                return null;
            }

            $valueIndex = $comma + 1;

            while (
                $valueIndex < $count
                && is_array($tokens[$valueIndex])
                && $tokens[$valueIndex][0] === T_WHITESPACE
            ) {
                $valueIndex++;
            }

            if (
                $valueIndex >= $count
                || !is_array($tokens[$valueIndex])
                || $tokens[$valueIndex][0] !== T_CONSTANT_ENCAPSED_STRING
            ) {
                return null;
            }

            $output .= $token[1];

            for ($k = $i + 1; $k < $valueIndex; $k++) {
                $output .= is_array($tokens[$k])
                    ? $tokens[$k][1]
                    : $tokens[$k];
            }

            $output .= var_export($value, true);
            $i = $valueIndex;
            $found++;
        }

        if ($found > 1) {
            return null;
        }

        return $output;
    }

    public function readBaseUrl(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $source = file_get_contents($file);

        if ($source === false) {
            return null;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (
                !is_array($tokens[$i])
                || $tokens[$i][0] !== T_STRING
                || strtolower($tokens[$i][1]) !== 'define'
            ) {
                continue;
            }

            $j = $i + 1;

            while (
                $j < $count
                && is_array($tokens[$j])
                && $tokens[$j][0] === T_WHITESPACE
            ) {
                $j++;
            }

            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }

            $j++;

            while (
                $j < $count
                && is_array($tokens[$j])
                && $tokens[$j][0] === T_WHITESPACE
            ) {
                $j++;
            }

            if (
                !isset($tokens[$j])
                || !is_array($tokens[$j])
                || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING
            ) {
                continue;
            }

            if (stripcslashes(trim($tokens[$j][1], "'\"")) !== 'BASE_URL') {
                continue;
            }

            $j++;

            while (
                $j < $count
                && is_array($tokens[$j])
                && $tokens[$j][0] === T_WHITESPACE
            ) {
                $j++;
            }

            if (($tokens[$j] ?? null) !== ',') {
                continue;
            }

            $j++;

            while (
                $j < $count
                && is_array($tokens[$j])
                && $tokens[$j][0] === T_WHITESPACE
            ) {
                $j++;
            }

            if (
                isset($tokens[$j])
                && is_array($tokens[$j])
                && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING
            ) {
                return stripcslashes(trim($tokens[$j][1], "'\""));
            }
        }

        return null;
    }
}


/**
 * Configures config/database.php and creates the MySQL database.
 *
 * The database name is derived from the current project directory name.
 * Only the default connection is modified; host, port, username and
 * password are preserved.
 */
final class DatabaseConfigurator
{
    public function databaseName(string $projectDirectory, string $baseUrl = ''): string
    {
        // The database name always comes from the project root directory,
        // never from BASE_URL (which may contain a virtual subdirectory).
        $normalizedPath = str_replace(
            '\\',
            '/',
            rtrim($projectDirectory, "/\\")
        );
        $name = strtolower(basename($normalizedPath));

        // Convert the directory name into a valid MySQL identifier.
        $name = preg_replace('/[^a-z0-9_$]/', '_', $name) ?? '';

        if ($name === '' || !preg_match('/^[a-z_]/', $name)) {
            $name = 'trongate_' . $name;
        }

        // MySQL database identifiers are limited to 64 characters.
        return substr($name, 0, 64);
    }

    public function readSettings(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new InstallException(
                'config/database.php is missing or is not readable.',
                EXIT_FILESYSTEM
            );
        }

        $loader = static function (string $__file): array {
            $databases = [];
            require $__file;
            return is_array($databases) ? $databases : [];
        };

        $databases = $loader($file);
        $settings = $databases['default'] ?? null;

        if (!is_array($settings)) {
            throw new InstallException(
                "config/database.php must define a 'default' database connection.",
                EXIT_INTEGRITY
            );
        }

        foreach (['host', 'port', 'user', 'password', 'database'] as $key) {
            if (!array_key_exists($key, $settings)) {
                throw new InstallException(
                    "The default database connection is missing '{$key}'.",
                    EXIT_INTEGRITY
                );
            }
        }

        return $settings;
    }

    public function patchDatabaseName(string $file, string $databaseName): bool
    {
        if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
            return false;
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return false;
        }

        // Restrict the replacement to the default connection array.
        $pattern = '/([\'"]default[\'"]\s*=>\s*\[.*?[\'"]database[\'"]\s*=>\s*)([\'"])(.*?)(\2)/s';

        $updated = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($databaseName): string {
                return $matches[1]
                    . "'"
                    . addcslashes($databaseName, "\\\\'")
                    . "'";
            },
            $source,
            1,
            $count
        );

        if (!is_string($updated) || $count !== 1) {
            return false;
        }

        if ($updated === $source) {
            // A database value may already match; still count as a valid patch.
            $settings = $this->readSettings($file);
            return ($settings['database'] ?? null) === $databaseName;
        }

        $temp = $file . '.installer-' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temp, $updated, LOCK_EX) === false) {
            return false;
        }

        $permissions = @fileperms($file);
        if ($permissions !== false) {
            @chmod($temp, $permissions & 0777);
        }

        if (!FileSystem::replaceFile($temp, $file)) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    public function createDatabase(array $settings, string $databaseName): bool
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new InstallException(
                'The PDO MySQL extension is required to create the database.',
                EXIT_PREFLIGHT
            );
        }

        $host = (string) $settings['host'];
        $port = (string) $settings['port'];
        $user = (string) $settings['user'];
        $password = (string) $settings['password'];

        if ($host === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InstallException(
                'The MySQL host or port in config/database.php is invalid.',
                EXIT_INTEGRITY
            );
        }

        try {
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $user,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 10,
                ]
            );

            $check = $pdo->prepare(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?'
            );
            $check->execute([$databaseName]);
            $existed = $check->fetchColumn() !== false;

            $quotedName = '`' . str_replace('`', '``', $databaseName) . '`';
            $pdo->exec(
                'CREATE DATABASE IF NOT EXISTS ' . $quotedName
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            return !$existed;
        } catch (\PDOException $error) {
            throw new InstallException(
                'Could not create the MySQL database. Check the credentials and database privileges in config/database.php. '
                . $error->getMessage(),
                EXIT_FILESYSTEM,
                $error
            );
        }
    }

    public function dropDatabase(array $settings, string $databaseName): void
    {
        if (!extension_loaded('pdo_mysql')) {
            return;
        }

        $dsn = 'mysql:host=' . $settings['host']
            . ';port=' . $settings['port']
            . ';charset=utf8mb4';

        $pdo = new \PDO(
            $dsn,
            (string) $settings['user'],
            (string) $settings['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $quotedName = '`' . str_replace('`', '``', $databaseName) . '`';
        $pdo->exec('DROP DATABASE IF EXISTS ' . $quotedName);
    }
}

interface Step
{
    public function label(): string;
    public function run(Context $context): void;
    public function rollback(Context $context): void;
}

abstract class BaseStep implements Step
{
    public function rollback(Context $context): void
    {
    }
}

final class PreflightStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger
    ) {
    }

    public function label(): string
    {
        return 'Preflight checks';
    }

    public function run(Context $context): void
    {
        $issues = (new Preflight())->check($context->expectedSha256 !== '');

        if ($issues !== []) {
            foreach ($issues as $issue) {
                $this->output->error($issue);
            }

            $this->logger->log('Preflight failed: ' . implode(' | ', $issues));

            throw new InstallException(
                'Preflight checks failed.',
                EXIT_PREFLIGHT
            );
        }

        $this->output->success(
            'PHP ' . PHP_VERSION . ' and required capabilities detected.'
        );

        $this->logger->log('Preflight passed.');
    }
}

final class NetworkStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger,
        private Downloader $downloader,
        private FrameworkRegistry $registry
    ) {
    }

    public function label(): string
    {
        return 'Check network connectivity';
    }

    public function run(Context $context): void
    {
        $url = $this->registry->zipUrl(
            $context->frameworkKey,
            $context->ref
        );

        if (!$this->downloader->checkConnectivity($url)) {
            $this->logger->log('Network connectivity check failed: ' . $url);

            throw new InstallException(
                'Unable to connect. Check your internet connection, DNS and firewall.',
                EXIT_NETWORK
            );
        }

        $this->output->success('GitHub connectivity verified.');
        $this->logger->log('Network connectivity verified: ' . $url);
    }
}

final class FetchStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger,
        private Downloader $downloader,
        private FrameworkRegistry $registry
    ) {
    }

    public function label(): string
    {
        return 'Fetch framework source';
    }

    public function run(Context $context): void
    {
        if ($context->dryRun) {
            $this->output->warn(
                'Dry run: framework source would be fetched from '
                . $context->framework['repo']
            );
            return;
        }

        if (
            $context->expectedSha256 === ''
            && $this->gitAvailable()
            && $this->cloneWithGit($context)
        ) {
            $context->gitUsed = true;
            $this->output->success('Framework cloned using Git.');
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            throw new InstallException(
                'Git cloning was unavailable or failed, and PHP ZipArchive is not enabled. '
                . 'Enable the PHP zip extension or install/configure Git, then try again.',
                EXIT_PREFLIGHT
            );
        }

        $this->output->info(
            'Git unavailable or clone failed; using ZIP download.'
        );

        $url = $this->registry->zipUrl(
            $context->frameworkKey,
            $context->ref
        );

        $bytes = $this->downloader->download($url);

        if ($context->expectedSha256 !== '') {
            $actual = hash('sha256', $bytes);

            if (!hash_equals($context->expectedSha256, $actual)) {
                throw new InstallException(
                    'SHA-256 checksum mismatch. The archive was rejected.',
                    EXIT_INTEGRITY
                );
            }

            $this->output->success('SHA-256 checksum verified.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'tg-installer-');

        if (
            $temp === false
            || file_put_contents($temp, $bytes, LOCK_EX) === false
        ) {
            throw new InstallException(
                'Could not save downloaded archive.',
                EXIT_FILESYSTEM
            );
        }

        $context->tempZipFile = $temp;

        $this->logger->log(
            'Downloaded ZIP (' . strlen($bytes) . ' bytes).'
        );

        $this->output->success('Framework archive downloaded.');
    }

    private function gitAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $lines = [];
        $code = 1;

        @exec('git --version 2>&1', $lines, $code);

        return $code === 0;
    }

    private function cloneWithGit(Context $context): bool
    {
        $staging = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'trongate-git-'
            . bin2hex(random_bytes(6));

        $command = 'git clone --depth 1 --branch '
            . escapeshellarg($context->ref)
            . ' '
            . escapeshellarg($context->framework['repo'])
            . ' '
            . escapeshellarg($staging)
            . ' 2>&1';

        $lines = [];
        $code = 1;

        @exec($command, $lines, $code);

        if ($code === 0 && is_dir($staging)) {
            $gitMetadata = $staging . DIRECTORY_SEPARATOR . '.git';

            if (is_dir($gitMetadata) || is_link($gitMetadata)) {
                try {
                    FileSystem::removeTree($gitMetadata);
                } catch (Throwable $error) {
                    // The copy iterator independently excludes .git, so a
                    // cleanup failure must not cause repository metadata to
                    // enter the installed project.
                    $this->logger->log(
                        'Git metadata cleanup warning: ' . $error->getMessage()
                    );
                }
            }

            $context->sourceDir = $staging;
            $context->gitUsed = true;
            $this->logger->log('Git clone completed (without .git metadata): ' . $staging);
            return true;
        }

        if (file_exists($staging)) {
            try {
                FileSystem::removeTree($staging);
            } catch (Throwable $error) {
                $this->logger->log(
                    'Failed Git staging cleanup: ' . $error->getMessage()
                );
            }
        }

        $this->logger->log(
            'Git clone failed: ' . implode(' | ', $lines)
        );

        return false;
    }

    public function rollback(Context $context): void
    {
        if (
            $context->sourceDir !== ''
            && is_dir($context->sourceDir)
        ) {
            FileSystem::removeTree($context->sourceDir);
            $context->sourceDir = '';
        }

        if (
            $context->tempZipFile !== ''
            && is_file($context->tempZipFile)
        ) {
            @unlink($context->tempZipFile);
            $context->tempZipFile = '';
        }
    }
}

final class PlaceStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger,
        private Extractor $extractor
    ) {
    }

    public function label(): string
    {
        return 'Install framework files';
    }

    public function run(Context $context): void
    {
        if ($context->dryRun) {
            $this->output->warn(
                'Dry run: framework files would be installed in '
                . $context->targetDir
            );
            return;
        }

        if ($context->sourceDir === '') {
            if (
                $context->tempZipFile === ''
                || !is_file($context->tempZipFile)
            ) {
                throw new InstallException(
                    'Downloaded ZIP archive is missing.',
                    EXIT_INTEGRITY
                );
            }

            $extract = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR
                . 'trongate-extract-'
                . bin2hex(random_bytes(6));

            if (!@mkdir($extract, 0700)) {
                throw new InstallException(
                    'Could not create temporary extraction directory.',
                    EXIT_FILESYSTEM
                );
            }

            $context->extractPath = $extract;
            $context->sourceDir = $this->extractor->extractSafely(
                $context->tempZipFile,
                $extract
            );
        }

        if (!is_dir($context->sourceDir)) {
            throw new InstallException(
                'Framework source directory was not found.',
                EXIT_INTEGRITY
            );
        }

        // Check all conflicts before copying the first framework file.
        FileSystem::assertNoConflicts(
            $context->sourceDir,
            $context->targetDir
        );

        FileSystem::copyIntoProject(
            $context->sourceDir,
            $context->targetDir,
            $context
        );

        $this->output->success(
            'Installed ' . ($context->framework['label'] ?? 'framework')
            . ' files in the current directory.'
        );

        $this->logger->log(
            'Installed ' . count($context->createdPaths) . ' filesystem entries.'
        );

        if ($context->sourceDir !== '' && is_dir($context->sourceDir)) {
            FileSystem::removeTree($context->sourceDir);
            $context->sourceDir = '';
        }

        if ($context->extractPath !== '' && is_dir($context->extractPath)) {
            FileSystem::removeTree($context->extractPath);
            $context->extractPath = '';
        }

        if (
            $context->tempZipFile !== ''
            && is_file($context->tempZipFile)
        ) {
            @unlink($context->tempZipFile);
            $context->tempZipFile = '';
        }
    }

    public function rollback(Context $context): void
    {
        FileSystem::rollbackCreatedPaths($context);

        if ($context->sourceDir !== '' && is_dir($context->sourceDir)) {
            FileSystem::removeTree($context->sourceDir);
            $context->sourceDir = '';
        }

        if ($context->extractPath !== '' && is_dir($context->extractPath)) {
            FileSystem::removeTree($context->extractPath);
            $context->extractPath = '';
        }

        if ($context->tempZipFile !== '' && is_file($context->tempZipFile)) {
            @unlink($context->tempZipFile);
            $context->tempZipFile = '';
        }
    }
}

final class ConfigStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger,
        private ConfigPatcher $patcher
    ) {
    }

    public function label(): string
    {
        return 'Configure project URL';
    }

    public function run(Context $context): void
    {
        $context->configFile = $context->targetDir
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'config.php';

        if ($context->dryRun) {
            $this->output->warn(
                'Dry run: BASE_URL would be set to ' . $context->baseUrl
            );
            return;
        }

        if (!is_file($context->configFile)) {
            $this->output->warn(
                'config/config.php was not found; BASE_URL was not changed.'
            );
            return;
        }

        $original = file_get_contents($context->configFile);

        if ($original === false) {
            throw new InstallException(
                'Could not read config/config.php.',
                EXIT_FILESYSTEM
            );
        }

        $context->originalConfig = $original;

        if (
            !$this->patcher->patchBaseUrl(
                $context->configFile,
                $context->baseUrl
            )
        ) {
            throw new InstallException(
                'Could not safely update BASE_URL. Review config/config.php manually.',
                EXIT_FILESYSTEM
            );
        }

        $context->configChanged = true;

        $this->output->success('BASE_URL configured.');
        $this->logger->log(
            'BASE_URL updated to ' . $context->baseUrl
        );

        // Trongate On Ice uses its own site name; leave the standard edition
        // configuration exactly as supplied by its repository.
        if ($context->frameworkKey === 'trongate-on-ice') {
            $siteOwnerFile = $context->targetDir
                . DIRECTORY_SEPARATOR . 'config'
                . DIRECTORY_SEPARATOR . 'site_owner.php';

            if (is_file($siteOwnerFile)) {
                $originalSiteOwner = file_get_contents($siteOwnerFile);
                if ($originalSiteOwner === false) {
                    throw new InstallException(
                        'Could not read config/site_owner.php.',
                        EXIT_FILESYSTEM
                    );
                }

                $context->originalSiteOwnerConfig = $originalSiteOwner;
                if (!$this->patcher->patchWebsiteName(
                    $siteOwnerFile,
                    'Trongate on Ice'
                )) {
                    throw new InstallException(
                        'Could not update WEBSITE_NAME in config/site_owner.php.',
                        EXIT_FILESYSTEM
                    );
                }

                $context->siteOwnerConfigChanged = true;
                $this->output->success('Site name set to Trongate on Ice.');
                $this->logger->log('WEBSITE_NAME set to Trongate on Ice.');
            } else {
                $this->output->warn(
                    'config/site_owner.php was not found; the site name was not changed.'
                );
            }
        }
    }

    public function rollback(Context $context): void
    {
        if (
            $context->configChanged
            && $context->originalConfig !== null
            && is_file($context->configFile)
        ) {
            $temp = $context->configFile
                . '.rollback-'
                . bin2hex(random_bytes(4));

            if (
                file_put_contents(
                    $temp,
                    $context->originalConfig,
                    LOCK_EX
                ) !== false
            ) {
                FileSystem::replaceFile($temp, $context->configFile);
            }
        }

        if (
            $context->siteOwnerConfigChanged
            && $context->originalSiteOwnerConfig !== null
        ) {
            $siteOwnerFile = $context->targetDir
                . DIRECTORY_SEPARATOR . 'config'
                . DIRECTORY_SEPARATOR . 'site_owner.php';
            $temp = $siteOwnerFile . '.rollback-' . bin2hex(random_bytes(4));
            if (file_put_contents(
                $temp,
                $context->originalSiteOwnerConfig,
                LOCK_EX
            ) !== false) {
                FileSystem::replaceFile($temp, $siteOwnerFile);
            }
        }
    }
}


final class DatabaseStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger,
        private DatabaseConfigurator $database
    ) {
    }

    public function label(): string
    {
        return 'Configure and create database';
    }

    public function run(Context $context): void
    {
        $context->databaseConfigFile = $context->targetDir
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'database.php';

        $context->databaseName = $this->database->databaseName(
            $context->targetDir,
            $context->baseUrl
        );

        if ($context->dryRun) {
            $this->output->warn(
                'Dry run: config/database.php would use database name '
                . $context->databaseName
                . ' and the MySQL database would be created.'
            );
            return;
        }

        if (!is_file($context->databaseConfigFile)) {
            throw new InstallException(
                'config/database.php was not found; cannot configure the database.',
                EXIT_FILESYSTEM
            );
        }

        $original = file_get_contents($context->databaseConfigFile);
        if ($original === false) {
            throw new InstallException(
                'Could not read config/database.php.',
                EXIT_FILESYSTEM
            );
        }

        $context->originalDatabaseConfig = $original;
        $settings = $this->database->readSettings(
            $context->databaseConfigFile
        );
        $context->databaseSettings = $settings;

        // Create the database before updating its configuration.
        $context->databaseCreated = $this->database->createDatabase(
            $settings,
            $context->databaseName
        );

        if (!$this->database->patchDatabaseName(
            $context->databaseConfigFile,
            $context->databaseName
        )) {
            throw new InstallException(
                'Could not safely update the database name in config/database.php.',
                EXIT_FILESYSTEM
            );
        }

        $context->databaseConfigChanged = true;

        $this->output->success(
            'Database configured: ' . $context->databaseName
        );
        $this->output->success(
            $context->databaseCreated
                ? 'MySQL/Mariadb database created.'
                : 'MySQL/Mariadb database already exists; it was left intact.'
        );

        $this->logger->log(
            'Database configured: ' . $context->databaseName
            . '; created=' . ($context->databaseCreated ? 'yes' : 'no')
        );
    }

    public function rollback(Context $context): void
    {
        if (
            $context->databaseConfigChanged
            && $context->originalDatabaseConfig !== null
            && is_file($context->databaseConfigFile)
        ) {
            $temp = $context->databaseConfigFile
                . '.rollback-' . bin2hex(random_bytes(4));

            if (file_put_contents(
                $temp,
                $context->originalDatabaseConfig,
                LOCK_EX
            ) !== false) {
                FileSystem::replaceFile($temp, $context->databaseConfigFile);
            }
        }

        // Never drop a database that existed before this installation.
        if (
            $context->databaseCreated
            && $context->databaseSettings !== null
        ) {
            try {
                $this->database->dropDatabase(
                    $context->databaseSettings,
                    $context->databaseName
                );
                $this->logger->log(
                    'Rolled back newly created database: '
                    . $context->databaseName
                );
            } catch (Throwable $error) {
                $this->output->warn(
                    'Could not remove newly created database during rollback: '
                    . $error->getMessage()
                );
                $this->logger->log(
                    'Database rollback failed: ' . $error->getMessage()
                );
            }
        }
    }
}

final class VerifyStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger,
        private ConfigPatcher $patcher,
        private DatabaseConfigurator $database
    ) {
    }

    public function label(): string
    {
        return 'Verify installation';
    }

    public function run(Context $context): void
    {
        if ($context->dryRun) {
            $this->output->success(
                'Dry-run checks completed; no project files were written.'
            );
            return;
        }

        $problems = [];

        $hasIndex = is_file($context->targetDir . DIRECTORY_SEPARATOR . 'index.php')
            || is_file(
                $context->targetDir
                . DIRECTORY_SEPARATOR . 'public'
                . DIRECTORY_SEPARATOR . 'index.php'
            );

        if (!$hasIndex) {
            $problems[] = 'No index.php found at the project root or in public/.';
        }

        if (!is_file($context->configFile)) {
            $problems[] = 'config/config.php is missing.';
        } else {
            $base = $this->patcher->readBaseUrl($context->configFile);

            if ($base !== $context->baseUrl) {
                $problems[] = 'BASE_URL could not be verified.';
            }

            if (function_exists('exec')) {
                $lines = [];
                $code = 1;

                $command = escapeshellarg(PHP_BINARY)
                    . ' -l '
                    . escapeshellarg($context->configFile)
                    . ' 2>&1';

                @exec($command, $lines, $code);

                if ($code !== 0) {
                    $problems[] = 'config.php has a PHP syntax error: '
                        . implode(' ', $lines);
                }
            }
        }

        if (!is_file($context->databaseConfigFile)) {
            $problems[] = 'config/database.php is missing.';
        } else {
            try {
                $settings = $this->database->readSettings(
                    $context->databaseConfigFile
                );

                if (($settings['database'] ?? null) !== $context->databaseName) {
                    $problems[] = 'Database name in config/database.php could not be verified.';
                }

                if (function_exists('exec')) {
                    $lines = [];
                    $code = 1;
                    $command = escapeshellarg(PHP_BINARY)
                        . ' -l '
                        . escapeshellarg($context->databaseConfigFile)
                        . ' 2>&1';
                    @exec($command, $lines, $code);

                    if ($code !== 0) {
                        $problems[] = 'database.php has a PHP syntax error: '
                            . implode(' ', $lines);
                    }
                }
            } catch (Throwable $error) {
                $problems[] = 'Database configuration verification failed: '
                    . $error->getMessage();
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->output->warn($problem);
            }

            $this->logger->log(
                'Verification warnings: ' . implode(' | ', $problems)
            );

            throw new InstallException(
                'Installation verification failed.',
                EXIT_INTEGRITY
            );
        }

        $this->output->success(
            'Project structure and configuration verified.'
        );

        $this->logger->log('Verification passed.');
    }

    public function rollback(Context $context): void
    {
        // The pipeline rolls back ConfigStep and PlaceStep separately.
    }
}

/**
 * Update the setup controller so the installer completion page is shown first,
 * while keeping the original setup form available through the setup() action.
 */
final class SetupControllerStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger
    ) {
    }

    public function label(): string
    {
        return 'Update setup controller';
    }

    public function run(Context $context): void
    {
        $path = $context->targetDir
            . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'setup'
            . DIRECTORY_SEPARATOR . 'Setup.php';

        if ($context->dryRun) {
            $this->output->warn(
                'Dry run: modules/setup/Setup.php would be updated.'
            );
            return;
        }

        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            throw new InstallException(
                'modules/setup/Setup.php is missing or is not readable and writable.',
                EXIT_FILESYSTEM
            );
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new InstallException(
                'Could not read modules/setup/Setup.php.',
                EXIT_FILESYSTEM
            );
        }

        $hasSetupAction = preg_match(
            '/^    public function setup\s*\(/m',
            $source
        ) === 1;

        if ($hasSetupAction) {
            $replacement = <<<'PHP'
                public function index()
                {
                    $start_time = microtime(true);
            
                    if (BASE_URL === '****') {
                        http_response_code(503);
                        echo '<code>BASE_URL</code> has not been set.';
                        die();
                    }
            
                    $load_time = (microtime(true) - $start_time) * 1000;
            
                    $data['load_time'] = number_format($load_time, 3);
            
                    $this->view('complete', $data);
                }
PHP;
        } else {
            $replacement = <<<'PHP'
    public function index() {
        $start_time = microtime(true);
       
        if (BASE_URL === '****') {
            http_response_code(503);
            echo '<code>BASE_URL</code> has not been set.';
            die();
        }
    
        $load_time = (microtime(true) - $start_time) * 1000;
    
        $data['load_time'] = number_format($load_time, 3);
    
        $this->view('complete', $data);
    }


    public function setup() {
        if (BASE_URL === '****') {
            http_response_code(503);
            echo '<code>BASE_URL</code> has not been set.';
            die();
        }

        $this->view('setup');
    }
PHP;
        }

        $pattern = '/^    public function index\s*\(\s*\)\s*\{.*?^    \}/ms';
        $updated = preg_replace($pattern, $replacement, $source, 1, $count);

        if ($updated === null || $count !== 1) {
            throw new InstallException(
                'Could not locate the public function index() in modules/setup/Setup.php.',
                EXIT_INTEGRITY
            );
        }

        if ($updated === $source) {
            $this->output->info('Setup controller already has the requested actions.');
            $this->logger->log('Setup controller already configured.');
            return;
        }

        $context->originalSetupController = $source;
        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new InstallException(
                'Could not update modules/setup/Setup.php.',
                EXIT_FILESYSTEM
            );
        }

        $context->setupControllerUpdated = true;
        $this->output->success('Setup controller updated.');
        $this->logger->log(
            'Updated modules/setup/Setup.php: index() now displays complete; setup() displays setup.'
        );
    }

    public function rollback(Context $context): void
    {
        if (!$context->setupControllerUpdated || $context->originalSetupController === null) {
            return;
        }

        $path = $context->targetDir
            . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'setup'
            . DIRECTORY_SEPARATOR . 'Setup.php';
        @file_put_contents($path, $context->originalSetupController, LOCK_EX);
    }
}

/**
 * Update the setup model so its database-name suggestion matches the
 * project-root-based database name created by the installer.
 */
final class SetupModelStep extends BaseStep
{
    public function __construct(
        private Output $output,
        private Logger $logger
    ) {
    }

    public function label(): string
    {
        return 'Update setup model database name';
    }

    public function run(Context $context): void
    {
        $candidates = [
            $context->targetDir . DIRECTORY_SEPARATOR . 'modules'
                . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'setup_model.php',
            $context->targetDir . DIRECTORY_SEPARATOR . 'modules'
                . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'Setup_model.php',
        ];

        $path = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if ($context->dryRun) {
            $this->output->warn(
                'Dry run: modules/setup/setup_model.php would suggest the project-root database name.'
            );
            return;
        }

        if ($path === null || !is_readable($path) || !is_writable($path)) {
            throw new InstallException(
                'modules/setup/setup_model.php is missing or is not readable and writable.',
                EXIT_FILESYSTEM
            );
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new InstallException(
                'Could not read the setup model.',
                EXIT_FILESYSTEM
            );
        }

        $replacement = <<<'PHP'
private function suggest_db_name(): string
{
    // The setup model is located in project/modules/setup/.
    // Resolve the project root instead of deriving the name from BASE_URL.
    $project_name = basename(dirname(__DIR__, 2));
    $database_name = strtolower($project_name);
    $database_name = preg_replace('/[^a-z0-9_$]/', '_', $database_name) ?? '';

    if ($database_name === '' || !preg_match('/^[a-z_]/', $database_name)) {
        $database_name = 'trongate_' . $database_name;
    }

    return substr($database_name, 0, 64);
}
PHP;

        $pattern = '/^([ \\t]*)private\\s+function\\s+suggest_db_name\\s*\\(\\s*\\)\\s*:\\s*string\\s*\\{\\R.*?^\\1\\}/ms';
        $updated = preg_replace($pattern, $replacement, $source, 1, $count);

        if (!is_string($updated) || $count !== 1) {
            throw new InstallException(
                'Could not locate suggest_db_name() in the setup model.',
                EXIT_INTEGRITY
            );
        }

        if ($updated === $source) {
            $this->output->info('Setup model already uses the project-root database name.');
            $this->logger->log('Setup model database-name suggestion already configured.');
            return;
        }

        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new InstallException(
                'Could not update the setup model database-name suggestion.',
                EXIT_FILESYSTEM
            );
        }

        $this->output->success('Setup model now suggests the project-root database name.');
        $this->logger->log('Updated setup model suggest_db_name() to use the project root.');
    }
}


final class CompletionViewStep extends BaseStep
{
    private bool $imagesInstalled = false;

    public function __construct(
        private Output $output,
        private Logger $logger
    ) {
    }

    public function label(): string
    {
        return 'Install completion view and images';
    }

    public function run(Context $context): void
    {
        $sourceView = __DIR__ . DIRECTORY_SEPARATOR . 'complete.php';
        $sourceImages = __DIR__ . DIRECTORY_SEPARATOR . 'img';

        $setupDirectory = $context->targetDir
            . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'setup';

        $destinationView = $setupDirectory
            . DIRECTORY_SEPARATOR . 'views'
            . DIRECTORY_SEPARATOR . 'complete.php';

        $destinationImages = $setupDirectory
            . DIRECTORY_SEPARATOR . 'img';

        if ($context->dryRun) {
            $this->output->warn(
                'Dry run: complete.php would be copied to modules/setup/views/'
            );

            $this->output->warn(
                'Dry run: img/ would be copied to modules/setup/img/'
            );

            return;
        }

        if (!is_file($sourceView) || !is_readable($sourceView)) {
            throw new InstallException(
                'The installer asset complete.php was not found beside the installer script.',
                EXIT_FILESYSTEM
            );
        }

        if (!is_dir($sourceImages) || !is_readable($sourceImages)) {
            throw new InstallException(
                'The installer img/ directory was not found or is not readable.',
                EXIT_FILESYSTEM
            );
        }

        if (!is_dir($setupDirectory) || !is_writable($setupDirectory)) {
            throw new InstallException(
                'modules/setup/ is missing or is not writable.',
                EXIT_FILESYSTEM
            );
        }

        if (!is_dir(dirname($destinationView)) || !is_writable(dirname($destinationView))) {
            throw new InstallException(
                'modules/setup/views/ is missing or is not writable.',
                EXIT_FILESYSTEM
            );
        }

        // Protect existing image assets from accidental overwriting.
        if (file_exists($destinationImages)) {
            throw new InstallException(
                'modules/setup/img/ already exists. Installation stopped to prevent overwriting existing images.',
                EXIT_FILESYSTEM
            );
        }

        // Preserve the original completion view for rollback.
        if (is_file($destinationView)) {
            $original = file_get_contents($destinationView);

            if ($original === false) {
                throw new InstallException(
                    'Could not read the existing setup completion view.',
                    EXIT_FILESYSTEM
                );
            }

            $context->originalCompleteView = $original;
        }

        // Copy complete.php into modules/setup/views/.
        if (!@copy($sourceView, $destinationView)) {
            throw new InstallException(
                'Could not copy complete.php into modules/setup/views/.',
                EXIT_FILESYSTEM
            );
        }

        $context->completeViewInstalled = true;

        // Copy img/ into modules/setup/img/.
        try {
            if (!@mkdir($destinationImages, 0755, true)) {
                throw new InstallException(
                    'Could not create modules/setup/img/.',
                    EXIT_FILESYSTEM
                );
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $sourceImages,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $relativePath = $iterator->getSubPathName();

                $targetPath = $destinationImages
                    . DIRECTORY_SEPARATOR
                    . $relativePath;

                if ($item->isLink()) {
                    throw new InstallException(
                        'The installer img/ directory contains a symbolic link: '
                        . $relativePath,
                        EXIT_INTEGRITY
                    );
                }

                if ($item->isDir()) {
                    if (
                        !is_dir($targetPath)
                        && !@mkdir($targetPath, 0755, true)
                    ) {
                        throw new InstallException(
                            'Could not create image directory: ' . $relativePath,
                            EXIT_FILESYSTEM
                        );
                    }

                    continue;
                }

                if (!@copy($item->getPathname(), $targetPath)) {
                    throw new InstallException(
                        'Could not copy image file: ' . $relativePath,
                        EXIT_FILESYSTEM
                    );
                }
            }

            $this->imagesInstalled = true;
        } catch (Throwable $error) {
            // Remove partially copied images if copying fails.
            if (is_dir($destinationImages)) {
                try {
                    FileSystem::removeTree($destinationImages);
                } catch (Throwable $cleanupError) {
                    $this->logger->log(
                        'Could not clean up partially copied images: '
                        . $cleanupError->getMessage()
                    );
                }
            }

            throw $error;
        }

        $this->output->success(
            'Completion view copied to modules/setup/views/complete.php.'
        );

        $this->output->success(
            'Image directory copied to modules/setup/img/.'
        );

        $this->logger->log(
            'Installed custom completion view at modules/setup/views/complete.php.'
        );

        $this->logger->log(
            'Copied installer img/ directory to modules/setup/img/.'
        );
    }

    public function rollback(Context $context): void
    {
        // Remove the copied image directory.
        if ($this->imagesInstalled) {
            $destinationImages = $context->targetDir
                . DIRECTORY_SEPARATOR . 'modules'
                . DIRECTORY_SEPARATOR . 'setup'
                . DIRECTORY_SEPARATOR . 'img';

            try {
                FileSystem::removeTree($destinationImages);
            } catch (Throwable $error) {
                $this->logger->log(
                    'Could not roll back modules/setup/img/: '
                    . $error->getMessage()
                );
            }

            $this->imagesInstalled = false;
        }

        // Restore or remove the completion view.
        if (!$context->completeViewInstalled) {
            return;
        }

        $destinationView = $context->targetDir
            . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'setup'
            . DIRECTORY_SEPARATOR . 'views'
            . DIRECTORY_SEPARATOR . 'complete.php';

        if ($context->originalCompleteView === null) {
            @unlink($destinationView);
            return;
        }

        $temp = $destinationView
            . '.rollback-'
            . bin2hex(random_bytes(4));

        if (file_put_contents(
            $temp,
            $context->originalCompleteView,
            LOCK_EX
        ) !== false) {
            FileSystem::replaceFile($temp, $destinationView);
        }
    }
}


final class Pipeline
{
    public function __construct(
        private array $steps,
        private Output $output,
        private Logger $logger
    ) {
    }

    public function run(Context $context): int
    {
        $completed = [];
        $current = null;

        try {
            foreach ($this->steps as $step) {
                $current = $step;

                $this->output->bold('> ' . $step->label());
                $this->logger->log('START ' . $step->label());

                $step->run($context);

                $completed[] = $step;
                $this->logger->log('DONE ' . $step->label());
                $this->output->line();
            }

            return EXIT_OK;
        } catch (Throwable $error) {
            $code = $error instanceof InstallException
                ? $error->exitCode
                : EXIT_ERROR;

            $this->output->error($error->getMessage());

            $this->logger->log(
                'FAILED '
                . ($current?->label() ?? 'unknown')
                . ': '
                . $error->getMessage()
            );

            $rollbackSteps = $completed;

            if (
                $current !== null
                && !in_array($current, $rollbackSteps, true)
            ) {
                $rollbackSteps[] = $current;
            }

            if ($rollbackSteps !== []) {
                $this->output->warn(
                    'Rolling back installation operations...'
                );

                foreach (array_reverse($rollbackSteps) as $step) {
                    try {
                        $step->rollback($context);
                        $this->logger->log(
                            'ROLLBACK ' . $step->label()
                        );
                    } catch (Throwable $rollbackError) {
                        $this->output->warn(
                            'Rollback issue ('
                            . $step->label()
                            . '): '
                            . $rollbackError->getMessage()
                        );

                        $this->logger->log(
                            'ROLLBACK ERROR: '
                            . $rollbackError->getMessage()
                        );
                    }
                }
            }

            return $code;
        }
    }
}

function isInteractiveTerminal(): bool
{
    if (!defined('STDIN')) {
        return false;
    }

    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }

    return function_exists('posix_isatty')
        && @posix_isatty(STDIN);
}

function validBaseUrl(string $url): bool
{
    if (!str_ends_with($url, '/')) {
        return false;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower(
        (string) parse_url($url, PHP_URL_SCHEME)
    );

    return in_array($scheme, ['http', 'https'], true)
        && parse_url($url, PHP_URL_HOST) !== null;
}

function showHelp(Output $output): int
{
    $output->box('Trongate Framework Installer', 'cyan');
    $output->line('Installs framework files into the current directory.');
    $output->line();
    $output->line('Usage: '); 
    $output->line(' php trongate install');
    $output->line(' php trongate install [options]');
    $output->line();
    $output->line('Options:');
    $output->line('  --framework=trongate-on-ice');
    $output->line('  --framework=trongate@master --base-url=http://127.0.0.1:8000/');
    $output->line('  --framework=<key>[@<ref>]  Select framework edition and optional branch/tag.');
    $output->line('  --base-url=<url>           Set BASE_URL (must end with /).');
    $output->line('  -n, --no-interaction       Use defaults and do not prompt.');
    $output->line('  -q, --quiet                Suppress informational output.');
    $output->line('  -v, --verbose              Enable additional diagnostic output.');
    $output->line('      --sha256=<hash>        Verify the downloaded ZIP (requires PHP zip; disables Git).');
    $output->line('      --dry-run              Simulate without writing project files.');
    $output->line('      --list                 List available framework editions.');
    $output->line('  -h, --help                 Display this help.');
    $output->line();
    $output->line('The current directory must contain only cli/ and the trongate launcher.');
    $output->line('Exit codes: 0 success, 1 general, 2 cancelled, 3 preflight, 4 network, 5 integrity, 6 filesystem.');

    return EXIT_OK;
}

function listFrameworks(Output $output, FrameworkRegistry $registry): int
{
    $output->bold('Available framework editions:');

    foreach ($registry->all() as $key => $framework) {
        $output->line();
        $output->line(
            '  ' . $key
            . ($key === $registry->defaultKey() ? ' (default)' : '')
        );
        $output->line('  ' . $framework['label']);
        $output->line('  ' . $framework['description']);
        $output->line('  Repository: ' . $framework['repo']);
        $output->line('  Default ref: ' . $framework['default_ref']);
    }

    return EXIT_OK;
}

function printNextSteps(Output $output, Context $context): void
{
    $parts = parse_url($context->baseUrl);
    $host = $parts['host'] ?? '127.0.0.1';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $documentRoot = is_dir(
        $context->targetDir . DIRECTORY_SEPARATOR . 'public'
    ) ? 'public' : '.';

    $output->bold('Next steps:');
    $output->line();
    $output->line('  1. Start PHP development server:');
    $output->line('     php trongate serve');
    $output->line();
    $output->line('  2. Open ' . $context->baseUrl . ' in your browser.');
    $output->line('  3. Complete the Trongate setup wizard, if presented.');
}

function run(array $arguments): int
{
    $frameworkArgument = null;
    $baseUrlArgument = null;
    $sha256Argument = null;

    $quiet = false;
    $verbose = false;
    $dryRun = false;
    $noInteraction = false;
    $list = false;
    $help = false;

    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--framework=')) {
            $frameworkArgument = substr(
                $argument,
                strlen('--framework=')
            );
        } elseif (str_starts_with($argument, '--base-url=')) {
            $baseUrlArgument = substr(
                $argument,
                strlen('--base-url=')
            );
        } elseif (str_starts_with($argument, '--sha256=')) {
            $sha256Argument = substr(
                $argument,
                strlen('--sha256=')
            );
        } elseif (in_array($argument, ['-n', '--no-interaction'], true)) {
            $noInteraction = true;
        } elseif (in_array($argument, ['-q', '--quiet'], true)) {
            $quiet = true;
        } elseif (in_array($argument, ['-v', '--verbose'], true)) {
            $verbose = true;
        } elseif ($argument === '--dry-run') {
            $dryRun = true;
        } elseif ($argument === '--list') {
            $list = true;
        } elseif (in_array($argument, ['-h', '--help'], true)) {
            $help = true;
        } elseif (str_starts_with($argument, '-')) {
            (new TerminalOutput())->error(
                'Unknown option: ' . $argument
            );
            return EXIT_ERROR;
        } else {
            (new TerminalOutput())->error(
                'Unexpected argument: ' . $argument
                . '. Project names are not accepted; install runs in the current directory.'
            );
            return EXIT_ERROR;
        }
    }

    $output = new TerminalOutput($quiet);

    try {
        $registry = new FrameworkRegistry();

        if ($help) {
            return showHelp(new TerminalOutput(false));
        }

        if ($list) {
            return listFrameworks(new TerminalOutput($quiet), $registry);
        }

        // Validate the project root before creating or writing the log.
        $targetDirectory = (new ProjectValidator())->validate();
        $logger = new Logger($targetDirectory);

        $context = new Context();
        $context->targetDir = $targetDirectory;
        $context->dryRun = $dryRun;
        $context->quiet = $quiet;
        $context->verbose = $verbose;
        $context->nonInteractive = $noInteraction || !isInteractiveTerminal();

        if ($sha256Argument !== null) {
            if (!preg_match('/^[a-fA-F0-9]{64}$/D', $sha256Argument)) {
                throw new InstallException(
                    '--sha256 must be exactly 64 hexadecimal characters.'
                );
            }

            $context->expectedSha256 = strtolower($sha256Argument);
        }

        if ($frameworkArgument !== null) {
            $parts = explode('@', $frameworkArgument, 2);
            $context->frameworkKey = $parts[0];
            $context->ref = $parts[1] ?? '';
        } elseif ($context->nonInteractive) {
            $context->frameworkKey = $registry->defaultKey();
        } else {
            $options = [];

            foreach ($registry->all() as $key => $framework) {
                $options[$key] = $framework['label'];
            }

            $context->frameworkKey = $output->select(
                'Step 1 of 2: Choose a framework edition',
                $options,
                $registry->defaultKey()
            );

            $output->info(
                $registry->get($context->frameworkKey)['description']
            );
        }

        if (!$registry->has($context->frameworkKey)) {
            throw new InstallException(
                'Unknown framework edition: ' . $context->frameworkKey
            );
        }

        $context->framework = $registry->get(
            $context->frameworkKey
        );

        if ($context->ref === '') {
            $context->ref = $context->framework['default_ref'];
        }

        if (
            !preg_match('/^[A-Za-z0-9._\/-]+$/D', $context->ref)
            || str_contains($context->ref, '..')
        ) {
            throw new InstallException(
                'Invalid branch or tag reference.'
            );
        }

        if ($baseUrlArgument !== null) {
            $context->baseUrl = $baseUrlArgument;
        } elseif (!$context->nonInteractive) {
            $context->baseUrl = $output->ask(
                'Step 2 of 2: Enter the project base URL',
                'http://127.0.0.1:8000/'
            );
        }

        if (!validBaseUrl($context->baseUrl)) {
            throw new InstallException(
                'BASE_URL must be a valid HTTP(S) URL ending with /.'
            );
        }

        $output->line();
        $output->bold('Installation summary');
        $output->hr();
        $output->line('  Edition:  ' . $context->framework['label']);
        $output->line('  Ref:      ' . $context->ref);
        $output->line('  Base URL: ' . $context->baseUrl);
        $output->line('  Target:   ' . $context->targetDir);
        $output->line('  Mode:     ' . ($dryRun ? 'DRY RUN' : 'INSTALL'));

        if ($context->expectedSha256 !== '') {
            $output->line('  SHA-256:  verification required');
        }

        if ($verbose) {
            $output->info(
                'Verbose diagnostics enabled; details are recorded in cli/cli.log.'
            );
        }

        $output->hr();

        if (
            !$context->nonInteractive
            && !$output->confirm('Proceed with installation?', true)
        ) {
            $output->info('Installation cancelled.');
            return EXIT_CANCELLED;
        }

        $downloader = new Downloader();

        if (!$quiet) {
            $downloader->setProgress(
                fn(float $ratio) => $output->progress($ratio, 'downloading')
            );
        }

        $patcher = new ConfigPatcher();

        $pipeline = new Pipeline(
            [
                new PreflightStep($output, $logger),
                new NetworkStep($output, $logger, $downloader, $registry),
                new FetchStep($output, $logger, $downloader, $registry),
                new PlaceStep($output, $logger, new Extractor()),
                new ConfigStep($output, $logger, $patcher),
                new DatabaseStep($output, $logger, new DatabaseConfigurator()),
                new VerifyStep(
                    $output,
                    $logger,
                    $patcher,
                    new DatabaseConfigurator()
                ),
                new CompletionViewStep($output, $logger),
            ],
            $output,
            $logger
        );

        $logger->log(
            'INSTALL start: ' . json_encode(
                [
                    'framework' => $context->frameworkKey,
                    'ref' => $context->ref,
                    'base_url' => $context->baseUrl,
                    'target' => $context->targetDir,
                    'dry_run' => $context->dryRun,
                ],
                JSON_UNESCAPED_SLASHES
            )
        );

        $exitCode = $pipeline->run($context);

        if ($exitCode !== EXIT_OK) {
            $output->error(
                'Installation failed with exit code ' . $exitCode . '.'
            );
            $output->info('Log: ' . $logger->path());
            return $exitCode;
        }

        // Update the installed setup controller only after the installation
        // pipeline has completed successfully. This is a post-install
        // customization, so a missing or read-only Setup.php must not trigger
        // rollback of an otherwise successful framework installation.
        if (!$dryRun) {
            $setupControllerStep = new SetupControllerStep($output, $logger);

            try {
                $output->bold('> Post-install: update setup controller');
                $setupControllerStep->run($context);
                $logger->log('DONE Post-install: update setup controller');
            } catch (Throwable $postInstallError) {
                $output->warn(
                    'The framework was installed, but modules/setup/Setup.php '
                    . 'could not be customized: '
                    . $postInstallError->getMessage()
                );
                $logger->log(
                    'POST-INSTALL WARNING: setup controller was not updated: '
                    . $postInstallError->getMessage()
                );
            }

            $setupModelStep = new SetupModelStep($output, $logger);

            try {
                $output->bold('> Post-install: update setup model');
                $setupModelStep->run($context);
                $logger->log('DONE Post-install: update setup model');
            } catch (Throwable $postInstallError) {
                $output->warn(
                    'The framework was installed, but the setup model could not be customized: '
                    . $postInstallError->getMessage()
                );
                $logger->log(
                    'POST-INSTALL WARNING: setup model was not updated: '
                    . $postInstallError->getMessage()
                );
            }
        }

        $output->box(
            $dryRun
                ? 'Dry run completed; no project files were installed.'
                : 'Trongate installation completed!',
            $dryRun ? 'yellow' : 'green'
        );

        if (!$dryRun) {
            printNextSteps($output, $context);
        }

        $output->line();
        $output->info('Installation log: ' . $logger->path());

        return EXIT_OK;
    } catch (InstallException $error) {
        $output->error($error->getMessage());

        if (isset($logger)) {
            $logger->log('ERROR: ' . $error->getMessage());
            $output->info('Log: ' . $logger->path());
        }

        return $error->exitCode;
    } catch (Throwable $error) {
        $output->error(
            'Unexpected error: ' . $error->getMessage()
        );

        if (isset($logger)) {
            $logger->log(
                'UNEXPECTED: ' . $error . PHP_EOL
            );
            $output->info('Log: ' . $logger->path());
        }

        return EXIT_ERROR;
    }
}
