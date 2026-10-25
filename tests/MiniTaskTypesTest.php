<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the mission console minitask pipeline.
 *
 * 1. addMinitask() stores a task type that minitask() later switches on. The
 *    `run` command used to register 'file_run' while the switch handled
 *    'run_file', so every executable with a non-zero executionTime fell into
 *    the default branch and printed "Some weird thing happened with a task"
 *    instead of running (and never completed a run_file objective).
 *
 * 2. executeFile() is called without the `$console` argument (it is a method,
 *    not a closure) but still wrote to $console->consoleOutput. On PHP 8,
 *    assigning a property on null is a fatal Error, so burst files died right
 *    after starting.
 */
final class MiniTaskTypesTest extends TestCase
{
    private const QUEST_UI_DIR = __DIR__ . '/../includes/class/quests';
    private const QCLASS      = __DIR__ . '/../includes/class/qclass.php';

    /**
     * Task types registered through addMinitask() anywhere in the console code.
     *
     * @return list<string>
     */
    private static function registeredTypes(): array
    {
        $sources = glob(self::QUEST_UI_DIR . '/*.php') ?: [];
        $sources[] = self::QCLASS;

        $types = [];
        foreach ($sources as $source) {
            $code = (string) file_get_contents($source);
            preg_match_all("~addMinitask\\(\\s*'([a-z_]+)'~", $code, $matches);
            $types = array_merge($types, $matches[1]);
        }

        return array_values(array_unique($types));
    }

    /**
     * Task types handled by MiniTaskManagement::minitask().
     *
     * @return list<string>
     */
    private static function handledTypes(): array
    {
        $code = (string) file_get_contents(self::QUEST_UI_DIR . '/MiniTaskManagement.php');
        preg_match_all("~case\\s+'([a-z_]+)'\\s*:~", $code, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function testEveryRegisteredMinitaskTypeIsHandled(): void
    {
        $unhandled = array_diff(self::registeredTypes(), self::handledTypes());

        $this->assertSame(
            [],
            array_values($unhandled),
            'addMinitask() registered types that MiniTaskManagement::minitask() never switches on; '
            . 'they fall through to the "Some weird thing happened with a task" default'
        );
    }

    public function testExecuteFileDoesNotUseTheUndefinedConsoleVariable(): void
    {
        $code = (string) file_get_contents(self::QUEST_UI_DIR . '/SSHCommandsManagement.php');

        $start = strpos($code, 'function executeFile(');
        $this->assertNotFalse($start, 'executeFile() must exist');

        $end = strpos($code, "\n  function ", (int) $start + 1);
        $body = $end === false ? substr($code, (int) $start) : substr($code, (int) $start, $end - (int) $start);

        $this->assertStringNotContainsString(
            '$console',
            $body,
            'executeFile() has no $console parameter; writing to $console->consoleOutput is a fatal Error on PHP 8'
        );
    }
}
