#!/usr/bin/env bash
# Grundeinrichtung des Backup-Servers (Debian 12/13, als root).
#
#   ADMIN_IP=203.0.113.10 ./setup-server.sh
#
# ADMIN_IP (optional): feste IP deines Büros. Dann ist SSH nur von dort erreichbar,
# sonst nur rate-limitiert offen. Das Skript kann mehrfach laufen.
#
# NICHT enthalten (bei der Installation des Betriebssystems erledigen):
#   - RAID 6 / ZFS RAID-Z2 über die Datenplatten
#   - LUKS-Verschlüsselung des Datenbereichs /srv/backup (siehe README)
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "Bitte als root ausführen." >&2; exit 1; }
HIER="$(cd "$(dirname "$0")" && pwd)"
ADMIN_IP="${ADMIN_IP:-}"
WG_IP="10.8.0.1"
[[ -f "$HIER/.env" ]] && WG_IP="$(grep -E '^WG_IP=' "$HIER/.env" | cut -d= -f2 || echo 10.8.0.1)"
WG_IP="${WG_IP:-10.8.0.1}"

echo "==> Pakete installieren"
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  docker.io ufw fail2ban unattended-upgrades apache2-utils rclone wireguard curl openssl
if ! docker compose version >/dev/null 2>&1; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y docker-compose-v2 2>/dev/null \
    || DEBIAN_FRONTEND=noninteractive apt-get install -y docker-compose
fi
docker compose version >/dev/null 2>&1 || echo "WARNUNG: 'docker compose' nicht verfügbar – Docker-Compose-Plugin nach Docker-Doku installieren."

echo "==> Automatische Sicherheitsupdates"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

echo "==> Datenverzeichnisse"
mkdir -p /srv/backup/data /srv/backup/admin
touch /srv/backup/data/.htpasswd /srv/backup/admin/.htpasswd
chmod 700 /srv/backup/admin
chmod 600 /srv/backup/data/.htpasswd /srv/backup/admin/.htpasswd

echo "==> Wartungszugang (rest-admin)"
if ! grep -q '^wartung:' /srv/backup/admin/.htpasswd; then
  ADMIN_PASS="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 40)"
  # -c: Datei ist noch leer (htpasswd ändert keine leere Datei ohne -c)
  printf '%s\n' "$ADMIN_PASS" | htpasswd -c -B -i /srv/backup/admin/.htpasswd wartung
  echo
  echo "  Benutzer: wartung"
  echo "  Passwort: $ADMIN_PASS"
  echo "  -> JETZT in den Passworttresor übernehmen und auf dem Wartungsrechner"
  echo "     in wartung/geheim/admin-rest.pass speichern. Wird nicht erneut angezeigt."
  echo
else
  echo "  Benutzer 'wartung' existiert bereits."
fi

echo "==> WireGuard (wg0, $WG_IP/24)"
if [[ ! -f /etc/wireguard/wg0.conf ]]; then
  umask 077
  wg genkey | tee /etc/wireguard/server.key | wg pubkey > /etc/wireguard/server.pub
  cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address = $WG_IP/24
ListenPort = 51820
PrivateKey = $(cat /etc/wireguard/server.key)

# Wartungsrechner – öffentlichen Schlüssel eintragen, dann: systemctl restart wg-quick@wg0
#[Peer]
#PublicKey = <öffentlicher Schlüssel des Wartungsrechners>
#AllowedIPs = 10.8.0.2/32
EOF
fi
systemctl enable --now wg-quick@wg0
echo "  Öffentlicher Server-Schlüssel: $(cat /etc/wireguard/server.pub)"

# Docker erst starten, wenn wg0 existiert (rest-admin bindet an die WireGuard-Adresse)
mkdir -p /etc/systemd/system/docker.service.d
cat > /etc/systemd/system/docker.service.d/10-wireguard.conf <<'EOF'
[Unit]
After=wg-quick@wg0.service
Wants=wg-quick@wg0.service
EOF
systemctl daemon-reload

echo "==> SSH absichern"
if [[ -s /root/.ssh/authorized_keys ]]; then
  cat > /etc/ssh/sshd_config.d/90-backup.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
  systemctl reload ssh || systemctl reload sshd || true
else
  echo "  WARNUNG: /root/.ssh/authorized_keys ist leer – Passwort-Login bleibt an."
  echo "  Erst SSH-Schlüssel hinterlegen, dann dieses Skript erneut ausführen."
fi

echo "==> Firewall"
ufw default deny incoming
ufw default allow outgoing
if [[ -n "$ADMIN_IP" ]]; then
  ufw allow from "$ADMIN_IP" to any port 22 proto tcp
else
  ufw limit 22/tcp
fi
ufw allow in on wg0 to any port 22 proto tcp
ufw allow 443/tcp
ufw allow 51820/udp
ufw --force enable
# Hinweis: Docker setzt eigene iptables-Regeln für veröffentlichte Ports. Deshalb
# ist rest-admin in docker-compose.yml ausschließlich an die WireGuard-Adresse gebunden.

systemctl enable --now fail2ban

echo "==> Cron-Jobs"
install -m 755 "$HIER/offsite-sync.sh" /usr/local/bin/backup-offsite-sync
install -m 755 "$HIER/server-check.sh" /usr/local/bin/backup-server-check
cat > /etc/cron.d/backup-plattform <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
BACKUP_ENV=$HIER/.env
30 2 * * * root /usr/local/bin/backup-offsite-sync >>/var/log/backup-offsite.log 2>&1
0 7 * * * root /usr/local/bin/backup-server-check >>/var/log/backup-check.log 2>&1
EOF

echo
echo "Fertig. Nächste Schritte:"
echo "  1. $HIER/.env aus env.example anlegen (DOMAIN, Ping-URLs)"
echo "  2. rclone-Konfiguration nach rclone.conf.example anlegen"
echo "  3. cd $HIER && docker compose up -d"
