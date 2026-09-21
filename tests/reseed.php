// Reseed the canonical baseline the HTTP drivers assume: a single always-on
// rule protecting `sesame-test/page-one`, its primary password ("code one")
// = `letmein`, no branding, edition lite. Run this between suites — several
// drivers deliberately mutate the password / epoch / rules, so they are not
// isolated from each other. `ddev craft shell < tests/reseed.php`.
\Craft::$app->db->close(); \Craft::$app->db->open();
$p = \iceboxind\sesame\Plugin::getInstance();

foreach ($p->rules->all() as $r) {
    $p->rules->delete($r->uid);
}
$p->branding->saveSiteDefaults(null, null, null, null);

$rule = new \iceboxind\sesame\models\Rule([
    'label' => 'Page One (test)',
    'matchType' => 'uri',
    'pattern' => 'sesame-test/page-one',
    'enabled' => true,
]);
$p->rules->save($rule);
$code = $p->codes->add($rule->uid, 'letmein', 'Default');

$pc = \Craft::$app->getProjectConfig();
$pc->set('plugins.sesame.edition', 'lite', 'reseed');
$pc->saveModifiedConfigData();

echo "RESEED rule={$rule->uid} codeOne={$code?->uid} mode={$code?->secretMode} epoch={$rule->epoch} edition=" . $p->edition . "\n";
