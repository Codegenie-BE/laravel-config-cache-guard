<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\PreBootstrapGuard;

$definedVariables = get_defined_vars();
$composerAutoloadPath = $definedVariables['_composer_autoload_path']
    ?? $GLOBALS['_composer_autoload_path']
    ?? null;

PreBootstrapGuard::run(is_string($composerAutoloadPath) ? $composerAutoloadPath : null);
