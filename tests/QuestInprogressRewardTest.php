<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for the solo-mission reward path in
 * includes/modules/quests/quest_inprogress.php.
 *
 * When a mission finishes, the module queues the reward and then records the
 * completion in `quests_user`. `$participants` is only populated for party
 * missions, so for solo missions `count($participants)` threw a TypeError on
 * PHP 8 *after* the reward had been inserted but *before* `quests_user` was
 * written. Result: reward pending, no completion row, next quest still locked.
 */
final class QuestInprogressRewardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('URL')) {
            define('URL', 'http://test.local/');
        }
        if (!defined('URL_C')) {
            define('URL_C', 'http://test.local/');
        }
    }

    /**
     * Minimal stand-in for MysqliDb: supports the chained calls the reward
     * block makes and records inserts.
     */
    private function fakeDb(): object
    {
        return new class {
            /** @var array<int, array{0: string, 1: array}> */
            public array $inserts = [];

            public array $questRow = [
                'qu_id'          => null,
                'type'           => 1,
                'money'          => 30,
                'experience'     => 30,
                'achievement_id' => null,
                'skillPoints'    => null,
                'done'           => null,
                'times'          => null,
            ];

            public function join(...$args) { return $this; }
            public function where(...$args) { return $this; }
            public function orderBy(...$args) { return $this; }
            public function rawQuery(...$args) { return $this; }
            public function delete(...$args) { return $this; }

            public function getOne($table, $fields = '*')
            {
                return $this->questRow;
            }

            public function insert($table, $data)
            {
                $this->inserts[] = [$table, $data];

                return count($this->inserts);
            }
        };
    }

    private function fakeUclass(): object
    {
        return new class {
            /** @var array<int, array{0: int, 1: array, 2: string}> */
            public array $rewards = [];
            public array $messages = [];

            public function addReward($user_id, $reward, $title)
            {
                $this->rewards[] = [$user_id, $reward, $title];

                return count($this->rewards);
            }

            public function send_msg($from, $to, $message, $title)
            {
                $this->messages[] = [$from, $to, $title];
            }
        };
    }

    public function testSoloMissionCompletionRecordsQuestAndQueuesReward(): void
    {
        // The module reads these straight from the including scope.
        $GET       = ['history' => null];
        $tVars     = [];
        $errors    = [];
        $success   = [];
        $warnings  = [];
        $messenger = [];

        $user  = ['id' => 18151, 'organization' => 0, 'in_party' => 0];
        $qtask = ['id' => 11, 'type' => 15, 'remainingSeconds' => 700];

        $qclass            = new stdClass();
        $qclass->finished  = true;
        $qclass->questTask = [
            'questID'     => 70,
            'name'        => 'Coincidence by design',
            'type'        => 15,
            'instance_id' => null,
        ];
        $qclass->questData = [];

        $db     = $this->fakeDb();
        $uclass = $this->fakeUclass();

        // No $participants is defined here on purpose: solo missions must not
        // depend on a variable that only party missions set.
        require dirname(__DIR__) . '/includes/modules/quests/quest_inprogress.php';

        $this->assertSame('quests/quest_play.tpl', $tVars['display']);
        $this->assertSame($qtask, $tVars['qtask']);

        // Reward must be queued...
        $this->assertCount(1, $uclass->rewards);
        [$rewardUserId, $reward, $rewardTitle] = $uclass->rewards[0];
        $this->assertSame(18151, $rewardUserId);
        $this->assertSame(30, $reward['money']);
        $this->assertSame(30, $reward['exp']);
        $this->assertSame('Coincidence by design', $rewardTitle);

        // ...and the completion must be recorded so the next quest unlocks.
        $questInserts = array_values(array_filter(
            $db->inserts,
            static fn (array $insert): bool => $insert[0] === 'quests_user'
        ));

        $this->assertCount(1, $questInserts, 'quests_user completion row was not written');
        $this->assertSame(70, $questInserts[0][1]['quest']);
        $this->assertSame(18151, $questInserts[0][1]['user_id']);
        $this->assertArrayHasKey('created', $questInserts[0][1]);
        $this->assertArrayHasKey('last_done', $questInserts[0][1]);

        // Feedback message that points at the completion row.
        $this->assertCount(1, $uclass->messages);
        $this->assertSame(18151, $uclass->messages[0][1]);
        $this->assertSame('Feedback: Coincidence by design', $uclass->messages[0][2]);
    }
}
