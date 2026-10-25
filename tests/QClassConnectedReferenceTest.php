<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for QClass::processQuestTaskData().
 *
 * $connected is a declared private property of QClass, so it bypasses
 * Alpha::__get()/__set() and the _dynamicProps array. It must be bound to the
 * quest data with a real reference assignment; when it was bound through
 * _dynamicProps the mission engine saw an empty connection and objectives such
 * as "run_file final.sh|IP:port" never matched (the engine built "final.sh|:").
 */
final class QClassConnectedReferenceTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__);

        if (!defined('URL')) {
            define('URL', 'http://test.local/');
        }
        // ConsoleManagement.php builds its command list at load time using $user
        // in the global scope of the included file.
        global $user;
        $user = [
            'id'       => 1,
            'username' => 'tester',
        ];

        require_once self::$root . '/includes/class/Container.php';
        require_once self::$root . '/includes/class/alpha.class.php';

        // qclass.php requires its traits relative to includes/class.
        $cwd = getcwd();
        chdir(self::$root . '/includes/class');
        require_once self::$root . '/includes/class/qclass.php';
        chdir($cwd);
    }

    /**
     * Boot a QClass instance without a container (only processQuestTaskData()
     * is exercised, which needs questTask/questData and nothing else).
     */
    private function makeQuest(array $connected = []): QClass
    {
        $reflection = new ReflectionClass('QClass');
        $quest      = $reflection->newInstanceWithoutConstructor();

        $quest->questTask = [
            'id'        => 1,
            'questID'   => 70,
            'questData' => [
                'objective' => ['id' => 605, 'sideObjectives' => []],
                'hosts'     => [
                    '10.1.2.3' => ['services' => [22 => ['files' => []]]],
                ],
                'main'      => '10.1.2.3',
                'connected' => $connected,
            ],
        ];

        $quest->processQuestTaskData();

        return $quest;
    }

    private function readConnected(QClass $quest): array
    {
        $reader = Closure::bind(function () {
            return $this->connected;
        }, $quest, QClass::class);

        return $reader();
    }

    private function readQuestDataConnected(QClass $quest): array
    {
        $reader = Closure::bind(function () {
            return $this->questData['connected'];
        }, $quest, QClass::class);

        return $reader();
    }

    public function testConnectedDefaultsToMainHostEvenWithoutSavedConnection(): void
    {
        $quest = $this->makeQuest();

        $connected = $this->readConnected($quest);

        $this->assertSame('10.1.2.3', $connected['ip']);
        $this->assertSame(22, $connected['port']);
    }

    public function testConnectedPropertyIsTheSameStorageAsQuestData(): void
    {
        $quest = $this->makeQuest();

        $this->assertSame(
            $this->readQuestDataConnected($quest),
            $this->readConnected($quest),
            '$connected should be a reference to $this->questData[\'connected\']'
        );
    }

    public function testSavedConnectionIsRestored(): void
    {
        $quest = $this->makeQuest(['ip' => '8.8.4.4', 'port' => 2222]);

        $connected = $this->readConnected($quest);

        $this->assertSame('8.8.4.4', $connected['ip']);
        $this->assertSame(2222, $connected['port']);
    }

    /**
     * The port_connect minitask assigns $this->connected directly; that write
     * has to reach the quest data that gets persisted at the end of a request.
     */
    public function testWritingConnectedUpdatesQuestData(): void
    {
        $quest = $this->makeQuest();

        $writer = Closure::bind(function () {
            $this->connected = ['ip' => '9.9.9.9', 'port' => 2022];
        }, $quest, QClass::class);
        $writer();

        $stored = $this->readQuestDataConnected($quest);
        $this->assertSame('9.9.9.9', $stored['ip']);
        $this->assertSame(2022, $stored['port']);
    }
}
