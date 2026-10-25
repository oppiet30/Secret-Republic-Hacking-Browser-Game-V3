<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for includes that used to be resolved relative to the
 * current working directory.
 *
 * Under the web SAPI the working directory is public_html/, so
 * `require('includes/constants/skills.php')` (and the same for
 * includes/class/class.server.php) could not be opened. PHP then raised an
 * uncaught E_COMPILE_ERROR, the console AJAX request got an HTML error page and
 * jQuery's .fail() swallowed it: the player typed a command (e.g.
 * `decrypt task3.txt`, which calls damageSoftwareThroughUsage 30% of the time)
 * and absolutely nothing happened - no output, no console history entry.
 */
final class ConsoleRequiresTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__);

        if (!defined('URL')) {
            define('URL', 'http://test.local/');
        }
        if (!defined('ABSPATH')) {
            define('ABSPATH', self::$root . '/');
        }

        // ConsoleManagement.php builds its command list at load time using $user.
        global $user;
        $user = [
            'id'       => 1,
            'username' => 'tester',
        ];

        require_once self::$root . '/includes/class/Container.php';
        require_once self::$root . '/includes/class/alpha.class.php';

        $cwd = getcwd();
        chdir(self::$root . '/includes/class');
        require_once self::$root . '/includes/class/qclass.php';
        chdir($cwd);
    }

    public function testSkillsIncludeResolvesWithDocrootAsWorkingDirectory(): void
    {
        $reflection = new ReflectionClass('QClass');
        $quest      = $reflection->newInstanceWithoutConstructor();

        unset($GLOBALS['theskills']);

        $cwd = getcwd();
        // Exactly what PHP-FPM uses for this vhost.
        chdir(self::$root . '/public_html');

        try {
            // An unknown command name matches no skill, so the method stops
            // right after loading the skills file (no db/server access).
            $quest->damageSoftwareThroughUsage('no_such_command', 1);
        } finally {
            chdir($cwd);
        }

        $this->assertIsArray($GLOBALS['theskills'] ?? null, 'includes/constants/skills.php was not loaded');
        $this->assertCount(16, $GLOBALS['theskills']);
    }

    /**
     * `require('includes/...')` / `include('templates/...')` style paths break
     * because the web working directory is public_html/. They must be prefixed
     * with ABSPATH (or __DIR__).
     */
    public function testNoAppRootRequiresAreWorkingDirectoryRelative(): void
    {
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/includes/vendor/')) {
                continue;
            }

            $lines = file($path) ?: [];
            foreach ($lines as $number => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (preg_match('/(require|include)(_once)?\s*\(?\s*[\'"](includes|templates|layout|public_html)\//', $line)
                    && !str_contains($line, 'ABSPATH')
                    && !str_contains($line, '__DIR__')) {
                    $offenders[] = substr($path, strlen(self::$root) + 1) . ':' . ($number + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "Working-directory-relative includes found:\n" . implode("\n", $offenders));
    }
}
