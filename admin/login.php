<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/layout.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (login(post('username'), post('password'))) {
        $next = get('next');
        redirect(safe_local_redirect($next));
    }
    $error = 'Benutzername oder Passwort stimmt nicht.';
    usleep(400000);
}

page_start('Anmelden', 'admin', '', false, false);
?>
<div class="login-wrap">
    <div class="panel login-card">
        <h2>Wettkampfbüro</h2>
        <p class="lead">Nur für die Wettkampfleitung.</p>
        <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="u">Benutzername</label>
                <input type="text" id="u" name="username" autocomplete="username" autofocus required>
            </div>
            <div class="field">
                <label for="p">Passwort</label>
                <input type="password" id="p" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn big" type="submit" style="width:100%">Anmelden</button>
        </form>
        <p class="login-back"><a href="../index.php">← zur Rangliste</a></p>
    </div>
</div>
<?php page_end();
