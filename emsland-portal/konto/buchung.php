<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user();

$b = booking_by_id((int)get('id'));
if (!$b || !user_can_see_booking($u, $b)) {
    flash('error', 'Buchung nicht gefunden.');
    redirect(url('konto/buchungen.php'));
}
$self = url('konto/buchung.php', ['id' => $b['id']]);
$hostMayConfirm = $u['role'] === 'gastgeber' && setting('hosts_can_confirm', '1') === '1';

if (is_post()) {
    check_csrf();
    $action = post('action');
    if ($hostMayConfirm && $b['status'] === 'angefragt' && in_array($action, ['bestaetigt', 'abgelehnt'], true)) {
        if ($b['series_ref'] && post('scope') === 'serie') {
            $errs = series_change_status($b['series_ref'], $action, true);
            foreach ($errs as $err) flash('error', $err);
            flash('ok', $action === 'bestaetigt' ? 'Serie bestätigt, der Gast wurde informiert.' : 'Serie abgelehnt, der Gast wurde informiert.');
        } else {
            $err = booking_change_status((int)$b['id'], $action, true);
            flash($err ? 'error' : 'ok', $err ?: ($action === 'bestaetigt' ? 'Buchung bestätigt, der Gast wurde informiert.' : 'Anfrage abgelehnt, der Gast wurde informiert.'));
        }
        admin_notify('Gastgeber hat Buchung ' . $b['ref'] . ' ' . ($action === 'bestaetigt' ? 'bestätigt' : 'abgelehnt'), "{$u['name']} hat die Buchung {$b['ref']} ({$b['title']}, " . date_de($b['date_from']) . ' – ' . date_de($b['date_to']) . ') ' . ($action === 'bestaetigt' ? 'bestätigt.' : 'abgelehnt.'));
    } elseif ($action === 'storno_anfrage' && $u['role'] === 'firma' && in_array($b['status'], ['angefragt', 'bestaetigt'], true)) {
        $reason = mb_substr(post('reason'), 0, 1000);
        admin_notify('Stornierungswunsch ' . $b['ref'], "{$u['name']} ({$u['company_name']}) möchte die Buchung {$b['ref']} stornieren.\n{$b['title']}, " . date_de($b['date_from']) . ' – ' . date_de($b['date_to']) . "\n\nBegründung:\n$reason");
        db()->prepare("UPDATE bookings SET admin_note = trim(admin_note || char(10) || ?) WHERE id = ?")->execute(['[' . date('d.m.Y H:i') . "] Stornierungswunsch von {$u['name']}: $reason", $b['id']]);
        flash('ok', 'Ihr Stornierungswunsch wurde übermittelt. Wir melden uns kurzfristig.');
    }
    redirect($self);
}

$lines = json_decode($b['price_detail'], true) ?: [];
$series = $b['series_ref'] ? series_bookings($b['series_ref']) : [];

konto_header('Buchung ' . $b['ref'], 'buchungen');
?>
<section class="account-main"><div class="wrap">
  <p class="small"><a href="<?= e(url('konto/buchungen.php')) ?>">← Alle Buchungen</a></p>
  <div class="admin-top"><h1>Buchung <?= e($b['ref']) ?> <?= booking_status_badge($b['status']) ?></h1>
    <?php if ($u['role'] === 'firma' && $b['invoice_no']): ?><a class="button button-ghost" href="<?= e(url('konto/rechnung.php', ['buchung' => $b['id']])) ?>">Rechnung <?= e($b['invoice_no']) ?></a><?php endif; ?>
  </div>
  <div class="panel">
    <dl class="kv">
      <dt>Unterkunft</dt><dd><a href="<?= e(url('unterkunft.php', ['u' => $b['slug']])) ?>"><?= e($b['title']) ?></a></dd>
      <dt>Zeitraum</dt><dd><?= date_de($b['date_from'], true) ?> – <?= date_de($b['date_to'], true) ?> (<?= booking_nights($b) ?> Nächte)</dd>
      <dt>Gast</dt><dd><?= e($b['name']) ?><?= $b['email'] ? ' · ' . e($b['email']) : '' ?><?= $b['phone'] ? ' · ' . e($b['phone']) : '' ?></dd>
      <dt>Personen</dt><dd><?= (int)$b['guests'] ?></dd>
      <?php if ($b['purpose']): ?><dt>Anlass</dt><dd><?= e($b['purpose']) ?></dd><?php endif; ?>
      <?php if ($b['company'] || $b['company_name']): ?><dt>Firma</dt><dd><?= e($b['company'] ?: $b['company_name']) ?></dd><?php endif; ?>
      <?php if ($b['cost_center']): ?><dt>Kostenstelle</dt><dd><?= e($b['cost_center']) ?></dd><?php endif; ?>
      <?php if ($b['booker_name']): ?><dt>Gebucht von</dt><dd><?= e($b['booker_name']) ?></dd><?php endif; ?>
      <?php if ($b['message']): ?><dt>Nachricht</dt><dd><?= nl2br(e($b['message'])) ?></dd><?php endif; ?>
      <dt>Preis</dt><dd>
        <?php if ($lines): ?><table class="data" style="max-width:520px"><?php foreach ($lines as [$t, $v]): ?><tr><td><?= e($t) ?></td><td class="num"><?= money((float)$v) ?></td></tr><?php endforeach; ?>
          <tr><td><strong>Gesamt</strong></td><td class="num"><strong><?= money((float)$b['price_total']) ?></strong></td></tr></table>
        <?php else: ?><?= money((float)$b['price_total']) ?><?php endif; ?>
      </dd>
      <?php if ($u['role'] === 'firma'): ?><dt>Abrechnung</dt><dd><?= booking_on_account($b) ? 'Sammelrechnung über das Firmenkonto' : 'Einzelrechnung' ?></dd><?php endif; ?>
    </dl>
  </div>

  <?php if ($series): ?>
  <div class="panel">
    <h2>Serie <?= e($b['series_ref']) ?> (<?= count($series) ?> Termine)</h2>
    <div class="table-wrap"><table class="data">
      <tr><th>Zeitraum</th><th class="num">Preis</th><th>Status</th></tr>
      <?php foreach ($series as $s): ?>
        <tr><td><a href="<?= e(url('konto/buchung.php', ['id' => $s['id']])) ?>"><?= date_de($s['date_from'], true) ?> – <?= date_de($s['date_to'], true) ?></a></td><td class="num"><?= money((float)$s['price_total']) ?></td><td><?= booking_status_badge($s['status']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php endif; ?>

  <?php if ($hostMayConfirm && $b['status'] === 'angefragt'): ?>
  <div class="panel">
    <h2>Anfrage beantworten</h2>
    <p>Der Gast wird automatisch per E-Mail informiert. Beim Bestätigen wird der Zeitraum im Kalender blockiert.</p>
    <form method="post" action="<?= e($self) ?>" class="actions">
      <?= csrf_field() ?>
      <?php if ($series): ?>
        <label class="check" style="width:100%"><input type="radio" name="scope" value="serie" checked> Alle offenen Termine der Serie</label>
        <label class="check" style="width:100%"><input type="radio" name="scope" value="einzeln"> Nur diesen Termin</label>
      <?php endif; ?>
      <button class="button" name="action" value="bestaetigt" type="submit">Bestätigen</button>
      <button class="button button-danger" name="action" value="abgelehnt" type="submit">Ablehnen</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($u['role'] === 'firma' && in_array($b['status'], ['angefragt', 'bestaetigt'], true) && $b['date_to'] >= date('Y-m-d')): ?>
  <details class="panel">
    <summary><strong>Stornierung oder Änderung anfragen</strong></summary>
    <form method="post" action="<?= e($self) ?>" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="action" value="storno_anfrage">
      <div class="field"><label for="reason">Was soll sich ändern?</label><textarea id="reason" name="reason" required placeholder="z. B. Projekt endet früher, Abreise schon am …"></textarea></div>
      <button class="button button-ghost" type="submit">Anfrage senden</button>
      <p class="hint">Es gelten die vereinbarten Stornobedingungen. Wir bestätigen Änderungen per E-Mail.</p>
    </form>
  </details>
  <?php endif; ?>
</div></section>
<?php page_footer();
