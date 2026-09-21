\Craft::$app->db->close(); \Craft::$app->db->open();
$p=\iceboxind\sesame\Plugin::getInstance();
foreach($p->rules->all() as $r){ if($r->pattern==='members/*'){ $p->rules->delete($r->uid); } }
echo "GLOBDEL\n";
