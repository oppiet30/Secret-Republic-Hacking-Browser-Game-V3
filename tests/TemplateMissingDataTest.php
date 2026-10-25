<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for templates that rendered content for data that does not
 * exist, producing broken images and fatal errors instead of degrading.
 *
 * 1. Achievements. The `achievements` table is not seeded, so a reward that
 *    grants an achievement id (level rewards grant id 2) resolved to an empty
 *    row. templates/profile/achievements.tpl still printed
 *
 *        <img src="{$config.url}layout/img/achievement/{$achievement.image}"/>
 *
 *    which became a request for the bare directory /layout/img/achievement/ and
 *    returned 403 - a broken image on the reward page.
 *
 * 2. Layers. Skills 12-15 (Data Points Mining, Node Initialization Success
 *    Rate, Hardware repair, Software repair) have no "layer" key in
 *    includes/constants/skills.php. templates/grid/layers.tpl called
 *    array_keys($theskills[$skill]["layer"]) for every skill the player owns,
 *    so opening /grid/layers with one of those skills raised
 *    "array_keys(): Argument #1 ($array) must be of type array, null given"
 *    and the page returned HTTP 500.
 */
final class TemplateMissingDataTest extends TestCase
{
    private static string $root;
    private string $compileDir;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__);
        require_once self::$root . '/includes/vendor/autoload.php';
    }

    protected function setUp(): void
    {
        $this->compileDir = sys_get_temp_dir() . '/sr-smarty-test-' . getmypid() . '-' . uniqid();
        mkdir($this->compileDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->compileDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->compileDir);
    }

    private function render(string $template, array $vars): string
    {
        $smarty = new \Smarty\Smarty();
        $smarty->setTemplateDir(self::$root . '/templates');
        $smarty->setCompileDir($this->compileDir);
        $smarty->setCaching(false);
        $smarty->assign('config', ['url' => 'http://test.local/']);
        foreach ($vars as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch($template);
    }

    public function testAchievementWithoutAnImageRendersNoImageTag(): void
    {
        $html = $this->render('profile/achievements.tpl', [
            'achievements' => [
                ['name' => 'Level 2', 'image' => '', 'description' => 'reached level 2', 'times' => 1],
            ],
        ]);

        $this->assertStringNotContainsString(
            'layout/img/achievement/',
            $html,
            'an achievement with no image must not emit an img tag (it requested a directory and returned 403)'
        );
        $this->assertStringContainsString('Level 2', $html, 'the achievement itself must still be listed');
    }

    public function testAchievementWithAnImageStillRendersIt(): void
    {
        $html = $this->render('profile/achievements.tpl', [
            'achievements' => [
                ['name' => 'Level 2', 'image' => 'trophy-1.png', 'description' => 'reached level 2', 'times' => 1],
            ],
        ]);

        $this->assertStringContainsString('layout/img/achievement/trophy-1.png', $html);
    }

    public function testEveryLayerLookupIsGuardedAgainstSkillsWithoutLayers(): void
    {
        $template = file_get_contents(self::$root . '/templates/grid/layers.tpl');
        $this->assertIsString($template);

        preg_match_all('~array_keys\(\$theskills\[\$skill\]\["layer"\]\)~', $template, $matches);
        $this->assertNotEmpty($matches[0], 'the layer lookups this test guards disappeared; update the test');

        foreach (preg_split('~\R~', $template) as $number => $line) {
            if (!str_contains($line, 'array_keys($theskills[$skill]["layer"])')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '~isset\(\$theskills\[\$skill\]\["layer"\]\)~',
                $line,
                'line ' . ($number + 1) . ' calls array_keys() on the layer config without an isset() guard, '
                . 'which is a fatal TypeError for skills 12-15'
            );
        }
    }

    public function testLayerLookupsOnlyExistInsideTheGuardedConditions(): void
    {
        $template = file_get_contents(self::$root . '/templates/grid/layers.tpl');
        $this->assertIsString($template);

        $this->assertSame(
            4,
            preg_match_all('~array_keys\(\$theskills\[\$skill\]\["layer"\]\)~', $template),
            'unexpected number of layer lookups; keep them all guarded'
        );
    }
}
