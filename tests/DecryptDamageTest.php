<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for a fatal error that made mission commands silently do
 * nothing.
 *
 * `decrypt <file>` damages the player's installed software 30% of the time
 * (damageSoftwareThroughUsage()). The method looked the applications up with
 *
 *     $apps = $this->db->rawQuery(...);
 *     $damage = ceil($damage / count($apps));
 *
 * A fresh account has no `server_apps` rows at all, so count($apps) was 0 and
 * PHP 8 raised an uncaught DivisionByZeroError. The console AJAX request then
 * received an HTML error page instead of JSON, jQuery's (empty) .fail() handler
 * swallowed it and the player saw no output whatsoever - not even the command
 * being written to the console history - so `decrypt task3.txt` looked like it
 * simply refused to start.
 */
final class DecryptDamageTest extends TestCase
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

        // QClass loads ConsoleManagement at parse time, which reads $user.
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

    /**
     * A quest object whose database reports no installed applications, which is
     * the state of every fresh account.
     */
    private function questWithoutInstalledApps(): QClass
    {
        $container = new Container();
        $container->set('db', new class {
            /** @var string[] */
            public array $queries = [];

            public function rawQuery(string $sql, array $params = []): array
            {
                $this->queries[] = $sql;

                return [];
            }
        });
        $container->set('user', ['id' => 1, 'server' => 1]);

        $quest = (new ReflectionClass('QClass'))->newInstanceWithoutConstructor();

        $containerProperty = new ReflectionProperty('Alpha', 'container');
        $containerProperty->setAccessible(true);
        $containerProperty->setValue($quest, $container);

        return $quest;
    }

    public function testDecryptDamageIsSkippedWhenNoApplicationsAreInstalled(): void
    {
        $quest = $this->questWithoutInstalledApps();

        // Before the fix this threw DivisionByZeroError inside the console
        // request, aborting it before the command could be logged.
        $quest->damageSoftwareThroughUsage('decrypt', 3);

        $db = $quest->db;
        $this->assertNotEmpty($db->queries, 'the application lookup never reached the database');
        $this->assertStringContainsString('from server_apps', $db->queries[0]);
    }

    /**
     * The lookup must be scoped to the player's own server and only consider
     * applications that provide the skill behind the command.
     */
    public function testDecryptDamageQueriesApplicationsOfThePlayersServer(): void
    {
        $quest = $this->questWithoutInstalledApps();
        $quest->damageSoftwareThroughUsage('decrypt', 3);

        $sql = $quest->db->queries[0];
        $this->assertStringContainsString('sa.server_id = ?', $sql);
        $this->assertStringContainsString('a.skill_id in (', $sql);
    }
}
