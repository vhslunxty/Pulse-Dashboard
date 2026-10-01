<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function hidden(string $csrf, string $action, int $id = 0): string
{
    return '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . '<input type="hidden" name="action" value="' . $action . '">'
        . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
}

function back(string $to = './'): never
{
    header('Location: ' . $to);
    exit;
}

function field(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

$password = (string) getenv('DASH_PASSWORD');
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$authed = $password === '' || !empty($_SESSION['auth']);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Requête invalide');
    }

    $action = field('action');
    $id = (int) field('id');

    if ($action === 'login') {
        if ($password !== '' && hash_equals($password, (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            back();
        }
        $error = 'Mot de passe incorrect';
    } elseif ($authed) {
        $db = db();

        switch ($action) {
            case 'save':
                $name = field('name');
                $event = field('event');
                $script = field('script');

                if ($name === '' || $event === '' || $script === '') {
                    $_SESSION['flash'] = 'Tous les champs sont obligatoires';
                    back($id ? "./?edit=$id" : './');
                }

                $id
                    ? $db->prepare('UPDATE rules SET name = ?, event = ?, script = ? WHERE id = ?')->execute([$name, $event, $script, $id])
                    : $db->prepare('INSERT INTO rules (name, event, script) VALUES (?, ?, ?)')->execute([$name, $event, $script]);

                $_SESSION['flash'] = 'Règle enregistrée';
                break;

            case 'toggle':
                $db->prepare('UPDATE rules SET enabled = 1 - enabled WHERE id = ?')->execute([$id]);
                break;

            case 'delete':
                $db->prepare('DELETE FROM rules WHERE id = ?')->execute([$id]);
                $_SESSION['flash'] = 'Règle supprimée';
                break;

            case 'emit':
                $event = field('event');
                $payload = field('payload') ?: '{}';
                json_decode($payload);

                if ($event === '' || json_last_error() !== JSON_ERROR_NONE) {
                    $_SESSION['flash'] = 'Événement ou JSON invalide';
                    break;
                }

                $db->prepare('INSERT INTO events (name, payload) VALUES (?, ?)')->execute([$event, $payload]);
                $_SESSION['flash'] = 'Événement émis';
                break;

            case 'clear':
                $db->exec('DELETE FROM events');
                $_SESSION['flash'] = 'Historique vidé';
                break;

            case 'logout':
                $_SESSION = [];
                session_destroy();
                break;
        }

        back();
    }
}

if (!$authed) {
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="center">
<form method="post" class="card login">
    <h1>Connexion</h1>
    <?= hidden($csrf, 'login') ?>
    <?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif ?>
    <input type="password" name="password" placeholder="Mot de passe" autofocus required>
    <button>Entrer</button>
</form>
</body>
</html>
<?php
    exit;
}

$db = db();
$stats = $db->query("SELECT
    (SELECT COUNT(*) FROM rules) AS total,
    (SELECT COUNT(*) FROM rules WHERE enabled = 1) AS active,
    (SELECT COUNT(*) FROM events WHERE created_at > strftime('%s', 'now') - 86400) AS day
")->fetch();
$rules = $db->query('SELECT * FROM rules ORDER BY id DESC')->fetchAll();
$events = $db->query('SELECT * FROM events ORDER BY id DESC LIMIT 20')->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM rules WHERE id = ?');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Automation Dashboard</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <h1>Automation Dashboard</h1>
    <?php if ($password !== ''): ?>
    <form method="post"><?= hidden($csrf, 'logout') ?><button class="ghost">Déconnexion</button></form>
    <?php endif ?>
</header>

<?php if ($flash): ?><p class="flash"><?= e($flash) ?></p><?php endif ?>

<section class="stats">
    <div class="card"><span><?= $stats['total'] ?></span>Règles</div>
    <div class="card"><span><?= $stats['active'] ?></span>Actives</div>
    <div class="card"><span><?= $stats['day'] ?></span>Événements / 24h</div>
</section>

<main>
    <div>
        <section class="card">
            <h2>Règles</h2>
            <?php if (!$rules): ?>
                <p class="muted">Aucune règle pour le moment.</p>
            <?php else: ?>
            <div class="scroll">
            <table>
                <thead><tr><th>Nom</th><th>Événement</th><th>État</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td><?= e($r['name']) ?></td>
                        <td><code><?= e($r['event']) ?></code></td>
                        <td><span class="badge <?= $r['enabled'] ? 'on' : 'off' ?>"><?= $r['enabled'] ? 'Active' : 'Pause' ?></span></td>
                        <td class="actions">
                            <a href="?edit=<?= (int) $r['id'] ?>">Modifier</a>
                            <form method="post"><?= hidden($csrf, 'toggle', (int) $r['id']) ?><button class="ghost"><?= $r['enabled'] ? 'Pause' : 'Activer' ?></button></form>
                            <form method="post" onsubmit="return confirm('Supprimer cette règle ?')"><?= hidden($csrf, 'delete', (int) $r['id']) ?><button class="ghost danger">Supprimer</button></form>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            </div>
            <?php endif ?>
        </section>

        <section class="card">
            <h2><?= $edit ? 'Modifier la règle' : 'Nouvelle règle' ?></h2>
            <form method="post" class="stack">
                <?= hidden($csrf, 'save', (int) ($edit['id'] ?? 0)) ?>
                <input name="name" placeholder="Nom" value="<?= e($edit['name'] ?? '') ?>" required>
                <input name="event" placeholder="Événement déclencheur (ex: temperature)" value="<?= e($edit['event'] ?? '') ?>" required>
                <textarea name="script" rows="8" spellcheck="false" placeholder="if event.temp > 25 then notify('Trop chaud') end" required><?= e($edit['script'] ?? '') ?></textarea>
                <div class="row">
                    <button>Enregistrer</button>
                    <?php if ($edit): ?><a href="./" class="btn ghost">Annuler</a><?php endif ?>
                </div>
            </form>
        </section>
    </div>

    <div>
        <section class="card">
            <h2>Émettre un événement</h2>
            <form method="post" class="stack">
                <?= hidden($csrf, 'emit') ?>
                <input name="event" placeholder="Nom de l'événement" required>
                <textarea name="payload" rows="3" spellcheck="false" placeholder='{"temp": 28}'></textarea>
                <button>Envoyer</button>
            </form>
        </section>

        <section class="card">
            <div class="row between">
                <h2>Derniers événements</h2>
                <?php if ($events): ?>
                <form method="post"><?= hidden($csrf, 'clear') ?><button class="ghost danger">Vider</button></form>
                <?php endif ?>
            </div>
            <?php if (!$events): ?>
                <p class="muted">Aucun événement reçu.</p>
            <?php else: ?>
            <ul class="events">
                <?php foreach ($events as $ev): ?>
                    <li>
                        <div class="row between"><code><?= e($ev['name']) ?></code><small><?= date('d/m H:i:s', (int) $ev['created_at']) ?></small></div>
                        <pre><?= e($ev['payload']) ?></pre>
                    </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </section>
    </div>
</main>
</body>
</html>
