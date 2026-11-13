<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        http_response_code(500);
        exit('Die PHP-Erweiterung „pdo_sqlite“ fehlt. Bitte im Hosting-Kundenbereich eine aktuelle PHP-Version (8.1 oder neuer) wählen.');
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $file = DATA_DIR . '/portal.sqlite';
    $isNew = !file_exists($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($isNew || !table_exists($pdo, 'settings')) {
        db_migrate($pdo);
        db_seed($pdo);
    }
    return $pdo;
}

function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  last_login TEXT
);
CREATE TABLE IF NOT EXISTS login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  attempted_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS places (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  lat REAL NOT NULL,
  lng REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS listings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'Ferienwohnung',
  place_id INTEGER REFERENCES places(id) ON DELETE SET NULL,
  address TEXT NOT NULL DEFAULT '',
  lat REAL,
  lng REAL,
  summary TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  max_guests INTEGER NOT NULL DEFAULT 2,
  bedrooms INTEGER NOT NULL DEFAULT 1,
  single_beds INTEGER NOT NULL DEFAULT 0,
  bathrooms INTEGER NOT NULL DEFAULT 1,
  size_m2 INTEGER NOT NULL DEFAULT 0,
  price_night REAL NOT NULL DEFAULT 0,
  cleaning_fee REAL NOT NULL DEFAULT 0,
  min_nights INTEGER NOT NULL DEFAULT 1,
  weekly_discount REAL NOT NULL DEFAULT 0,
  month_price REAL NOT NULL DEFAULT 0,
  base_guests INTEGER NOT NULL DEFAULT 2,
  extra_guest_fee REAL NOT NULL DEFAULT 0,
  mode_urlaub INTEGER NOT NULL DEFAULT 1,
  mode_business INTEGER NOT NULL DEFAULT 0,
  mode_langzeit INTEGER NOT NULL DEFAULT 0,
  wifi_mbit INTEGER NOT NULL DEFAULT 0,
  amenities TEXT NOT NULL DEFAULT '[]',
  host_name TEXT NOT NULL DEFAULT '',
  host_email TEXT NOT NULL DEFAULT '',
  host_phone TEXT NOT NULL DEFAULT '',
  ical_import_url TEXT NOT NULL DEFAULT '',
  ical_token TEXT NOT NULL,
  ical_synced_at TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  featured INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS listing_images (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
  file TEXT NOT NULL,
  alt TEXT NOT NULL DEFAULT '',
  sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS bookings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE,
  listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'angefragt',
  mode TEXT NOT NULL DEFAULT 'urlaub',
  date_from TEXT NOT NULL,
  date_to TEXT NOT NULL,
  guests INTEGER NOT NULL DEFAULT 1,
  weekdays_only INTEGER NOT NULL DEFAULT 0,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  company TEXT NOT NULL DEFAULT '',
  vat_id TEXT NOT NULL DEFAULT '',
  cost_center TEXT NOT NULL DEFAULT '',
  billing_address TEXT NOT NULL DEFAULT '',
  purpose TEXT NOT NULL DEFAULT '',
  message TEXT NOT NULL DEFAULT '',
  price_total REAL NOT NULL DEFAULT 0,
  price_detail TEXT NOT NULL DEFAULT '[]',
  admin_note TEXT NOT NULL DEFAULT '',
  invoice_no TEXT,
  invoice_date TEXT,
  source TEXT NOT NULL DEFAULT 'web',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_bookings_listing ON bookings(listing_id, date_from, date_to);
CREATE TABLE IF NOT EXISTS blocks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
  date_from TEXT NOT NULL,
  date_to TEXT NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'manuell'
);
CREATE TABLE IF NOT EXISTS inquiries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  name TEXT NOT NULL,
  company TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  persons INTEGER NOT NULL DEFAULT 0,
  date_from TEXT NOT NULL DEFAULT '',
  duration TEXT NOT NULL DEFAULT '',
  place TEXT NOT NULL DEFAULT '',
  message TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'offen',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS pages (
  slug TEXT PRIMARY KEY,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT ''
);
SQL);
}

function db_seed(PDO $pdo): void
{
    $pdo->beginTransaction();

    $settings = [
        'site_name'      => 'EmsQuartier',
        'site_claim'     => 'Ferienwohnungen und Häuser im Emsland – für Urlaub und Wohnen auf Zeit',
        'contact_email'  => 'info@example.de',
        'contact_phone'  => '05931 000000',
        'company_name'   => 'EmsQuartier (Betreiber eintragen)',
        'company_address'=> "Musterstraße 1\n49716 Meppen",
        'tax_id'         => '',
        'vat_rate'       => '7',
        'small_business' => '0',
        'notify_email'   => '',
        'noindex'        => '1',
        'invoice_counter'=> '0',
        'payment_info'   => "Bitte überweisen Sie den Betrag innerhalb von 14 Tagen.\nIBAN: DE00 0000 0000 0000 0000 00",
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES (?, ?)');
    foreach ($settings as $k => $v) {
        $st->execute([$k, $v]);
    }

    // Orte im Emsland (Koordinaten gerundet, für Entfernungen per Luftlinie)
    $places = [
        ['Lingen (Ems)', 52.5230, 7.3170], ['Meppen', 52.6920, 7.2910], ['Papenburg', 53.0770, 7.4040],
        ['Haren (Ems)', 52.7890, 7.2400], ['Sögel', 52.8450, 7.5240], ['Emsbüren', 52.3920, 7.2930],
        ['Haselünne', 52.6720, 7.4840], ['Werlte', 52.8510, 7.6780], ['Freren', 52.4860, 7.5430],
        ['Dörpen', 52.9580, 7.3330], ['Spelle', 52.3670, 7.4660], ['Salzbergen', 52.3230, 7.3490],
        ['Twist', 52.6360, 7.0880], ['Lathen', 52.8660, 7.3220], ['Ahmsen', 52.7680, 7.6440],
        ['Geeste', 52.6000, 7.2700], ['Lünne', 52.4300, 7.4300],
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO places(name, lat, lng) VALUES (?, ?, ?)');
    foreach ($places as $p) {
        $st->execute($p);
    }
    $placeId = fn(string $n): int => (int)$pdo->query('SELECT id FROM places WHERE name = ' . $pdo->quote($n))->fetchColumn();

    $listings = [
        [
            'slug' => 'haus-rosenstock-ahmsen', 'title' => 'Haus Rosenstock', 'type' => 'Ferienhaus',
            'place' => 'Ahmsen', 'lat' => 52.7690, 'lng' => 7.6460,
            'summary' => 'Klinkerhaus mit Rosengarten am Rand des Hümmlings – ideal für Familien und Freunde.',
            'description' => "Klassisches Emsländer Klinkerhaus mit großem Garten, Kaminofen und offener Küche.\n\nIm Garten blühen von Juni bis September Hunderte Rosen. Ausflugsziele wie Schloss Clemenswerth und die Meyer Werft sind gut erreichbar.",
            'max_guests' => 6, 'bedrooms' => 3, 'single_beds' => 2, 'bathrooms' => 2, 'size_m2' => 120,
            'price_night' => 115, 'cleaning_fee' => 80, 'min_nights' => 3, 'weekly_discount' => 10, 'month_price' => 2400,
            'base_guests' => 4, 'extra_guest_fee' => 10, 'mode_urlaub' => 1, 'mode_business' => 0, 'mode_langzeit' => 1, 'wifi_mbit' => 50,
            'amenities' => ['wlan', 'kueche', 'spuelmaschine', 'waschmaschine', 'parkplatz', 'garten', 'kamin', 'terrasse', 'grill', 'hund', 'kinderbett', 'fahrradgarage'],
            'images' => ['hero.jpg', 'haus.jpg', 'garten.jpg'], 'featured' => 1,
        ],
        [
            'slug' => 'apartment-am-bahnhof-lingen', 'title' => 'Business-Apartment am Bahnhof', 'type' => 'Apartment',
            'place' => 'Lingen (Ems)', 'lat' => 52.5205, 'lng' => 7.3225,
            'summary' => 'Ruhiges Apartment mit Arbeitsplatz, 5 Minuten zu Fuß zum Bahnhof. Ideal für Projektleiter und Ärzte auf Zeit.',
            'description' => "Modernes 1-Zimmer-Apartment mit separatem Schreibtisch, Glasfaser-WLAN und Verdunkelung für Schichtdienst.\n\nSelf-Check-in per Schlüsselbox, Reinigung alle 14 Tage im Monatspreis enthalten.",
            'max_guests' => 2, 'bedrooms' => 1, 'single_beds' => 0, 'bathrooms' => 1, 'size_m2' => 38,
            'price_night' => 69, 'cleaning_fee' => 40, 'min_nights' => 2, 'weekly_discount' => 12, 'month_price' => 1190,
            'base_guests' => 1, 'extra_guest_fee' => 15, 'mode_urlaub' => 1, 'mode_business' => 1, 'mode_langzeit' => 1, 'wifi_mbit' => 250,
            'amenities' => ['wlan', 'schreibtisch', 'kueche', 'waschmaschine', 'parkplatz', 'selfcheckin', 'verdunkelung'],
            'images' => ['haus.jpg', 'hero.jpg'], 'featured' => 1,
        ],
        [
            'slug' => 'monteurwohnung-papenburg', 'title' => 'Monteurwohnung nahe Werft', 'type' => 'Monteurwohnung',
            'place' => 'Papenburg', 'lat' => 53.0700, 'lng' => 7.3950,
            'summary' => '4 Einzelbetten in 3 Zimmern, Stellplatz für Transporter, 10 Minuten zur Werft.',
            'description' => "Geräumige Wohnung für Montageteams: drei Schlafzimmer mit insgesamt vier Einzelbetten, große Küche, Waschmaschine und Trockner.\n\nStellplätze für zwei Transporter direkt am Haus. Rechnung auf Firma möglich.",
            'max_guests' => 4, 'bedrooms' => 3, 'single_beds' => 4, 'bathrooms' => 1, 'size_m2' => 85,
            'price_night' => 95, 'cleaning_fee' => 60, 'min_nights' => 3, 'weekly_discount' => 15, 'month_price' => 1950,
            'base_guests' => 2, 'extra_guest_fee' => 18, 'mode_urlaub' => 0, 'mode_business' => 1, 'mode_langzeit' => 1, 'wifi_mbit' => 100,
            'amenities' => ['wlan', 'kueche', 'waschmaschine', 'trockner', 'parkplatz', 'transporter', 'einzelbetten', 'selfcheckin'],
            'images' => ['werft.jpg', 'haus.jpg'], 'featured' => 1,
        ],
        [
            'slug' => 'ferienwohnung-am-hasetal-haseluenne', 'title' => 'Ferienwohnung am Hasetal', 'type' => 'Ferienwohnung',
            'place' => 'Haselünne', 'lat' => 52.6740, 'lng' => 7.4800,
            'summary' => 'Helle Wohnung direkt am Hase-Radweg, mit Fahrradgarage und Balkon.',
            'description' => "Zwei Zimmer, Balkon mit Blick ins Grüne, abschließbare Fahrradgarage mit Ladestation für E-Bikes.\n\nDie Altstadt von Haselünne erreichen Sie in fünf Minuten zu Fuß.",
            'max_guests' => 3, 'bedrooms' => 1, 'single_beds' => 1, 'bathrooms' => 1, 'size_m2' => 55,
            'price_night' => 79, 'cleaning_fee' => 45, 'min_nights' => 2, 'weekly_discount' => 8, 'month_price' => 0,
            'base_guests' => 2, 'extra_guest_fee' => 12, 'mode_urlaub' => 1, 'mode_business' => 0, 'mode_langzeit' => 0, 'wifi_mbit' => 50,
            'amenities' => ['wlan', 'kueche', 'parkplatz', 'fahrradgarage', 'eladestation', 'terrasse', 'hund'],
            'images' => ['garten.jpg', 'moor.jpg'], 'featured' => 1,
        ],
        [
            'slug' => 'landhaus-am-moor-geeste', 'title' => 'Landhaus am Moor', 'type' => 'Ferienhaus',
            'place' => 'Geeste', 'lat' => 52.6020, 'lng' => 7.2650,
            'summary' => 'Freistehendes Landhaus mit Sauna und Kamin, ruhig am Rand des Moores.',
            'description' => "Großzügiges Haus für bis zu acht Personen mit Sauna, Kaminofen und großem Garten.\n\nAuch als ruhiges Zuhause auf Zeit für Ärzte und Projektleiter, die mit Familie anreisen.",
            'max_guests' => 8, 'bedrooms' => 4, 'single_beds' => 2, 'bathrooms' => 2, 'size_m2' => 160,
            'price_night' => 155, 'cleaning_fee' => 110, 'min_nights' => 3, 'weekly_discount' => 10, 'month_price' => 3200,
            'base_guests' => 4, 'extra_guest_fee' => 15, 'mode_urlaub' => 1, 'mode_business' => 0, 'mode_langzeit' => 1, 'wifi_mbit' => 100,
            'amenities' => ['wlan', 'schreibtisch', 'kueche', 'spuelmaschine', 'waschmaschine', 'parkplatz', 'garten', 'sauna', 'kamin', 'terrasse', 'grill', 'kinderbett', 'barrierefrei'],
            'images' => ['moor.jpg', 'hero.jpg', 'garten.jpg'], 'featured' => 1,
        ],
        [
            'slug' => 'stadtwohnung-meppen', 'title' => 'Stadtwohnung an der Hase', 'type' => 'Ferienwohnung',
            'place' => 'Meppen', 'lat' => 52.6930, 'lng' => 7.2950,
            'summary' => 'Zentral in Meppen, nahe Klinik und Innenstadt. Monatsweise buchbar.',
            'description' => "Zwei-Zimmer-Wohnung im ruhigen Hinterhaus mit Arbeitsplatz und schnellem Internet.\n\nDie Klinik, Bahnhof und Innenstadt erreichen Sie zu Fuß oder mit dem Rad in wenigen Minuten.",
            'max_guests' => 2, 'bedrooms' => 1, 'single_beds' => 0, 'bathrooms' => 1, 'size_m2' => 48,
            'price_night' => 72, 'cleaning_fee' => 40, 'min_nights' => 2, 'weekly_discount' => 10, 'month_price' => 1290,
            'base_guests' => 2, 'extra_guest_fee' => 0, 'mode_urlaub' => 1, 'mode_business' => 1, 'mode_langzeit' => 1, 'wifi_mbit' => 250,
            'amenities' => ['wlan', 'schreibtisch', 'kueche', 'waschmaschine', 'selfcheckin', 'verdunkelung', 'fahrradgarage'],
            'images' => ['haus.jpg', 'dankern.jpg'], 'featured' => 1,
        ],
    ];

    $ins = $pdo->prepare('INSERT INTO listings (slug, title, type, place_id, lat, lng, summary, description, max_guests, bedrooms, single_beds, bathrooms, size_m2,
        price_night, cleaning_fee, min_nights, weekly_discount, month_price, base_guests, extra_guest_fee, mode_urlaub, mode_business, mode_langzeit,
        wifi_mbit, amenities, host_name, ical_token, featured)
        VALUES (:slug, :title, :type, :place_id, :lat, :lng, :summary, :description, :max_guests, :bedrooms, :single_beds, :bathrooms, :size_m2,
        :price_night, :cleaning_fee, :min_nights, :weekly_discount, :month_price, :base_guests, :extra_guest_fee, :mode_urlaub, :mode_business, :mode_langzeit,
        :wifi_mbit, :amenities, :host_name, :ical_token, :featured)');
    $img = $pdo->prepare('INSERT INTO listing_images (listing_id, file, alt, sort) VALUES (?, ?, ?, ?)');
    foreach ($listings as $l) {
        $images = $l['images'];
        $place = $l['place'];
        unset($l['images'], $l['place']);
        $l['place_id'] = $placeId($place);
        $l['amenities'] = json_encode($l['amenities']);
        $l['host_name'] = 'Beispiel-Gastgeber';
        $l['ical_token'] = bin2hex(random_bytes(16));
        $ins->execute($l);
        $id = (int)$pdo->lastInsertId();
        foreach ($images as $i => $file) {
            $img->execute([$id, 'demo:' . $file, $l['title'] . ' (Platzhalterbild)', $i]);
        }
    }

    $pages = [
        ['impressum', 'Impressum', "Angaben gemäß § 5 DDG\n\n[Name und Anschrift des Betreibers]\n\nKontakt: [Telefon, E-Mail]\n\nBitte im Admin-Bereich unter „Seiten“ vollständig ausfüllen."],
        ['datenschutz', 'Datenschutzerklärung', "Platzhalter. Bitte im Admin-Bereich unter „Seiten“ eine vollständige Datenschutzerklärung einfügen.\n\nDiese Webseite verwendet nur ein technisch notwendiges Session-Cookie, keine Tracking-Dienste und lädt keine Schriften oder Skripte von fremden Servern."],
        ['agb', 'Buchungsbedingungen', "Platzhalter für Ihre Buchungs- und Stornobedingungen.\n\nBitte im Admin-Bereich unter „Seiten“ anpassen."],
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO pages(slug, title, body) VALUES (?, ?, ?)');
    foreach ($pages as $p) {
        $st->execute($p);
    }

    $pdo->commit();
}
