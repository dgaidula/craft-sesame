<?php

namespace iceboxind\sesame\web\twig;

use iceboxind\sesame\Plugin;

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
