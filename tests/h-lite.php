$pc=\Craft::$app->getProjectConfig(); $pc->set('plugins.sesame.edition','lite','regression'); $pc->set('plugins.sesame.settings.rememberMeDuration',0,'regression'); $pc->saveModifiedConfigData();
echo "ED=".$pc->get('plugins.sesame.edition')."\n";
