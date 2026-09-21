\Craft::$app->db->close(); \Craft::$app->db->open();
$p=\iceboxind\sesame\Plugin::getInstance();
foreach($p->rules->all() as $r){ if($r->pattern==='members/*'){ $p->rules->delete($r->uid); } }
$rule=new \iceboxind\sesame\models\Rule(['label'=>'Glob rule (test)','matchType'=>'uri','pattern'=>'members/*','enabled'=>true]);
$p->rules->save($rule);
$p->codes->add($rule->uid, 'patternpass', 'Default'); // code one = the rule's password (P1.2)
echo 'GLOBUID='.$rule->uid."\n";
