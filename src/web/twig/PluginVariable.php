<?php

namespace iceboxind\sesame\web\twig;

/**
 * Exposes `craft.sesame.*` to templates. Registered in {@see \iceboxind\sesame\Plugin::registerTwigVariable()}.
 *
 * Intentionally empty for now — Sesame gates entirely via the request lifecycle
 * and needs no Twig surface yet. This is the seam for the planned
 * `craft.sesame.isProtected(entry)` / query-filter helpers (see docs/PRO-PRIORITIES.md,
 * "P2"). The starter's placeholder `example()` method was removed.
 */
class PluginVariable
{
}
