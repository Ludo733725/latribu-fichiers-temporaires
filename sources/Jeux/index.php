<?php
declare(strict_types=1);
session_start();

$privateDir = dirname(__DIR__) . '/private/la-tribu-jeux';
$dataFile = $privateDir . '/data.json';
if (!is_dir($privateDir)) {
    @mkdir($privateDir, 0750, true);
}
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode(['users'=>[], 'scores'=>[], 'challenges'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function load_data(): array {
    global $dataFile;
    $raw = @file_get_contents($dataFile);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) $data = [];
    return array_merge(['users'=>[], 'scores'=>[], 'challenges'=>[]], $data);
}
function save_data(array $data): void {
    global $dataFile;
    $tmp = $dataFile . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($tmp, 0640);
    rename($tmp, $dataFile);
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(403); exit('Requête refusée. Recharge la page.');
        }
    }
}
function current_user(array $data): ?array {
    $id = $_SESSION['uid'] ?? '';
    foreach ($data['users'] as $u) if (($u['id'] ?? '') === $id && !empty($u['active'])) return $u;
    return null;
}
function is_admin(?array $u): bool { return $u && ($u['role'] ?? '') === 'admin'; }
function redirect(string $url='index.php'): never { header('Location: '.$url); exit; }
function clean_name(string $s, int $max=30): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return mb_substr($s, 0, $max);
}
$data = load_data();
$user = current_user($data);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'setup') {
        if (count($data['users']) > 0) { $error = "Le compte administrateur existe déjà."; }
        else {
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 12) $error = "Choisis un mot de passe d'au moins 12 caractères.";
            else {
                $data['users'][] = ['id'=>bin2hex(random_bytes(8)), 'username'=>'admin', 'display_name'=>'Administrateur', 'password'=>password_hash($password, PASSWORD_DEFAULT), 'role'=>'admin', 'active'=>true, 'created'=>time()];
                save_data($data); session_regenerate_id(true); $_SESSION['uid']=$data['users'][0]['id']; redirect();
            }
        }
    } elseif ($action === 'login') {
        $username = mb_strtolower(trim((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        foreach ($data['users'] as $u) {
            if (mb_strtolower($u['username'] ?? '') === $username && !empty($u['active']) && password_verify($password, $u['password'] ?? '')) {
                session_regenerate_id(true); $_SESSION['uid']=$u['id']; redirect();
            }
        }
        $error = "Identifiant ou mot de passe incorrect.";
    } elseif ($action === 'logout') {
        $_SESSION = []; session_destroy(); redirect();
    } elseif (!$user) {
        http_response_code(403); exit('Connexion obligatoire.');
    } elseif ($action === 'create_user' && is_admin($user)) {
        $username = clean_name((string)($_POST['username'] ?? ''), 24);
        $display = clean_name((string)($_POST['display_name'] ?? ''), 32);
        $password = (string)($_POST['password'] ?? '');
        $exists = false;
        foreach ($data['users'] as $u) if (mb_strtolower($u['username']) === mb_strtolower($username)) $exists = true;
        if (strlen($username)<3 || !preg_match('/^[\p{L}\p{N}_.-]+$/u', $username)) $error = "Identifiant invalide (3 caractères minimum, lettres, chiffres, . _ -).";
        elseif ($exists) $error = "Cet identifiant existe déjà.";
        elseif (strlen($password)<8) $error = "Le mot de passe doit contenir au moins 8 caractères.";
        else {
            $data['users'][] = ['id'=>bin2hex(random_bytes(8)), 'username'=>$username, 'display_name'=>$display ?: $username, 'password'=>password_hash($password, PASSWORD_DEFAULT), 'role'=>'member', 'active'=>true, 'created'=>time()];
            save_data($data); $notice = "Compte créé.";
        }
    } elseif ($action === 'delete_user' && is_admin($user)) {
        $id = (string)($_POST['id'] ?? '');
        $data['users'] = array_values(array_filter($data['users'], fn($u)=>$u['id'] !== $id || $u['role']==='admin'));
        save_data($data); $notice = "Compte supprimé.";
    } elseif ($action === 'create_challenge' && is_admin($user)) {
        $title = clean_name((string)($_POST['title'] ?? ''), 70);
        $game = (string)($_POST['game'] ?? 'any');
        $target = max(1, min(100, (int)($_POST['target'] ?? 1)));
        $points = max(1, min(100, (int)($_POST['points'] ?? 10)));
        if ($title !== '') {
            $data['challenges'][] = ['id'=>bin2hex(random_bytes(6)), 'title'=>$title, 'game'=>$game, 'target'=>$target, 'points'=>$points, 'active'=>true, 'created'=>time()];
            save_data($data); $notice = "Défi ajouté.";
        } else $error = "Donne un nom au défi.";
    } elseif ($action === 'toggle_challenge' && is_admin($user)) {
        foreach ($data['challenges'] as &$c) if ($c['id'] === ($_POST['id'] ?? '')) $c['active'] = !$c['active'];
        unset($c); save_data($data); $notice = "Défi mis à jour.";
    } elseif ($action === 'score') {
        $game = preg_replace('/[^a-z0-9_-]/', '', (string)($_POST['game'] ?? ''));
        $points = max(0, min(50, (int)($_POST['points'] ?? 0)));
        $validGames = ['morpion','memory','puissance4','quiz','pendu','mystere'];
        if (in_array($game, $validGames, true) && $points > 0) {
            $data['scores'][] = ['id'=>bin2hex(random_bytes(8)), 'user_id'=>$user['id'], 'username'=>$user['display_name'], 'game'=>$game, 'points'=>$points, 'date'=>time()];
            if (count($data['scores']) > 5000) $data['scores'] = array_slice($data['scores'], -5000);
            save_data($data);
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true]); exit;
        }
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false]); exit;
    }
    $data = load_data(); $user = current_user($data);
}

$setupNeeded = count($data['users']) === 0;
$gameNames = ['morpion'=>'Morpion','memory'=>'Memory','puissance4'=>'Puissance 4','quiz'=>'Quiz familial','pendu'=>'Le pendu','mystere'=>'Nombre mystère'];
$totals = [];
$byGame = [];
foreach ($data['scores'] as $s) {
    $id = $s['user_id'];
    $totals[$id] = ($totals[$id] ?? 0) + (int)$s['points'];
    $byGame[$s['game']] = ($byGame[$s['game']] ?? 0) + 1;
}
$members = $data['users'];
usort($members, fn($a,$b)=>(($totals[$b['id']]??0)<=>($totals[$a['id']]??0)));
$recent = array_slice(array_reverse($data['scores']), 0, 12);
$csrf = csrf_token();
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Jeux de La Tribu</title><link rel="stylesheet" href="style.css"></head>
<body>
<header class="top"><div class="brand"><span class="mark">🎮</span><div><strong>Jeux de La Tribu</strong><small>Le club privé de la famille</small></div></div>
<?php if ($user): ?><div class="userbar"><span>👋 <?=h($user['display_name'])?></span><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="logout"><button class="linkbtn">Déconnexion</button></form></div><?php endif; ?></header>
<main class="wrap">
<?php if ($setupNeeded): ?>
<section class="panel auth"><div class="eyebrow">PREMIÈRE CONFIGURATION</div><h1>Bienvenue dans le club !</h1><p>Crée le compte administrateur. Son identifiant sera <b>admin</b>. Tu pourras ensuite créer les comptes des membres.</p>
<?php if ($error): ?><div class="alert"><?=h($error)?></div><?php endif; ?>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="setup"><label>Mot de passe administrateur<input type="password" name="password" minlength="12" required autocomplete="new-password" placeholder="12 caractères minimum"></label><button class="primary">Créer l'administrateur</button></form></section>
<?php elseif (!$user): ?>
<section class="panel auth"><div class="eyebrow">ACCÈS RÉSERVÉ AUX MEMBRES</div><h1>La Tribu joue ensemble !</h1><p>Connecte-toi avec ton compte personnel pour accéder aux jeux, défis et classements.</p>
<?php if ($error): ?><div class="alert"><?=h($error)?></div><?php endif; ?>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="login"><label>Identifiant<input name="username" required autocomplete="username"></label><label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Se connecter</button></form></section>
<?php else: ?>
<?php if ($notice): ?><div class="success"><?=h($notice)?></div><?php endif; ?><?php if ($error): ?><div class="alert"><?=h($error)?></div><?php endif; ?>
<section class="hero"><div><div class="eyebrow">BIENVENUE DANS TON ESPACE PRIVÉ</div><h1>À toi de jouer, <?=h($user['display_name'])?> !</h1><p>Joue, relève des défis et fais grimper ton score dans la Tribu.</p></div><div class="heroicon">🏆</div></section>
<nav class="tabs"><a href="#jeux">Les jeux</a><a href="#classement">Classement</a><a href="#defis">Défis</a><?php if (is_admin($user)): ?><a href="#admin">Administration</a><?php endif; ?></nav>
<section id="jeux"><div class="sectionhead"><div><div class="eyebrow">À TOI DE CHOISIR</div><h2>La ludothèque</h2></div><span class="muted">6 jeux disponibles</span></div>
<div class="games">
<article class="gamecard" data-game="morpion"><div class="gameemoji">⭕</div><h3>Morpion</h3><p>Aligne trois symboles avant l'ordinateur.</p><button class="playbtn" onclick="openGame('morpion')">Jouer</button></article>
<article class="gamecard" data-game="memory"><div class="gameemoji">🧠</div><h3>Memory</h3><p>Retrouve toutes les paires le plus vite possible.</p><button class="playbtn" onclick="openGame('memory')">Jouer</button></article>
<article class="gamecard" data-game="puissance4"><div class="gameemoji">🔴</div><h3>Puissance 4</h3><p>Aligne quatre jetons contre l'ordinateur.</p><button class="playbtn" onclick="openGame('puissance4')">Jouer</button></article>
<article class="gamecard" data-game="quiz"><div class="gameemoji">💡</div><h3>Quiz familial</h3><p>Teste tes connaissances avec des questions variées.</p><button class="playbtn" onclick="openGame('quiz')">Jouer</button></article>
<article class="gamecard" data-game="pendu"><div class="gameemoji">🔤</div><h3>Le pendu</h3><p>Retrouve le mot caché lettre après lettre.</p><button class="playbtn" onclick="openGame('pendu')">Jouer</button></article>
<article class="gamecard" data-game="mystere"><div class="gameemoji">🔢</div><h3>Nombre mystère</h3><p>Devine le nombre secret entre 1 et 100.</p><button class="playbtn" onclick="openGame('mystere')">Jouer</button></article>
</div></section>
<section id="classement" class="panel"><div class="sectionhead"><div><div class="eyebrow">QUI MÈNE LA PARTIE ?</div><h2>Classement familial</h2></div><span class="pill">🏆 Total des points</span></div>
<?php if (!$members): ?><p class="muted">Aucun membre pour le moment.</p><?php else: ?><ol class="leaderboard"><?php $rank=0; foreach ($members as $m): if (!$m['active']) continue; $rank++; ?><li><span class="rank"><?= $rank===1?'🥇':($rank===2?'🥈':($rank===3?'🥉':$rank.'.')) ?></span><span class="membername"><?=h($m['display_name'])?><?= $m['id']===$user['id']?' <em>(toi)</em>':'' ?></span><b><?= (int)($totals[$m['id']]??0) ?> pts</b></li><?php endforeach; ?></ol><?php endif; ?></section>
<section id="defis" class="panel"><div class="sectionhead"><div><div class="eyebrow">À RELEVER</div><h2>Les défis de la Tribu</h2></div><span class="pill">⭐ Bonus</span></div>
<?php $activeChallenges=array_filter($data['challenges'],fn($c)=>!empty($c['active'])); if (!$activeChallenges): ?><p class="muted">Pas encore de défi. L'administrateur pourra en ajouter !</p><?php else: ?><div class="challengegrid"><?php foreach ($activeChallenges as $c): ?><div class="challenge"><b>🎯 <?=h($c['title'])?></b><span><?=h($c['target'])?> partie(s) · +<?=h($c['points'])?> pts bonus</span><small><?= $c['game']==='any'?'Tous les jeux':h($gameNames[$c['game']]??'Tous les jeux') ?></small></div><?php endforeach; ?></div><?php endif; ?></section>
<?php if (is_admin($user)): ?>
<section id="admin" class="panel"><div class="eyebrow">ESPACE ADMINISTRATEUR</div><h2>Gestion de la Tribu</h2><div class="admincols"><div><h3>Créer un compte membre</h3><form method="post" class="stack compact"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="create_user"><label>Identifiant<input name="username" required minlength="3" maxlength="24"></label><label>Prénom / nom affiché<input name="display_name" maxlength="32" placeholder="Ex. Camille"></label><label>Mot de passe provisoire<input type="password" name="password" required minlength="8"></label><button class="primary">Créer le compte</button></form></div><div><h3>Créer un défi</h3><form method="post" class="stack compact"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="create_challenge"><label>Nom du défi<input name="title" required maxlength="70" placeholder="Ex. 3 parties de Memory"></label><label>Jeu<select name="game"><option value="any">Tous les jeux</option><?php foreach($gameNames as $k=>$v): ?><option value="<?=h($k)?>"><?=h($v)?></option><?php endforeach; ?></select></label><div class="two"><label>Nombre de parties<input type="number" name="target" value="3" min="1" max="100"></label><label>Bonus de points<input type="number" name="points" value="10" min="1" max="100"></label></div><button class="primary">Ajouter le défi</button></form></div></div>
<h3>Comptes membres</h3><div class="accountlist"><?php foreach($data['users'] as $m): if($m['role']==='admin') continue; ?><div class="account"><span><?=h($m['display_name'])?> <small>(<?=h($m['username'])?>)</small></span><form method="post" onsubmit="return confirm('Supprimer ce compte ?')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?=h($m['id'])?>"><button class="danger smallbtn">Supprimer</button></form></div><?php endforeach; ?></div>
<h3>Défis configurés</h3><div class="accountlist"><?php foreach($data['challenges'] as $c): ?><div class="account"><span><?=h($c['title'])?> <small><?=!empty($c['active'])?'(actif)':'(masqué)'?></small></span><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="toggle_challenge"><input type="hidden" name="id" value="<?=h($c['id'])?>"><button class="secondary smallbtn"><?=!empty($c['active'])?'Désactiver':'Activer'?></button></form></div><?php endforeach; ?></div>
<h3>Derniers scores</h3><div class="scores"><?php if(!$recent): ?><p class="muted">Aucun score enregistré.</p><?php else: foreach($recent as $s): ?><div><span><?=h($s['username'])?></span><span><?=h($gameNames[$s['game']]??$s['game'])?></span><b>+<?= (int)$s['points'] ?> pts</b></div><?php endforeach; endif; ?></div>
</section>
<?php endif; ?>
<div id="gameModal" class="modal" aria-hidden="true"><div class="modalbox"><button class="close" onclick="closeGame()" aria-label="Fermer">×</button><div id="gameContent"></div></div></div>
<?php endif; ?>
<footer>La Tribu · Un espace privé pour jouer ensemble 💚</footer>
</main>
<script>
const CSRF = <?=json_encode($csrf)?>;
const gameLabels = <?=json_encode($gameNames, JSON_UNESCAPED_UNICODE)?>;
function openGame(game){document.getElementById('gameModal').classList.add('show');document.getElementById('gameModal').setAttribute('aria-hidden','false');renderGame(game);}
function closeGame(){document.getElementById('gameModal').classList.remove('show');document.getElementById('gameModal').setAttribute('aria-hidden','true');}
function gameShell(title,body){document.getElementById('gameContent').innerHTML=`<div class="eyebrow">MINI-JEU</div><h2>${title}</h2>${body}<div id="gameResult" class="game-result" aria-live="polite"></div>`;}
function finish(game,points,msg){const el=document.getElementById('gameResult');el.innerHTML=`<b>${msg}</b><p>+${points} points ${points>0?'ajoutés à ton score !':'— retente ta chance !'}</p><button class="primary" onclick="closeGame()">Terminer</button>`;if(points>0){const f=new FormData();f.append('csrf',CSRF);f.append('action','score');f.append('game',game);f.append('points',points);fetch('index.php',{method:'POST',body:f});}}
function renderGame(g){if(g==='morpion') tic();else if(g==='memory') memory();else if(g==='puissance4') connect4();else if(g==='quiz') quiz();else if(g==='pendu') hangman();else if(g==='mystere') mystery();}
function tic(){gameShell('Morpion',`<p class="muted">Tu joues avec ✕. L'ordinateur joue avec ○.</p><div class="tic" id="tic"></div><button class="secondary" onclick="tic()">Recommencer</button>`);let b=Array(9).fill(''),done=false;const el=document.getElementById('tic');function win(a,p){return [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]].some(x=>x.every(i=>a[i]===p));}function draw(){el.innerHTML='';b.forEach((v,i)=>{let x=document.createElement('button');x.textContent=v;x.onclick=()=>move(i);el.appendChild(x);});}function move(i){if(done||b[i])return;b[i]='✕';draw();if(win(b,'✕')){done=true;finish('morpion',10,'Bravo, tu as gagné !');return;}if(b.every(Boolean)){done=true;finish('morpion',2,'Match nul !');return;}let opts=b.map((v,i)=>v?null:i).filter(x=>x!==null);let j=opts[Math.floor(Math.random()*opts.length)];b[j]='○';draw();if(win(b,'○')){done=true;finish('morpion',0,'L’ordinateur a gagné.');}else if(b.every(Boolean)){done=true;finish('morpion',2,'Match nul !');}}draw();}
function memory(){let symbols=['🍓','🚀','🐸','🎸','🌈','🐼'];let deck=[...symbols,...symbols].sort(()=>Math.random()-.5),open=[],matched=0,moves=0,lock=false;gameShell('Memory',`<p class="muted">Retrouve les 6 paires avec le moins de coups possible.</p><div class="memory" id="memory"></div><p id="moves">Coups : 0</p>`);let el=document.getElementById('memory');function draw(){el.innerHTML='';deck.forEach((s,i)=>{let b=document.createElement('button');b.textContent=(open.includes(i)||deck[i]===null)?s:'?';b.className=(deck[i]===null?'matched':'');b.onclick=()=>flip(i);el.appendChild(b);});document.getElementById('moves').textContent='Coups : '+moves;}function flip(i){if(lock||open.includes(i)||deck[i]===null)return;open.push(i);draw();if(open.length===2){moves++;if(deck[open[0]]===deck[open[1]]){deck[open[0]]=deck[open[1]]=null;matched+=2;open=[];draw();if(matched===12)finish('memory',Math.max(3,20-Math.floor(moves/2)),'Toutes les paires retrouvées !');}else{lock=true;setTimeout(()=>{open=[];lock=false;draw();},650);}}}draw();}
function connect4(){let b=Array(42).fill(0),done=false;gameShell('Puissance 4',`<p class="muted">Aligne quatre jetons. Tu joues en rouge.</p><div class="c4" id="c4"></div><button class="secondary" onclick="connect4()">Recommencer</button>`);let el=document.getElementById('c4');function winner(p){for(let r=0;r<6;r++)for(let c=0;c<7;c++){let i=r*7+c;if(c<4&&[1,2,3].every(k=>b[i+k]===p)||r<3&&[1,2,3].every(k=>b[i+k*7]===p)||r<3&&c<4&&[1,2,3].every(k=>b[i+k*8]===p)||r<3&&c>2&&[1,2,3].every(k=>b[i+k*6]===p))return true;}return false;}function render(){el.innerHTML='';for(let c=0;c<7;c++){let col=document.createElement('button');col.className='c4col';col.onclick=()=>drop(c);for(let r=0;r<6;r++){let dot=document.createElement('i');let v=b[r*7+c];if(v)dot.classList.add(v===1?'red':'yellow');col.appendChild(dot);}el.appendChild(col);}}function put(c,p){for(let r=5;r>=0;r--)if(!b[r*7+c]){b[r*7+c]=p;return true;}return false;}function drop(c){if(done||!put(c,1))return;render();if(winner(1)){done=true;finish('puissance4',12,'Victoire !');return;}let valid=Array.from({length:7},(_,i)=>i).filter(i=>!b[i]);if(!valid.length){done=true;finish('puissance4',2,'Match nul !');return;}put(valid[Math.floor(Math.random()*valid.length)],2);render();if(winner(2)){done=true;finish('puissance4',0,'L’ordinateur a gagné.');}}render();}
const questions=[['Combien de côtés a un hexagone ?', '6',['5','6','8']],['Quelle planète est surnommée la planète rouge ?','Mars',['Vénus','Mars','Jupiter']],['Combien font 7 × 8 ?','56',['54','56','64']],['Quel animal est le plus grand du monde ?','La baleine bleue',['Éléphant','Girafe','La baleine bleue']],['Quelle saison vient après le printemps ?','L’été',['L’hiver','L’été','L’automne']],['Combien de minutes dans une heure ?','60',['30','60','100']],['Quel est le contraire de rapide ?','Lent',['Lent','Fort','Grand']],['Quelle est la capitale de l’Italie ?','Rome',['Madrid','Rome','Lisbonne']]];
function quiz(){let qs=[...questions].sort(()=>Math.random()-.5).slice(0,5),i=0,score=0;function show(){if(i>=qs.length){finish('quiz',score*2,'Quiz terminé : '+score+'/5 bonnes réponses.');return;}let q=qs[i];gameShell('Quiz familial',`<p class="muted">Question ${i+1} sur ${qs.length}</p><h3>${q[0]}</h3><div class="answers">${q[2].map(a=>`<button class="secondary" onclick="answerQuiz(${JSON.stringify(a).replace(/"/g,'&quot;')})">${a}</button>`).join('')}</div>`);}window.answerQuiz=(a)=>{if(a===qs[i][1])score++;i++;show();};show();}
function hangman(){let words=['FAMILLE','VACANCES','CHOCOLAT','JARDIN','MUSIQUE','SOLEIL','ANANAS','PIRATE'],word=words[Math.floor(Math.random()*words.length)],used=[],errors=0;function show(){gameShell('Le pendu',`<p class="muted">Trouve le mot. 7 erreurs maximum.</p><h2 class="word">${[...word].map(x=>used.includes(x)?x:'_').join(' ')}</h2><p>Erreurs : ${errors}/7</p><div class="letters">${'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').map(x=>`<button ${used.includes(x)?'disabled':''} onclick="guessHang('${x}')">${x}</button>`).join('')}</div>`);}window.guessHang=(x)=>{used.push(x);if(!word.includes(x))errors++;if([...word].every(c=>used.includes(c))){show();finish('pendu',Math.max(2,12-errors),'Bravo, mot trouvé !');}else if(errors>=7){show();finish('pendu',0,'Perdu ! Le mot était '+word+'.');}else show();};show();}
function mystery(){let secret=1+Math.floor(Math.random()*100),tries=0;gameShell('Nombre mystère',`<p class="muted">Trouve le nombre entre 1 et 100.</p><form class="guess" onsubmit="guessMystery(event)"><input type="number" id="guess" min="1" max="100" required placeholder="Ton nombre"><button class="primary">Tester</button></form><p id="hint"></p>`);window.guessMystery=(e)=>{e.preventDefault();let n=Number(document.getElementById('guess').value);tries++;let hint=document.getElementById('hint');if(n===secret){finish('mystere',Math.max(3,15-tries),'Bravo, trouvé en '+tries+' essai(s) !');}else hint.textContent=n<secret?'C’est plus grand !':'C’est plus petit !';};}
</script>
</main></body></html>
