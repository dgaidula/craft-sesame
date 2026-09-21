<?php
// Standalone verification of the fixed Rules::matchesUri() logic (#1 fix),
// copied verbatim from src/services/Rules.php — no Craft needed.
function matchesUri(string $pattern, string $uri): bool
{
    if ($uri === '' || $pattern === '') {
        return false;
    }
    if (str_ends_with($pattern, '/*')) {
        $base = substr($pattern, 0, -2);
        $regex = '#^' . preg_quote($base, '#') . '(/.*)?$#i';
    } else {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
    }
    return (bool) preg_match($regex, $uri);
}

$cases = [
    // [pattern, uri, expected]
    ['district-resources/*', 'district-resources', true],        // THE FIX: base path now covered
    ['district-resources/*', 'district-resources/child', true],  // descendant
    ['district-resources/*', 'district-resources/a/b', true],    // deep descendant
    ['district-resources/*', 'district-resources-other', false], // must NOT over-match a sibling
    ['district-resources',   'district-resources', true],        // exact = just this page
    ['district-resources',   'district-resources/child', false], // exact does not cover children
    ['contact', 'contact', true],
    ['contact', 'contact-us', false],                            // verifier's over-match concern
    ['members/*', 'members', true],                              // the reported members-area case
    ['members/*', 'members/secret', true],
];

$fail = 0;
foreach ($cases as [$p, $u, $exp]) {
    $got = matchesUri($p, $u);
    $ok = ($got === $exp);
    if (!$ok) {
        $fail++;
    }
    printf("  %s  pattern=%-22s uri=%-24s => %s (expected %s)\n",
        $ok ? 'PASS' : 'FAIL', $p, $u, var_export($got, true), var_export($exp, true));
}
printf("\nRESULT: %d passed, %d failed\n", count($cases) - $fail, $fail);
exit($fail ? 1 : 0);
