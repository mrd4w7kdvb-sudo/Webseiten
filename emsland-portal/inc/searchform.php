<?php
/** Suchmaske (Startseite). Erwartet optional $search = [modus, ort, von, bis, gaeste, arbeitsort]. */
$s = ($search ?? []) + ['modus' => 'urlaub', 'ort' => '', 'von' => '', 'bis' => '', 'gaeste' => '2', 'arbeitsort' => ''];
$today = date('Y-m-d');
?>
<form class="searchbox" action="<?= e(url('suche.php')) ?>" method="get" data-mode="<?= e($s['modus']) ?>" data-search>
  <div class="mode-switch" role="radiogroup" aria-label="Art des Aufenthalts">
    <input type="radio" id="m-urlaub" name="modus" value="urlaub" <?= $s['modus'] !== 'business' ? 'checked' : '' ?>>
    <label for="m-urlaub">Urlaub &amp; Wochenende</label>
    <input type="radio" id="m-business" name="modus" value="business" <?= $s['modus'] === 'business' ? 'checked' : '' ?>>
    <label for="m-business">Wohnen auf Zeit / Business</label>
  </div>
  <div class="search-grid">
    <div class="field only-urlaub">
      <label for="s-ort">Ort</label>
      <select id="s-ort" name="ort">
        <option value="">Ganzes Emsland</option>
        <?php foreach (places() as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (string)$p['id'] === (string)$s['ort'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field only-business">
      <label for="s-arbeitsort">Mein Arbeitsort</label>
      <select id="s-arbeitsort" name="arbeitsort">
        <option value="">Bitte wählen</option>
        <?php foreach (places() as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (string)$p['id'] === (string)$s['arbeitsort'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="s-von">Anreise</label>
      <input type="date" id="s-von" name="von" min="<?= $today ?>" value="<?= e($s['von']) ?>">
    </div>
    <div class="field">
      <label for="s-bis">Abreise</label>
      <input type="date" id="s-bis" name="bis" min="<?= $today ?>" value="<?= e($s['bis']) ?>">
    </div>
    <div class="field">
      <label for="s-gaeste">Personen</label>
      <input type="number" id="s-gaeste" name="gaeste" min="1" max="20" value="<?= e((string)$s['gaeste']) ?>">
    </div>
    <div class="search-submit"><button class="button button-accent button-block" type="submit">Suchen</button></div>
  </div>
</form>
