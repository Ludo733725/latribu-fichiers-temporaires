<?php
declare(strict_types=1);
session_start();

$privateDir = dirname(__DIR__) . '/private/la-tribu-presentation';
$dataFile = $privateDir . '/data.json';
$photoDir = $privateDir . '/photos';

if (!is_dir($privateDir)) mkdir($privateDir, 0750, true);
if (!is_dir($photoDir)) mkdir($photoDir, 0750, true);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function loadData(): array {
    global $dataFile;
    if (!file_exists($dataFile)) return ['users'=>[], 'profiles'=>[]];
    $d = json_decode((string)file_get_contents($dataFile), true);
    return is_array($d) ? array_merge(['users'=>[], 'profiles'=>[]], $d) : ['users'=>[], 'profiles'=>[]];
}
function saveData(array $d): void {
    global $dataFile;
    file_put_contents($dataFile, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function loggedIn(): bool { return isset($_SESSION['user']); }
function isAdmin(): bool { return loggedIn() && ($_SESSION['role'] ?? '') === 'admin'; }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function checkCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Requête invalide. Recharge la page.'); }
}
function redirect(string $url='index.php'): never { header('Location: '.$url); exit; }

$data = loadData();
$error = '';
$notice = '';

if (isset($_GET['logout'])) { session_destroy(); redirect(); }

if (!$data['users']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
        checkCsrf();
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 12) $error = 'Choisis un mot de passe d’au moins 12 caractères.';
        else {
            $data['users'][] = ['username'=>'admin','password'=>password_hash($password, PASSWORD_DEFAULT),'role'=>'admin'];
            saveData($data);
            $_SESSION['user']='admin'; $_SESSION['role']='admin';
            redirect();
        }
    }
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Présentation — La Tribu</title><link rel="stylesheet" href="style.css"></head><body><main class="auth"><div class="brand">🌿 La Tribu</div><section class="card"><h1>Bienvenue !</h1><p>Crée le compte administrateur pour commencer à présenter la famille.</p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="setup"><label>Mot de passe administrateur</label><input type="password" name="password" minlength="12" required autocomplete="new-password"><button>Créer l’administrateur</button></form><small>Identifiant : <strong>admin</strong> · 12 caractères minimum</small></section></main></body></html><?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $found = null;
        foreach ($data['users'] as $u) if (strcasecmp($u['username'], $username) === 0) { $found=$u; break; }
        if ($found && password_verify($password, $found['password'])) {
            session_regenerate_id(true);
            $_SESSION['user']=$found['username']; $_SESSION['role']=$found['role']; redirect();
        }
        $error = 'Identifiant ou mot de passe incorrect.';
    } elseif (!loggedIn()) {
        redirect();
    } elseif ($action === 'save_profile' && isAdmin()) {
        $id = (string)($_POST['id'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') $error = 'Le prénom ou nom est obligatoire.';
        else {
            $profile = [
                'id'=>$id ?: bin2hex(random_bytes(8)),
                'name'=>$name,
                'birthday'=>trim((string)($_POST['birthday'] ?? '')),
                'passions'=>trim((string)($_POST['passions'] ?? '')),
                'bio'=>trim((string)($_POST['bio'] ?? '')),
                'updated'=>date('c')
            ];
            if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                $tmp=$_FILES['photo']['tmp_name'];
                $info=@getimagesize($tmp);
                $mime=$info['mime'] ?? '';
                $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime] ?? '';
                if (!$ext || ($_FILES['photo']['size'] ?? 0) > 5*1024*1024) $error='Photo invalide (JPG, PNG, WEBP ou GIF, 5 Mo maximum).';
                else {
                    $filename=bin2hex(random_bytes(16)).'.'.$ext;
                    if (move_uploaded_file($tmp, $photoDir.'/'.$filename)) $profile['photo']=$filename;
                    else $error='Impossible d’enregistrer la photo.';
                }
            }
            if (!$error) {
                foreach ($data['profiles'] as $old) if ($old['id']===$id && !empty($old['photo'])) $profile['photo']=$profile['photo'] ?? $old['photo'];
                $found=false;
                foreach ($data['profiles'] as $i=>$old) if ($old['id']===$profile['id']) { $data['profiles'][$i]=$profile; $found=true; break; }
                if (!$found) $data['profiles'][]=$profile;
                saveData($data); redirect();
            }
        }
    } elseif ($action === 'delete_profile' && isAdmin()) {
        $id=(string)($_POST['id'] ?? '');
        foreach ($data['profiles'] as $i=>$p) if ($p['id']===$id) {
            if (!empty($p['photo']) && is_file($photoDir.'/'.$p['photo'])) @unlink($photoDir.'/'.$p['photo']);
            array_splice($data['profiles'],$i,1); break;
        }
        saveData($data); redirect();
    } elseif ($action === 'add_user' && isAdmin()) {
        $username=trim((string)($_POST['username'] ?? ''));
        $password=(string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/',$username)) $error='Identifiant : 3 à 30 caractères (lettres, chiffres, _ ou -).';
        elseif (strlen($password)<8) $error='Mot de passe : 8 caractères minimum.';
        else {
            foreach($data['users'] as $u) if(strcasecmp($u['username'],$username)===0) $error='Cet identifiant existe déjà.';
            if(!$error){$data['users'][]=['username'=>$username,'password'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'viewer'];saveData($data);redirect();}
        }
    } elseif ($action === 'delete_user' && isAdmin()) {
        $username=(string)($_POST['username'] ?? '');
        if ($username !== 'admin') {
            $data['users']=array_values(array_filter($data['users'],fn($u)=>$u['username']!==$username));
            saveData($data);
        }
        redirect();
    }
}

if (!loggedIn()) {
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion — La Tribu</title><link rel="stylesheet" href="style.css"></head><body><main class="auth"><div class="brand">🌿 La Tribu</div><section class="card"><h1>Présentation de la famille</h1><p>Connecte-toi pour découvrir les membres de la Tribu.</p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="login"><label>Identifiant</label><input name="username" required autocomplete="username"><label>Mot de passe</label><input type="password" name="password" required autocomplete="current-password"><button>Se connecter</button></form></section></main></body></html><?php
    exit;
}
usort($data['profiles'], fn($a,$b)=>strnatcasecmp($a['name']??'',$b['name']??''));
$edit = null;
if (isAdmin() && isset($_GET['edit'])) foreach($data['profiles'] as $p) if($p['id']===$_GET['edit']) {$edit=$p;break;}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>La Tribu — Présentation</title><link rel="stylesheet" href="style.css"></head><body>
<header class="top"><div><div class="brand">🌿 La Tribu</div><span>Les visages et les passions de la famille</span></div><nav><span><?=h($_SESSION['user'])?><?=isAdmin()?' · Administrateur':''?></span><a href="?logout=1">Déconnexion</a></nav></header>
<main class="wrap"><div class="intro"><div><p class="eyebrow">NOTRE FAMILLE</p><h1>Qui est qui dans la Tribu ?</h1><p>Quelques mots, des passions et des souvenirs pour mieux se connaître.</p></div><?php if(isAdmin()): ?><a class="button" href="#formulaire">＋ Ajouter une personne</a><?php endif; ?></div>
<?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<?php if(!$data['profiles']): ?><section class="empty card"><div class="empty-icon">👨‍👩‍👧‍👦</div><h2>La Tribu n’a pas encore de portraits</h2><p>Les présentations apparaîtront ici dès que l’administrateur aura ajouté les premières fiches.</p></section><?php else: ?>
<div class="profiles"><?php foreach($data['profiles'] as $p): ?><article class="profile card"><div class="photo"><?php if(!empty($p['photo']) && is_file($photoDir.'/'.$p['photo'])): ?><img src="photo.php?f=<?=rawurlencode($p['photo'])?>" alt="Photo de <?=h($p['name'])?>"><?php else: ?><span><?=h(mb_strtoupper(mb_substr($p['name'],0,1,'UTF-8'),'UTF-8'))?></span><?php endif; ?></div><div class="profile-body"><h2><?=h($p['name'])?></h2><?php if(!empty($p['birthday'])): ?><p class="birthday">🎂 <?=h($p['birthday'])?></p><?php endif; ?><?php if(!empty($p['passions'])): ?><h3>✨ Ses passions</h3><p><?=nl2br(h($p['passions']))?></p><?php endif; ?><?php if(!empty($p['bio'])): ?><h3>💬 Quelques mots</h3><p><?=nl2br(h($p['bio']))?></p><?php endif; ?><?php if(isAdmin()): ?><div class="actions"><a href="?edit=<?=h($p['id'])?>#formulaire">Modifier</a><form method="post" onsubmit="return confirm('Supprimer cette fiche ?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_profile"><input type="hidden" name="id" value="<?=h($p['id'])?>"><button class="link danger">Supprimer</button></form></div><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
<?php if(isAdmin()): ?><section class="card editor" id="formulaire"><h2><?= $edit?'Modifier une fiche':'Ajouter une personne' ?></h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_profile"><input type="hidden" name="id" value="<?=h($edit['id']??'')?>"><label>Prénom (et nom si souhaité) *</label><input name="name" required maxlength="80" value="<?=h($edit['name']??'')?>"><label>Date d’anniversaire (facultatif)</label><input name="birthday" maxlength="80" placeholder="Ex. 14 mai" value="<?=h($edit['birthday']??'')?>"><label>Passions et centres d’intérêt</label><textarea name="passions" rows="3" maxlength="1500" placeholder="Ex. vélo, dessin, jeux de société…"><?=h($edit['passions']??'')?></textarea><label>Quelques mots sur la personne</label><textarea name="bio" rows="4" maxlength="3000" placeholder="Son caractère, ce qu’elle aime, une petite anecdote…"><?=h($edit['bio']??'')?></textarea><label>Photo (JPG, PNG, WEBP ou GIF — 5 Mo maximum)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"><?php if($edit): ?><p class="hint">Laisse le champ photo vide pour conserver la photo actuelle.</p><?php endif; ?><div class="form-actions"><button>Enregistrer la fiche</button><?php if($edit): ?><a class="cancel" href="index.php">Annuler</a><?php endif; ?></div></form></section>
<section class="card users"><h2>Comptes de la famille</h2><p class="hint">Les autres comptes peuvent consulter les portraits, mais pas les modifier.</p><form method="post" class="user-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add_user"><input name="username" placeholder="Nouvel identifiant" minlength="3" maxlength="30" required><input type="password" name="password" placeholder="Mot de passe (8 caractères min.)" minlength="8" required><button>Créer le compte</button></form><ul class="user-list"><?php foreach($data['users'] as $u): ?><li><span><?=h($u['username'])?><?= $u['role']==='admin'?' · admin':' · membre' ?></span><?php if($u['role']!=='admin'): ?><form method="post" onsubmit="return confirm('Supprimer ce compte ?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="username" value="<?=h($u['username'])?>"><button class="link danger">Supprimer</button></form><?php endif; ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<footer>La Tribu · Un petit portrait de chacun 🌱</footer></main></body></html>
