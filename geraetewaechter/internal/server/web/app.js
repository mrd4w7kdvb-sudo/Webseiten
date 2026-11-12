'use strict';
/* Gerätewächter – Weboberfläche (ohne externe Abhängigkeiten) */

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
const main = () => $('#main');
let ME = null;
let refreshTimer = null;

// ---------- Hilfsfunktionen ----------
function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
async function api(method, path, body) {
  const opt = { method, headers: { 'X-GW-Request': '1' }, credentials: 'same-origin' };
  if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const r = await fetch(path, opt);
  if (r.status === 401 && path !== '/api/login') { showLogin(); throw new Error('Bitte anmelden'); }
  let data = null;
  try { data = await r.json(); } catch (_) { /* leer */ }
  if (!r.ok) { const e = new Error((data && data.error) || ('Fehler ' + r.status)); e.data = data; throw e; }
  return data;
}
function toast(msg, err) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast' + (err ? ' err' : '');
  clearTimeout(t._h);
  t._h = setTimeout(() => t.classList.add('hidden'), 3500);
}
const fmtTime = ts => ts ? new Date(ts * 1000).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'medium' }) : '–';
function fmtAgo(ts) {
  if (!ts) return 'nie';
  const s = Math.max(0, Math.floor(Date.now() / 1000 - ts));
  if (s < 60) return 'vor ' + s + ' s';
  if (s < 3600) return 'vor ' + Math.floor(s / 60) + ' min';
  if (s < 86400) return 'vor ' + Math.floor(s / 3600) + ' h';
  return 'vor ' + Math.floor(s / 86400) + ' Tagen';
}
function fmtDur(s) {
  s = Math.floor(s);
  const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
  return d ? `${d} T ${h} h` : h ? `${h} h ${m} min` : `${m} min`;
}
function fmtBytes(b) {
  if (b == null || b < 0) return '–';
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
  return b.toFixed(b < 10 && i ? 1 : 0) + ' ' + u[i];
}
const fmtPct = v => v == null || v < 0 ? '–' : v.toFixed(0) + ' %';
const LEVELS = ['Notfall', 'Alarm', 'Kritisch', 'Fehler', 'Warnung', 'Hinweis', 'Info', 'Debug'];
const BRANCH = { pflege: 'Mobiler Pflegedienst', landwirtschaft: 'Landwirtschaft / Hof', sonstige: 'Sonstige' };
const SEV = { kritisch: 'kritisch', warnung: 'warnung', info: 'info' };
const OSNAME = { windows: 'Windows', linux: 'Linux', darwin: 'macOS' };

const METRICS = {
  'cpu.percent': 'Prozessor-Auslastung (%)',
  'mem.percent': 'Arbeitsspeicher belegt (%)',
  'swap.percent': 'Auslagerungsspeicher belegt (%)',
  'disk.percent': 'Festplatte belegt (%) – Label mount',
  'disk.free_bytes': 'Festplatte frei (Bytes) – Label mount',
  'load.1': 'Systemlast 1 min (Linux/macOS)',
  'load.15': 'Systemlast 15 min (Linux/macOS)',
  'net.rx_bytes_per_s': 'Netzwerk Empfang (Bytes/s)',
  'net.tx_bytes_per_s': 'Netzwerk Senden (Bytes/s)',
  'net.errors': 'Netzwerkfehler pro Intervall',
  'uptime.seconds': 'Laufzeit seit Neustart (s)',
  'proc.count': 'Anzahl Prozesse',
  'battery.percent': 'Akkustand (%)',
  'battery.charging': 'Akku lädt (1/0)',
  'temp.cpu': 'CPU-Temperatur (°C, Linux)',
  'process.running': 'Überwachtes Programm läuft (1/0) – Label name',
  'service.running': 'Überwachter Dienst läuft (1/0) – Label name',
  'agent.queue': 'Gepufferte Meldungen (Verbindungsprobleme)',
  'log.received': 'Empfangene Log-Einträge pro Meldung',
};

function badgeOnline(a) {
  if (!a.approved) return '<span class="badge warn">wartet auf Freigabe</span>';
  if (a.disabled) return '<span class="badge off">deaktiviert</span>';
  return a.online ? '<span class="badge ok">online</span>' : '<span class="badge crit">offline</span>';
}
function bar(v) {
  if (v == null || v < 0) return '<span class="muted">–</span>';
  const cls = v >= 90 ? 'crit' : v >= 75 ? 'warn' : '';
  return `<div class="nowrap">${fmtPct(v)}</div><div class="bar ${cls}"><i data-w="${Math.min(100, v)}"></i></div>`;
}
function applyBars(root) { $$('[data-w]', root).forEach(el => { el.style.width = el.dataset.w + '%'; }); }
function setMain(html) { main().innerHTML = html; applyBars(main()); }
function table(head, rows, empty) {
  if (!rows.length) return `<div class="empty">${esc(empty || 'Keine Einträge')}</div>`;
  return `<div class="table-wrap"><table><thead><tr>${head.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
}

// ---------- Dialog ----------
function openDialog(html, onSubmit) {
  const d = $('#dialog'), f = $('#dialogForm');
  f.innerHTML = html + `<p class="error" id="dlgErr"></p><div class="foot"><button class="btn" value="cancel" type="button" id="dlgCancel">Abbrechen</button><button class="btn primary" type="submit">Speichern</button></div>`;
  $('#dlgCancel', f).onclick = () => d.close();
  f.onsubmit = async ev => {
    ev.preventDefault();
    try { await onSubmit(new FormData(f), f); d.close(); } catch (e) { $('#dlgErr', f).textContent = e.message; }
  };
  d.showModal();
  return f;
}
async function confirmDo(text, fn) {
  if (!window.confirm(text)) return;
  try { await fn(); } catch (e) { toast(e.message, true); }
}
const lines = s => String(s || '').split(/[\n,;]/).map(x => x.trim()).filter(Boolean);

// ---------- Routing ----------
const routes = [
  [/^$/, viewOverview],
  [/^alarme$/, viewAlerts],
  [/^kunden$/, viewTenants],
  [/^kunde\/(\d+)$/, viewTenant],
  [/^geraete$/, viewAgents],
  [/^geraet\/([a-z0-9]+)$/, viewAgent],
  [/^regeln$/, viewRules],
  [/^kanaele$/, viewChannels],
  [/^einstellungen$/, viewSettings],
];
async function route() {
  clearInterval(refreshTimer);
  const path = location.hash.replace(/^#\/?/, '');
  $$('#nav a').forEach(a => a.classList.toggle('active', a.dataset.nav === path.split('/')[0] ||
    (a.dataset.nav === 'kunden' && path.startsWith('kunde/')) || (a.dataset.nav === 'geraete' && path.startsWith('geraet/'))));
  for (const [re, fn] of routes) {
    const m = path.match(re);
    if (m) {
      try { await fn(...m.slice(1)); } catch (e) { setMain(`<div class="card error">${esc(e.message)}</div>`); }
      updateAlertPill();
      return;
    }
  }
  setMain('<div class="card">Seite nicht gefunden.</div>');
}
function autoRefresh(fn, ms = 30000) { refreshTimer = setInterval(() => { if (!$('#dialog').open) fn(); }, ms); }
async function updateAlertPill() {
  try {
    const o = await api('GET', '/api/alerts?state=firing&limit=500');
    const p = $('#navAlerts');
    p.textContent = o.length;
    p.classList.toggle('hidden', !o.length);
  } catch (_) { /* ignorieren */ }
}

// ---------- Übersicht ----------
function alertRows(list, withAck) {
  return list.map(a => `<tr>
    <td><span class="badge ${a.state === 'firing' ? SEV[a.severity] : 'ok'}">${a.state === 'firing' ? esc(a.severity) : 'behoben'}</span></td>
    <td><strong>${esc(a.rule_name)}</strong><div class="muted">${esc(a.message)}</div></td>
    <td><a href="#/kunde/${a.tenant_id}">${esc(a.tenant_name)}</a></td>
    <td><a href="#/geraet/${a.agent_id}">${esc(a.agent_name)}</a></td>
    <td class="nowrap">${fmtTime(a.started_at)}${a.resolved_at ? '<div class="muted">bis ' + fmtTime(a.resolved_at) + '</div>' : ''}</td>
    <td class="nowrap">${a.acked_by ? '<span class="muted">✓ ' + esc(a.acked_by) + '</span>' : (withAck && a.state === 'firing' ? `<button class="btn small" data-ack="${a.id}">Quittieren</button>` : '')}</td>
  </tr>`);
}
function bindAck(reload) {
  $$('[data-ack]').forEach(b => b.onclick = async () => {
    try { await api('POST', `/api/alerts/${b.dataset.ack}/ack`); reload(); } catch (e) { toast(e.message, true); }
  });
}
async function viewOverview() {
  const o = await api('GET', '/api/overview');
  setMain(`
    <div class="page-head"><div><h1>Übersicht</h1><div class="muted">Stand ${new Date().toLocaleTimeString('de-DE')}</div></div></div>
    <div class="tiles">
      <div class="tile"><div class="label">Kunden</div><div class="value">${o.tenants}</div></div>
      <div class="tile ${o.online < o.agents ? 'warn' : 'ok'}"><div class="label">Geräte online</div><div class="value">${o.online} / ${o.agents}</div></div>
      <div class="tile ${o.alerts.kritisch ? 'crit' : 'ok'}"><div class="label">Kritische Alarme</div><div class="value">${o.alerts.kritisch}</div></div>
      <div class="tile ${o.alerts.warnung ? 'warn' : 'ok'}"><div class="label">Warnungen</div><div class="value">${o.alerts.warnung}</div></div>
      <div class="tile"><div class="label">Hinweise</div><div class="value">${o.alerts.info}</div></div>
      ${o.pending ? `<div class="tile warn"><div class="label">Warten auf Freigabe</div><div class="value">${o.pending}</div><div class="sub"><a href="#/geraete">Geräte prüfen</a></div></div>` : ''}
    </div>
    <div class="card"><h2>Aktive Alarme</h2>${table(['Schwere', 'Regel', 'Kunde', 'Gerät', 'Seit', ''], alertRows(o.firing, true), 'Keine aktiven Alarme – alles in Ordnung.')}</div>
    <div class="card"><h2>Nicht erreichbare Geräte</h2>${table(['Gerät', 'Kunde', 'System', 'Zuletzt gesehen'], o.offline.map(a => `<tr>
      <td><a href="#/geraet/${a.id}">${esc(a.display_name)}</a></td><td>${esc(a.tenant_name)}</td>
      <td>${esc(OSNAME[a.os] || a.os)} ${esc(a.platform_version)}</td><td>${fmtAgo(a.last_seen)}</td></tr>`), 'Alle Geräte melden sich.')}</div>`);
  bindAck(viewOverview);
  autoRefresh(viewOverview);
}

// ---------- Alarme ----------
let alertState = 'firing';
async function viewAlerts() {
  const list = await api('GET', '/api/alerts?state=' + alertState + '&limit=500');
  setMain(`<div class="page-head"><div><h1>Alarme</h1></div>
    <div class="seg">${[['firing', 'Aktiv'], ['resolved', 'Behoben'], ['all', 'Alle']].map(([k, t]) => `<button data-st="${k}" class="${alertState === k ? 'on' : ''}">${t}</button>`).join('')}</div></div>
    <div class="card">${table(['Schwere', 'Regel / Meldung', 'Kunde', 'Gerät', 'Zeit', ''], alertRows(list, true), 'Keine Alarme')}</div>`);
  $$('[data-st]').forEach(b => b.onclick = () => { alertState = b.dataset.st; viewAlerts(); });
  bindAck(viewAlerts);
  autoRefresh(viewAlerts);
}

// ---------- Kunden ----------
async function viewTenants() {
  const list = await api('GET', '/api/tenants');
  setMain(`<div class="page-head"><div><h1>Kunden</h1><div class="muted">${list.length} Kunden</div></div>
    <div class="actions"><button class="btn primary" id="newTenant">Neuer Kunde</button></div></div>
    <div class="card">${table(['Name', 'Branche', 'Geräte', 'Online', 'Aktive Alarme', 'Kontakt'], list.map(t => `<tr>
      <td><a href="#/kunde/${t.id}"><strong>${esc(t.name)}</strong></a></td><td>${esc(BRANCH[t.branch] || t.branch)}</td>
      <td class="num">${t.agents}</td><td class="num">${t.online}</td>
      <td>${t.firing ? `<span class="badge crit">${t.firing}</span>` : '<span class="badge ok">0</span>'}</td><td>${esc(t.contact)}</td></tr>`), 'Noch keine Kunden angelegt.')}</div>`);
  $('#newTenant').onclick = () => tenantDialog({ require_approval: true, branch: 'pflege', interval_sec: 60, watch_processes: [], watch_services: [] });
}
function tenantDialog(t) {
  openDialog(`<h2>${t.id ? 'Kunde bearbeiten' : 'Neuer Kunde'}</h2>
    <label>Name<input name="name" required value="${esc(t.name)}"></label>
    <div class="form-row">
      <label>Branche<select name="branch">${Object.entries(BRANCH).map(([k, v]) => `<option value="${k}" ${t.branch === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
      <label>Meldeintervall (Sekunden)<input name="interval_sec" type="number" min="15" max="3600" value="${t.interval_sec || 60}"></label>
    </div>
    <label>Ansprechpartner / Kontakt<input name="contact" value="${esc(t.contact)}"></label>
    <label>Überwachte Programme (ein Prozessname pro Zeile)<textarea name="watch_processes" placeholder="z.B. MediFox.exe&#10;Vivendi.exe&#10;HerdeManager.exe">${esc((t.watch_processes || []).join('\n'))}</textarea></label>
    <label>Überwachte Dienste (Dienstname pro Zeile)<textarea name="watch_services" placeholder="z.B. MSSQLSERVER&#10;Spooler&#10;mosquitto">${esc((t.watch_services || []).join('\n'))}</textarea></label>
    <p class="hint">Der Server übermittelt dem Agenten nur diese Namen. Welche Log-Dateien gelesen werden und alle Datenschutz-Einstellungen legt ausschließlich die lokale Konfiguration beim Kunden fest.</p>
    <label class="check"><input type="checkbox" name="require_approval" ${t.require_approval ? 'checked' : ''}> Neue Geräte erst nach Freigabe annehmen (empfohlen)</label>
    <label>Interne Notizen<textarea name="notes">${esc(t.notes)}</textarea></label>`,
  async fd => {
    const body = { name: fd.get('name'), branch: fd.get('branch'), interval_sec: +fd.get('interval_sec'), contact: fd.get('contact'),
      notes: fd.get('notes'), require_approval: !!fd.get('require_approval'), watch_processes: lines(fd.get('watch_processes')), watch_services: lines(fd.get('watch_services')) };
    const r = t.id ? await api('PUT', '/api/tenants/' + t.id, body) : await api('POST', '/api/tenants', body);
    toast('Gespeichert');
    if (t.id) route(); else location.hash = '#/kunde/' + r.id;
  });
}
async function viewTenant(id) {
  const [t, agents, rules, tpls, channels] = await Promise.all([
    api('GET', '/api/tenants/' + id), api('GET', '/api/agents?tenant=' + id), api('GET', '/api/rules?tenant=' + id),
    api('GET', '/api/templates'), api('GET', '/api/channels')]);
  const url = ME.public_url;
  const own = rules.filter(r => r.tenant_id === t.id);
  const global = rules.filter(r => r.tenant_id == null);
  setMain(`<div class="page-head"><div><div class="muted"><a href="#/kunden">Kunden</a> /</div><h1>${esc(t.name)}</h1>
      <div class="muted">${esc(BRANCH[t.branch])} · angelegt ${fmtTime(t.created_at)}</div></div>
      <div class="actions"><button class="btn" id="edit">Bearbeiten</button><button class="btn danger" id="del">Löschen</button></div></div>
    <div class="grid grid-2">
      <div class="card"><h2>Stammdaten</h2><dl class="kv">
        <dt>Kontakt</dt><dd>${esc(t.contact) || '–'}</dd>
        <dt>Meldeintervall</dt><dd>${t.interval_sec} s</dd>
        <dt>Programme</dt><dd>${t.watch_processes.map(esc).join(', ') || '–'}</dd>
        <dt>Dienste</dt><dd>${t.watch_services.map(esc).join(', ') || '–'}</dd>
        <dt>Notizen</dt><dd>${esc(t.notes) || '–'}</dd></dl></div>
      <div class="card"><h2>Gerät hinzufügen</h2>
        ${tokenState(t)}
        <h3>Windows</h3>
        <p class="hint">Agent herunterladen: <a href="${esc(url)}/downloads/gw-agent-windows_amd64.exe">64-Bit (Intel/AMD)</a> · <a href="${esc(url)}/downloads/gw-agent-windows_arm64.exe">ARM</a>, dann in einer <strong>Eingabeaufforderung als Administrator</strong> im Download-Ordner:</p>
        <div class="cmd"><pre id="cmdWin">gw-agent-windows_amd64.exe setup --server ${esc(url)} --token ${esc(t.enroll_token)}</pre><button class="btn small" data-copy="cmdWin">Kopieren</button></div>
        <p class="hint">Alternativ ohne Browser (PowerShell als Administrator):</p>
        <div class="cmd"><pre id="cmdPs">Invoke-WebRequest ${esc(url)}/downloads/gw-agent-windows_amd64.exe -OutFile $env:TEMP\\gw-agent.exe
&amp; $env:TEMP\\gw-agent.exe setup --server ${esc(url)} --token ${esc(t.enroll_token)}</pre><button class="btn small" data-copy="cmdPs">Kopieren</button></div>
        <h3>Linux / Raspberry Pi / macOS</h3>
        <div class="cmd"><pre id="cmdNix">curl -fsSL ${esc(url)}/install.sh | sudo sh -s -- ${esc(t.enroll_token)}</pre><button class="btn small" data-copy="cmdNix">Kopieren</button></div>
        <div class="actions"><span class="muted">Registrierungsschlüssel: <code>${esc(t.enroll_token)}</code></span><button class="btn small" id="rotate">Neu erzeugen</button></div>
      </div>
    </div>
    <div class="card"><h2>Geräte (${agents.length})</h2>${agentTable(agents, false)}</div>
    <div class="card"><div class="page-head"><h2>Alarmregeln für diesen Kunden</h2>
      <div class="actions"><select id="tpl">${Object.entries(tpls).map(([k, v]) => `<option value="${k}" ${k === t.branch ? 'selected' : ''}>${esc(v.title)}</option>`).join('')}</select>
      <button class="btn" id="applyTpl">Vorlage anwenden</button><button class="btn primary" id="newRule">Neue Regel</button></div></div>
      ${ruleTable(own)}
      ${global.length ? `<p class="hint">Zusätzlich gelten ${global.length} globale Regeln für alle Kunden.</p>` : ''}</div>`);
  bindCopy();
  bindApprove(() => viewTenant(id));
  bindRuleButtons(() => viewTenant(id));
  $('#edit').onclick = () => tenantDialog(t);
  $('#del').onclick = () => confirmDo(`Kunde "${t.name}" mit allen Geräten, Messwerten und Regeln löschen?`, async () => { await api('DELETE', '/api/tenants/' + id); location.hash = '#/kunden'; });
  $('#rotate').onclick = () => {
    const f = openDialog(`<h2>Neuen Registrierungsschlüssel erzeugen</h2>
      <p>Der alte Schlüssel wird sofort ungültig. Bereits registrierte Geräte bleiben verbunden.</p>
      <label>Gültig für<select name="days"><option value="1">1 Tag</option><option value="7" selected>7 Tage</option><option value="30">30 Tage</option><option value="0">unbegrenzt (nicht empfohlen)</option></select></label>`,
    async fd => { await api('POST', `/api/tenants/${id}/rotate-token`, { days: +fd.get('days') }); toast('Neuer Schlüssel erzeugt'); viewTenant(id); });
    $('button[type=submit]', f).textContent = 'Erzeugen';
  };
  $('#newRule').onclick = () => ruleDialog({ kind: 'metric', enabled: true, op: '>', severity: 'warnung', tenant_id: t.id, channels: channels.filter(c => c.enabled).map(c => c.id), log_max_level: 3, log_count: 1, duration_min: 5 }, () => viewTenant(id));
  $('#applyTpl').onclick = () => {
    const key = $('#tpl').value;
    const tpl = tpls[key];
    const f = openDialog(`<h2>Vorlage „${esc(tpl.title)}“ anwenden</h2><p>${esc(tpl.description)}</p>
      <ul>${tpl.rules.map(r => `<li>${esc(r.name)}</li>`).join('')}</ul>
      <h3>Benachrichtigen über</h3>${channelChecks(channels, channels.filter(c => c.enabled).map(c => c.id))}`, async (fd) => {
      const r = await api('POST', `/api/tenants/${id}/apply-template`, { template: key, channels: fd.getAll('channels').map(Number) });
      toast(r.created + ' Regeln angelegt');
      viewTenant(id);
    });
    $('button[type=submit]', f).textContent = 'Regeln anlegen';
  };
}
function tokenState(t) {
  const now = Date.now() / 1000;
  if (t.enroll_expires && t.enroll_expires < now) return '<p class="privacy-note">Der Registrierungsschlüssel ist <strong>abgelaufen</strong>. Für neue Geräte unten „Neu erzeugen“ wählen.</p>';
  const exp = t.enroll_expires ? 'gültig bis ' + fmtTime(t.enroll_expires) : '<strong>unbegrenzt gültig</strong> – besser mit Ablaufdatum neu erzeugen';
  return `<p class="hint">Befehl auf dem Gerät mit Administratorrechten ausführen. Der Agent wird registriert und als Dienst gestartet. Schlüssel ${exp}.${t.require_approval ? ' Neue Geräte erscheinen zuerst als „wartet auf Freigabe“.' : ''}</p>`;
}
function bindCopy() {
  $$('[data-copy]').forEach(b => b.onclick = async () => {
    try { await navigator.clipboard.writeText($('#' + b.dataset.copy).textContent); toast('In die Zwischenablage kopiert'); }
    catch (_) { toast('Kopieren nicht möglich – bitte manuell markieren', true); }
  });
}

// ---------- Geräte ----------
function agentTable(list, withTenant) {
  const head = ['Gerät', ...(withTenant ? ['Kunde'] : []), 'Status', 'System', 'CPU', 'RAM', 'Disk', 'Alarme', 'Zuletzt'];
  return table(head, list.map(a => `<tr>
    <td><a href="#/geraet/${a.id}"><strong>${esc(a.display_name)}</strong></a><div class="muted mono">${esc(a.id)}</div></td>
    ${withTenant ? `<td><a href="#/kunde/${a.tenant_id}">${esc(a.tenant_name)}</a></td>` : ''}
    <td>${badgeOnline(a)}</td><td>${esc(OSNAME[a.os] || a.os)}<div class="muted">${esc(a.platform)} ${esc(a.platform_version)}</div></td>
    <td>${bar(a.cpu)}</td><td>${bar(a.mem)}</td><td>${bar(a.disk)}</td>
    <td>${a.firing ? `<span class="badge crit">${a.firing}</span>` : '<span class="badge ok">0</span>'}</td>
    <td class="nowrap">${fmtAgo(a.last_seen)}${a.approved ? '' : `<div><button class="btn small primary" data-approve="${a.id}">Freigeben</button></div>`}</td></tr>`), 'Noch keine Geräte registriert.');
}
function bindApprove(reload) {
  $$('[data-approve]').forEach(b => b.onclick = () => confirmDo('Gerät freigeben? Prüfe vorher, ob du die Installation auf diesem Gerät veranlasst hast (Kunde, Zeitpunkt, Betriebssystem).', async () => {
    await api('POST', `/api/agents/${b.dataset.approve}/approve`); toast('Gerät freigegeben'); reload();
  }));
}
let agentFilter = '';
async function viewAgents() {
  const list = await api('GET', '/api/agents');
  const render = () => {
    const q = agentFilter.toLowerCase();
    const f = list.filter(a => !q || (a.display_name + ' ' + a.tenant_name + ' ' + a.os + ' ' + a.platform).toLowerCase().includes(q));
    $('#agentList').innerHTML = agentTable(f, true);
    applyBars($('#agentList'));
    bindApprove(viewAgents);
  };
  setMain(`<div class="page-head"><div><h1>Geräte</h1><div class="muted">${list.filter(a => a.online).length} von ${list.length} online</div></div>
    <input id="filter" placeholder="Suchen …" value="${esc(agentFilter)}"></div><div class="card" id="agentList"></div>`);
  render();
  $('#filter').oninput = e => { agentFilter = e.target.value; render(); };
  autoRefresh(viewAgents, 60000);
}

let chartHours = 24;
let logFilter = { max_level: 4, channel: '', q: '' };
async function viewAgent(id) {
  const [d, tenants] = await Promise.all([api('GET', '/api/agents/' + id), api('GET', '/api/tenants')]);
  const a = d.agent;
  const latest = (n, lb = '') => d.latest.find(l => l.name === n && l.labels === lb);
  const disks = d.latest.filter(l => l.name === 'disk.percent');
  const bat = latest('battery.percent');
  const temp = latest('temp.cpu');
  setMain(`<div class="page-head"><div><div class="muted"><a href="#/kunde/${a.tenant_id}">${esc(a.tenant_name)}</a> /</div>
      <h1>${esc(a.display_name)} ${badgeOnline(a)}</h1>
      <div class="muted">${esc(OSNAME[a.os] || a.os)} · ${esc(a.platform)} ${esc(a.platform_version)} · ${esc(a.arch)} · Agent ${esc(a.agent_version)} · zuletzt ${fmtAgo(a.last_seen)}</div></div>
      <div class="actions">${a.approved ? '' : `<button class="btn primary" data-approve="${a.id}">Freigeben</button>`}<button class="btn" id="edit">Bearbeiten</button><button class="btn danger" id="del">Entfernen</button></div></div>
    ${a.approved ? '' : '<div class="privacy-note">Dieses Gerät hat sich registriert, wurde aber noch nicht freigegeben. Bis zur Freigabe werden seine Meldungen verworfen.</div><p></p>'}
    <div class="tiles">
      <div class="tile"><div class="label">Prozessor</div><div class="value">${fmtPct(a.cpu)}</div><div class="sub">${latest('cpu.cores') ? latest('cpu.cores').value + ' Kerne' : ''}</div></div>
      <div class="tile"><div class="label">Arbeitsspeicher</div><div class="value">${fmtPct(a.mem)}</div><div class="sub">${latest('mem.used_bytes') ? fmtBytes(latest('mem.used_bytes').value) + ' von ' + fmtBytes(latest('mem.total_bytes')?.value) : ''}</div></div>
      <div class="tile"><div class="label">Laufzeit</div><div class="value">${fmtDur(a.uptime)}</div><div class="sub">seit Neustart</div></div>
      ${bat ? `<div class="tile ${bat.value < 20 ? 'warn' : ''}"><div class="label">Akku</div><div class="value">${fmtPct(bat.value)}</div><div class="sub">${latest('battery.charging')?.value ? 'lädt' : 'Akkubetrieb'}</div></div>` : ''}
      ${temp ? `<div class="tile ${temp.value > 75 ? 'warn' : ''}"><div class="label">CPU-Temperatur</div><div class="value">${temp.value.toFixed(0)} °C</div></div>` : ''}
      <div class="tile ${latest('agent.queue')?.value ? 'warn' : ''}"><div class="label">Puffer</div><div class="value">${latest('agent.queue')?.value ?? 0}</div><div class="sub">nachgereichte Meldungen</div></div>
    </div>
    ${d.alerts.length ? `<div class="card"><h2>Aktive Alarme</h2>${table(['Schwere', 'Regel / Meldung', 'Kunde', 'Gerät', 'Seit', ''], alertRows(d.alerts, true))}</div>` : ''}
    <div class="grid grid-2">
      <div class="card"><h2>Festplatten</h2>${table(['Laufwerk', 'Belegt', 'Frei', 'Gesamt'], disks.map(l => {
        const m = l.labels.replace(/^mount=/, '');
        const free = latest('disk.free_bytes', l.labels), tot = latest('disk.total_bytes', l.labels);
        return `<tr><td class="mono">${esc(m)}</td><td>${bar(l.value)}</td><td>${fmtBytes(free?.value)}</td><td>${fmtBytes(tot?.value)}</td></tr>`;
      }), 'Keine Daten')}</div>
      <div class="card"><h2>Programme &amp; Dienste</h2>${table(['Art', 'Name', 'Status', 'Detail'], d.checks.map(c => `<tr>
        <td>${c.kind === 'process' ? 'Programm' : 'Dienst'}</td><td class="mono">${esc(c.name)}</td>
        <td>${c.ok ? '<span class="badge ok">läuft</span>' : '<span class="badge crit">läuft nicht</span>'}</td><td class="muted">${esc(c.detail)}</td></tr>`),
        'Keine Programme/Dienste zur Überwachung eingetragen (beim Kunden konfigurierbar).')}</div>
    </div>
    <div class="card"><div class="page-head"><h2>Verlauf</h2><div class="seg">${[[6, '6 h'], [24, '24 h'], [168, '7 Tage'], [720, '30 Tage']].map(([h, t]) => `<button data-h="${h}" class="${chartHours === h ? 'on' : ''}">${t}</button>`).join('')}</div></div>
      <div class="grid grid-2" id="charts"></div></div>
    <div class="card"><div class="page-head"><h2>System- und Software-Logs</h2>
      <div class="actions">
        <select id="lfLevel">${[[2, 'nur Kritisch'], [3, 'Fehler und schwerer'], [4, 'Warnungen und schwerer'], [7, 'alle']].map(([v, t]) => `<option value="${v}" ${logFilter.max_level == v ? 'selected' : ''}>${t}</option>`).join('')}</select>
        <select id="lfChan"><option value="">alle Quellen</option><option value="system" ${logFilter.channel === 'system' ? 'selected' : ''}>Betriebssystem</option><option value="app" ${logFilter.channel === 'app' ? 'selected' : ''}>Software</option></select>
        <input id="lfQ" placeholder="Text suchen" value="${esc(logFilter.q)}"></div></div>
      <div class="privacy-note">Alle Texte wurden bereits auf dem Gerät bereinigt (z.B. Namen, E-Mail-Adressen, Telefonnummern, Benutzerkonten → Platzhalter). Einträge ohne Text stammen aus Quellen im Datenschutz-Modus „nur Metadaten“.</div>
      <div id="logs"></div></div>`);
  $('#edit').onclick = () => openDialog(`<h2>Gerät bearbeiten</h2>
      <label>Anzeigename<input name="display_name" required value="${esc(a.display_name)}"></label>
      <p class="hint">Tipp: Funktion statt Personenname verwenden, z.B. „Tour-Laptop 3“ oder „Melkstand-PC“.</p>
      <label>Kunde<select name="tenant_id">${tenants.map(t => `<option value="${t.id}" ${t.id === a.tenant_id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label>
      <label class="check"><input type="checkbox" name="disabled" ${a.disabled ? 'checked' : ''}> Gerät deaktivieren (Meldungen werden abgelehnt, keine Alarme)</label>`,
    async fd => { await api('PUT', '/api/agents/' + id, { display_name: fd.get('display_name'), tenant_id: +fd.get('tenant_id'), disabled: !!fd.get('disabled') }); toast('Gespeichert'); viewAgent(id); });
  $('#del').onclick = () => confirmDo('Gerät mit allen Messwerten und Logs entfernen? Der Agent auf dem Gerät muss danach neu registriert werden.', async () => { await api('DELETE', '/api/agents/' + id); location.hash = '#/kunde/' + a.tenant_id; });
  $$('[data-h]').forEach(b => b.onclick = () => { chartHours = +b.dataset.h; $$('[data-h]').forEach(x => x.classList.toggle('on', x === b)); loadCharts(id, d); });
  bindAck(() => viewAgent(id));
  bindApprove(() => viewAgent(id));
  const reloadLogs = () => loadLogs(id);
  $('#lfLevel').onchange = e => { logFilter.max_level = +e.target.value; reloadLogs(); };
  $('#lfChan').onchange = e => { logFilter.channel = e.target.value; reloadLogs(); };
  let deb;
  $('#lfQ').oninput = e => { clearTimeout(deb); deb = setTimeout(() => { logFilter.q = e.target.value; reloadLogs(); }, 300); };
  loadCharts(id, d);
  reloadLogs();
}
async function loadLogs(id) {
  const p = new URLSearchParams({ hours: 168, limit: 500, max_level: logFilter.max_level, channel: logFilter.channel, q: logFilter.q });
  const list = await api('GET', `/api/agents/${id}/logs?${p}`);
  $('#logs').innerHTML = table(['Zeit', 'Stufe', 'Quelle', 'Meldung'], list.map(l => `<tr>
    <td class="nowrap">${fmtTime(l.ts)}</td><td class="lvl lvl-${l.level}">${LEVELS[l.level]}</td>
    <td>${esc(l.source)}${l.event_id ? ` <span class="muted">#${esc(l.event_id)}</span>` : ''}<div class="muted">${l.channel === 'system' ? 'System' : esc(l.channel.replace(/^app:/, 'Software: '))}</div></td>
    <td>${l.message ? esc(l.message) : `<span class="muted">(nur Metadaten · Kennung ${esc(l.fingerprint)})</span>`}</td></tr>`), 'Keine Log-Einträge im gewählten Filter.');
}
async function loadCharts(id, d) {
  const defs = [
    ['cpu.percent', 'Prozessor %', v => v.toFixed(0) + ' %', 100],
    ['mem.percent', 'Arbeitsspeicher %', v => v.toFixed(0) + ' %', 100],
    ['disk.percent', 'Festplatten belegt %', v => v.toFixed(0) + ' %', 100],
    ['net.rx_bytes_per_s', 'Netzwerk Empfang', v => fmtBytes(v) + '/s'],
  ];
  if (d.latest.some(l => l.name === 'battery.percent')) defs.push(['battery.percent', 'Akku %', v => v.toFixed(0) + ' %', 100]);
  if (d.latest.some(l => l.name === 'temp.cpu')) defs.push(['temp.cpu', 'CPU-Temperatur', v => v.toFixed(0) + ' °C']);
  $('#charts').innerHTML = defs.map((x, i) => `<div class="chart-box"><h3><span>${x[1]}</span><span class="muted" id="cv${i}"></span></h3><canvas class="chart" id="ch${i}"></canvas><div class="legend" id="lg${i}"></div></div>`).join('');
  await Promise.all(defs.map(async ([name, , fmt, maxY], i) => {
    const s = await api('GET', `/api/agents/${id}/series?name=${encodeURIComponent(name)}&hours=${chartHours}`);
    drawChart($('#ch' + i), s.series, fmt, maxY);
    const keys = Object.keys(s.series);
    $('#lg' + i).innerHTML = keys.length > 1 || (keys[0] || '') !== '' ? keys.map((k, j) => `<span><i class="sw${j % 6 + 1}"></i>${esc(k.replace(/^\w+=/, '') || 'gesamt')}</span>`).join('') : '';
    const first = s.series[keys[0]];
    if (first && first.length) $('#cv' + i).textContent = 'aktuell ' + fmt(first[first.length - 1][1]);
  }));
}
function cssVar(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
function drawChart(cv, series, fmt, maxY) {
  const dpr = window.devicePixelRatio || 1;
  const W = cv.clientWidth, H = cv.clientHeight;
  cv.width = W * dpr; cv.height = H * dpr;
  const g = cv.getContext('2d');
  g.scale(dpr, dpr);
  const keys = Object.keys(series);
  const pad = { l: 58, r: 8, t: 8, b: 22 };
  g.font = '11px system-ui, sans-serif';
  g.fillStyle = cssVar('--muted');
  if (!keys.length) { g.fillText('Keine Daten im Zeitraum', pad.l, H / 2); return; }
  let tMin = Infinity, tMax = -Infinity, vMax = maxY || 0;
  keys.forEach(k => series[k].forEach(p => { tMin = Math.min(tMin, p[0]); tMax = Math.max(tMax, p[0]); if (!maxY) vMax = Math.max(vMax, p[2]); }));
  const now = Date.now() / 1000;
  tMin = Math.min(tMin, now - chartHours * 3600); tMax = Math.max(tMax, now);
  if (vMax <= 0) vMax = 1;
  const x = t => pad.l + (t - tMin) / (tMax - tMin) * (W - pad.l - pad.r);
  const y = v => pad.t + (1 - v / vMax) * (H - pad.t - pad.b);
  g.strokeStyle = cssVar('--border'); g.lineWidth = 1;
  for (let i = 0; i <= 4; i++) {
    const v = vMax * i / 4, yy = Math.round(y(v)) + .5;
    g.beginPath(); g.moveTo(pad.l, yy); g.lineTo(W - pad.r, yy); g.stroke();
    g.fillText(fmt(v), 4, yy + 4);
  }
  const ticks = 5;
  for (let i = 0; i <= ticks; i++) {
    const t = tMin + (tMax - tMin) * i / ticks;
    const dt = new Date(t * 1000);
    const lbl = chartHours > 48 ? dt.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' }) : dt.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    const w = g.measureText(lbl).width;
    g.fillText(lbl, Math.min(Math.max(x(t) - w / 2, pad.l), W - w - 2), H - 6);
  }
  keys.forEach((k, j) => {
    const pts = series[k];
    g.strokeStyle = cssVar('--c' + (j % 6 + 1)); g.lineWidth = 1.6; g.beginPath();
    let prev = null;
    pts.forEach(p => {
      const gap = prev && p[0] - prev > 3 * (pts.length > 1 ? (pts[1][0] - pts[0][0]) : 60);
      if (!prev || gap) g.moveTo(x(p[0]), y(p[1])); else g.lineTo(x(p[0]), y(p[1]));
      prev = p[0];
    });
    g.stroke();
    // einzelne Punkte (ohne Nachbarn) sichtbar machen
    g.fillStyle = g.strokeStyle;
    pts.forEach((p, i) => {
      const step = pts.length > 1 ? pts[1][0] - pts[0][0] : 60;
      const lonely = (i === 0 || p[0] - pts[i - 1][0] > 3 * step) && (i === pts.length - 1 || pts[i + 1][0] - p[0] > 3 * step);
      if (lonely) { g.beginPath(); g.arc(x(p[0]), y(p[1]), 2.5, 0, 7); g.fill(); }
    });
  });
}

// ---------- Regeln ----------
function ruleDesc(r) {
  const dur = r.duration_min ? ` für ${r.duration_min} min` : '';
  if (r.kind === 'offline') return `keine Meldung seit ${r.duration_min} min`;
  if (r.kind === 'log') return `≥ ${r.log_count} Log-Einträge (Stufe ≤ ${LEVELS[r.log_max_level]})${r.log_source_re ? ', Quelle ~ /' + r.log_source_re + '/' : ''}${r.log_message_re ? ', Text ~ /' + r.log_message_re + '/' : ''} in ${r.duration_min} min`;
  return `${r.metric}${r.label_filter ? ' [' + r.label_filter + ']' : ''} ${r.op} ${r.threshold}${dur}`;
}
function ruleTable(list) {
  return table(['Aktiv', 'Name', 'Bedingung', 'Geltung', 'Schwere', ''], list.map(r => `<tr>
    <td>${r.enabled ? '<span class="badge ok">an</span>' : '<span class="badge off">aus</span>'}</td>
    <td><strong>${esc(r.name)}</strong></td><td class="mono">${esc(ruleDesc(r))}</td>
    <td>${r.agent_id ? 'Gerät: ' + esc(r.agent_name) : r.tenant_id ? 'Kunde: ' + esc(r.tenant_name) : '<em>alle Kunden</em>'}</td>
    <td><span class="badge ${SEV[r.severity]}">${esc(r.severity)}</span></td>
    <td class="nowrap"><button class="btn small" data-edit-rule="${r.id}">Bearbeiten</button> <button class="btn small danger" data-del-rule="${r.id}">Löschen</button></td></tr>`), 'Keine Regeln');
}
let RULECACHE = [];
function bindRuleButtons(reload) {
  $$('[data-edit-rule]').forEach(b => b.onclick = async () => {
    const all = await api('GET', '/api/rules');
    ruleDialog(all.find(r => r.id === +b.dataset.editRule), reload);
  });
  $$('[data-del-rule]').forEach(b => b.onclick = () => confirmDo('Regel löschen?', async () => { await api('DELETE', '/api/rules/' + b.dataset.delRule); reload(); }));
}
async function viewRules() {
  const [rules, channels] = await Promise.all([api('GET', '/api/rules'), api('GET', '/api/channels')]);
  RULECACHE = rules;
  setMain(`<div class="page-head"><div><h1>Alarmregeln</h1><div class="muted">Eigene Parameter je Kunde, je Gerät oder global</div></div>
    <div class="actions"><button class="btn primary" id="newRule">Neue Regel</button></div></div>
    <div class="card">${ruleTable(rules)}</div>
    <div class="card"><h2>So funktionieren Regeln</h2><ul class="muted">
      <li><strong>Messwert:</strong> Bedingung wie <code>disk.percent &gt; 90</code>. Mit Dauer muss sie durchgehend erfüllt sein (verhindert Fehlalarme bei kurzen Spitzen).</li>
      <li><strong>Label-Filter:</strong> z.B. <code>mount=C:</code> oder <code>name=MediFox.exe</code>; <code>name=*</code> gilt für alle Einträge, jeder löst einzeln aus.</li>
      <li><strong>Erreichbarkeit:</strong> Alarm, wenn sich ein Gerät länger nicht meldet.</li>
      <li><strong>Log:</strong> Alarm, wenn in einem Zeitfenster mindestens N passende Einträge eingehen (Quelle/Text als regulärer Ausdruck, z.B. <code>backup|sicherung</code>).</li>
      <li><strong>Erinnerung:</strong> wiederholt die Benachrichtigung, solange der Alarm aktiv ist.</li></ul></div>`);
  $('#newRule').onclick = () => ruleDialog({ kind: 'metric', enabled: true, op: '>', severity: 'warnung', channels: channels.filter(c => c.enabled).map(c => c.id), log_max_level: 3, log_count: 1, duration_min: 5 }, viewRules);
  bindRuleButtons(viewRules);
}
function channelChecks(channels, selected) {
  if (!channels.length) return '<p class="hint">Noch keine Benachrichtigungskanäle angelegt (Menü „Benachrichtigungen“). Alarme erscheinen trotzdem in der Oberfläche.</p>';
  return channels.map(c => `<label class="check"><input type="checkbox" name="channels" value="${c.id}" ${selected.includes(c.id) ? 'checked' : ''}> ${esc(c.name)} <span class="muted">(${esc(c.kind)})</span></label>`).join('');
}
async function ruleDialog(r, reload) {
  const [tenants, agents, channels, names] = await Promise.all([api('GET', '/api/tenants'), api('GET', '/api/agents'), api('GET', '/api/channels'), api('GET', '/api/metric-names')]);
  const metricOpts = Array.from(new Set([...Object.keys(METRICS), ...names])).sort();
  const f = openDialog(`<h2>${r.id ? 'Regel bearbeiten' : 'Neue Alarmregel'}</h2>
    <label>Name<input name="name" required value="${esc(r.name)}" placeholder="z.B. Pflegesoftware abgestürzt"></label>
    <div class="form-row">
      <label>Typ<select name="kind">
        <option value="metric" ${r.kind === 'metric' ? 'selected' : ''}>Messwert-Schwelle</option>
        <option value="offline" ${r.kind === 'offline' ? 'selected' : ''}>Gerät nicht erreichbar</option>
        <option value="log" ${r.kind === 'log' ? 'selected' : ''}>Log-Ereignisse</option></select></label>
      <label>Schwere<select name="severity">${['info', 'warnung', 'kritisch'].map(s => `<option ${r.severity === s ? 'selected' : ''}>${s}</option>`).join('')}</select></label>
    </div>
    <div class="form-row">
      <label>Kunde<select name="tenant_id"><option value="">alle Kunden</option>${tenants.map(t => `<option value="${t.id}" ${r.tenant_id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label>
      <label>Gerät<select name="agent_id"><option value="">alle Geräte</option></select></label>
    </div>
    <div data-k="metric" class="form">
      <label>Messwert<select name="metric">${metricOpts.map(m => `<option value="${esc(m)}" ${r.metric === m ? 'selected' : ''}>${esc(m)}${METRICS[m] ? ' – ' + esc(METRICS[m]) : ''}</option>`).join('')}</select></label>
      <div class="form-row">
        <label>Vergleich<select name="op">${['>', '>=', '<', '<=', '==', '!='].map(o => `<option ${r.op === o ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></label>
        <label>Schwellwert<input name="threshold" type="number" step="any" value="${r.threshold ?? 0}"></label>
        <label>Label-Filter (optional)<input name="label_filter" value="${esc(r.label_filter)}" placeholder="mount=C: oder name=*"></label>
      </div></div>
    <div data-k="log" class="form">
      <div class="form-row">
        <label>Stufe bis<select name="log_max_level">${LEVELS.map((l, i) => `<option value="${i}" ${r.log_max_level === i ? 'selected' : ''}>${i} – ${l}</option>`).join('')}</select></label>
        <label>Mindestanzahl<input name="log_count" type="number" min="1" value="${r.log_count || 1}"></label>
      </div>
      <div class="form-row">
        <label>Quelle (regulärer Ausdruck)<input name="log_source_re" value="${esc(r.log_source_re)}" placeholder="z.B. ^app: oder Disk|Ntfs"></label>
        <label>Text (regulärer Ausdruck)<input name="log_message_re" value="${esc(r.log_message_re)}" placeholder="z.B. backup|sicherung"></label>
      </div></div>
    <div class="form-row">
      <label><span data-dur-label>Dauer / Zeitfenster (Minuten)</span><input name="duration_min" type="number" min="0" value="${r.duration_min ?? 0}"></label>
      <label>Erinnerung alle … Minuten (0 = aus)<input name="repeat_min" type="number" min="0" value="${r.repeat_min || 0}"></label>
    </div>
    <h3>Benachrichtigen über</h3>${channelChecks(channels, r.channels || [])}
    <label class="check"><input type="checkbox" name="enabled" ${r.enabled ? 'checked' : ''}> Regel aktiv</label>`,
  async fd => {
    const tid = fd.get('tenant_id'), aid = fd.get('agent_id');
    const body = { name: fd.get('name'), kind: fd.get('kind'), severity: fd.get('severity'), enabled: !!fd.get('enabled'),
      tenant_id: tid ? +tid : null, agent_id: aid || null, metric: fd.get('metric'), op: fd.get('op'), threshold: +fd.get('threshold'),
      label_filter: fd.get('label_filter'), duration_min: +fd.get('duration_min'), repeat_min: +fd.get('repeat_min'),
      log_max_level: +fd.get('log_max_level'), log_count: +fd.get('log_count'), log_source_re: fd.get('log_source_re'), log_message_re: fd.get('log_message_re'),
      channels: fd.getAll('channels').map(Number) };
    if (r.id) await api('PUT', '/api/rules/' + r.id, body); else await api('POST', '/api/rules', body);
    toast('Regel gespeichert');
    reload();
  });
  const kindSel = $('[name=kind]', f), tSel = $('[name=tenant_id]', f), aSel = $('[name=agent_id]', f);
  const sync = () => {
    $$('[data-k]', f).forEach(el => el.classList.toggle('hidden', el.dataset.k !== kindSel.value));
    $('[data-dur-label]', f).textContent = kindSel.value === 'offline' ? 'Keine Meldung seit (Minuten)' : kindSel.value === 'log' ? 'Zeitfenster (Minuten)' : 'Bedingung erfüllt seit (Minuten, 0 = sofort)';
  };
  const fillAgents = () => {
    const tid = +tSel.value;
    aSel.innerHTML = '<option value="">alle Geräte</option>' + agents.filter(a => !tid || a.tenant_id === tid).map(a => `<option value="${a.id}" ${r.agent_id === a.id ? 'selected' : ''}>${esc(a.display_name)}${tid ? '' : ' (' + esc(a.tenant_name) + ')'}</option>`).join('');
  };
  kindSel.onchange = sync; tSel.onchange = fillAgents;
  sync(); fillAgents();
}

// ---------- Kanäle ----------
const CHFIELDS = {
  email: [['host', 'SMTP-Server'], ['port', 'Port (587 = STARTTLS, 465 = TLS)'], ['username', 'Benutzer'], ['password', 'Passwort', 'password'], ['from', 'Absender'], ['to', 'Empfänger (mehrere mit Komma)']],
  webhook: [['url', 'URL (z.B. Teams-, Slack-, Mattermost-Webhook)'], ['token', 'Bearer-Token (optional)', 'password']],
  ntfy: [['url', 'Themen-URL, z.B. https://ntfy.sh/mein-geheimes-thema'], ['token', 'Zugriffstoken (optional)', 'password']],
  telegram: [['bot_token', 'Bot-Token', 'password'], ['chat_id', 'Chat-ID']],
};
const CHNAME = { email: 'E-Mail', webhook: 'Webhook', ntfy: 'ntfy (Push aufs Handy)', telegram: 'Telegram' };
async function viewChannels() {
  const list = await api('GET', '/api/channels');
  setMain(`<div class="page-head"><div><h1>Benachrichtigungen</h1><div class="muted">Kanäle, über die Alarme verschickt werden</div></div>
    <div class="actions"><button class="btn primary" id="newCh">Neuer Kanal</button></div></div>
    <div class="card">${table(['Aktiv', 'Name', 'Typ', 'Ziel', ''], list.map(c => `<tr>
      <td>${c.enabled ? '<span class="badge ok">an</span>' : '<span class="badge off">aus</span>'}</td><td><strong>${esc(c.name)}</strong></td><td>${esc(CHNAME[c.kind])}</td>
      <td class="mono">${esc(c.config.to || c.config.url || c.config.chat_id || '')}</td>
      <td class="nowrap"><button class="btn small" data-test="${c.id}">Test senden</button> <button class="btn small" data-edit="${c.id}">Bearbeiten</button> <button class="btn small danger" data-del="${c.id}">Löschen</button></td></tr>`), 'Noch keine Kanäle.')}</div>
    <div class="privacy-note">Benachrichtigungen enthalten nur Kundenname, Gerätebezeichnung, Regel und den bereinigten Meldungstext. Für E-Mails einen Anbieter mit Sitz in der EU und TLS verwenden.</div>`);
  $('#newCh').onclick = () => channelDialog({ kind: 'email', enabled: true, config: { port: '587' } });
  $$('[data-edit]').forEach(b => b.onclick = () => channelDialog(list.find(c => c.id === +b.dataset.edit)));
  $$('[data-del]').forEach(b => b.onclick = () => confirmDo('Kanal löschen?', async () => { await api('DELETE', '/api/channels/' + b.dataset.del); viewChannels(); }));
  $$('[data-test]').forEach(b => b.onclick = async () => {
    b.disabled = true;
    try { await api('POST', `/api/channels/${b.dataset.test}/test`); toast('Testnachricht verschickt'); } catch (e) { toast(e.message, true); }
    b.disabled = false;
  });
}
function channelDialog(c) {
  const f = openDialog(`<h2>${c.id ? 'Kanal bearbeiten' : 'Neuer Kanal'}</h2>
    <div class="form-row"><label>Name<input name="name" required value="${esc(c.name)}"></label>
    <label>Typ<select name="kind">${Object.entries(CHNAME).map(([k, v]) => `<option value="${k}" ${c.kind === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label></div>
    <div id="chFields" class="form"></div>
    <label class="check"><input type="checkbox" name="enabled" ${c.enabled ? 'checked' : ''}> aktiv</label>`,
  async fd => {
    const kind = fd.get('kind'), config = {};
    CHFIELDS[kind].forEach(([k]) => { config[k] = fd.get('cf_' + k) || ''; });
    const body = { name: fd.get('name'), kind, enabled: !!fd.get('enabled'), config };
    if (c.id) await api('PUT', '/api/channels/' + c.id, body); else await api('POST', '/api/channels', body);
    toast('Gespeichert');
    viewChannels();
  });
  const render = () => {
    const kind = $('[name=kind]', f).value;
    $('#chFields', f).innerHTML = CHFIELDS[kind].map(([k, l, t]) => `<label>${esc(l)}<input name="cf_${k}" type="${t || 'text'}" value="${esc((c.config || {})[k] ?? '')}" autocomplete="off"></label>`).join('');
  };
  $('[name=kind]', f).onchange = render;
  render();
}

// ---------- Einstellungen ----------
async function viewSettings() {
  setMain(`<div class="page-head"><h1>Einstellungen</h1></div>
    <div class="grid grid-2">
      <div class="card"><h2>Passwort ändern</h2><form id="pwForm" class="form">
        <label>Aktuelles Passwort<input name="old" type="password" required autocomplete="current-password"></label>
        <label>Neues Passwort (mind. 10 Zeichen)<input name="new" type="password" required minlength="10" autocomplete="new-password"></label>
        <button class="btn primary" type="submit">Ändern</button></form></div>
      <div class="card"><h2>Zwei-Faktor-Anmeldung</h2><div id="totpBox"></div></div>
      <div class="card"><h2>Speicherfristen</h2><dl class="kv">
        <dt>Messwerte</dt><dd>${ME.retention.metrics} Tage</dd>
        <dt>Logs</dt><dd>${ME.retention.logs} Tage</dd>
        <dt>Behobene Alarme</dt><dd>${ME.retention.alerts} Tage</dd></dl>
        <p class="hint">Änderbar über die Umgebungsvariablen GW_METRIC_RETENTION_DAYS, GW_LOG_RETENTION_DAYS und GW_ALERT_RETENTION_DAYS. Ältere Daten werden stündlich automatisch gelöscht.</p></div>
    </div>
    <div class="card"><h2>Datenschutz-Konzept</h2><ul>
      <li>Der Agent überträgt nur fest definierte Felder: Systemkennzahlen, Laufzustand eingetragener Programme/Dienste und Log-Einträge (Zeit, Quelle, Stufe, Ereignis-ID, bereinigter Text).</li>
      <li>Nicht erhoben werden: Benutzernamen, Dateien und Dateiinhalte, Prozess-Kommandozeilen, Fenstertitel, Browserverläufe, Netzwerkverbindungen, Standort, Bildschirminhalte, Windows-Sicherheitsprotokoll.</li>
      <li>Texte werden <em>auf dem Gerät</em> bereinigt (Namen nach Anrede, lokale Konten, Klarnamen, Rechnername, E-Mail, Telefon, IBAN, Versicherten- und SV-Nummern, Adressen, Geburtsdaten, IP-/MAC-Adressen, GPS, Benutzerpfade, Dokumentnamen) und auf dem Server ein zweites Mal.</li>
      <li>Software-Logs werden standardmäßig nur als Metadaten (ohne Text) übertragen.</li>
      <li>Der Server kann dem Agenten keine Befehle schicken und keine Dateien anfordern. Datenschutz-Einstellungen sind nur lokal änderbar.</li>
      <li>Auf jedem Gerät liegt die zuletzt gesendete Meldung lesbar zur Kontrolle (<code>letzte-meldung.json</code>); <code>gw-agent preview</code> zeigt eine Meldung vor dem Senden.</li>
      <li>IP-Adressen der Kunden werden vom Server nicht protokolliert.</li>
      <li>Der Windows-Agent startet keine Hilfsprogramme (kein PowerShell); unter Linux läuft er in einer systemd-Sandbox mit Lesezugriff.</li></ul></div>`);
  renderTOTP();
  $('#pwForm').onsubmit = async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try { await api('POST', '/api/password', { old: fd.get('old'), new: fd.get('new') }); toast('Passwort geändert – bitte neu anmelden'); setTimeout(showLogin, 1200); }
    catch (err) { toast(err.message, true); }
  };
}

function renderTOTP() {
  const box = $('#totpBox');
  if (ME.totp_enabled) {
    box.innerHTML = `<p><span class="badge ok">aktiv</span> Die Anmeldung erfordert zusätzlich einen Code aus der Authenticator-App.</p>
      <form id="totpOff" class="form"><label>Passwort<input name="password" type="password" required autocomplete="current-password"></label>
      <label>Aktueller Code<input name="code" inputmode="numeric" required autocomplete="one-time-code"></label>
      <button class="btn danger" type="submit">Abschalten</button></form>`;
    $('#totpOff').onsubmit = async e => {
      e.preventDefault(); const fd = new FormData(e.target);
      try { await api('POST', '/api/totp/disable', { password: fd.get('password'), code: fd.get('code') }); ME.totp_enabled = false; toast('Zwei-Faktor-Anmeldung abgeschaltet'); renderTOTP(); }
      catch (err) { toast(err.message, true); }
    };
    return;
  }
  box.innerHTML = `<p><span class="badge warn">nicht aktiv</span> Dringend empfohlen: Schützt die Verwaltung auch dann, wenn das Passwort bekannt wird.</p>
    <button class="btn primary" id="totpStart">Einrichten</button>`;
  $('#totpStart').onclick = async () => {
    try {
      const r = await api('POST', '/api/totp/setup');
      box.innerHTML = `<ol>
        <li>Authenticator-App öffnen (z.B. Microsoft Authenticator, Google Authenticator, Aegis, 2FAS) und „Schlüssel manuell eingeben“ wählen.</li>
        <li>Konto: <strong>Gerätewächter</strong>, Schlüssel (zeitbasiert):<div class="cmd"><pre id="totpSecret">${esc(r.secret)}</pre><button class="btn small" data-copy="totpSecret">Kopieren</button></div>
          <details><summary class="muted">Einrichtungslink (für Passwortmanager)</summary><div class="cmd"><pre id="totpUri">${esc(r.uri)}</pre><button class="btn small" data-copy="totpUri">Kopieren</button></div></details></li>
        <li>Den angezeigten 6-stelligen Code eingeben:</li></ol>
        <form id="totpOn" class="form"><label>Code<input name="code" inputmode="numeric" required autocomplete="one-time-code"></label><button class="btn primary" type="submit">Aktivieren</button></form>`;
      bindCopy();
      $('#totpOn').onsubmit = async e => {
        e.preventDefault();
        try { await api('POST', '/api/totp/enable', { code: new FormData(e.target).get('code') }); ME.totp_enabled = true; toast('Zwei-Faktor-Anmeldung aktiv'); renderTOTP(); }
        catch (err) { toast(err.message, true); }
      };
    } catch (err) { toast(err.message, true); }
  };
}

// ---------- Anmeldung ----------
function showLogin() {
  clearInterval(refreshTimer);
  $('#app').classList.add('hidden');
  $('#login').classList.remove('hidden');
  $('#loginForm [name=username]').focus();
}
async function start() {
  try {
    ME = await api('GET', '/api/me');
  } catch (_) { return; }
  $('#login').classList.add('hidden');
  $('#app').classList.remove('hidden');
  $('#whoami').textContent = ME.user.username;
  route();
}
document.addEventListener('DOMContentLoaded', () => {
  $('#loginForm').onsubmit = async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      await api('POST', '/api/login', { username: fd.get('username'), password: fd.get('password'), code: fd.get('code') || '' });
      $('#loginError').textContent = '';
      e.target.reset();
      $('#codeRow').classList.add('hidden');
      start();
    } catch (err) {
      if (err.data && err.data.totp_required) {
        $('#codeRow').classList.remove('hidden');
        $('#loginForm [name=code]').focus();
        if (!fd.get('code')) { $('#loginError').textContent = ''; return; }
      }
      $('#loginError').textContent = err.message;
    }
  };
  $('#logout').onclick = async () => { try { await api('POST', '/api/logout'); } catch (_) { /* egal */ } showLogin(); };
  window.addEventListener('hashchange', () => { if (ME) route(); });
  start();
});
