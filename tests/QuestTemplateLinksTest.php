<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for links rendered from the mission page template
 * (templates/quests/quest_play.tpl).
 *
 * `$url` looks like a global Smarty variable but it is not: includes/header.php
 * aliases `$container->url` for PHP only, and index.php never assigns `url` to
 * Smarty. A template using `{$url}objectiveView/full` therefore renders
 * href="objectiveView/full", which resolves relative to the current URL. On the
 * mission page that "worked" by accident (/quests/objectiveView/full) until the
 * back button produced /quests/objectiveView/full/objectiveView/no and the
 * player ended up playing from a garbage URL.
 *
 * App links must be built from `{$config.url}` (the dominant convention in
 * templates/), and every such link must resolve to a real module.
 */
final class QuestTemplateLinksTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../templates/quests/quest_play.tpl';

    private static function template(): string
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template, 'Quest play template must be readable');

        return $template;
    }

    /**
     * @return list<string> link targets found after {$config.url}
     */
    private static function configUrlTargets(string $template): array
    {
        preg_match_all('~href="\{\$config\.url\}([^"{}]*)~', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function testMissionTemplateDoesNotUseTheUnassignedUrlVariable(): void
    {
        $this->assertStringNotContainsString(
            '{$url}',
            self::template(),
            '{$url} is never assigned to Smarty; build app links from {$config.url} instead'
        );
    }

    public function testFullScreenAndHistoryLinksStayInsideTheQuestsModule(): void
    {
        $template = self::template();

        $this->assertStringContainsString(
            'href="{$config.url}quests/objectiveView/full"',
            $template,
            'The "read in full screen" link must be an absolute mission-page URL'
        );
        $this->assertStringContainsString(
            'href="{$config.url}quests/history"',
            $template,
            'The console history link must point at quests/history, the route the module handles'
        );
        $this->assertStringContainsString(
            'href="{$config.url}quests"',
            $template,
            'The full-screen back button must return to a clean /quests URL'
        );
    }

    public function testEveryMissionTemplateRouteResolvesToAModule(): void
    {
        $repo = realpath(__DIR__ . '/..');
        $this->assertIsString($repo);

        // Static asset / virtual paths that are not modules.
        $staticPrefixes = ['layout', 'mp3', 'ogg', 'img', 'pages'];
        $unresolved     = [];

        foreach (self::configUrlTargets(self::template()) as $target) {
            $path = preg_split('~[?#]~', $target)[0] ?? '';
            $path = trim($path, '/');

            if ($path === '') {
                continue; // site root
            }

            $first = explode('/', $path)[0];

            if (in_array($first, $staticPrefixes, true)) {
                continue;
            }

            $moduleFile = "$repo/includes/modules/$first.php";
            $moduleDir  = "$repo/includes/modules/$first/$first.php";

            if (!is_file($moduleFile) && !is_file($moduleDir)) {
                $unresolved[] = $target;
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            "Mission template links point at routes the router cannot serve:\n" . implode("\n", $unresolved)
        );
    }
}
