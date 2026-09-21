$entries = Craft::$app->getEntries();
$existing = $entries->getSectionByHandle('sesameTest');
if ($existing) { return 'section already exists: id=' . $existing->id; }

$et = new \craft\models\EntryType();
$et->name = 'Sesame Test';
$et->handle = 'sesameTest';
if (!$entries->saveEntryType($et)) { return 'ENTRY TYPE errors: ' . json_encode($et->getErrors()); }

$section = new \craft\models\Section();
$section->name = 'Sesame Test';
$section->handle = 'sesameTest';
$section->type = \craft\models\Section::TYPE_CHANNEL;
$section->setSiteSettings([
    new \craft\models\Section_SiteSettings([
        'siteId' => 1,
        'hasUrls' => true,
        'uriFormat' => 'sesame-test/{slug}',
        'template' => 'sesame-test',
    ]),
]);
$section->setEntryTypes([$et]);
if (!$entries->saveSection($section)) { return 'SECTION errors: ' . json_encode($section->getErrors()); }

$make = function (string $title, string $slug) use ($section, $et) {
    $e = new \craft\elements\Entry();
    $e->sectionId = $section->id;
    $e->typeId = $et->id;
    $e->title = $title;
    $e->slug = $slug;
    $e->enabled = true;
    if (!Craft::$app->getElements()->saveElement($e, false)) {
        return 'ENTRY errors: ' . json_encode($e->getErrors());
    }
    return $e->id . ' -> ' . $e->uri;
};

$a = $make('Protected Page', 'page-one');
$b = $make('Child Page', 'child');

return 'section=' . $section->id . '; entries: ' . $a . ' | ' . $b;
