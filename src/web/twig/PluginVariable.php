<?php

namespace dgaidula\sesame\web\twig;

use dgaidula\sesame\Plugin;

/**
 * Exposes `craft.sesame.*` to templates.
 *
 *   {{ craft.sesame.example() }}
 */
class PluginVariable
{
    /** Example method — replace with whatever this plugin actually needs to expose. */
    public function example(): string
    {
        return Plugin::getInstance()->name;
    }
}
