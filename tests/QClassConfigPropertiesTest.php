<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for QClass configuration values that the console commands
 * read through $this->... .
 *
 * They used to be declared `private static`, and a static property never
 * matches an instance read: `$this->cardinalOS` fell through to
 * Alpha::__get() and returned null. Symptoms:
 *   - `ifconfig` printed "Operating System: " with nothing after it,
 *   - `ddos status` printed negative "Remaining DDOS's",
 *   - email forwarding built "username" instead of "username@secretrepublic.net".
 */
final class QClassConfigPropertiesTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__);

        if (!defined('URL')) {
            define('URL', 'http://test.local/');
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

    private function makeQuest(): QClass
    {
        $reflection = new ReflectionClass('QClass');
        $quest      = $reflection->newInstanceWithoutConstructor();

        $quest->questTask = [
            'id'        => 1,
            'questID'   => 70,
            'questData' => [
                'objective' => ['id' => 605, 'sideObjectives' => []],
                'hosts'     => [
                    '10.1.2.3' => [
                        'hostname' => '[MAIN] New server 1',
                        'mac'      => 'AA:BB:CC:DD:EE:FF',
                        'services' => [22 => ['files' => []]],
                    ],
                ],
                'main'      => '10.1.2.3',
                'connected' => [],
            ],
        ];

        $quest->processQuestTaskData();
        $quest->consoleOutput = '';

        return $quest;
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function configPropertyProvider(): array
    {
        return [
            'cardinalOS'    => ['cardinalOS', 'Cardinal OS V2.2'],
            'userGameEmail' => ['userGameEmail', '@secretrepublic.net'],
            'ddosPerHost'   => ['ddosPerHost', 3],
            'bouncesPerHost' => ['bouncesPerHost', 3],
        ];
    }

    #[DataProvider('configPropertyProvider')]
    public function testPropertyIsReadableThroughThis(string $property, $expected): void
    {
        $reflection = new ReflectionProperty('QClass', $property);

        $this->assertFalse(
            $reflection->isStatic(),
            sprintf('QClass::$%s must be an instance property: the commands read $this->%s', $property, $property)
        );

        $quest = $this->makeQuest();
        $this->assertSame($expected, $reflection->getValue($quest));
    }

    public function testIfconfigPrintsOperatingSystem(): void
    {
        $quest = $this->makeQuest();

        $quest->ifconfigCommand();

        $this->assertStringContainsString('IP: 10.1.2.3', $quest->consoleOutput);
        $this->assertStringContainsString('Port: 22', $quest->consoleOutput);
        $this->assertStringContainsString('Hostname: [MAIN] New server 1', $quest->consoleOutput);
        $this->assertStringContainsString('MAC Address: AA:BB:CC:DD:EE:FF', $quest->consoleOutput);
        $this->assertStringContainsString(
            'Operating System: Cardinal OS V2.2',
            $quest->consoleOutput,
            'ifconfig must not end with an empty "Operating System:" line'
        );
    }

    public function testUserGameEmailBuildsFullAddress(): void
    {
        $quest = $this->makeQuest();

        // forward_email compares $toEmail against strtolower(username . domain)
        // and delivers to the inbox when it matches; a null domain meant the
        // address was just "tester" and the match/delivery never happened.
        $domain = (new ReflectionProperty('QClass', 'userGameEmail'))->getValue($quest);

        $this->assertSame('tester@secretrepublic.net', strtolower('tester' . $domain));
    }
}
