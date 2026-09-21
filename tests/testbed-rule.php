$p = \iceboxind\sesame\Plugin::getInstance();
$rule = new \iceboxind\sesame\models\Rule();
$rule->enabled = true;
$rule->label = 'Testbed';
$rule->matchType = 'uri';
$rule->pattern = 'sesame-test/page-one';
$rule->message = 'Protected. Enter the password.';
$ok = $p->rules->save($rule);
// Since P1.2 a rule's password is "code one" in the Codes service, not a column
// on the rule — add it after the rule has a uid.
$code = $ok ? $p->codes->add($rule->uid, 'letmein', 'Default') : null;
return json_encode(['saved' => $ok, 'mode' => $code?->secretMode, 'uid' => $rule->uid, 'errors' => $rule->getErrors()]);
