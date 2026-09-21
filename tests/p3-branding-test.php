\Craft::$app->db->close(); \Craft::$app->db->open();
$p = \iceboxind\sesame\Plugin::getInstance();
$rules = $p->rules; $codes = $p->codes; $branding = $p->branding;
$B = \iceboxind\sesame\services\Branding::class;
$pass = 0; $fail = 0;
$ck = function($n, $c) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n\n"; } };

// --- sanitizeAccent (safe + legible: hex/rgb/hsl, contrast-gated, no bare words) ---
$ck('accent: legible hex kept', $B::sanitizeAccent('#2f6f7e') === '#2f6f7e');
$ck('accent: legible rgb() kept', $B::sanitizeAccent('rgb(47, 111, 126)') === 'rgb(47, 111, 126)');
$ck('accent: legible hsl() kept', $B::sanitizeAccent('hsl(210, 50%, 40%)') === 'hsl(210, 50%, 40%)');
$ck('accent: bare keyword rejected (unparseable colour)', $B::sanitizeAccent('rebeccapurple') === null);
$ck('accent: white rejected (invisible on the light card)', $B::sanitizeAccent('#ffffff') === null);
$ck('accent: black rejected (invisible on the dark card)', $B::sanitizeAccent('#000000') === null);
$ck('accent: too-light hex rejected (low contrast)', $B::sanitizeAccent('#abc') === null);
$ck('accent: CSS-injection rejected', $B::sanitizeAccent('red} body{display:none') === null);
$ck('accent: markup rejected', $B::sanitizeAccent('</style><script>') === null);
$ck('accent: over-length rejected', $B::sanitizeAccent('rgba(255.000, 255.000, 255.000, 0.5000)') === null);
$ck('accent: array param => null (no TypeError)', $B::sanitizeAccent(['#fff']) === null);
$ck('accent: blank => null', $B::sanitizeAccent('') === null);

// --- fresh rule + code one ---
foreach ($rules->all() as $r) { $rules->delete($r->uid); }
$branding->saveSiteDefaults(null, null, null, null); // clear site defaults
$rule = new \iceboxind\sesame\models\Rule(['label'=>'Page One (test)','matchType'=>'uri','pattern'=>'sesame-test/page-one','enabled'=>true]);
$rules->save($rule);
$codes->add($rule->uid, 'letmein', 'Default');
$ruleUid = $rule->uid;
$scope = $rules->getByUid($ruleUid)->toScope();

// --- no branding anywhere => all null (template falls back to built-in) ---
$b0 = $branding->resolveForScope($scope);
$ck('no branding => accent null', $b0['accent'] === null);
$ck('no branding => heading null', $b0['heading'] === null);
$ck('no branding => intro null', $b0['intro'] === null);
$ck('no branding => logoUrl null', $b0['logoUrl'] === null);

// --- site defaults apply when the rule has no override ---
$branding->saveSiteDefaults(null, 'Members Area', 'Enter the shared code.', '#123456');
$sd = $branding->siteDefaults();
$ck('siteDefaults round-trips', $sd['heading'] === 'Members Area' && $sd['accent'] === '#123456' && $sd['intro'] === 'Enter the shared code.');
$b1 = $branding->resolveForScope($scope);
$ck('site default heading applies', $b1['heading'] === 'Members Area');
$ck('site default accent applies', $b1['accent'] === '#123456');
$ck('site default intro applies (rule has no message)', $b1['intro'] === 'Enter the shared code.');

// --- per-rule overrides win ---
$rl = $rules->getByUid($ruleUid);
$rl->brandHeading = 'Board Portal';
$rl->brandAccent = '#ff0000';
$rl->message = 'Board members only.';
$rules->save($rl);
$b2 = $branding->resolveForScope($rules->getByUid($ruleUid)->toScope());
$ck('per-rule heading overrides site', $b2['heading'] === 'Board Portal');
$ck('per-rule accent overrides site', $b2['accent'] === '#ff0000');
$ck('per-rule intro (message) overrides site intro', $b2['intro'] === 'Board members only.');

// --- entry scope (no rule) uses site defaults ---
$entryScope = new \iceboxind\sesame\models\Scope(['type'=>'entry','uid'=>'nope']);
$b3 = $branding->resolveForScope($entryScope);
$ck('entry scope uses site heading', $b3['heading'] === 'Members Area');

// cleanup
$branding->saveSiteDefaults(null, null, null, null);
$rl = $rules->getByUid($ruleUid); $rl->brandHeading=null; $rl->brandAccent=null; $rl->message=null; $rules->save($rl);
echo "\nRESULT: $pass passed, $fail failed\n";
