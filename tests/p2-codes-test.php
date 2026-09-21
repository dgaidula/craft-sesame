\Craft::$app->db->close(); \Craft::$app->db->open();
$p = \iceboxind\sesame\Plugin::getInstance();
$rules = $p->rules; $codes = $p->codes; $gate = $p->gate;
$pass = 0; $fail = 0;
$ck = function($n, $c) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n\n"; } };
$utc = new \DateTimeZone('UTC');
$past = (new \DateTime('now', $utc))->modify('-1 day')->format('Y-m-d H:i:s');
$future = (new \DateTime('now', $utc))->modify('+1 day')->format('Y-m-d H:i:s');

// fresh rule protecting page-one
foreach ($rules->all() as $r) { $rules->delete($r->uid); }
$rule = new \iceboxind\sesame\models\Rule(['label'=>'Page One (test)','matchType'=>'uri','pattern'=>'sesame-test/page-one','enabled'=>true]);
$rules->save($rule);
$ruleUid = $rule->uid;

// --- add code one + verify ---
$c1 = $codes->add($ruleUid, 'letmein', 'Default');
$ck('add code one returns a Code', $c1 !== null && $c1->uid !== null);
$ck('codeOne is code one', $codes->codeOne($ruleUid)->uid === $c1->uid);
$ck('count = 1', $codes->count($ruleUid) === 1);
$scope = $rules->getByUid($ruleUid)->toScope();
$v = $gate->verify($scope, 'letmein');
$ck('verify(letmein) matches code one', $v['matched'] === true && $v['codeId'] === $c1->uid);
$ck('verify(wrong) no match', $gate->verify($scope, 'wrong')['matched'] === false);
$ck('reveal code one = letmein', $codes->reveal($c1->uid) === 'letmein');

// --- second code, per-code revoke ---
$c2 = $codes->add($ruleUid, 'district-a-pw', 'District A');
$ck('verify(district-a-pw) matches code two', $gate->verify($scope, 'district-a-pw')['codeId'] === $c2->uid);
$codes->revoke($c2->uid);
$ck('after revoke: code two no longer verifies', $gate->verify($scope, 'district-a-pw')['matched'] === false);
$ck('after revoke: code two not active', $codes->isActive($c2->uid) === false);
$ck('after revoke: code one still verifies', $gate->verify($scope, 'letmein')['matched'] === true);

// --- expiry ---
$c3 = $codes->add($ruleUid, 'expired-pw', 'Expired');
$codes->setExpiry($c3->uid, $past);
$ck('expired code does not verify', $gate->verify($scope, 'expired-pw')['matched'] === false);
$c4 = $codes->add($ruleUid, 'future-pw', 'Future-exp');
$codes->setExpiry($c4->uid, $future);
$ck('code with future expiry verifies', $gate->verify($scope, 'future-pw')['codeId'] === $c4->uid);

// --- change code one password bumps the rule epoch ---
$epochBefore = $rules->getByUid($ruleUid)->epoch;
$codes->changePassword($c1->uid, 'newpass');
$epochAfter = $rules->getByUid($ruleUid)->epoch;
$scope2 = $rules->getByUid($ruleUid)->toScope();
$ck('changePassword: newpass verifies', $gate->verify($scope2, 'newpass')['matched'] === true);
$ck('changePassword: old letmein fails', $gate->verify($scope2, 'letmein')['matched'] === false);
$ck('changePassword bumps the rule epoch', (int)$epochAfter === (int)$epochBefore + 1);

// --- relabel ---
$codes->relabel($c1->uid, 'Primary');
$ck('relabel takes effect', $codes->getByUid($c1->uid)->label === 'Primary');

// --- delete: last-code guard ---
foreach ($codes->forRule($ruleUid) as $c) { if ($c->uid !== $c1->uid) { $codes->delete($c->uid); } }
$ck('down to one code', $codes->count($ruleUid) === 1);
$ck('delete refuses the LAST code', $codes->delete($c1->uid) === false && $codes->count($ruleUid) === 1);

// --- cap at 25 ---
for ($i = 0; $i < 30; $i++) { $codes->add($ruleUid, "bulk-$i", "Bulk $i"); }
$ck('cap: never exceeds MAX_CODES (25)', $codes->count($ruleUid) === 25);
$ck('cap: activeForRule capped at 25', count($codes->activeForRule($ruleUid)) === 25);

// cleanup -> single clean code-one rule for HTTP tests
foreach ($rules->all() as $r) { $rules->delete($r->uid); }
$fresh = new \iceboxind\sesame\models\Rule(['label'=>'Page One (test)','matchType'=>'uri','pattern'=>'sesame-test/page-one','enabled'=>true]);
$rules->save($fresh);
$codes->add($fresh->uid, 'letmein', 'Default');
echo "\nRESULT: $pass passed, $fail failed\n";
