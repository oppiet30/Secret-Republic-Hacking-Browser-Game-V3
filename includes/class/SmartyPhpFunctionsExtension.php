<?php

use Smarty\BlockHandler\BlockHandlerInterface;
use Smarty\Compile\CompilerInterface;
use Smarty\Compile\Modifier\ModifierCompilerInterface;
use Smarty\Extension\ExtensionInterface;
use Smarty\FunctionHandler\FunctionHandlerInterface;

/**
 * Restores the pre-Smarty-5 behaviour where any native PHP function could be
 * used as a template modifier, e.g. {$x|ceil} or {$x|strtoupper}.
 *
 * The game's templates also use helper functions defined in
 * includes/functions.php as modifiers (romanic_number, sec2hms, date_fashion,
 * profile_link, ...). Smarty 5 no longer falls back to PHP functions on its
 * own, so this catch-all extension maps known functions to themselves.
 *
 * Registered after the core extensions, it only sees modifier names that the
 * core extensions did not already handle.
 */
class SmartyPhpFunctionsExtension implements ExtensionInterface
{
    public function getTagCompiler(string $tag): ?CompilerInterface
    {
        return null;
    }

    public function getModifierCompiler(string $modifier): ?ModifierCompilerInterface
    {
        return null;
    }

    public function getFunctionHandler(string $functionName): ?FunctionHandlerInterface
    {
        return null;
    }

    public function getBlockHandler(string $blockTagName): ?BlockHandlerInterface
    {
        return null;
    }

    public function getModifierCallback(string $modifierName)
    {
        return function_exists($modifierName) ? $modifierName : null;
    }

    public function getPreFilters(): array
    {
        return [];
    }

    public function getPostFilters(): array
    {
        return [];
    }

    public function getOutputFilters(): array
    {
        return [];
    }
}