<?php
declare(strict_types=1);

/*
 * Benutzerkonten für Firmen (role=firma) und Gastgeber (role=gastgeber).
 * Admins haben einen eigenen Login (siehe inc/admin.php).
 */

const USER_IDLE_SECONDS = 7200;
const COMPANY_STATUS = ['beantragt' => 'Beantragt', 'freigeschaltet' => 'Freigeschaltet', 'gesperrt' => 'Gesperrt'];

function login_blocked(string $ip): bool
{
    $since = time() - 900;
    db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?');
    $st->execute([$ip, $since]);
    return (int)$st->fetchColumn() >= 5;
}

function login_failed(string $ip): void
{
    db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([$ip, time()]);
}

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$id || time() - (int)($_SESSION['user_seen'] ?? 0) > USER_IDLE_SECONDS) {
        unset($_SESSION['user_id']);
        return $cache = null;
    }
    $st = db()->prepare('SELECT u.id, u.role, u.company_id, u.is_owner, u.name, u.email, u.phone, c.name AS company_name, c.status AS company_status,
            c.vat_id AS company_vat_id, c.billing_address AS company_billing_address, c.max_price_night AS company_max_price
        FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ? AND u.active = 1');
    $st->execute([$id]);
    $u = $st->fetch() ?: null;
    if (!$u || ($u['role'] === 'firma' && $u['company_status'] === 'gesperrt')) {
        unset($_SESSION['user_id']);
        return $cache = null;
    }
    $_SESSION['user_seen'] = time();
    return $cache = $u;
}

function require_user(?string $role = null): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(url('konto/login.php'));
    }
    if ($role && $u['role'] !== $role) {
        flash('error', 'Dieser Bereich ist für Ihr Konto nicht verfügbar.');
        redirect(url('konto/index.php'));
    }
    return $u;
}

function user_login(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_seen'] = time();
    unset($_SESSION['csrf']);
    db()->prepare("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?")->execute([$u['id']]);
}

function user_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([mb_strtolower(trim($email))]);
    return $st->fetch() ?: null;
}

function email_taken(string $email): bool
{
    $email = mb_strtolower(trim($email));
    $st = db()->prepare('SELECT (SELECT COUNT(*) FROM users WHERE email = ?) + (SELECT COUNT(*) FROM admins WHERE email = ?)');
    $st->execute([$email, $email]);
    return (int)$st->fetchColumn() > 0;
}

/** Legt einen Link zum Setzen des Passworts an (Einladung oder „Passwort vergessen“). */
function password_link(int $userId, int $hours = 48): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM password_tokens WHERE user_id = ? OR expires_at < ?')->execute([$userId, time()]);
    db()->prepare('INSERT INTO password_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), time() + $hours * 3600]);
    return absolute_url('konto/passwort-neu.php', ['t' => $token]);
}

function password_token_user(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $st = db()->prepare('SELECT u.* FROM password_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > ?');
    $st->execute([hash('sha256', $token), time()]);
    return $st->fetch() ?: null;
}

function send_invite(array $u): string
{
    $link = password_link((int)$u['id'], 24 * 7);
    $what = $u['role'] === 'gastgeber' ? 'Ihren Gastgeber-Zugang' : 'Ihren Zugang zum Firmenkonto';
    send_mail($u['email'], 'Ihr Zugang bei ' . setting('site_name'),
        "Guten Tag {$u['name']},\n\nfür Sie wurde $what bei " . setting('site_name') . " eingerichtet.\n\nBitte legen Sie hier Ihr Passwort fest (Link 7 Tage gültig):\n$link\n\nDanach melden Sie sich an unter:\n" . absolute_url('konto/login.php')
        . "\n\nViele Grüße\n" . setting('site_name'));
    return $link;
}

function company_cost_centers(int $companyId): array
{
    $st = db()->prepare('SELECT * FROM cost_centers WHERE company_id = ? ORDER BY name');
    $st->execute([$companyId]);
    return $st->fetchAll();
}

/* ---------- Seitenrahmen Kundenkonto ---------- */

function konto_header(string $title, string $active = ''): void
{
    $u = current_user();
    header('Cache-Control: no-store');
    page_header($title);
    if (!$u) {
        return;
    }
    $nav = $u['role'] === 'gastgeber'
        ? ['index' => ['konto/index.php', 'Übersicht'], 'buchungen' => ['konto/buchungen.php', 'Buchungen'], 'unterkuenfte' => ['konto/unterkuenfte.php', 'Meine Unterkünfte'], 'profil' => ['konto/profil.php', 'Profil']]
        : ['index' => ['konto/index.php', 'Übersicht'], 'buchungen' => ['konto/buchungen.php', 'Buchungen'], 'rechnungen' => ['konto/rechnungen.php', 'Rechnungen'], 'team' => ['konto/team.php', 'Team & Kostenstellen'], 'profil' => ['konto/profil.php', 'Profil']];
    ?>
<div class="account-bar">
  <div class="wrap account-bar-inner">
    <div><strong><?= e($u['role'] === 'firma' ? $u['company_name'] : 'Gastgeber-Bereich') ?></strong> <span class="muted small">· <?= e($u['name']) ?></span></div>
    <nav class="tabs" aria-label="Kontonavigation" style="margin:0">
      <?php foreach ($nav as $key => [$href, $label]): ?><a href="<?= e(url($href)) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
      <form method="post" action="<?= e(url('konto/logout.php')) ?>" class="inline-form"><?= csrf_field() ?><button class="button button-ghost button-small" type="submit">Abmelden</button></form>
    </nav>
  </div>
</div>
<?php
}

/** Einschränkung der Buchungen auf das eigene Konto: Firma → Firmenbuchungen, Gastgeber → eigene Unterkünfte. */
function booking_scope(array $u): array
{
    return $u['role'] === 'gastgeber'
        ? ['l.host_user_id = ?', [(int)$u['id']]]
        : ['b.company_id = ?', [(int)$u['company_id']]];
}

/** Darf der Benutzer diese Buchung sehen? */
function user_can_see_booking(array $u, array $b): bool
{
    return $u['role'] === 'gastgeber'
        ? (int)$b['host_user_id'] === (int)$u['id']
        : (int)$b['company_id'] === (int)$u['company_id'] && (int)$u['company_id'] > 0;
}
