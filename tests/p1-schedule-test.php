\Craft::$app->db->close(); \Craft::$app->db->open();
$rulesSvc = \iceboxind\sesame\Plugin::getInstance()->rules;
$pass = 0; $fail = 0;
$ck = function($n, $c) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n\n"; } };

$utc = new \DateTimeZone('UTC');
$now = new \DateTime('now', $utc);
$past = (clone $now)->modify('-1 day')->format('Y-m-d H:i:s');
$future = (clone $now)->modify('+1 day')->format('Y-m-d H:i:s');
$mk = fn($from, $until) => new \iceboxind\sesame\models\Rule(['protectFrom' => $from, 'protectUntil' => $until]);

// --- isScheduledActive unit cases ---
$ck('no schedule => active', $mk(null, null)->isScheduledActive());
$ck('protectFrom in the future => NOT active (public until then)', $mk($future, null)->isScheduledActive() === false);
$ck('protectFrom in the past => active', $mk($past, null)->isScheduledActive() === true);
$ck('protectUntil in the future => active', $mk(null, $future)->isScheduledActive() === true);
$ck('protectUntil in the past => NOT active (expired, public now)', $mk(null, $past)->isScheduledActive() === false);
$ck('window [past, future] covering now => active', $mk($past, $future)->isScheduledActive() === true);
$ck('window entirely in the future => NOT active', $mk($future, (clone $now)->modify('+2 days')->format('Y-m-d H:i:s'))->isScheduledActive() === false);
$ck('window entirely in the past => NOT active', $mk((clone $now)->modify('-2 days')->format('Y-m-d H:i:s'), $past)->isScheduledActive() === false);
$ck('unparseable protectFrom => FAIL CLOSED (active)', $mk('not-a-date', null)->isScheduledActive() === true);

// --- match() honors the schedule; anyEnabledRuleMatches() ignores it ---
$entry = \craft\elements\Entry::find()->section('sesameTest')->slug('page-one')->one();
$codesSvc = \iceboxind\sesame\Plugin::getInstance()->codes;
// clear existing rules, make one protecting page-one, EXPIRED (protectUntil in the past)
foreach ($rulesSvc->all() as $r) { $rulesSvc->delete($r->uid); }
$expired = new \iceboxind\sesame\models\Rule(['label'=>'sched','matchType'=>'uri','pattern'=>'sesame-test/page-one','enabled'=>true,'protectUntil'=>$past]);
$rulesSvc->save($expired);
$codesSvc->add($expired->uid, 'letmein', 'Default'); // code one = rule password (P1.2)
$ck('EXPIRED rule: match() returns null (gate lets the page through)', $rulesSvc->match($entry) === null);
$ck('EXPIRED rule: anyEnabledRuleMatches() true (cache veto ignores schedule)', $rulesSvc->anyEnabledRuleMatches($entry) === true);

// flip to an ACTIVE window
$expired->protectUntil = $future;
$rulesSvc->save($expired);
$ck('ACTIVE rule: match() returns a scope (page protected)', $rulesSvc->match($entry) !== null);
$ck('ACTIVE rule: anyEnabledRuleMatches() still true', $rulesSvc->anyEnabledRuleMatches($entry) === true);

// cleanup -> restore a plain always-on rule for later HTTP tests
foreach ($rulesSvc->all() as $r) { $rulesSvc->delete($r->uid); }
$plain = new \iceboxind\sesame\models\Rule(['label'=>'Page One (test)','matchType'=>'uri','pattern'=>'sesame-test/page-one','enabled'=>true]);
$rulesSvc->save($plain);
$codesSvc->add($plain->uid, 'letmein', 'Default');
echo "\nRESULT: $pass passed, $fail failed\n";
