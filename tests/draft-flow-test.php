\Craft::$app->db->close(); \Craft::$app->db->open();
$entries = \Craft::$app->getEntries();
$elements = \Craft::$app->getElements();
$drafts = \Craft::$app->getDrafts();
$secrets = \iceboxind\sesame\Plugin::getInstance()->secrets;
$pass = 0; $fail = 0;
$ck = function($n, $c) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n\n"; } };

function canonical() { return \craft\elements\Entry::find()->section('sesameTest')->slug('child')->status(null)->one(); }

// Simulate the CP: autosave a provisional draft with a field value, then (fresh
// request) reload it from the DB and apply it.
$editAndApply = function(array $fieldVal) use ($drafts, $elements) {
    $c = canonical();
    $draft = $drafts->createDraft($c, $c->getAuthorId() ?? 1, null, null, [], true);
    $draft->setFieldValue('sesameProtect', $fieldVal);
    $elements->saveElement($draft);
    $fresh = \craft\elements\Entry::find()->id($draft->id)->siteId($draft->siteId)->drafts()->provisionalDrafts()->status(null)->one();
    return $drafts->applyDraft($fresh);
};
$state = function() use ($secrets) {
    $c = canonical();
    $row = $secrets->getForEntry($c->uid);
    return $row;
};

// clean slate: unprotected canonical
$secrets->clearForEntry(canonical()->uid);

// A. fresh entry: enable + type 'alpha' -> canonical protected, alpha unlocks
$editAndApply(['enabled' => true, 'password' => 'alpha']);
$a = $state();
$ck('A. fresh enable+password: canonical has a real secret', $a && $a['secret'] !== '');
$ck('A. alpha unlocks', $a && $secrets->verify('alpha', $a['secret'], $a['mode']));
$epochA = $a['epoch'];

// B. edit WITHOUT touching the password (password=null) -> alpha preserved
$editAndApply(['enabled' => true, 'password' => null]);
$b = $state();
$ck('B. no-password edit preserves alpha', $b && $secrets->verify('alpha', $b['secret'], $b['mode']));
$ck('B. epoch unchanged on a no-op edit', $b && (int)$b['epoch'] === (int)$epochA);

// C. change the password to 'beta' -> beta unlocks, alpha does not, epoch bumps
$editAndApply(['enabled' => true, 'password' => 'beta']);
$c = $state();
$ck('C. beta unlocks after change', $c && $secrets->verify('beta', $c['secret'], $c['mode']));
$ck('C. alpha no longer unlocks', $c && !$secrets->verify('alpha', $c['secret'], $c['mode']));
$ck('C. epoch bumped on password change', $c && (int)$c['epoch'] > (int)$epochA);

// D. disable protection via the draft -> canonical unprotected (disabled tombstone)
$editAndApply(['enabled' => false, 'password' => null]);
$d = $state();
$ck('D. disable => mode disabled (entry unprotected)', $d && $d['mode'] === 'disabled');

// E. discard a draft that staged a password -> orphan row, cleaned by GC
$secrets->clearForEntry(canonical()->uid);
$c0 = canonical();
$draft = $drafts->createDraft($c0, $c0->getAuthorId() ?? 1, null, null, [], true);
$draft->setFieldValue('sesameProtect', ['enabled' => true, 'password' => 'gamma']);
$elements->saveElement($draft);
$draftUid = $draft->uid;
$ck('E. staged draft row exists before discard', $secrets->getForEntry($draftUid) !== null);
$elements->deleteElement($draft, true); // discard (hard delete)
$ck('E. draft row survives the discard (not cleared by the field hook)', $secrets->getForEntry($draftUid) !== null);
$removed = $secrets->purgeOrphans();
$ck('E. purgeOrphans removes the orphaned draft row', $secrets->getForEntry($draftUid) === null && $removed >= 1);

// F. a revision never writes a secret row
$secrets->clearForEntry(canonical()->uid);
$cF = canonical();
$revId = \Craft::$app->getRevisions()->createRevision($cF, $cF->getAuthorId() ?? 1);
$rev = \craft\elements\Entry::find()->id($revId)->revisions()->status(null)->one();
$ck('F. revision wrote no secret row', $rev && $secrets->getForEntry($rev->uid) === null);

$secrets->clearForEntry(canonical()->uid);
echo "\nRESULT: $pass passed, $fail failed\n";
