<?php
// ─────────────────────────────────────────────
//  My Journal — Konfigurasi Database
// ─────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'my_journal');

// Upload config
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB
define('ALLOWED_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

if ($db->connect_error) {
    die('<div style="font-family:sans-serif;padding:40px;color:#c0392b">
        <h2>❌ Koneksi Database Gagal</h2>
        <p>' . htmlspecialchars($db->connect_error) . '</p>
    </div>');
}

// Buat folder uploads jika belum ada
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

// ─────────────────────────────────────────────
//  Auto-migrate: buat tabel jika belum ada, lalu tambah kolom baru
// ─────────────────────────────────────────────
function migrateDb(mysqli $db): void {
    // Cek dulu apakah tabel entries sudah ada
    $check = $db->query("SHOW TABLES LIKE 'entries'");
    if (!$check || $check->num_rows === 0) {
        // Tabel belum ada — buat sekaligus dengan semua kolom terbaru
        $db->query("CREATE TABLE IF NOT EXISTS `entries` (
            `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `title`       VARCHAR(255)    NOT NULL,
            `body`        TEXT            NOT NULL,
            `mood`        VARCHAR(10)     NOT NULL DEFAULT '😊',
            `tags`        VARCHAR(500)    NOT NULL DEFAULT '',
            `color`       VARCHAR(10)     NOT NULL DEFAULT '#f5ede0',
            `photos`      TEXT            NULL DEFAULT NULL,
            `archived`    TINYINT(1)      NOT NULL DEFAULT 0,
            `archived_at` DATETIME            NULL DEFAULT NULL,
            `trashed`     TINYINT(1)      NOT NULL DEFAULT 0,
            `deleted_at`  DATETIME            NULL DEFAULT NULL,
            `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                     ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_archived`   (`archived`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_trashed`    (`trashed`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return; // Kolom sudah lengkap, tidak perlu ALTER
    }

    // Tabel sudah ada — cek kolom yang mungkin belum ada (untuk upgrade)
    $res  = $db->query("SHOW COLUMNS FROM `entries`");
    $cols = [];
    while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];

    if (!in_array('photos', $cols)) {
        $db->query("ALTER TABLE `entries` ADD COLUMN `photos` TEXT NULL DEFAULT NULL AFTER `color`");
    }
    if (!in_array('trashed', $cols)) {
        $db->query("ALTER TABLE `entries` ADD COLUMN `trashed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `archived_at`");
        $db->query("ALTER TABLE `entries` ADD INDEX `idx_trashed` (`trashed`)");
    }
    if (!in_array('deleted_at', $cols)) {
        $db->query("ALTER TABLE `entries` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `trashed`");
    }
}
migrateDb($db);

// ─────────────────────────────────────────────
//  Helper Functions
// ─────────────────────────────────────────────

// DIPERBARUI: tambah parameter $search untuk filter SQL
function allEntries(mysqli $db, bool $archived = false, bool $trashed = false, string $search = ''): array {
    if ($trashed) {
        if ($search !== '') {
            $s = '%' . $db->real_escape_string($search) . '%';
            $res = $db->query("SELECT * FROM entries WHERE trashed = 1
                AND (title LIKE '$s' OR body LIKE '$s' OR tags LIKE '$s' OR mood LIKE '$s')
                ORDER BY deleted_at DESC");
        } else {
            $res = $db->query("SELECT * FROM entries WHERE trashed = 1 ORDER BY deleted_at DESC");
        }
    } else {
        $flag = $archived ? 1 : 0;
        if ($search !== '') {
            $s = '%' . $db->real_escape_string($search) . '%';
            $res = $db->query("SELECT * FROM entries WHERE archived = $flag AND trashed = 0
                AND (title LIKE '$s' OR body LIKE '$s' OR tags LIKE '$s' OR mood LIKE '$s')
                ORDER BY updated_at DESC");
        } else {
            $res = $db->query("SELECT * FROM entries WHERE archived = $flag AND trashed = 0 ORDER BY updated_at DESC");
        }
    }
    if (!$res) return [];
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}

function loadEntry(mysqli $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM entries WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function insertEntry(mysqli $db, array $d): int {
    $stmt = $db->prepare(
        "INSERT INTO entries (title, body, mood, tags, color, photos) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssssss', $d['title'], $d['body'], $d['mood'], $d['tags'], $d['color'], $d['photos']);
    $stmt->execute();
    return $db->insert_id;
}

function updateEntry(mysqli $db, array $d): void {
    $stmt = $db->prepare(
        "UPDATE entries SET title=?, body=?, mood=?, tags=?, color=?, photos=?, updated_at=NOW() WHERE id=?"
    );
    $stmt->bind_param('ssssssi', $d['title'], $d['body'], $d['mood'], $d['tags'], $d['color'], $d['photos'], $d['id']);
    $stmt->execute();
}

function trashEntry(mysqli $db, int $id): void {
    $stmt = $db->prepare("UPDATE entries SET trashed=1, deleted_at=NOW() WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function restoreEntry(mysqli $db, int $id): void {
    $stmt = $db->prepare("UPDATE entries SET trashed=0, deleted_at=NULL WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function deleteEntry(mysqli $db, int $id): void {
    $entry = loadEntry($db, $id);
    if ($entry && !empty($entry['photos'])) {
        $photos = json_decode($entry['photos'], true) ?: [];
        foreach ($photos as $p) {
            $path = UPLOAD_DIR . basename($p);
            if (file_exists($path)) unlink($path);
        }
    }
    $stmt = $db->prepare("DELETE FROM entries WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function archiveEntry(mysqli $db, int $id): void {
    $stmt = $db->prepare("UPDATE entries SET archived=1, archived_at=NOW() WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function unarchiveEntry(mysqli $db, int $id): void {
    $stmt = $db->prepare("UPDATE entries SET archived=0, archived_at=NULL WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function countEntries(mysqli $db, bool $archived = false): int {
    $flag = $archived ? 1 : 0;
    $res  = $db->query("SELECT COUNT(*) FROM entries WHERE archived = $flag AND trashed = 0");
    return (int) $res->fetch_row()[0];
}

function countTrashed(mysqli $db): int {
    $res = $db->query("SELECT COUNT(*) FROM entries WHERE trashed = 1");
    return (int) $res->fetch_row()[0];
}

function handlePhotoUploads(array $existingPhotos = []): array {
    $photos = $existingPhotos;
    if (empty($_FILES['photos']['name'][0])) return $photos;

    foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['photos']['size'][$i] > MAX_FILE_SIZE) continue;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        if (!in_array($mime, ALLOWED_TYPES)) continue;

        $ext  = pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION);
        $name = uniqid('photo_', true) . '.' . strtolower($ext);
        if (move_uploaded_file($tmp, UPLOAD_DIR . $name)) {
            $photos[] = UPLOAD_URL . $name;
        }
    }
    return $photos;
}

// BARU: Highlight kata kunci pencarian dalam teks
function highlightText(string $text, string $query): string {
    if ($query === '') return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $safeQ   = preg_quote(htmlspecialchars($query, ENT_QUOTES, 'UTF-8'), '/');
    return preg_replace(
        '/(' . $safeQ . ')/iu',
        '<mark class="search-highlight">$1</mark>',
        $escaped
    );
}

// ─────────────────────────────────────────────
//  Router
// ─────────────────────────────────────────────
$action   = $_GET['action']   ?? 'list';
$id       = (int)($_GET['id'] ?? 0);
$archived = isset($_GET['archived']);
$trashed  = isset($_GET['trashed']);
// BARU: tangkap query pencarian dari URL
$search   = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save') {
        $isEdit       = (int)($_POST['id'] ?? 0);
        $existPhotos  = [];
        if ($isEdit) {
            $cur = loadEntry($db, $isEdit);
            $existPhotos = $cur ? (json_decode($cur['photos'] ?? '[]', true) ?: []) : [];
            $removePhotos = $_POST['remove_photos'] ?? [];
            foreach ($removePhotos as $rp) {
                $path = UPLOAD_DIR . basename($rp);
                if (file_exists($path)) unlink($path);
                $existPhotos = array_values(array_filter($existPhotos, fn($p) => $p !== $rp));
            }
        }

        $photos = handlePhotoUploads($existPhotos);
        $data   = [
            'id'     => $isEdit,
            'title'  => trim($_POST['title'] ?? 'Untitled'),
            'body'   => trim($_POST['body']  ?? ''),
            'mood'   => $_POST['mood']  ?? '😊',
            'tags'   => trim($_POST['tags']  ?? ''),
            'color'  => $_POST['color'] ?? '#f5ede0',
            'photos' => json_encode(array_values($photos)),
        ];
        if ($data['id'] === 0) {
            $newId = insertEntry($db, $data);
            header('Location: index.php?action=view&id=' . $newId);
        } else {
            updateEntry($db, $data);
            header('Location: index.php?action=view&id=' . $data['id']);
        }
        exit;
    }

    if ($postAction === 'trash') {
        trashEntry($db, (int)$_POST['id']);
        $wasArch = !empty($_POST['archived']);
        header('Location: index.php' . ($wasArch ? '?archived' : ''));
        exit;
    }

    if ($postAction === 'restore') {
        restoreEntry($db, (int)$_POST['id']);
        header('Location: index.php?trashed');
        exit;
    }

    if ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        deleteEntry($db, $delId);
        header('Location: index.php?trashed');
        exit;
    }

    if ($postAction === 'archive') {
        archiveEntry($db, (int)$_POST['id']);
        header('Location: index.php');
        exit;
    }

    if ($postAction === 'unarchive') {
        unarchiveEntry($db, (int)$_POST['id']);
        header('Location: index.php');
        exit;
    }
}

// DIPERBARUI: teruskan $search ke allEntries
$entries = allEntries($db, $archived, $trashed, $search);
$entry   = null;
if (in_array($action, ['view', 'edit']) && $id) {
    $entry = loadEntry($db, $id);
}

// ─────────────────────────────────────────────
//  View Helpers
// ─────────────────────────────────────────────
function moods(): array {
    return ['😊','😌','😔','😤','🥰','😴','🤔','🌟','💭','✨'];
}
function cardColors(): array {
    return [
        '#f5ede0','#e8dcc8','#d4c5a9',
        '#c9d4c1','#b8c9b0','#a8bfa0',
        '#d4b8a8','#c9a090','#e8c4b8',
        '#e0d4c0','#f0e8d8','#dde8d5',
    ];
}
function tagColor(string $tag): string {
    $colors = ['#c9a090','#b8c9b0','#d4c5a9','#c9d4c1','#d4b8a8'];
    return $colors[abs(crc32($tag)) % count($colors)];
}
function excerpt(string $body, int $len = 120): string {
    $plain = strip_tags($body);
    return mb_strlen($plain) > $len ? mb_substr($plain, 0, $len) . '…' : $plain;
}
function fdate(?string $dt): string {
    if (!$dt) return '-';
    return date('d M Y, H:i', strtotime($dt));
}
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function getPhotos(?string $json): array {
    if (!$json) return [];
    return json_decode($json, true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Journal ✦</title>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400;600;700&family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=DM+Serif+Display:ital@0;1&family=Lora:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --cream:      #f7f0e6;
  --paper:      #f0e6d3;
  --brown-lt:   #c9a87c;
  --brown:      #8b6b4a;
  --brown-dk:   #5c3d1e;
  --sage:       #7a9e7e;
  --sage-lt:    #a8c5a0;
  --terracotta: #c4715b;
  --terra-lt:   #e8b4a0;
  --ink:        #2d2016;
  --ink-lt:     #5a4a35;
  --shadow:     rgba(93,61,30,.18);
  --radius:     12px;
  --radius-lg:  20px;
}

html { scroll-behavior: smooth; }

body {
  font-family: 'Lora', Georgia, serif;
  background: var(--cream);
  color: var(--ink);
  min-height: 100vh;
  overflow-x: hidden;
}

body::before {
  content: '';
  position: fixed; inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='400' height='400' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  pointer-events: none; z-index: 0;
}
body::after {
  content: '';
  position: fixed; inset: 0;
  background-image: repeating-linear-gradient(transparent,transparent 27px,rgba(139,107,74,.07) 27px,rgba(139,107,74,.07) 28px);
  pointer-events: none; z-index: 0;
}

/* ─── Sidebar ─── */
.sidebar {
  position: fixed; left: 0; top: 0; bottom: 0;
  width: 240px;
  background: var(--brown-dk);
  z-index: 100;
  display: flex; flex-direction: column;
  box-shadow: 4px 0 24px var(--shadow);
  overflow: hidden;
}
.sidebar-header {
  padding: 28px 24px 20px;
  border-bottom: 1px solid rgba(255,255,255,.1);
}
.sidebar-logo {
  font-family: 'Caveat', cursive;
  font-size: 2rem; font-weight: 700;
  color: var(--terra-lt); line-height: 1;
}
.sidebar-tagline {
  font-family: 'Lora', serif;
  font-size: .7rem; color: rgba(255,255,255,.45);
  margin-top: 4px; font-style: italic;
}
.sidebar-nav {
  flex: 1; padding: 16px 12px;
  display: flex; flex-direction: column; gap: 4px;
}
.nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: 10px;
  color: rgba(255,255,255,.7);
  text-decoration: none;
  font-family: 'Lora', serif; font-size: .88rem;
  transition: all .2s;
}
.nav-link:hover, .nav-link.active {
  background: rgba(255,255,255,.1); color: var(--terra-lt);
}
.nav-link .icon { font-size: 1.1rem; }
.nav-badge {
  margin-left: auto;
  background: var(--terracotta); color: #fff;
  font-size: .7rem; padding: 1px 7px; border-radius: 20px;
  font-family: 'Lora', serif;
}
.sidebar-footer {
  padding: 16px 24px;
  border-top: 1px solid rgba(255,255,255,.1);
  font-family: 'Caveat', cursive; font-size: .85rem;
  color: rgba(255,255,255,.3); text-align: center;
}

/* ─── Main ─── */
.main { margin-left: 240px; min-height: 100vh; position: relative; z-index: 1; }

.topbar {
  background: rgba(247,240,230,.92);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid rgba(139,107,74,.15);
  padding: 14px 32px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 50;
  gap: 16px;
}
.topbar-title {
  font-family: 'DM Serif Display', serif;
  font-size: 1.5rem; color: var(--brown-dk);
  white-space: nowrap;
}

/* ════════════════════════════════════════
   BARU — Search Bar
   ════════════════════════════════════════ */
.search-form {
  flex: 1;
  max-width: 400px;
  display: flex;
  align-items: center;
  position: relative;
}
.search-input {
  width: 100%;
  padding: 9px 40px 9px 38px;
  border: 1.5px solid rgba(139,107,74,.3);
  border-radius: 50px;
  background: rgba(255,255,255,.7);
  font-family: 'Lora', serif;
  font-size: .88rem;
  color: var(--ink);
  outline: none;
  transition: border-color .2s, box-shadow .2s, background .2s;
}
.search-input:focus {
  border-color: var(--brown-lt);
  box-shadow: 0 0 0 3px rgba(201,168,120,.2);
  background: rgba(255,255,255,.95);
}
.search-input::placeholder { color: var(--brown); opacity: .55; }
.search-icon {
  position: absolute; left: 13px;
  font-size: .95rem; pointer-events: none;
  color: var(--brown); opacity: .6;
}
.search-clear {
  position: absolute; right: 10px;
  background: none; border: none; cursor: pointer;
  font-size: 1rem; color: var(--brown); opacity: .5;
  padding: 4px; border-radius: 50%;
  display: none; line-height: 1;
  transition: opacity .15s;
}
.search-clear:hover { opacity: 1; }
.search-clear.visible { display: block; }

/* BARU — Info banner hasil pencarian */
.search-result-info {
  display: flex; align-items: center; gap: 8px;
  background: rgba(196,113,91,.08);
  border: 1px solid rgba(196,113,91,.2);
  border-radius: var(--radius);
  padding: 10px 16px; margin-bottom: 20px;
  font-family: 'Caveat', cursive; font-size: .95rem;
  color: var(--terracotta);
}
.search-result-info a {
  color: var(--brown); text-decoration: underline;
  margin-left: auto; font-size: .85rem;
}

/* BARU — Highlight teks yang cocok */
mark.search-highlight {
  background: rgba(196,113,91,.28);
  color: var(--brown-dk);
  border-radius: 3px;
  padding: 0 2px;
  font-style: normal;
}

/* ─── Buttons ─── */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; border-radius: 50px; border: none;
  cursor: pointer; font-family: 'Lora', serif;
  font-size: .88rem; font-weight: 500;
  text-decoration: none; transition: all .2s;
}
.btn-primary { background: var(--terracotta); color: #fff; box-shadow: 0 3px 12px rgba(196,113,91,.35); }
.btn-primary:hover { background: #b05e49; transform: translateY(-1px); }
.btn-sage    { background: var(--sage); color: #fff; box-shadow: 0 3px 12px rgba(122,158,126,.3); }
.btn-sage:hover { background: #618f66; transform: translateY(-1px); }
.btn-outline { background: transparent; color: var(--brown); border: 1.5px solid var(--brown-lt); }
.btn-outline:hover { background: var(--paper); }
.btn-danger  { background: #c0392b; color: #fff; }
.btn-danger:hover { background: #a93226; }
.btn-warn    { background: #e67e22; color: #fff; }
.btn-warn:hover { background: #ca6f1e; }
.btn-sm { padding: 6px 13px; font-size: .8rem; }

/* ─── Content ─── */
.content { padding: 32px; }

/* ─── Stats ─── */
.stats-strip { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
.stat-box {
  background: rgba(255,255,255,.55);
  border: 1px solid rgba(139,107,74,.15);
  border-radius: var(--radius);
  padding: 14px 20px; flex: 1; min-width: 110px;
  text-align: center; box-shadow: 0 2px 8px rgba(93,61,30,.06);
}
.stat-number { font-family: 'DM Serif Display', serif; font-size: 2rem; color: var(--terracotta); line-height: 1; }
.stat-label  { font-family: 'Caveat', cursive; font-size: .85rem; color: var(--brown); margin-top: 3px; }

/* ─── Cards ─── */
.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 24px;
}
.journal-card {
  border-radius: var(--radius-lg); padding: 22px;
  position: relative;
  box-shadow: 0 2px 8px var(--shadow), 0 8px 32px rgba(93,61,30,.08), inset 0 1px 0 rgba(255,255,255,.6);
  transition: transform .2s, box-shadow .2s;
  overflow: hidden; cursor: pointer;
  text-decoration: none; display: block; color: var(--ink);
}
.journal-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0;
  height: 3px; background: linear-gradient(90deg, var(--brown-lt), transparent); opacity: .5;
}
.journal-card::after {
  content: ''; position: absolute; top: -6px; right: 24px;
  width: 48px; height: 20px;
  background: rgba(201,168,120,.45); border-radius: 3px; transform: rotate(-2deg);
}
.journal-card:hover { transform: translateY(-4px) rotate(.3deg); box-shadow: 0 12px 40px rgba(93,61,30,.18); }

.card-photo-thumb {
  width: 100%; height: 140px; object-fit: cover;
  border-radius: 10px; margin-bottom: 10px;
  display: block;
}

.card-mood   { position: absolute; top: 16px; left: 18px; font-size: 1.4rem; line-height: 1; }
.card-date   { font-family: 'Caveat', cursive; font-size: .85rem; color: var(--brown); opacity: .7; margin-bottom: 8px; padding-left: 34px; }
.card-title  { font-family: 'Playfair Display', serif; font-size: 1.15rem; font-weight: 600; color: var(--brown-dk); margin-bottom: 8px; line-height: 1.3; }
.card-excerpt { font-size: .83rem; color: var(--ink-lt); line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.card-tags   { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 5px; }
.tag-pill    { font-family: 'Caveat', cursive; font-size: .78rem; padding: 2px 10px; border-radius: 20px; color: var(--brown-dk); }
.card-actions { margin-top: 14px; display: flex; gap: 7px; flex-wrap: wrap; }

/* ─── Empty state ─── */
.empty-state { text-align: center; padding: 64px 32px; color: var(--brown); opacity: .6; }
.empty-state .big-icon { font-size: 4rem; margin-bottom: 12px; }
.empty-state h3 { font-family: 'Caveat', cursive; font-size: 1.6rem; margin-bottom: 6px; }

/* ─── Section header ─── */
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
.section-header h2 { font-family: 'DM Serif Display', serif; font-size: 1.6rem; color: var(--brown-dk); font-style: italic; }

/* ─── Entry paper ─── */
.entry-wrapper { max-width: 720px; margin: 0 auto; }
.entry-paper {
  background: var(--paper); border-radius: var(--radius-lg);
  padding: 48px 52px;
  box-shadow: 0 4px 20px var(--shadow), 0 16px 60px rgba(93,61,30,.1), inset 0 1px 0 rgba(255,255,255,.7);
  position: relative; overflow: hidden;
}
.entry-paper::before {
  content: ''; position: absolute; left: 72px; top: 0; bottom: 0;
  width: 1.5px; background: rgba(196,113,91,.25);
}
.entry-paper::after {
  content: '◉\A◉\A◉\A◉\A◉\A◉\A◉\A◉\A◉\A◉\A◉';
  white-space: pre; position: absolute;
  left: 10px; top: 32px; font-size: .55rem;
  color: rgba(139,107,74,.3); line-height: 3.2; letter-spacing: 4px;
}

.entry-meta   { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; flex-wrap: wrap; }
.entry-mood-badge { font-size: 2rem; line-height: 1; }
.entry-date-info  { flex: 1; }
.entry-date-main  { font-family: 'Caveat', cursive; font-size: 1.1rem; color: var(--brown); }
.entry-date-sub   { font-size: .75rem; color: var(--ink-lt); opacity: .6; }
.entry-title  { font-family: 'DM Serif Display', serif; font-size: 2rem; color: var(--brown-dk); margin-bottom: 6px; line-height: 1.2; font-style: italic; }
.entry-tags   { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 24px; }
.entry-divider { border: none; border-top: 1px dashed rgba(139,107,74,.3); margin: 20px 0; }
.entry-body   { font-family: 'Lora', serif; font-size: 1rem; line-height: 2; color: var(--ink); white-space: pre-wrap; word-break: break-word; }
.entry-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 24px; }

/* ─── Photo Gallery (View) ─── */
.photo-gallery {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 10px; margin-top: 24px;
}
.photo-gallery a {
  display: block; border-radius: 10px; overflow: hidden;
  box-shadow: 0 2px 8px var(--shadow);
  transition: transform .2s;
}
.photo-gallery a:hover { transform: scale(1.03); }
.photo-gallery img {
  width: 100%; height: 160px; object-fit: cover; display: block;
}
.gallery-label {
  font-family: 'Caveat', cursive; font-size: 1rem;
  color: var(--brown); margin: 20px 0 10px;
  display: flex; align-items: center; gap: 6px;
}

/* ─── Form ─── */
.form-group    { margin-bottom: 20px; }
.form-label    { display: block; font-family: 'Caveat', cursive; font-size: 1rem; color: var(--brown); margin-bottom: 6px; }
.form-input, .form-textarea {
  width: 100%; padding: 11px 14px;
  border: 1.5px solid rgba(139,107,74,.3); border-radius: var(--radius);
  background: rgba(255,255,255,.6);
  font-family: 'Lora', serif; font-size: .95rem; color: var(--ink);
  outline: none; transition: border-color .2s, box-shadow .2s;
}
.form-input:focus, .form-textarea:focus {
  border-color: var(--brown-lt); box-shadow: 0 0 0 3px rgba(201,168,120,.2);
}
.form-textarea { min-height: 320px; resize: vertical; line-height: 2; }

/* ─── Photo Upload Area ─── */
.upload-area {
  border: 2px dashed rgba(139,107,74,.4);
  border-radius: var(--radius);
  padding: 28px 20px;
  text-align: center;
  cursor: pointer;
  transition: all .2s;
  background: rgba(255,255,255,.3);
  position: relative;
}
.upload-area:hover, .upload-area.dragover {
  border-color: var(--brown);
  background: rgba(201,168,120,.1);
}
.upload-area input[type="file"] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.upload-icon { font-size: 2rem; margin-bottom: 8px; }
.upload-text { font-family: 'Caveat', cursive; font-size: 1rem; color: var(--brown); }
.upload-hint { font-size: .78rem; color: var(--brown); opacity: .6; margin-top: 4px; }

.preview-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.preview-item {
  position: relative; width: 100px; height: 100px;
  border-radius: 10px; overflow: hidden;
  box-shadow: 0 2px 8px var(--shadow);
}
.preview-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.preview-remove {
  position: absolute; top: 4px; right: 4px;
  background: rgba(0,0,0,.6); color: #fff;
  border: none; border-radius: 50%; width: 20px; height: 20px;
  font-size: .75rem; cursor: pointer; display: flex;
  align-items: center; justify-content: center; line-height: 1;
}
.preview-remove:hover { background: #c0392b; }

.existing-photos { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.existing-photo {
  position: relative; width: 100px; height: 100px;
  border-radius: 10px; overflow: hidden;
  box-shadow: 0 2px 8px var(--shadow);
}
.existing-photo img { width: 100%; height: 100%; object-fit: cover; }
.existing-photo label {
  position: absolute; inset: 0;
  background: rgba(0,0,0,0); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .2s;
}
.existing-photo input[type="checkbox"] { display: none; }
.existing-photo input:checked + label { background: rgba(192,57,43,.65); }
.existing-photo input:checked + label::after { content: '🗑️'; font-size: 1.6rem; }

/* ─── Mood / Color picker ─── */
.mood-picker   { display: flex; flex-wrap: wrap; gap: 8px; }
.mood-btn {
  font-size: 1.5rem; background: rgba(255,255,255,.5);
  border: 2px solid transparent; border-radius: 10px;
  padding: 5px 9px; cursor: pointer; transition: all .15s; line-height: 1;
}
.mood-btn:hover { background: rgba(255,255,255,.9); transform: scale(1.1); }
.mood-btn.selected { border-color: var(--terracotta); background: rgba(196,113,91,.1); }

.color-picker  { display: flex; flex-wrap: wrap; gap: 8px; }
.color-swatch  {
  width: 32px; height: 32px; border-radius: 50%;
  border: 2px solid transparent; cursor: pointer; transition: transform .15s, border-color .15s;
}
.color-swatch:hover   { transform: scale(1.15); }
.color-swatch.selected { border-color: var(--brown-dk); }

/* ─── Breadcrumb ─── */
.breadcrumb {
  display: flex; align-items: center; gap: 8px;
  font-family: 'Caveat', cursive; font-size: .95rem; color: var(--brown); margin-bottom: 24px;
}
.breadcrumb a { color: var(--brown); text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }

/* ─── Hero desc ─── */
.hero-desc {
  background: rgba(255,255,255,.45);
  border: 1px solid rgba(139,107,74,.15);
  border-radius: var(--radius-lg);
  padding: 20px 24px; margin-bottom: 28px;
  position: relative; overflow: hidden;
}

/* ─── Modal ─── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(45,32,22,.5); z-index: 200;
  align-items: center; justify-content: center;
  backdrop-filter: blur(3px);
}
.modal-overlay.show { display: flex; }
.modal-box {
  background: var(--paper); border-radius: var(--radius-lg);
  padding: 36px; max-width: 400px; width: 90%;
  box-shadow: 0 20px 60px rgba(93,61,30,.3); text-align: center;
}
.modal-box h3 { font-family: 'DM Serif Display', serif; font-size: 1.4rem; color: var(--brown-dk); margin-bottom: 10px; }
.modal-box p  { color: var(--ink-lt); font-size: .9rem; margin-bottom: 24px; }
.modal-actions { display: flex; gap: 10px; justify-content: center; }

/* ─── Trash banner ─── */
.trash-banner {
  background: rgba(192,57,43,.08);
  border: 1px solid rgba(192,57,43,.2);
  border-radius: var(--radius);
  padding: 12px 18px; margin-bottom: 20px;
  font-family: 'Caveat', cursive; font-size: .95rem;
  color: #c0392b; display: flex; align-items: center; gap: 8px;
}

/* ─── Sticker / Badge ─── */
.sticker { position: absolute; font-size: 1.4rem; opacity: .3; pointer-events: none; transform: rotate(var(--r,-10deg)); }
.archived-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(139,107,74,.15); color: var(--brown);
  padding: 3px 10px; border-radius: 20px;
  font-family: 'Caveat', cursive; font-size: .82rem;
}
.trashed-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(192,57,43,.12); color: #c0392b;
  padding: 3px 10px; border-radius: 20px;
  font-family: 'Caveat', cursive; font-size: .82rem;
}

/* ─── Lightbox ─── */
#lightbox {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.85); z-index: 300;
  align-items: center; justify-content: center;
  cursor: zoom-out;
}
#lightbox.show { display: flex; }
#lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 10px; box-shadow: 0 0 60px rgba(0,0,0,.6); }
#lightbox-close {
  position: absolute; top: 20px; right: 28px;
  color: #fff; font-size: 2rem; cursor: pointer; background: none; border: none; line-height: 1;
}

/* ─── Animations ─── */
@keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
.animate  { animation: fadeUp .4s ease both; }
.delay-1  { animation-delay: .05s; }
.delay-2  { animation-delay: .10s; }
.delay-3  { animation-delay: .15s; }

/* ─── Responsive ─── */
@media (max-width: 768px) {
  .sidebar { width: 200px; } .main { margin-left: 200px; }
  .entry-paper { padding: 28px 24px 28px 40px; }
  .content { padding: 20px; }
  .topbar { flex-wrap: wrap; gap: 10px; }
  .search-form { max-width: 100%; order: 3; flex: 1 1 100%; }
}
@media (max-width: 560px) {
  .sidebar { position: fixed; bottom: 0; left: 0; right: 0; top: auto; height: 60px; width: 100%; flex-direction: row; }
  .sidebar-header, .sidebar-footer { display: none; }
  .sidebar-nav { flex-direction: row; justify-content: space-around; padding: 8px; gap: 0; width: 100%; }
  .nav-link span:not(.icon) { display: none; }
  .main { margin-left: 0; margin-bottom: 60px; }
}
</style>
</head>
<body>

<!-- ─── Sidebar ─── -->
<?php $trashedCount = countTrashed($db); ?>
<aside class="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">✦ My Journal</div>
    <div class="sidebar-tagline">tulis · kenang · abadikan</div>
  </div>
  <nav class="sidebar-nav">
    <a href="index.php" class="nav-link <?= (!$archived && !$trashed && $action==='list') ? 'active' : '' ?>">
      <span class="icon">📖</span><span>Semua Entri</span>
    </a>
    <a href="index.php?action=new" class="nav-link <?= $action==='new' ? 'active' : '' ?>">
      <span class="icon">✏️</span><span>Tulis Baru</span>
    </a>
    <a href="index.php?archived" class="nav-link <?= ($archived && !$trashed) ? 'active' : '' ?>">
      <span class="icon">🗂️</span><span>Arsip</span>
    </a>
    <a href="index.php?trashed" class="nav-link <?= $trashed ? 'active' : '' ?>">
      <span class="icon">🗑️</span><span>Sampah</span>
      <?php if ($trashedCount > 0): ?>
      <span class="nav-badge"><?= $trashedCount ?></span>
      <?php endif; ?>
    </a>
  </nav>
  <div class="sidebar-footer">Platform refleksi harianmu ✦</div>
</aside>

<!-- ─── Main ─── -->
<main class="main">

<?php if ($action === 'list'): ?>
<!-- ═══ LIST ═══ -->
<div class="topbar">
  <div class="topbar-title">
    <?php if ($trashed) echo '🗑️ Sampah';
    elseif ($archived) echo '🗂️ Arsip';
    else echo '📖 Jurnal Harianku'; ?>
  </div>

  <!-- ════ BARU: Search Bar ════ -->
  <form class="search-form" method="GET" action="index.php" id="searchForm">
    <?php if ($archived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
    <?php if ($trashed):  ?><input type="hidden" name="trashed"  value="1"><?php endif; ?>
    <span class="search-icon">🔍</span>
    <input
      type="text"
      name="q"
      id="searchInput"
      class="search-input"
      placeholder="Cari judul, isi, tag, mood… (tekan /)"
      value="<?= e($search) ?>"
      autocomplete="off"
      spellcheck="false"
    >
    <button type="button"
            class="search-clear <?= $search !== '' ? 'visible' : '' ?>"
            id="searchClear"
            title="Hapus pencarian">✕</button>
  </form>

  <?php if (!$archived && !$trashed): ?>
  <a href="index.php?action=new" class="btn btn-primary">✏️ Tulis Baru</a>
  <?php endif; ?>
</div>

<div class="content">

  <?php if ($trashed): ?>
  <div class="trash-banner">
    🗑️ Entri di sini sudah dihapus. Kamu bisa <strong>memulihkan</strong> atau <strong>menghapus permanen</strong>.
  </div>
  <?php endif; ?>

  <?php if (!$archived && !$trashed && $search === ''):
    $totalActive = countEntries($db, false);
    $totalArch   = countEntries($db, true);
    $moodRows    = $db->query("SELECT DISTINCT mood FROM entries WHERE archived=0 AND trashed=0")->fetch_all();
    $moodCount   = count($moodRows);
    $lastMood    = !empty($entries) ? $entries[0]['mood'] : '📖';
  ?>
  <div class="stats-strip animate">
    <div class="stat-box"><div class="stat-number"><?= $totalActive ?></div><div class="stat-label">Entri Aktif</div></div>
    <div class="stat-box"><div class="stat-number"><?= $totalArch ?></div><div class="stat-label">Di Arsip</div></div>
    <div class="stat-box"><div class="stat-number"><?= $moodCount ?></div><div class="stat-label">Mood Berbeda</div></div>
    <div class="stat-box"><div class="stat-number" style="font-size:1.5rem"><?= e($lastMood) ?></div><div class="stat-label">Terakhir</div></div>
  </div>
  <div class="hero-desc animate delay-1">
    <div style="position:absolute;top:-10px;right:16px;font-size:2.5rem;opacity:.15">📝</div>
    <p style="font-family:'Playfair Display',serif;font-style:italic;color:var(--brown-dk);font-size:1rem;line-height:1.7;max-width:600px">
      Platform ini memudahkan pengguna untuk menulis, menyunting, dan mengorganisir refleksi harian dalam satu wadah yang kreatif.
    </p>
    <div style="margin-top:10px;font-family:'Caveat',cursive;font-size:.88rem;color:var(--brown);opacity:.7">
      — Mulai dari halaman kosong, jadikan bermakna ✦
    </div>
  </div>
  <?php endif; ?>

  <!-- ════ BARU: Banner hasil pencarian ════ -->
  <?php if ($search !== ''):
    $baseUrl = $trashed ? 'index.php?trashed' : ($archived ? 'index.php?archived' : 'index.php');
  ?>
  <div class="search-result-info animate">
    🔍 <strong><?= count($entries) ?></strong> hasil untuk
    "<strong><?= e($search) ?></strong>"
    <a href="<?= $baseUrl ?>">✕ Hapus pencarian</a>
  </div>
  <?php endif; ?>

  <div class="section-header animate delay-2">
    <h2>
      <?php if ($search !== '')     echo 'Hasil Pencarian';
      elseif ($trashed)             echo 'Entri Terhapus';
      elseif ($archived)            echo 'Entri Tersimpan di Arsip';
      else                          echo 'Catatan Terbaru'; ?>
    </h2>
    <span style="font-family:'Caveat',cursive;font-size:.9rem;color:var(--brown);opacity:.6"><?= count($entries) ?> entri</span>
  </div>

  <?php if (empty($entries)): ?>
  <div class="empty-state animate delay-2">
    <div class="big-icon"><?= $search !== '' ? '🔍' : ($trashed ? '🗑️' : ($archived ? '🗂️' : '📔')) ?></div>
    <h3>
      <?php if ($search !== '')  echo 'Tidak ada hasil ditemukan';
      elseif ($trashed)          echo 'Sampah kosong';
      elseif ($archived)         echo 'Belum ada arsip';
      else                       echo 'Halaman masih kosong…'; ?>
    </h3>
    <p style="font-size:.9rem;margin-top:6px">
      <?php if ($search !== '')  echo 'Coba kata kunci lain atau periksa ejaanmu.';
      elseif ($trashed)          echo 'Tidak ada entri yang dihapus.';
      elseif ($archived)         echo 'Arsipkan entri dari halaman utama.';
      else                       echo 'Mulai tulis refleksi pertamamu hari ini.'; ?>
    </p>
    <?php if ($search !== ''): ?>
    <a href="<?= $baseUrl ?>" class="btn btn-outline" style="margin-top:16px">← Kembali ke semua entri</a>
    <?php elseif (!$archived && !$trashed): ?>
    <a href="index.php?action=new" class="btn btn-primary" style="margin-top:16px">✏️ Mulai Menulis</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($entries as $i => $e):
      $photos = getPhotos($e['photos'] ?? null);
      $thumb  = $photos[0] ?? null;
    ?>
    <div class="journal-card animate delay-<?= ($i%3)+1 ?>"
         style="background:<?= e($e['color'] ?? '#f5ede0') ?>"
         onclick="location.href='index.php?action=view&id=<?= (int)$e['id'] ?><?= $archived?'&archived':''; ?><?= $trashed?'&trashed':''; ?>'">

      <?php if ($thumb): ?>
      <img src="<?= e($thumb) ?>" class="card-photo-thumb" alt="foto" loading="lazy">
      <?php endif; ?>

      <div class="card-mood" <?= $thumb ? 'style="top:156px"' : '' ?>><?= e($e['mood'] ?? '📖') ?></div>
      <div class="card-date" style="<?= $thumb ? 'padding-top:4px' : '' ?>"><?= fdate($e['updated_at']) ?></div>

      <!-- BARU: judul & kutipan dengan highlight bila ada query -->
      <div class="card-title">
        <?= $search !== '' ? highlightText($e['title'], $search) : e($e['title']) ?>
      </div>
      <div class="card-excerpt">
        <?php $exc = excerpt($e['body']);
        echo $search !== '' ? highlightText($exc, $search) : e($exc); ?>
      </div>

      <?php if (!empty($e['tags'])): ?>
      <div class="card-tags">
        <?php foreach (array_slice(array_map('trim', explode(',', $e['tags'])), 0, 3) as $tag): ?>
        <span class="tag-pill" style="background:<?= tagColor($tag) ?>40;border:1px solid <?= tagColor($tag) ?>80">
          #<?= $search !== '' ? highlightText($tag, $search) : e($tag) ?>
        </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="card-actions" onclick="event.stopPropagation()">
        <a href="index.php?action=view&id=<?= (int)$e['id'] ?><?= $archived?'&archived':''; ?><?= $trashed?'&trashed':''; ?>" class="btn btn-outline btn-sm">👁 Baca</a>

        <?php if ($trashed): ?>
          <button onclick="confirmAction('restore','<?= (int)$e['id'] ?>',0,0,'Pulihkan entri ini?','Entri akan kembali ke daftar utama.')" class="btn btn-sage btn-sm">↩ Pulihkan</button>
          <button onclick="confirmAction('delete','<?= (int)$e['id'] ?>',0,0,'Hapus permanen?','Foto dan data tidak bisa dipulihkan.')" class="btn btn-danger btn-sm">🗑️ Hapus</button>
        <?php elseif ($archived): ?>
          <button onclick="confirmAction('unarchive','<?= (int)$e['id'] ?>',1,0,'Pulihkan entri ini?','Entri akan kembali ke daftar utama.')" class="btn btn-sage btn-sm">↩ Pulihkan</button>
          <button onclick="confirmAction('trash','<?= (int)$e['id'] ?>',1,0,'Hapus entri ini?','Entri akan dipindah ke sampah.')" class="btn btn-danger btn-sm">🗑️</button>
        <?php else: ?>
          <a href="index.php?action=edit&id=<?= (int)$e['id'] ?>" class="btn btn-sage btn-sm">✏️ Edit</a>
          <button onclick="confirmAction('archive','<?= (int)$e['id'] ?>',0,0,'Arsipkan entri ini?','Entri akan dipindah ke arsip.')" class="btn btn-outline btn-sm">🗂️</button>
          <button onclick="confirmAction('trash','<?= (int)$e['id'] ?>',0,0,'Hapus entri ini?','Entri akan dipindah ke sampah.')" class="btn btn-danger btn-sm">🗑️</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>


<?php elseif ($action === 'view' && $entry): ?>
<!-- ═══ VIEW ═══ -->
<?php $photos = getPhotos($entry['photos'] ?? null); ?>
<div class="topbar">
  <div class="topbar-title">Membaca Jurnal</div>
  <?php if (!$entry['archived'] && !$entry['trashed']): ?>
  <a href="index.php?action=edit&id=<?= (int)$entry['id'] ?>" class="btn btn-sage">✏️ Edit</a>
  <?php endif; ?>
</div>

<div class="content">
  <div class="entry-wrapper">
    <div class="breadcrumb animate">
      <a href="index.php<?= $entry['trashed'] ? '?trashed' : ($entry['archived']?'?archived':'') ?>">
        ← <?= $entry['trashed'] ? 'Sampah' : ($entry['archived'] ? 'Arsip' : 'Semua Entri') ?>
      </a>
    </div>

    <div class="entry-paper animate delay-1" style="background:<?= e($entry['color'] ?? '#f0e6d3') ?>">
      <div class="sticker" style="top:18px;right:30px;--r:8deg">🌿</div>
      <div class="sticker" style="bottom:24px;left:24px;--r:-12deg;font-size:1rem">✦</div>

      <div class="entry-meta">
        <div class="entry-mood-badge"><?= e($entry['mood'] ?? '📖') ?></div>
        <div class="entry-date-info">
          <div class="entry-date-main"><?= fdate($entry['created_at']) ?></div>
          <?php if ($entry['updated_at'] !== $entry['created_at']): ?>
          <div class="entry-date-sub">Diperbarui: <?= fdate($entry['updated_at']) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($entry['trashed']): ?>
        <span class="trashed-badge">🗑️ Dihapus <?= fdate($entry['deleted_at']) ?></span>
        <?php elseif ($entry['archived']): ?>
        <span class="archived-badge">🗂️ Diarsipkan <?= fdate($entry['archived_at']) ?></span>
        <?php endif; ?>
      </div>

      <h1 class="entry-title"><?= e($entry['title']) ?></h1>

      <?php if (!empty($entry['tags'])): ?>
      <div class="entry-tags">
        <?php foreach (array_map('trim', explode(',', $entry['tags'])) as $tag): ?>
        <span class="tag-pill" style="background:<?= tagColor($tag) ?>50;border:1px solid <?= tagColor($tag) ?>">#<?= e($tag) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <hr class="entry-divider">
      <div class="entry-body"><?= e($entry['body']) ?></div>

      <?php if (!empty($photos)): ?>
      <div class="gallery-label">📸 Foto (<?= count($photos) ?>)</div>
      <div class="photo-gallery">
        <?php foreach ($photos as $p): ?>
        <a href="#" onclick="openLightbox('<?= e($p) ?>');return false;">
          <img src="<?= e($p) ?>" alt="foto jurnal" loading="lazy">
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="entry-actions animate delay-2">
      <?php if ($entry['trashed']): ?>
        <button onclick="confirmAction('restore','<?= (int)$entry['id'] ?>',0,0,'Pulihkan entri ini?','Entri akan kembali ke daftar utama.')" class="btn btn-sage">↩ Pulihkan</button>
        <button onclick="confirmAction('delete','<?= (int)$entry['id'] ?>',0,0,'Hapus permanen?','Foto dan data tidak bisa dipulihkan.')" class="btn btn-danger">🗑️ Hapus Permanen</button>
      <?php elseif ($entry['archived']): ?>
        <button onclick="confirmAction('unarchive','<?= (int)$entry['id'] ?>',1,0,'Pulihkan entri ini?','Entri akan kembali ke daftar utama.')" class="btn btn-sage">↩ Pulihkan dari Arsip</button>
        <button onclick="confirmAction('trash','<?= (int)$entry['id'] ?>',1,0,'Hapus entri ini?','Entri akan dipindah ke sampah.')" class="btn btn-danger">🗑️ Hapus</button>
      <?php else: ?>
        <a href="index.php?action=edit&id=<?= (int)$entry['id'] ?>" class="btn btn-primary">✏️ Edit Entri</a>
        <button onclick="confirmAction('archive','<?= (int)$entry['id'] ?>',0,0,'Arsipkan entri ini?','Entri akan dipindah ke arsip.')" class="btn btn-outline">🗂️ Arsipkan</button>
        <button onclick="confirmAction('trash','<?= (int)$entry['id'] ?>',0,0,'Hapus entri ini?','Entri akan dipindah ke sampah.')" class="btn btn-danger">🗑️ Hapus</button>
      <?php endif; ?>
      <a href="index.php" class="btn btn-outline">← Kembali</a>
    </div>
  </div>
</div>


<?php elseif ($action === 'new' || ($action === 'edit' && $entry)): ?>
<!-- ═══ WRITE / EDIT ═══ -->
<?php
$isEdit     = ($action === 'edit' && $entry);
$curPhotos  = $isEdit ? getPhotos($entry['photos'] ?? null) : [];
?>
<div class="topbar">
  <div class="topbar-title"><?= $isEdit ? '✏️ Edit Entri' : '✏️ Tulis Entri Baru' ?></div>
</div>

<div class="content">
  <div class="entry-wrapper">
    <div class="breadcrumb animate">
      <a href="<?= $isEdit ? 'index.php?action=view&id='.(int)$entry['id'] : 'index.php' ?>">
        ← <?= $isEdit ? 'Kembali ke Entri' : 'Semua Entri' ?>
      </a>
    </div>

    <form method="POST" action="index.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
      <?php endif; ?>
      <input type="hidden" name="mood"  id="moodInput"  value="<?= e($entry['mood']  ?? '😊') ?>">
      <input type="hidden" name="color" id="colorInput" value="<?= e($entry['color'] ?? '#f5ede0') ?>">

      <div class="entry-paper animate delay-1" id="formPaper" style="background:<?= e($entry['color'] ?? '#f5ede0') ?>">

        <div class="form-group">
          <label class="form-label">📌 Judul Entri</label>
          <input type="text" name="title" class="form-input"
                 placeholder="Apa yang ingin kamu abadikan hari ini?"
                 value="<?= e($entry['title'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">😊 Mood Hari Ini</label>
          <div class="mood-picker">
            <?php foreach (moods() as $m): ?>
            <button type="button" class="mood-btn <?= ($entry['mood']??'😊')===$m?'selected':'' ?>"
                    onclick="selectMood('<?= $m ?>',this)"><?= $m ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">🏷️ Tags <span style="opacity:.5;font-size:.8rem">(pisah dengan koma)</span></label>
          <input type="text" name="tags" class="form-input"
                 placeholder="refleksi, syukur, alam, kerja…"
                 value="<?= e($entry['tags'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">🎨 Warna Kartu</label>
          <div class="color-picker">
            <?php foreach (cardColors() as $c): ?>
            <div class="color-swatch <?= ($entry['color']??'#f5ede0')===$c?'selected':'' ?>"
                 style="background:<?= $c ?>;border-color:<?= ($entry['color']??'#f5ede0')===$c?'var(--brown-dk)':'transparent' ?>"
                 onclick="selectColor('<?= $c ?>',this)"></div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">📸 Foto</label>

          <?php if (!empty($curPhotos)): ?>
          <p style="font-family:'Caveat',cursive;font-size:.85rem;color:var(--brown);margin-bottom:8px">
            Klik foto untuk menandai hapus:
          </p>
          <div class="existing-photos">
            <?php foreach ($curPhotos as $p): ?>
            <div class="existing-photo">
              <input type="checkbox" name="remove_photos[]" value="<?= e($p) ?>" id="rm_<?= md5($p) ?>">
              <label for="rm_<?= md5($p) ?>">
                <img src="<?= e($p) ?>" alt="foto">
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="upload-area" id="uploadArea">
            <input type="file" name="photos[]" id="photoInput" multiple accept="image/*">
            <div class="upload-icon">📷</div>
            <div class="upload-text">Klik atau seret foto ke sini</div>
            <div class="upload-hint">JPG, PNG, GIF, WEBP · Maks 10 MB per foto</div>
          </div>
          <div class="preview-grid" id="previewGrid"></div>
        </div>

        <hr class="entry-divider">

        <div class="form-group">
          <label class="form-label">📝 Isi Jurnal</label>
          <textarea name="body" class="form-textarea"
                    placeholder="Tuliskan pikiran, perasaan, atau momen hari ini di sini…"><?= e($entry['body'] ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button type="submit" class="btn btn-primary">💾 Simpan Entri</button>
          <a href="<?= $isEdit ? 'index.php?action=view&id='.(int)$entry['id'] : 'index.php' ?>" class="btn btn-outline">✕ Batal</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div class="content">
  <div class="empty-state">
    <div class="big-icon">🔍</div>
    <h3>Entri tidak ditemukan</h3>
    <a href="index.php" class="btn btn-primary" style="margin-top:16px">← Kembali</a>
  </div>
</div>
<?php endif; ?>

</main>

<!-- ─── Modal ─── -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal-box">
    <div style="font-size:2.5rem;margin-bottom:12px" id="modalIcon">⚠️</div>
    <h3 id="modalTitle">Konfirmasi</h3>
    <p id="modalDesc">Apakah kamu yakin?</p>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal()">✕ Batal</button>
      <button class="btn btn-danger" id="modalConfirm">Lanjutkan</button>
    </div>
  </div>
</div>

<!-- Hidden POST form -->
<form id="actionForm" method="POST" action="index.php" style="display:none">
  <input type="hidden" name="action"   id="formAction">
  <input type="hidden" name="id"       id="formId">
  <input type="hidden" name="archived" id="formArchived">
</form>

<!-- Lightbox -->
<div id="lightbox" onclick="closeLightbox()">
  <button id="lightbox-close" onclick="closeLightbox()">✕</button>
  <img id="lightbox-img" src="" alt="foto besar">
</div>

<script>
// ─── Mood & Color ───
const icons = { archive:'🗂️', unarchive:'↩', trash:'🗑️', restore:'↩', delete:'💥' };

function selectMood(mood, btn) {
  document.querySelectorAll('.mood-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('moodInput').value = mood;
}

function selectColor(color, swatch) {
  document.querySelectorAll('.color-swatch').forEach(s => { s.classList.remove('selected'); s.style.borderColor = 'transparent'; });
  swatch.classList.add('selected');
  swatch.style.borderColor = 'var(--brown-dk)';
  document.getElementById('colorInput').value = color;
  const p = document.getElementById('formPaper');
  if (p) p.style.background = color;
}

// ─── Modal ───
function confirmAction(action, id, archived, trashed, title, desc) {
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalDesc').textContent  = desc;
  document.getElementById('modalIcon').textContent  = icons[action] ?? '⚠️';
  const confirmBtn = document.getElementById('modalConfirm');
  confirmBtn.className = 'btn ' + (action === 'restore' || action === 'unarchive' ? 'btn-sage' : 'btn-danger');
  document.getElementById('modalOverlay').classList.add('show');
  confirmBtn.onclick = function() {
    document.getElementById('formAction').value   = action;
    document.getElementById('formId').value       = id;
    document.getElementById('formArchived').value = archived;
    document.getElementById('actionForm').submit();
  };
}

function closeModal() { document.getElementById('modalOverlay').classList.remove('show'); }
document.getElementById('modalOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal(); closeLightbox(); }
});

// ════════════════════════════════════════
// BARU — Search Bar Logic
// ════════════════════════════════════════
const searchInput = document.getElementById('searchInput');
const searchClear = document.getElementById('searchClear');
const searchForm  = document.getElementById('searchForm');

if (searchInput) {
  let debounceTimer;

  // Tampilkan/sembunyikan tombol ✕
  searchInput.addEventListener('input', function () {
    searchClear.classList.toggle('visible', this.value.length > 0);
    // Submit otomatis setelah berhenti mengetik 450ms
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => searchForm.submit(), 450);
  });

  // Klik tombol clear → kosongkan & submit
  searchClear.addEventListener('click', function () {
    searchInput.value = '';
    this.classList.remove('visible');
    clearTimeout(debounceTimer);
    searchForm.submit();
  });

  // Enter langsung submit tanpa debounce
  searchInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { clearTimeout(debounceTimer); searchForm.submit(); }
  });

  // Jika ada query aktif, posisikan kursor di ujung
  if (searchInput.value.length > 0) {
    searchInput.focus();
    searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
  }

  // Shortcut: tekan "/" di luar input → fokus ke search
  document.addEventListener('keydown', function (e) {
    if (
      e.key === '/' &&
      document.activeElement !== searchInput &&
      !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)
    ) {
      e.preventDefault();
      searchInput.focus();
      searchInput.select();
    }
  });
}

// ─── Photo Preview ───
const photoInput  = document.getElementById('photoInput');
const previewGrid = document.getElementById('previewGrid');
const uploadArea  = document.getElementById('uploadArea');

if (photoInput) {
  photoInput.addEventListener('change', renderPreviews);

  uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
  uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
  uploadArea.addEventListener('drop', e => {
    e.preventDefault(); uploadArea.classList.remove('dragover');
    const dt = new DataTransfer();
    if (photoInput.files) Array.from(photoInput.files).forEach(f => dt.items.add(f));
    Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/')).forEach(f => dt.items.add(f));
    photoInput.files = dt.files;
    renderPreviews();
  });
}

function renderPreviews() {
  if (!previewGrid) return;
  previewGrid.innerHTML = '';
  Array.from(photoInput.files).forEach((file, i) => {
    const reader = new FileReader();
    reader.onload = ev => {
      const item = document.createElement('div');
      item.className = 'preview-item';
      item.innerHTML = `<img src="${ev.target.result}" alt="preview">
        <button type="button" class="preview-remove" onclick="removePreview(${i})">✕</button>`;
      previewGrid.appendChild(item);
    };
    reader.readAsDataURL(file);
  });
}

function removePreview(idx) {
  const dt = new DataTransfer();
  Array.from(photoInput.files).filter((_, i) => i !== idx).forEach(f => dt.items.add(f));
  photoInput.files = dt.files;
  renderPreviews();
}

// ─── Lightbox ───
function openLightbox(src) {
  document.getElementById('lightbox-img').src = src;
  document.getElementById('lightbox').classList.add('show');
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('show');
  document.getElementById('lightbox-img').src = '';
}

// ─── Auto-resize textarea ───
document.querySelectorAll('.form-textarea').forEach(t => {
  t.addEventListener('input', () => { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; });
});
</script>
</body>
</html>