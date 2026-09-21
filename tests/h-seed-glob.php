\Craft::$app->db->close(); \Craft::$app->db->open();
$p=\iceboxind\sesame\Plugin::getInstance();
foreach($p->rules->all() as $r){ if($r->pattern==='members/*'){ $p->rules->delete($r->uid); } }
$enc=$p->secrets->store('patternpass');
$rule=new \iceboxind\sesame\models\Rule(['label'=>'Glob rule (test)','matchType'=>'uri','pattern'=>'members/*','secret'=>$enc['secret'],'secretMode'=>$enc['mode'],'enabled'=>true]);
$p->rules->save($rule);
echo 'GLOBUID='.$rule->uid."\n";
