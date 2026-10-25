<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for experience/level persistence.
 *
 * add_exp() computes the new exp/level from $this->user and then persists the
 * result with updatePlayer(). $this->user is served by Alpha::__get(), which
 * binds it as a reference to Container::get('user').
 *
 * Container::get() used to return by value. Binding a reference to a by-value
 * return silently produced a reference to a throw-away copy, so
 *
 *     $this->user['exp'] += $exp;
 *
 * changed nothing: the while loop saw the old (0) exp and updatePlayer() wrote
 * exp = 0 back to the database. Players never gained experience or levels, even
 * though money and skill points from the same reward were saved correctly.
 */
final class AddExpTest extends TestCase
{
    private const USER_ID = 18151;

    private Container $container;
    private object $db;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/class/Container.php';
        require_once dirname(__DIR__) . '/includes/class/alpha.class.php';
        require_once dirname(__DIR__) . '/includes/class/userclass.php';
    }

    protected function setUp(): void
    {
        $this->db = new class {
            /** @var array<int, array{table: string, where: mixed, data: array}> */
            public array $updates = [];

            private mixed $whereValue = null;

            public function where($key, $value = null, $operator = '=')
            {
                $this->whereValue = $key === 'id' ? $value : null;

                return $this;
            }

            public function getOne($table, $fields = '*')
            {
                if ($table === 'level_rewards') {
                    return ['level_id' => 0];
                }
                if ($table === 'user_referrals') {
                    return [];
                }

                return [];
            }

            public function update($table, $data, $limit = null)
            {
                $this->updates[] = ['table' => $table, 'where' => $this->whereValue, 'data' => $data];

                return true;
            }

            public function insert($table, $data)
            {
                return 1;
            }

            public function rawQuery($sql, $params = [])
            {
                return [];
            }

            /** @return array<string, mixed>|null last update written to users */
            public function lastUserUpdate(): ?array
            {
                for ($i = count($this->updates) - 1; $i >= 0; $i--) {
                    if ($this->updates[$i]['table'] === 'users') {
                        return $this->updates[$i];
                    }
                }

                return null;
            }
        };

        $this->container = new Container();
        $this->container->set('db', $this->db);
        $this->container->set('config', ['url' => 'http://test.local/']);
    }

    private function makeUser(int $exp, int $expNext, int $level, int $energy = 72, int $maxEnergy = 100): UserClass
    {
        $this->container->set('user', [
            'id'        => self::USER_ID,
            'exp'       => $exp,
            'expNext'   => $expNext,
            'level'     => $level,
            'energy'    => $energy,
            'maxEnergy' => $maxEnergy,
        ]);

        return new UserClass($this->container);
    }

    public function testAddExpPersistsExperienceForTheCurrentUser(): void
    {
        $userclass = $this->makeUser(0, 62, 1);

        $userclass->add_exp(30);

        $update = $this->db->lastUserUpdate();
        $this->assertNotNull($update, 'add_exp() never wrote to the users table');
        $this->assertSame(self::USER_ID, $update['where'], 'experience must be saved for the user who earned it');
        $this->assertSame(30, $update['data']['exp'], 'the gained experience must be persisted');
        $this->assertSame(1, $update['data']['level'], 'below the threshold the level must not change');
        $this->assertSame(30, $this->container->get('user')['exp'], 'the in-memory user must be updated too');
    }

    public function testAddExpAccumulatesAcrossCalls(): void
    {
        $userclass = $this->makeUser(0, 62, 1);

        $userclass->add_exp(30);
        $userclass->add_exp(20);

        $update = $this->db->lastUserUpdate();
        $this->assertSame(50, $update['data']['exp'], 'experience must accumulate on top of the stored value');
        $this->assertSame(50, $this->container->get('user')['exp']);
    }

    public function testAddExpLevelsUpWhenThresholdIsReached(): void
    {
        $userclass = $this->makeUser(60, 62, 1);

        $userclass->add_exp(30);

        $update = $this->db->lastUserUpdate();
        $this->assertSame(2, $update['data']['level'], 'crossing expNext must raise the level');
        $this->assertSame(28, $update['data']['exp'], 'surplus experience must carry over');
        $this->assertGreaterThan(62, $update['data']['expNext'], 'the next threshold must grow');
        $this->assertSame(110, $update['data']['maxEnergy'], 'level 2 grants the level energy');
        $this->assertSame(2, $this->container->get('user')['level']);
        $this->assertSame(110, $this->container->get('user')['energy']);
    }
}
