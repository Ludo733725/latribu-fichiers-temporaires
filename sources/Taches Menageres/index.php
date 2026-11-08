<?php
declare(strict_types=1); session_start();
$dir=dirname(__DIR__).'/private/la-tribu-data'; $file=$dir.'/family.json';
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function go(){header('Location: index.php');exit;}
if(!is_dir($dir)) @mkdir($dir,0750,true);
if(!file_exists($file)) file_put_contents($file,json_encode(['family'=>null,'users'=>[],'paid'=>[],'unpaid'=>[],'claims'=>[],'cap'=>5],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX);
function data(){global $file;$x=json_decode(file_get_contents($file),true);return is_array($x)?$x:[];}
function save($d){global $file;$f=fopen($file,'c+');flock($f,LOCK_EX);ftruncate($f,0);rewind($f);fwrite($f,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));fflush($f);flock($f,LOCK_UN);fclose($f);}
function id(){return bin2hex(random_bytes(8));}
function wk(){return date('o-\WW');}
function euro($n){return number_format((float)$n,2,',',' ').' €';}
$d=data();$err='';
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
if(isset($_GET['out'])){session_destroy();go();}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??'')){http_response_code(400);exit('Recharge la page.');}
 $a=$_POST['a']??'';
 try{
 if($a==='setup' && !$d['family']){
  $login=strtolower(trim($_POST['login']??''));$pw=$_POST['pw']??'';$name=trim($_POST['name']??'');
  if(!$name||!preg_match('/^[a-z0-9._-]{3,30}$/',$login)||strlen($pw)<8)throw new Exception('Prénom, identifiant valide et mot de passe de 8 caractères minimum requis.');
  $d['family']=['name'=>'La Tribu'];$d['users'][]=['id'=>id(),'name'=>$name,'login'=>$login,'pw'=>password_hash($pw,PASSWORD_DEFAULT),'role'=>'parent'];save($d);$_SESSION['uid']=$d['users'][0]['id'];go();
 }
 if($a==='login'){foreach($d['users'] as $u)if($u['login']===strtolower(trim($_POST['login']??''))&&password_verify($_POST['pw']??'',$u['pw'])){session_regenerate_id(true);$_SESSION['uid']=$u['id'];go();}throw new Exception('Identifiant ou mot de passe incorrect.');}
 $me=null;foreach($d['users'] as $u)if($u['id']==($_SESSION['uid']??''))$me=$u;
 if(!$me)throw new Exception('Connecte-toi.');$parent=$me['role']==='parent';
 if($a==='claim'){
  $task=null;foreach($d['paid'] as $p)if($p['id']===$_POST['task'])$task=$p;if(!$task)throw new Exception('Mission introuvable.');
  $count=max(1,min(30,(int)$_POST['count']));$sum=0;foreach($d['claims'] as $c)if($c['uid']===$me['id']&&$c['week']===wk()&&in_array($c['status'],['pending','approved']))$sum+=$c['price']*$c['count'];
  if($sum+$task['price']*$count>$d['cap']+.0001)throw new Exception('Le plafond hebdomadaire serait dépassé.');
  $d['claims'][]=['id'=>id(),'uid'=>$me['id'],'task'=>$task['name'],'price'=>$task['price'],'count'=>$count,'status'=>'pending','week'=>wk()];save($d);go();
 }
 if($parent){
  if($a==='approve'||$a==='reject'){foreach($d['claims'] as &$c)if($c['id']===$_POST['id'])$c['status']=$a==='approve'?'approved':'rejected';unset($c);save($d);go();}
  if($a==='user'){ $login=strtolower(trim($_POST['login']));$pw=$_POST['pw'];if(!trim($_POST['name'])||!preg_match('/^[a-z0-9._-]{3,30}$/',$login)||strlen($pw)<8)throw new Exception('Vérifie prénom, identifiant et mot de passe (8 caractères minimum).');foreach($d['users'] as $u)if($u['login']===$login)throw new Exception('Identifiant déjà utilisé.');$d['users'][]=['id'=>id(),'name'=>trim($_POST['name']),'login'=>$login,'pw'=>password_hash($pw,PASSWORD_DEFAULT),'role'=>'member'];save($d);go();}
  if($a==='paid'){ $n=trim($_POST['name']);$p=(float)str_replace(',','.',$_POST['price']);if(!$n||$p<0)throw new Exception('Nom ou prix invalide.');$d['paid'][]=['id'=>id(),'name'=>$n,'price'=>$p];save($d);go();}
  if($a==='unpaid'){if(trim($_POST['name']))$d['unpaid'][]=trim($_POST['name']);save($d);go();}
  if($a==='cap'){$d['cap']=max(0,(float)$_POST['cap']);save($d);go();}
 }
 }catch(Throwable $e){$err=$e->getMessage();$d=data();}
}
$me=null;foreach($d['users'] as $u)if($u['id']==($_SESSION['uid']??''))$me=$u;
function form($a){return '<form method="post"><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="a" value="'.$a.'">';}
$week=wk();$approved=0;$pending=0;
if($me)foreach($d['claims'] as $c)if($c['uid']===$me['id']&&$c['week']===$week){if($c['status']==='approved')$approved+=$c['price']*$c['count'];if($c['status']==='pending')$pending+=$c['price']*$c['count'];}
?>
<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#315b50"><title>La Tribu</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#fbf8f0;color:#26372f;font:16px system-ui}header{background:#315b50;color:white;padding:22px;border-radius:0 0 24px 24px}header h1,main{max-width:820px;margin:auto}header h1{font-size:28px}main{padding:14px}.card{background:white;border:1px solid #e6e5dc;border-radius:18px;padding:16px;margin:12px 0}h2{font-size:19px;margin:0 0 12px}.muted{color:#718078;font-size:13px}.row{display:flex;justify-content:space-between;align-items:center;gap:10px}.task{border-top:1px solid #eee;padding:12px 0}.price{color:#315b50;font-weight:800}input,select{width:100%;padding:11px;border:1px solid #d8ddd7;border-radius:10px;font:inherit;margin:5px 0 10px}label{display:block;font-size:13px;font-weight:700;margin-top:7px}button{border:0;border-radius:10px;padding:10px 13px;background:#315b50;color:white;font-weight:700;cursor:pointer}.light{background:#e8f2ec;color:#315b50}.red{background:#f9e8e5;color:#963e32}.stat{background:#e8f2ec;border-radius:12px;padding:12px;flex:1}.stat b{display:block;font-size:22px}.alert{padding:12px;background:#fff0d2;border-radius:10px;margin:10px 0}.tabs{display:flex;gap:12px;flex-wrap:wrap}.tabs a{color:#315b50;font-weight:700}.pill{background:#e8f2ec;padding:4px 8px;border-radius:20px;font-size:12px}
</style><body><header><h1>🏡 <?=h($d['family']['name']??'La Tribu')?></h1><div>Les petites missions, les grandes fiertés !</div></header><main>
<?php if($err):?><div class="alert"><?=h($err)?></div><?php endif;?>
<?php if(!$d['family']):?><div class="card"><h2>Créer l'espace famille</h2><?=form('setup')?><label>Ton prénom</label><input name="name" required><label>Identifiant parent</label><input name="login" required><label>Mot de passe (8 caractères minimum)</label><input type="password" name="pw" required><button>Créer La Tribu</button></form></div>
<?php elseif(!$me):?><div class="card"><h2>Connexion</h2><?=form('login')?><label>Identifiant</label><input name="login" required><label>Mot de passe</label><input type="password" name="pw" required><button>Se connecter</button></form></div>
<?php else:?><div class="row"><b>Bonjour <?=h($me['name'])?> !</b><a href="?out=1">Déconnexion</a></div><div class="card"><h2>💰 Ma cagnotte cette semaine</h2><div class="row"><div class="stat">Validé<b><?=euro($approved)?></b></div><div class="stat">En attente<b><?=euro($pending)?></b></div></div><p class="muted">Plafond : <?=euro($d['cap'])?> par semaine.</p></div>
<div class="card"><h2>✨ Missions rémunérées</h2><?php foreach($d['paid'] as $p):?><div class="task"><div class="row"><b><?=h($p['name'])?></b><span class="price"><?=euro($p['price'])?></span></div><?=form('claim')?><input type="hidden" name="task" value="<?=h($p['id'])?>"><label>Combien de fois ?</label><div class="row"><input type="number" name="count" min="1" max="30" value="1"><button>Déclarer</button></div></form></div><?php endforeach;?></div>
<div class="card"><h2>🏠 Responsabilités habituelles (non rémunérées)</h2><?php foreach($d['unpaid'] as $u):?><div class="task"><?=h($u)?></div><?php endforeach;?></div>
<div class="card"><h2>📋 Mes déclarations</h2><?php $mine=array_filter($d['claims'],fn($c)=>$c['uid']===$me['id']);if(!$mine)echo '<p class="muted">Aucune déclaration pour le moment.</p>';foreach(array_reverse($mine) as $c):?><div class="task row"><span><?=h($c['task'])?> × <?=$c['count']?> · <?=h($c['status']==='pending'?'En attente':($c['status']==='approved'?'Validée':'Refusée'))?></span><b><?=euro($c['price']*$c['count'])?></b></div><?php endforeach;?></div>
<?php if($me['role']==='parent'):?><div class="card"><h2>🔐 Espace parent — validations</h2><?php foreach($d['claims'] as $c):if($c['status']!=='pending')continue;$who='Membre';foreach($d['users'] as $u)if($u['id']===$c['uid'])$who=$u['name'];?><div class="task"><b><?=h($who)?></b> : <?=h($c['task'])?> × <?=$c['count']?> = <?=euro($c['price']*$c['count'])?><div class="row"><?=form('approve')?><input type="hidden" name="id" value="<?=h($c['id'])?>"><button>Valider</button></form><?=form('reject')?><input type="hidden" name="id" value="<?=h($c['id'])?>"><button class="red">Refuser</button></form></div></div><?php endforeach;?></div>
<div class="card"><h2>👨‍👩‍👧 Ajouter un membre</h2><?=form('user')?><label>Prénom</label><input name="name" required><label>Identifiant</label><input name="login" required><label>Mot de passe (8 caractères minimum)</label><input type="password" name="pw" required><button>Ajouter</button></form><p class="muted">Comptes existants : <?php foreach($d['users'] as $u)echo h($u['name']).' · ';?></p></div>
<div class="card"><h2>🧽 Ajouter une mission rémunérée</h2><?=form('paid')?><label>Nom</label><input name="name" required><label>Prix par réalisation (€)</label><input type="number" name="price" step=".01" min="0" required><button>Ajouter</button></form><?php foreach($d['paid'] as $p):?><div class="task row"><?=h($p['name'])?> <b><?=euro($p['price'])?></b></div><?php endforeach;?></div>
<div class="card"><h2>🏡 Ajouter une responsabilité non rémunérée</h2><?=form('unpaid')?><input name="name" placeholder="Ex. Ranger ses affaires" required><button>Ajouter</button></form><?php foreach($d['unpaid'] as $u):?><div class="task"><?=h($u)?></div><?php endforeach;?></div>
<div class="card"><h2>Plafond hebdomadaire</h2><?=form('cap')?><input type="number" name="cap" min="0" step=".5" value="<?=h($d['cap'])?>"><button>Enregistrer</button></form></div><?php endif;?>
<?php endif;?><footer class="muted">La Tribu · Espace familial privé</footer></main></body></html>