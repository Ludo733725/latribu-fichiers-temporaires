<?php
declare(strict_types=1);
session_start();

$DATA_DIR = dirname(__DIR__) . '/private/la-tribu-informations';
$DATA_FILE = $DATA_DIR . '/data.json';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0750, true); }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function load_data(): array {
    global $DATA_FILE;
    if (!is_file($DATA_FILE)) return ['users'=>[], 'posts'=>[]];
    $d=json_decode((string)file_get_contents($DATA_FILE),true);
    return is_array($d) ? array_merge(['users'=>[],'posts'=>[]],$d) : ['users'=>[],'posts'=>[]];
}
function save_data(array $d): bool {
    global $DATA_FILE,$DATA_DIR;
    if (!is_dir($DATA_DIR) && !@mkdir($DATA_DIR,0750,true)) return false;
    $tmp=$DATA_FILE.'.tmp';
    $json=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false || file_put_contents($tmp,$json,LOCK_EX)===false) return false;
    return rename($tmp,$DATA_FILE);
}
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if(!hash_equals(csrf(),(string)($_POST['csrf']??''))){http_response_code(400);exit('Requête invalide. Recharge la page.');} }
function get_user(array $d): ?array {
    $id=$_SESSION['uid']??'';
    foreach($d['users'] as $u) if(($u['id']??'')===$id) return $u;
    return null;
}
function go(string $url='index.php'): never { header('Location: '.$url); exit; }

$data=load_data(); $setup=count($data['users'])===0; $error=''; $notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf(); $action=(string)($_POST['action']??'');
    if($action==='setup' && $setup){
        $name=trim((string)($_POST['name']??'Administrateur')); $pass=(string)($_POST['password']??'');
        if($name==='' || strlen($pass)<12) $error='Choisis un nom et un mot de passe de 12 caractères minimum.';
        else{
            $data['users'][]=['id'=>bin2hex(random_bytes(8)),'name'=>$name,'username'=>'admin','password'=>password_hash($pass,PASSWORD_DEFAULT),'role'=>'admin'];
            if(save_data($data)){session_regenerate_id(true);$_SESSION['uid']=$data['users'][0]['id'];go();}
            $error='Impossible d’enregistrer les données. Vérifie les droits d’écriture du dossier privé.';
        }
    } elseif($action==='login' && !$setup){
        $username=trim((string)($_POST['username']??''));$pass=(string)($_POST['password']??'');
        foreach($data['users'] as $u){
            if(strcasecmp((string)$u['username'],$username)===0 && password_verify($pass,(string)$u['password'])){
                session_regenerate_id(true);$_SESSION['uid']=$u['id'];go();
            }
        }
        $error='Identifiant ou mot de passe incorrect.';
    } elseif($action==='logout'){
        $_SESSION=[];session_destroy();go();
    } else {
        $user=get_user($data); if(!$user) go();
        $isAdmin=($user['role']??'')==='admin';
        if($action==='add_user' && $isAdmin){
            $name=trim((string)($_POST['name']??''));$username=strtolower(trim((string)($_POST['username']??'')));$pass=(string)($_POST['password']??'');
            if($name==='' || !preg_match('/^[a-zA-Z0-9._-]{3,30}$/',$username) || strlen($pass)<8) $error='Nom requis, identifiant de 3 à 30 caractères (lettres, chiffres, point, tiret, underscore) et mot de passe de 8 caractères minimum.';
            else{
                foreach($data['users'] as $u) if(strcasecmp($u['username'],$username)===0) $error='Cet identifiant existe déjà.';
                if(!$error){
                    $data['users'][]=['id'=>bin2hex(random_bytes(8)),'name'=>$name,'username'=>$username,'password'=>password_hash($pass,PASSWORD_DEFAULT),'role'=>'member'];
                    if(save_data($data))$notice='Compte créé.';else$error='Enregistrement impossible. Vérifie les permissions.';
                }
            }
        } elseif($action==='delete_user' && $isAdmin){
            $id=(string)($_POST['id']??'');
            $data['users']=array_values(array_filter($data['users'],fn($u)=>($u['id']??'')!==$id && ($u['role']??'')!=='admin'));
            if(save_data($data))$notice='Compte supprimé.';else$error='Impossible d’enregistrer.';
        } elseif($action==='add_post'){
            $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$category=(string)($_POST['category']??'Info');
            $allowed=['Info','À retenir','Événement','Coup de main'];
            if($title==='' || $body==='')$error='Ajoute un titre et un message.';
            elseif(strlen($title)>120 || strlen($body)>5000)$error='Le titre ou le message est trop long.';
            else{
                if(!in_array($category,$allowed,true))$category='Info';
                $data['posts'][]=['id'=>bin2hex(random_bytes(8)),'author_id'=>$user['id'],'author_name'=>$user['name'],'title'=>$title,'body'=>$body,'category'=>$category,'pinned'=>false,'created'=>date('c'),'updated'=>date('c')];
                if(save_data($data))$notice='Publication ajoutée au tableau !';else$error='Impossible d’enregistrer la publication.';
            }
        } elseif($action==='edit_post'){
            $id=(string)($_POST['id']??'');$found=false;
            foreach($data['posts'] as &$p){
                if(($p['id']??'')===$id){
                    if(!$isAdmin && ($p['author_id']??'')!==$user['id']){$error='Tu peux modifier uniquement tes propres publications.';break;}
                    $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$category=(string)($_POST['category']??'Info');
                    if($title===''||$body===''||strlen($title)>120||strlen($body)>5000){$error='Le titre et le message sont obligatoires (120 caractères max pour le titre, 5 000 pour le message).';break;}
                    if(!in_array($category,['Info','À retenir','Événement','Coup de main'],true))$category='Info';
                    $p['title']=$title;$p['body']=$body;$p['category']=$category;$p['updated']=date('c');$found=true;break;
                }
            } unset($p);
            if(!$error && !$found)$error='Publication introuvable.';
            if(!$error){if(save_data($data))$notice='Publication modifiée.';else$error='Impossible d’enregistrer.';}
        } elseif($action==='delete_post'){
            $id=(string)($_POST['id']??'');$found=false;
            foreach($data['posts'] as $p)if(($p['id']??'')===$id){$found=true;if(!$isAdmin && ($p['author_id']??'')!==$user['id'])$error='Tu peux supprimer uniquement tes propres publications.';break;}
            if(!$found)$error='Publication introuvable.';
            if(!$error){$data['posts']=array_values(array_filter($data['posts'],fn($p)=>($p['id']??'')!==$id));if(save_data($data))$notice='Publication supprimée.';else$error='Impossible d’enregistrer.';}
        } elseif($action==='toggle_pin' && $isAdmin){
            $id=(string)($_POST['id']??'');
            foreach($data['posts'] as &$p)if(($p['id']??'')===$id){$p['pinned']=!($p['pinned']??false);break;}unset($p);
            if(save_data($data))$notice='Épinglage mis à jour.';else$error='Impossible d’enregistrer.';
        }
    }
}
$data=load_data();$user=get_user($data);$setup=count($data['users'])===0;
$posts=$data['posts'];usort($posts,function($a,$b){$pin=(int)($b['pinned']??false)<=>(int)($a['pinned']??false);return $pin?:strcmp((string)($b['created']??''),(string)($a['created']??''));});
$editing=null;
if($user && isset($_GET['edit']))foreach($posts as $p)if($p['id']===$_GET['edit'] && ((($user['role']??'')==='admin')||$p['author_id']===$user['id'])){$editing=$p;break;}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Informations de la Tribu</title><link rel="stylesheet" href="style.css"></head><body>
<header class="top"><div class="brand"><span class="mark">📌</span><div><strong>La Tribu</strong><small>Le tableau d'informations</small></div></div><?php if($user):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="logout"><button class="logout">Déconnexion</button></form><?php endif;?></header>
<main class="wrap">
<?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="alert success"><?=h($notice)?></div><?php endif;?>
<?php if($setup):?>
<section class="panel narrow"><span class="eyebrow">Première installation</span><h1>Créer le compte administrateur</h1><p>L'administrateur pourra créer les comptes de la famille et gérer toutes les publications. Chaque membre pourra ensuite publier ses propres messages.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="setup"><label>Nom affiché<input name="name" required placeholder="Administrateur"></label><label>Mot de passe administrateur<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button class="primary">Créer le compte admin</button></form><p class="hint">12 caractères minimum. L'identifiant administrateur sera <strong>admin</strong>.</p></section>
<?php elseif(!$user):?>
<section class="panel narrow"><span class="eyebrow">Bienvenue à la maison</span><h1>Connexion</h1><p>Connecte-toi pour consulter et publier les informations de la famille.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="login"><label>Identifiant<input name="username" required autocomplete="username"></label><label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Se connecter</button></form></section>
<?php else:?>
<div class="intro"><div><span class="eyebrow">Toutes les nouvelles de la maison</span><h1>Le tableau de la Tribu</h1><p>Bonjour <?=h($user['name'])?> ! Partage les informations avec tout le monde.</p></div><span class="role"><?=($user['role']==='admin')?'Administrateur':'Membre'?></span></div>
<section class="panel composer"><h2><?= $editing?'Modifier une publication':'Partager une information'?></h2><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="<?=$editing?'edit_post':'add_post'?>"><?php if($editing):?><input type="hidden" name="id" value="<?=h($editing['id'])?>"><?php endif;?><label>Titre<input name="title" maxlength="120" required value="<?=h($editing['title']??'')?>" placeholder="Ex. N'oubliez pas le rendez-vous de samedi"></label><label>Catégorie<select name="category"><?php foreach(['Info','À retenir','Événement','Coup de main'] as $c):?><option value="<?=h($c)?>" <?=($editing['category']??'Info')===$c?'selected':''?>><?=h($c)?></option><?php endforeach;?></select></label><label>Message<textarea name="body" rows="4" maxlength="5000" required placeholder="Écris ton message ici…"><?=h($editing['body']??'')?></textarea></label><div class="actions"><button class="primary"><?=$editing?'Enregistrer les modifications':'Publier sur le tableau'?></button><?php if($editing):?><a class="cancel" href="index.php">Annuler</a><?php endif;?></div></form></section>
<?php if($user['role']==='admin'):?><section class="panel adminbox"><h2>Comptes de la famille</h2><p>Crée un identifiant pour chaque membre. Tous les comptes peuvent publier et gérer leurs propres messages.</p><form method="post" class="userform"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add_user"><label>Nom affiché<input name="name" required placeholder="Ex. Camille"></label><label>Identifiant<input name="username" required pattern="[a-zA-Z0-9._-]{3,30}" placeholder="camille"></label><label>Mot de passe<input name="password" type="password" minlength="8" required placeholder="8 caractères minimum"></label><button class="primary">Créer le compte</button></form><?php $members=array_filter($data['users'],fn($u)=>($u['role']??'')==='member');?><div class="userlist"><?php foreach($members as $m):?><div class="userrow"><span><strong><?=h($m['name'])?></strong><small><?=h($m['username'])?></small></span><form method="post" onsubmit="return confirm('Supprimer ce compte ? Les publications existantes resteront visibles.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?=h($m['id'])?>"><button class="delete">Supprimer</button></form></div><?php endforeach;?></div></section><?php endif;?>
<div class="feedhead"><div><span class="eyebrow">Le fil familial</span><h2>Les publications</h2></div><span class="count"><?=count($posts)?> message(s)</span></div>
<?php if(!$posts):?><section class="empty"><span>🌿</span><h3>Le tableau est tout neuf !</h3><p>La première publication lancera les échanges de la Tribu.</p></section><?php else:?><div class="feed"><?php foreach($posts as $p):$mine=($p['author_id']??'')===$user['id'];$canManage=($user['role']==='admin'||$mine);?><article class="post <?=!empty($p['pinned'])?'pinned':''?>"><?php if(!empty($p['pinned'])):?><div class="pinlabel">📌 Message épinglé</div><?php endif;?><div class="postmeta"><span class="category"><?=h($p['category']??'Info')?></span><span><?=h($p['author_name']??'Membre')?> · <?=h(date('d/m/Y à H:i',strtotime($p['created']??'now')))?></span></div><h3><?=h($p['title'])?></h3><p class="postbody"><?=nl2br(h($p['body']))?></p><?php if($canManage||$user['role']==='admin'):?><div class="postactions"><?php if($canManage):?><a href="?edit=<?=h($p['id'])?>">Modifier</a><?php endif;?><?php if($user['role']==='admin'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="id" value="<?=h($p['id'])?>"><button><?=!empty($p['pinned'])?'Désépingler':'Épingler'?></button></form><?php endif;?><form method="post" onsubmit="return confirm('Supprimer cette publication ?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?=h($p['id'])?>"><button class="danger">Supprimer</button></form></div><?php endif;?></article><?php endforeach;?></div><?php endif;?>
<?php endif;?>
<footer>La Tribu · Les infos importantes, au même endroit ❤️</footer></main></body></html>