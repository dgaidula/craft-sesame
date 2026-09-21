$pc=\Craft::$app->getProjectConfig(); $pc->set('plugins.sesame.edition','pro','regression'); $pc->saveModifiedConfigData();
echo "ED=".$pc->get('plugins.sesame.edition')."\n";
