<?php
declare(strict_types=1);

/**
 * Logika PDDikti (endpoint internal situs, BUKAN API resmi), token dosen, dan ekspor Excel.
 * Dipakai oleh api/index.php. Tanpa penyimpanan bersama: aman untuk serverless (Vercel).
 */

const API_ROOT = 'https://pddikti.kemdiktisaintek.go.id/api';
const PT_MATCH = 'politekniknegerilhokseumawe';
const CACHE_CARI = 180;
const CACHE_DET = 600;

class PddiktiException extends RuntimeException
{
    public array $extra;
    public function __construct(string $message, int $status = 502, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->extra = $extra;
    }
}

function normalize(string $v): string
{
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($v), 'UTF-8')) ?? '';
}

function is_list_array(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

function records_from(mixed $v, int $depth = 0): array
{
    if ($depth > 6 || !is_array($v))
        return [];
    if (is_list_array($v))
        return array_values(array_filter($v, 'is_array'));
    foreach (['dosen', 'data', 'results', 'result', 'items', 'records', 'content'] as $k) {
        if (array_key_exists($k, $v)) {
            $r = records_from($v[$k], $depth + 1);
            if ($r)
                return $r;
        }
    }
    return [];
}

function scalar_str(mixed $v): string
{
    if (is_string($v) || is_numeric($v))
        return (string) $v;
    if (is_array($v)) {
        foreach (['nama_pt', 'nama', 'name'] as $k) {
            if (isset($v[$k]) && (is_string($v[$k]) || is_numeric($v[$k])))
                return (string) $v[$k];
        }
    }
    return '';
}

function pick(array $rec, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($rec[$k]) && (is_string($rec[$k]) || is_numeric($rec[$k])) && trim((string) $rec[$k]) !== '') {
            return trim((string) $rec[$k]);
        }
    }
    return '';
}

function is_pnl(array $rec): bool
{
    foreach (['nama_pt', 'nama_perguruan_tinggi', 'perguruan_tinggi', 'pt', 'nama_institusi'] as $k) {
        if (array_key_exists($k, $rec)) {
            $n = normalize(scalar_str($rec[$k]));
            if ($n !== '')
                return str_contains($n, PT_MATCH) || $n === 'pnl';
        }
    }
    return false;
}

function find_ca_file(): string
{
    $s = DIRECTORY_SEPARATOR;
    $cands = [
        getenv('PDDIKTI_CAINFO') ?: '',
        __DIR__ . $s . 'cacert.pem',
        (string) ini_get('curl.cainfo'),
        (string) ini_get('openssl.cafile'),
        'C:' . $s . 'laragon' . $s . 'etc' . $s . 'ssl' . $s . 'cacert.pem',
        'C:' . $s . 'xampp' . $s . 'apache' . $s . 'bin' . $s . 'curl-ca-bundle.crt',
        'C:' . $s . 'xampp' . $s . 'php' . $s . 'extras' . $s . 'ssl' . $s . 'cacert.pem',
    ];
    foreach ($cands as $c) {
        if ($c !== '' && is_file($c) && is_readable($c))
            return $c;
    }
    return '';
}

function http_call(string $method, string $url, ?array $json = null): array
{
    if (!function_exists('curl_init')) {
        throw new PddiktiException('Ekstensi cURL belum aktif. Aktifkan extension=curl di php.ini lalu restart server.', 503);
    }
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'Origin: https://pddikti.kemdiktisaintek.go.id',
        'Referer: https://pddikti.kemdiktisaintek.go.id/',
    ];
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36',
    ];
    if (($ca = find_ca_file()) !== '')
        $opt[CURLOPT_CAINFO] = $ca;
    if ($method === 'POST') {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($json ?? new stdClass(), JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    $opt[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('PDDikti curl error: ' . $err);
        throw new PddiktiException(
            'Koneksi aman ke PDDikti gagal. Periksa internet dan sertifikat CA (letakkan cacert.pem di folder ini).',
            502,
            ['curl_error' => $err]
        );
    }
    $payload = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300 || !is_array($payload)) {
        $msg = match (true) {
            $status === 403 => 'PDDikti menolak permintaan (HTTP 403).',
            $status === 429, $status === 503 => 'PDDikti sedang membatasi permintaan. Coba lagi beberapa saat.',
            $status >= 200 && $status < 300 => 'PDDikti mengirim respons yang tidak dapat dibaca.',
            default => 'PDDikti gagal memproses permintaan (HTTP ' . $status . ').',
        };
        throw new PddiktiException($msg, 502, ['upstream_status' => $status, 'response_preview' => mb_substr((string) $raw, 0, 400)]);
    }
    return $payload;
}

/* ---------- cache file ---------- */
function cache_dir(): string
{
    static $d = null;
    if ($d === null) {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dosen-pnl-cache';
        if (!is_dir($d))
            @mkdir($d, 0700, true);
    }
    return $d;
}

function cache_get(string $key, int $ttl): ?array
{
    $f = cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    if (is_file($f) && filemtime($f) > time() - $ttl) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d))
            return $d;
    }
    return null;
}

function cache_put(string $key, array $data): void
{
    if (is_writable(cache_dir())) {
        @file_put_contents(
            cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

/* ---------- lingkungan, rahasia aplikasi, dan token dosen (stateless) ---------- */

function env_get(string $k): string
{
    $v = getenv($k);
    if ($v === false || $v === '')
        $v = $_SERVER[$k] ?? $_ENV[$k] ?? '';
    return is_string($v) ? $v : '';
}

function is_vercel(): bool
{
    return env_get('VERCEL') !== '' || env_get('VERCEL_ENV') !== '' || env_get('VERCEL_URL') !== ''
        || str_starts_with(__DIR__, '/var/task');
}

/** Debug (?debug=1, ?raw=1, ?aksi=cek) otomatis aktif di lokal; di Vercel hanya jika APP_DEBUG=1. */
function debug_allowed(): bool
{
    return !is_vercel() || env_get('APP_DEBUG') === '1';
}

function debug_on(): bool
{
    return isset($_GET['debug']) && debug_allowed();
}

function app_secret(): string
{
    $s = env_get('APP_SECRET');
    if ($s !== '')
        return $s;
    if (is_vercel()) {
        throw new PddiktiException('APP_SECRET belum diatur di Environment Variables Vercel.', 500);
    }
    // Lokal: rahasia acak yang disimpan di folder temp.
    $f = cache_dir() . DIRECTORY_SEPARATOR . 'app-secret.txt';
    $s = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        if (@file_put_contents($f, $s, LOCK_EX) === false)
            $s = hash('sha256', __FILE__ . php_uname());
    }
    return $s;
}

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64u_dec(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/'), true);
}

/**
 * Token bertanda tangan (HMAC) berisi data ringkas satu dosen PNL hasil pencarian.
 * Pengganti daftar "dosen yang boleh dibuka" di file: tidak butuh penyimpanan bersama.
 */
function dosen_token(array $d): string
{
    $payload = b64u((string) json_encode(
        [$d['id'], $d['nama'], $d['nidn'], $d['nuptk'], $d['nama_prodi']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    return $payload . '.' . b64u(hash_hmac('sha256', $payload, app_secret(), true));
}

function dosen_from_token(string $t): ?array
{
    if ($t === '' || strlen($t) > 2000 || substr_count($t, '.') !== 1)
        return null;
    [$payload, $sig] = explode('.', $t);
    if (!hash_equals(b64u(hash_hmac('sha256', $payload, app_secret(), true)), $sig))
        return null;
    $raw = b64u_dec($payload);
    $a = $raw === false ? null : json_decode($raw, true);
    if (!is_array($a) || count($a) !== 5)
        return null;
    return [
        'id' => (string) $a[0],
        'nama' => (string) $a[1],
        'nidn' => (string) $a[2],
        'nuptk' => (string) $a[3],
        'nama_prodi' => (string) $a[4]
    ];
}

function with_tokens(array $rows): array
{
    return array_map(static fn(array $d): array => $d + ['token' => dosen_token($d)], $rows);
}

/* ---------- operasi utama ---------- */

/** Cari dosen PNL berdasarkan nama. */
function cari_dosen(string $keyword): array
{
    $len = mb_strlen($keyword);
    if ($len < 3 || $len > 120 || preg_match('/[\x00-\x1F\x7F]/', $keyword)) {
        throw new PddiktiException('Masukkan nama dosen minimal 3 karakter.', 400);
    }
    $ckey = 'cari:' . mb_strtolower($keyword, 'UTF-8');
    if (($hit = cache_get($ckey, CACHE_CARI)) !== null)
        return with_tokens($hit);

    $urls = [
        API_ROOT . '/pencarian/dosen/' . rawurlencode($keyword),
        API_ROOT . '/pencarian/en/call/' . rawurlencode($keyword),
    ];
    $records = null;
    $lastErr = null;
    foreach ($urls as $u) {
        try {
            $records = records_from(http_call('GET', $u));
            if ($records)
                break;
        } catch (PddiktiException $e) {
            $lastErr = $e;
        }
    }
    if ($records === null)
        throw $lastErr ?? new PddiktiException('Pencarian gagal.');

    $hasil = [];
    foreach ($records as $rec) {
        if (!is_pnl($rec))
            continue;
        $id = pick($rec, ['id', 'id_dosen', 'id_sdm', 'id_person']);
        $nama = pick($rec, ['nama', 'nama_dosen']);
        if ($id === '' || $nama === '')
            continue;
        $hasil[] = [
            'id' => $id,
            'nama' => $nama,
            'nidn' => pick($rec, ['nidn']),
            'nuptk' => pick($rec, ['nuptk']),
            'nama_prodi' => pick($rec, ['nama_prodi', 'prodi']),
        ];
    }
    cache_put($ckey, $hasil);
    return with_tokens($hasil);
}

/** Ambil detail: 'profil' | 'pendidikan' | 'mengajar'. $dosen harus hasil dosen_from_token(). */
function detail_dosen(string $aksi, array $dosen): array
{
    static $routes = [
    'profil' => '/dosen/profile',
    'pendidikan' => '/dosen/study-history',
    'mengajar' => '/dosen/teaching-history',
    ];
    if (!isset($routes[$aksi]))
        throw new PddiktiException('Aksi tidak dikenal.', 400);
    $id = (string) ($dosen['id'] ?? '');
    if ($id === '' || strlen($id) > 200 || preg_match('/[\x00-\x20\x7F]/', $id)) {
        throw new PddiktiException('ID dosen tidak valid.', 400);
    }
    $ckey = $aksi . ':' . $id;
    if (($hit = cache_get($ckey, CACHE_DET)) !== null)
        return $hit;

    $payload = http_call('POST', API_ROOT . $routes[$aksi], ['id' => $id]);
    cache_put($ckey, $payload);
    return $payload;
}

/* ---------- normalisasi untuk tampilan ---------- */

function normalisasi_pendidikan(array $rec): array
{
    return [
        'jenjang' => pick($rec, ['jenjang', 'nama_jenjang_didik', 'jenjang_pendidikan', 'nama_jenjang']),
        'gelar' => pick($rec, ['gelar', 'singkatan_gelar', 'nama_gelar']),
        'bidang' => pick($rec, ['bidang', 'bidang_studi', 'nama_prodi', 'program_studi', 'prodi']),
        'pt' => pick($rec, ['nama_pt', 'perguruan_tinggi', 'nama_perguruan_tinggi']),
        'tahun' => pick($rec, ['tahun_lulus', 'tahun', 'tgl_lulus']),
    ];
}

function normalisasi_mengajar(array $rec): array
{
    return [
        'mk' => pick($rec, ['nama_mk', 'nama_mata_kuliah', 'mata_kuliah']),
        'prodi' => pick($rec, ['nama_prodi', 'prodi']),
        'semester' => pick($rec, ['nama_semester', 'semester', 'id_smt']),
        'sks' => pick($rec, ['sks']),
    ];
}

/** Ratakan satu tingkat: nilai skalar dipertahankan, array bersarang jadi "induk_anak". */
function flatten_scalars(array $rec): array
{
    $out = [];
    foreach ($rec as $k => $v) {
        if (is_string($v) || is_numeric($v) || is_bool($v)) {
            $out[(string) $k] = is_bool($v) ? ($v ? 'Ya' : 'Tidak') : trim((string) $v);
        } elseif (is_array($v) && !is_list_array($v)) {
            foreach ($v as $k2 => $v2) {
                if (is_string($v2) || is_numeric($v2))
                    $out[$k . '_' . $k2] = trim((string) $v2);
            }
        }
    }
    return $out;
}

function label_kolom(string $key): string
{
    static $map = [
    'nama_mk' => 'Mata kuliah',
    'nama_mata_kuliah' => 'Mata kuliah',
    'mata_kuliah' => 'Mata kuliah',
    'nm_mk' => 'Mata kuliah',
    'nama_matkul' => 'Mata kuliah',
    'matkul' => 'Mata kuliah',
    'kode_mk' => 'Kode',
    'kode_mata_kuliah' => 'Kode',
    'kd_mk' => 'Kode',
    'nama_kelas' => 'Kelas',
    'kelas' => 'Kelas',
    'nm_kls' => 'Kelas',
    'nama_kls' => 'Kelas',
    'nama_prodi' => 'Prodi',
    'prodi' => 'Prodi',
    'program_studi' => 'Prodi',
    'nama_pt' => 'Perguruan tinggi',
    'sks' => 'SKS',
    'sks_mk' => 'SKS',
    'jml_mhs' => 'Mahasiswa',
    'jumlah_mahasiswa' => 'Mahasiswa',
    ];
    return $map[$key] ?? ucfirst(trim(str_replace('_', ' ', $key)));
}

function is_semester_key(string $k): bool
{
    return (bool) preg_match('/semester|(^|_)smt($|_)/i', $k);
}

function looks_like_id(string $v): bool
{
    return (bool) (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $v) || (strlen($v) >= 24 && !str_contains($v, ' ')));
}

/**
 * Susun riwayat mengajar: kelompokkan per semester, kolom ditentukan otomatis dari data.
 * @return array{groups: array<string, list<array<string,string>>>, cols: list<string>}
 */
function susun_mengajar(array $records): array
{
    $rows = array_map('flatten_scalars', $records);

    // Kunci semester (pilih yang nilainya berupa teks seperti "2025/2026 Ganjil" bila ada)
    $semKey = null;
    foreach ($rows as $r) {
        foreach ($r as $k => $v) {
            if (is_semester_key($k) && $v !== '') {
                if ($semKey === null || (preg_match('/[A-Za-z]/', $v) && !preg_match('/[A-Za-z]/', $r[$semKey] ?? '')))
                    $semKey = $k;
            }
        }
        if ($semKey !== null)
            break;
    }

    // Kolom: semua kunci yang punya isi, tanpa kolom semester dan kolom ID teknis
    $cols = [];
    foreach ($rows as $r) {
        foreach ($r as $k => $v) {
            if ($v === '' || is_semester_key($k))
                continue;
            $cols[$k] ??= ['n' => 0, 'id' => 0];
            $cols[$k]['n']++;
            if (looks_like_id($v))
                $cols[$k]['id']++;
        }
    }
    $cols = array_keys(array_filter($cols, static fn(array $c): bool => $c['id'] < $c['n']));

    // Urutan kolom: kode, mata kuliah, kelas, prodi, lalu SKS dan sisanya
    usort($cols, static function (string $a, string $b): int {
        $rank = static function (string $k): int {
            return match (true) {
                stripos($k, 'sks') !== false => 8,
                (bool) preg_match('/jml|jumlah/i', $k) => 9,
                stripos($k, 'kode') !== false || stripos($k, 'kd_') === 0 => 0,
                (bool) preg_match('/mata|matkul|(^|_)mk($|_)/i', $k) => 1,
                (bool) preg_match('/kelas|kls/i', $k) => 2,
                stripos($k, 'prodi') !== false => 3,
                default => 5,
            };
        };
        return $rank($a) <=> $rank($b);
    });

    $groups = [];
    foreach ($rows as $r) {
        $g = $semKey !== null && ($r[$semKey] ?? '') !== '' ? $r[$semKey] : 'Semester tidak diketahui';
        $groups[$g][] = $r;
    }
    return ['groups' => $groups, 'cols' => $cols];
}

/** Urutkan riwayat pendidikan dari yang terbaru. */
function urut_pendidikan(array $recs): array
{
    usort($recs, static function (array $a, array $b): int {
        $ya = (int) preg_replace('/\D/', '', normalisasi_pendidikan($a)['tahun']);
        $yb = (int) preg_replace('/\D/', '', normalisasi_pendidikan($b)['tahun']);
        return $yb <=> $ya;
    });
    return $recs;
}

/* ---------- ekspor Excel (.xlsx) tanpa library tambahan dan tanpa ekstensi zip ---------- */

function xlsx_col(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26))
        $s = chr(65 + ($n - 1) % 26) . $s;
    return $s;
}

function xlsx_esc(string $v): string
{
    $v = mb_scrub($v, 'UTF-8');
    $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v) ?? '';
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_sheet_name(string $n, array $used): string
{
    $n = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $n) ?? '');
    $n = mb_substr($n === '' ? 'Sheet' : $n, 0, 31);
    $base = $n;
    $i = 2;
    while (in_array(mb_strtolower($n), $used, true))
        $n = mb_substr($base, 0, 28) . ' ' . $i++;
    return $n;
}

/**
 * Kemas berkas menjadi zip (metode "store", tanpa kompresi) murni di PHP.
 * Cukup untuk .xlsx berukuran kecil dan tidak bergantung pada ekstensi zip/zlib.
 * @param array<string,string> $files nama berkas => isi
 */
function zip_store(array $files): string
{
    $t = time();
    $dosTime = ((int) date('G', $t) << 11) | ((int) date('i', $t) << 5) | ((int) date('s', $t) >> 1);
    $dosDate = (((int) date('Y', $t) - 1980) << 9) | ((int) date('n', $t) << 5) | (int) date('j', $t);

    $out = '';
    $central = '';
    $count = 0;
    foreach ($files as $name => $data) {
        $name = (string) $name;
        $crc = crc32($data);
        $size = strlen($data);
        $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $count++;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
}

/**
 * @param list<array{name:string, rows:list<list<mixed>>, header?:bool, label_col?:bool}> $sheets
 *   header: baris pertama dijadikan judul kolom (beku + filter). label_col: kolom A dicetak tebal.
 *   Nilai int/float disimpan sebagai angka, lainnya sebagai teks (NIDN tidak kehilangan angka 0 di depan).
 * @return string path file sementara berisi .xlsx (hapus setelah dipakai)
 */
function xlsx_build(array $sheets): string
{
    // Pengumpul isi file; dikemas ke zip di akhir oleh zip_store() (tidak butuh ekstensi zip).
    $zip = new class {
        public array $f = [];
        public function addFromString(string $name, string $content): void
        {
            $this->f[$name] = $content;
        }
    };
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    $names = [];
    $ct = '';
    $wbSheets = '';
    $wbRels = '';
    foreach (array_values($sheets) as $si => $sh) {
        $n = $si + 1;
        $name = xlsx_sheet_name($sh['name'], $names);
        $names[] = mb_strtolower($name);
        $rows = $sh['rows'];
        $hasHeader = $sh['header'] ?? true;
        $labelCol = $sh['label_col'] ?? false;

        $w = [];
        foreach ($rows as $r)
            foreach (array_values($r) as $ci => $v) {
                $len = mb_strlen((string) $v);
                $w[$ci] = max($w[$ci] ?? 0, $len);
            }
        $cols = '';
        foreach ($w as $ci => $len) {
            $width = min(60, max(10, $len + 2));
            $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $width . '" customWidth="1"/>';
        }

        $data = '';
        foreach ($rows as $ri => $r) {
            $data .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                if ($v === null || $v === '')
                    continue;
                $ref = xlsx_col($ci) . ($ri + 1);
                $style = ($hasHeader && $ri === 0) ? 1 : (($labelCol && $ci === 0) ? 2 : 0);
                if (is_int($v) || is_float($v)) {
                    $data .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
                } else {
                    $data .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string) $v) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $lastCol = xlsx_col(max(0, count($w) - 1));
        $pane = $hasHeader && count($rows) > 0
            ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '';
        $filter = $hasHeader && count($rows) > 1
            ? '<autoFilter ref="A1:' . $lastCol . count($rows) . '"/>' : '';

        $zip->addFromString("xl/worksheets/sheet$n.xml", $xml .
            '<worksheet xmlns="' . $ns . '"><sheetViews><sheetView workbookViewId="0"' . ($si === 0 ? ' tabSelected="1"' : '') . '>' . $pane . '</sheetView></sheetViews>' .
            '<sheetFormatPr defaultRowHeight="15"/>' . ($cols !== '' ? "<cols>$cols</cols>" : '') .
            "<sheetData>$data</sheetData>$filter</worksheet>");

        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="' . $rel . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $sn = count($sheets) + 1;
    $wbRels .= '<Relationship Id="rId' . $sn . '" Type="' . $rel . '/styles" Target="styles.xml"/>';

    $zip->addFromString('[Content_Types].xml', $xml .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        $ct . '</Types>');
    $zip->addFromString('_rels/.rels', $xml .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $xml .
        '<workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets>' . $wbSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $xml .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
    $zip->addFromString('xl/styles.xml', $xml .
        '<styleSheet xmlns="' . $ns . '">' .
        '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
        '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4D3F"/><bgColor indexed="64"/></patternFill></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="3">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top"/></xf>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>' .
        '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top"/></xf>' .
        '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    // [Content_Types].xml dan berkas inti diletakkan paling depan, sesuai kebiasaan paket OOXML.
    $prio = ['[Content_Types].xml' => 0, '_rels/.rels' => 1, 'xl/workbook.xml' => 2, 'xl/_rels/workbook.xml.rels' => 3, 'xl/styles.xml' => 4];
    $files = $zip->f;
    uksort($files, static fn(string $a, string $b): int => (($prio[$a] ?? 9) <=> ($prio[$b] ?? 9)) ?: strcmp($a, $b));

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false || file_put_contents($tmp, zip_store($files)) === false) {
        throw new PddiktiException('Gagal membuat file Excel sementara.', 500);
    }
    return $tmp;
}

/** Kirim file xlsx ke browser lalu hapus file sementaranya. */
function xlsx_send(string $path, string $filename): void
{
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'data.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
}

function slug_nama(string $s): string
{
    $s = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
    return mb_substr($s !== '' ? $s : 'dosen', 0, 50);
}

function tanggal_wib(string $fmt): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format($fmt);
}

/** Susun workbook riwayat satu dosen: Profil, Riwayat Pendidikan, Riwayat Mengajar. */
function excel_dosen(array $dosen): array
{
    $pend = urut_pendidikan(records_from(detail_dosen('pendidikan', $dosen)));
    $meng = records_from(detail_dosen('mengajar', $dosen));

    $profil = [
        ['Nama', $dosen['nama']],
        ['NIDN', $dosen['nidn']],
        ['NUPTK', $dosen['nuptk']],
        ['Program studi', $dosen['nama_prodi']],
        ['Perguruan tinggi', 'Politeknik Negeri Lhokseumawe'],
        ['Sumber data', 'Situs publik PDDikti (bukan arsip kepegawaian kampus)'],
        ['Diunduh', tanggal_wib('d-m-Y H:i') . ' WIB'],
    ];

    $rowsP = [['Jenjang', 'Gelar', 'Bidang / Prodi', 'Perguruan Tinggi', 'Tahun Lulus']];
    $dipakai = false;
    foreach ($pend as $rec) {
        $p = normalisasi_pendidikan($rec);
        if (!array_filter($p))
            continue;
        $dipakai = true;
        $tahun = $p['tahun'];
        $rowsP[] = [$p['jenjang'], $p['gelar'], $p['bidang'], $p['pt'], ctype_digit($tahun) ? (int) $tahun : $tahun];
    }
    if (!$dipakai && $pend) { // nama field tidak dikenali: pakai semua kolom yang ada
        $flat = array_map('flatten_scalars', $pend);
        $cols = [];
        foreach ($flat as $r)
            foreach ($r as $k => $v)
                if ($v !== '' && !looks_like_id($v))
                    $cols[$k] = true;
        $cols = array_keys($cols);
        $rowsP = [array_map('label_kolom', $cols)];
        foreach ($flat as $r)
            $rowsP[] = array_map(static fn(string $c): string => $r[$c] ?? '', $cols);
    }

    $s = susun_mengajar($meng);
    $rowsM = [array_merge(['Semester'], array_map('label_kolom', $s['cols']))];
    foreach ($s['groups'] as $sem => $baris) {
        foreach ($baris as $r) {
            $line = [(string) $sem];
            foreach ($s['cols'] as $c) {
                $v = $r[$c] ?? '';
                $line[] = ($v !== '' && preg_match('/sks|jml|jumlah/i', $c) && preg_match('/^\d+$/', $v)) ? (int) $v : $v;
            }
            $rowsM[] = $line;
        }
    }

    return [
        ['name' => 'Profil', 'rows' => $profil, 'header' => false, 'label_col' => true],
        ['name' => 'Riwayat Pendidikan', 'rows' => $rowsP, 'header' => true],
        ['name' => 'Riwayat Mengajar', 'rows' => $rowsM, 'header' => true],
    ];
}

/** Workbook daftar hasil pencarian dosen PNL. */
function excel_daftar(string $keyword): array
{
    $rows = [['Nama', 'NIDN', 'NUPTK', 'Program Studi']];
    foreach (cari_dosen($keyword) as $d)
        $rows[] = [$d['nama'], $d['nidn'], $d['nuptk'], $d['nama_prodi']];
    return [['name' => 'Daftar Dosen PNL', 'rows' => $rows, 'header' => true]];
}