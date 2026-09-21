\Craft::$app->db->close(); \Craft::$app->db->open();
$p = \iceboxind\sesame\Plugin::getInstance();
$t = $p->throttle;
$cache = \Craft::$app->getCache();
$L = $p->getSettings()->attemptLimit; // per-IP limit (8)
$SCOPE = $L * 5;                        // per-scope ceiling (multiplier 5)
$W = intdiv(time(), $p->getSettings()->attemptWindow); // fixed-window number (keys carry it)

$pass = 0; $fail = 0;
$ck = function($name, $cond) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
};
$cache->flush();

// ---- Per-IP bucket ----
$A = 'rule.testA';
for ($i = 0; $i < $L - 1; $i++) { $t->record('1.1.1.1', $A); }
$ck("per-IP: one below limit is NOT throttled", $t->tooMany('1.1.1.1', $A) === false);
$t->record('1.1.1.1', $A); // now at L
$ck("per-IP: at limit IS throttled", $t->tooMany('1.1.1.1', $A) === true);
$ck("per-IP: a DIFFERENT ip on same scope is NOT throttled (scope bucket still low)", $t->tooMany('2.2.2.2', $A) === false);
$ck("per-IP: sequential count is exact (= L)", (int)$cache->get("sesame:attempts:1.1.1.1:{$A}:{$W}") === $L);

// ---- Per-scope bucket: rotating-IP attack ----
$B = 'rule.testB';
for ($i = 0; $i < $SCOPE - 1; $i++) { $t->record("10.0.0.$i", $B); } // distinct IPs, one hit each
$ck("scope: below ceiling, a FRESH ip is NOT throttled", $t->tooMany('9.9.9.9', $B) === false);
$ck("scope: each rotated ip stayed at 1 (never tripped its own per-IP bucket)", (int)$cache->get("sesame:attempts:10.0.0.0:{$B}:{$W}") === 1);
$t->record('10.0.0.999', $B); // one more distinct IP -> scope hits ceiling
$ck("scope: at ceiling, a FRESH ip IS throttled (rotating-IP defense)", $t->tooMany('9.9.9.9', $B) === true);
$ck("scope: per-scope count is exact (= L*5)", (int)$cache->get("sesame:attempts:scope:{$B}:{$W}") === $SCOPE);

$cache->flush();
echo "\nRESULT: $pass passed, $fail failed (L=$L, scopeCeiling=$SCOPE)\n";
