\Craft::$app->db->close(); \Craft::$app->db->open();
$p = \iceboxind\sesame\Plugin::getInstance();
$s = $p->secrets;
$gate = $p->gate;
$E = \craft\elements\Entry::find()->slug('child')->section('sesameTest')->one();
$uid = $E->uid;

$pass = 0; $fail = 0;
$ck = function($name, $cond) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
};
// scope epoch for this entry via the public isProtected() path (entryScope is private).
$scopeEpoch = function() use ($gate, $E) {
    $sc = $gate->isProtected($E);
    return $sc === null ? 'NULL' : $sc->epoch;
};

$s->clearForEntry($uid); // clean slate

$s->storeForEntry($uid, 'alpha');
$ck('fresh insert => epoch 0', $scopeEpoch() === 0);
$ck('fresh insert => protected (encrypt)', $s->getForEntry($uid)['mode'] === 'encrypt');

$s->storeForEntry($uid, 'beta'); // password change (UPDATE branch)
$ck('password change => epoch 1', $scopeEpoch() === 1);

$s->disableForEntry($uid);
$row = $s->getForEntry($uid);
$ck('disable => row kept as tombstone', $row !== null);
$ck('disable => mode disabled', $row['mode'] === 'disabled');
$ck('disable => epoch 2 (bumped, not reset)', (int)$row['epoch'] === 2);
$ck('disable => entry not protected (scope NULL)', $scopeEpoch() === 'NULL');
$ck('disable => hasEntrySecret false', $s->hasEntrySecret($uid) === false);

$s->storeForEntry($uid, 'gamma'); // RE-ENABLE with new password
$ck('re-enable => epoch 3 (MONOTONIC across disable, not reset to 0)', $scopeEpoch() === 3);
$ck('re-enable => protected again (encrypt)', $s->getForEntry($uid)['mode'] === 'encrypt');

$s->disableForEntry($uid); // -> epoch 4, disabled
$s->markProtectedWithoutSecret($uid); // re-enable w/o password -> fail closed
$row2 = $s->getForEntry($uid);
$ck('disable->markProtectedWithoutSecret => epoch 5 (monotonic)', (int)$row2['epoch'] === 5);
$ck('markProtectedWithoutSecret => fail-closed empty secret', $row2['secret'] === '' && $row2['mode'] === 'encrypt');
$ck('markProtectedWithoutSecret => protected (scope not null)', $scopeEpoch() !== 'NULL');

$s->clearForEntry($uid); // cleanup
$ck('clearForEntry (hard delete) => row gone', $s->getForEntry($uid) === null);

echo "\nRESULT: $pass passed, $fail failed\n";
