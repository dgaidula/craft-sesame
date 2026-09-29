// 1.0.1: section / entry-type rules are keyed by UID, not handle. In 1.0.0 a
// handle rename silently unprotected every page the rule covered. Covers the
// 1.0.0 → 1.0.1 migration (handle rows → UID), matching across a handle rename
// for both rule types, and a deleted target protecting nothing.
// `ddev craft shell < tests/section-uid-test.php` (Pro not required: match() has no edition check).
\Craft::$app->db->close(); \Craft::$app->db->open();
$p = \iceboxind\sesame\Plugin::getInstance();
$entriesSvc = \Craft::$app->getEntries();
$pass = 0; $fail = 0;
$ck = function($n, $c) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $n\n"; } else { $fail++; echo "  FAIL  $n\n"; } };
$fresh = fn() => new \iceboxind\sesame\services\Rules(); // bypass the request-scoped rule cache
$entry = fn() => \craft\elements\Entry::find()->slug('page-one')->status(null)->one();

$page = $entry();
$section = $page->getSection();
$type = $page->getType();
$origSectionHandle = $section->handle;
$origTypeHandle = $type->handle;

foreach ($p->rules->all() as $r) { $p->rules->delete($r->uid); }

// --- Migration: 1.0.0 rows stored the handle ---
$db = \Craft::$app->getDb();
$now = \craft\helpers\Db::prepareDateForDb(new \DateTime());
$legacy = function (string $label, string $matchType, string $pattern, int $sort) use ($db, $now) {
    $uid = \craft\helpers\StringHelper::UUID();
    $db->createCommand()->insert('{{%sesame_rules}}', [
        'label' => $label, 'matchType' => $matchType, 'pattern' => $pattern, 'enabled' => true,
        'sortOrder' => $sort, 'rememberMe' => false, 'epoch' => 0,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => $uid,
    ])->execute();
    return $uid;
};
$uSection = $legacy('legacy section', 'section', $origSectionHandle, 1);
$uType = $legacy('legacy type', 'entryType', $origTypeHandle, 2);
$uGone = $legacy('legacy gone', 'section', 'noSuchSectionHandle', 3);
$pat = fn($uid) => (new \craft\db\Query())->select('pattern')->from('{{%sesame_rules}}')->where(['uid' => $uid])->scalar();

ob_start();
$m = new \iceboxind\sesame\migrations\m260928_000000_section_rules_by_uid();
$ok = $m->safeUp();
$ok2 = $m->safeUp(); // idempotent
ob_end_clean();
$ck('migration succeeds, and again (idempotent)', $ok && $ok2);
$ck('migration: section handle -> section UID', $pat($uSection) === $section->uid);
$ck('migration: entry-type handle -> entry-type UID', $pat($uType) === $type->uid);
$ck('migration: unresolvable handle left unchanged', $pat($uGone) === 'noSuchSectionHandle');

// 1.0.0 matched the entry's type handle, which is a section's per-section
// OVERRIDE when set; the migration must map an override handle to the type UID.
$ovr = 'sesameOvr' . substr(md5((string) microtime(true)), 0, 6);
$origOvr = (new \craft\db\Query())->select('handle')->from('{{%sections_entrytypes}}')->where(['sectionId' => $section->id, 'typeId' => $type->id])->scalar();
$db->createCommand()->update('{{%sections_entrytypes}}', ['handle' => $ovr], ['sectionId' => $section->id, 'typeId' => $type->id])->execute();
try {
    $uOvr = $legacy('legacy override', 'entryType', $ovr, 5);
    ob_start(); (new \iceboxind\sesame\migrations\m260928_000000_section_rules_by_uid())->safeUp(); ob_end_clean();
    $ck('migration: per-section override handle -> entry-type UID', $pat($uOvr) === $type->uid);
} finally {
    $db->createCommand()->update('{{%sections_entrytypes}}', ['handle' => $origOvr ?: null], ['sectionId' => $section->id, 'typeId' => $type->id])->execute();
}
$p->rules->delete($uOvr);

// --- Matching by UID, before and after a handle rename ---
$db->createCommand()->update('{{%sesame_rules}}', ['enabled' => false], ['uid' => [$uType, $uGone]])->execute();
$ck('section rule (UID) protects page-one', $fresh()->match($entry()) !== null);

try {
    $section->handle = $origSectionHandle . 'Renamed';
    $entriesSvc->saveSection($section);
    $ck('section handle renamed', $entriesSvc->getSectionByUid($section->uid)->handle === $origSectionHandle . 'Renamed');
    $ck('section rule STILL protects page-one after the rename', $fresh()->match($entry()) !== null);
    $ck('section rule STILL vetoes static caching after the rename', $fresh()->anyEnabledRuleMatches($entry()) === true);
} finally {
    $section = $entriesSvc->getSectionByUid($section->uid);
    $section->handle = $origSectionHandle;
    $entriesSvc->saveSection($section);
}

$db->createCommand()->update('{{%sesame_rules}}', ['enabled' => false], ['uid' => $uSection])->execute();
$db->createCommand()->update('{{%sesame_rules}}', ['enabled' => true], ['uid' => $uType])->execute();
$ck('entry-type rule (UID) protects page-one', $fresh()->match($entry()) !== null);
try {
    $type->handle = $origTypeHandle . 'Renamed';
    $entriesSvc->saveEntryType($type);
    $ck('entry-type handle renamed', $entriesSvc->getEntryTypeByUid($type->uid)->handle === $origTypeHandle . 'Renamed');
    $ck('entry-type rule STILL protects page-one after the rename', $fresh()->match($entry()) !== null);
} finally {
    $type = $entriesSvc->getEntryTypeByUid($type->uid);
    $type->handle = $origTypeHandle;
    $entriesSvc->saveEntryType($type);
}

// --- A deleted target protects nothing, and target() reports it ---
$db->createCommand()->update('{{%sesame_rules}}', ['enabled' => false], ['uid' => $uType])->execute();
$gone = $legacy('deleted target', 'section', \craft\helpers\StringHelper::UUID(), 4);
$svc = $fresh();
$ck('rule whose section no longer exists matches nothing', $svc->match($entry()) === null);
$ck('target() is null for a deleted section', $svc->target($svc->getByUid($gone)) === null);
$ck('target() resolves a live section by UID', $svc->target($svc->getByUid($uSection))?->uid === $section->uid);
$ck('handles restored', $entriesSvc->getSectionByUid($section->uid)->handle === $origSectionHandle && $entriesSvc->getEntryTypeByUid($type->uid)->handle === $origTypeHandle);

foreach ([$uSection, $uType, $uGone, $gone] as $u) { $p->rules->delete($u); }
\Craft::$app->getProjectConfig()->saveModifiedConfigData();
echo "\nRESULT: $pass passed, $fail failed\n";
