<?php
declare(strict_types=1);

/**
 * Titik masuk tunggal.
 *  - Tanpa ?aksi=  → halaman web (lib/view.php)
 *  - ?aksi=cari|pendidikan|mengajar|profil → JSON
 *  - ?aksi=unduh|unduh-daftar → file Excel (.xlsx)
 *  - ?aksi=cek → diagnosis (lokal, atau di Vercel bila APP_DEBUG=1)
 */

require __DIR__ . '/../lib/pddikti.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

// Di Vercel semua path diarahkan ke file ini; selain halaman utama dijawab 404 (mis. /favicon.ico).
if (is_vercel()) {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!in_array($path, ['/', '/index.php', '/api/index.php'], true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Halaman tidak ditemukan.';
        exit;
    }
}

function kirim_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function dosen_dari_permintaan(): array
{
    $d = dosen_from_token(trim((string) ($_GET['d'] ?? '')));
    if ($d === null) {
        throw new PddiktiException('Tautan tidak valid atau kedaluwarsa. Cari dosen lagi, lalu gunakan tautan dari hasil pencarian.', 403);
    }
    return $d;
}

function jalankan_endpoint(string $aksi): void
{
    try {
        if ($aksi === 'cek') {
            if (!debug_allowed())
                throw new PddiktiException('Tidak tersedia.', 404);
            $info = [
                'php' => PHP_VERSION,
                'ekstensi' => ['curl' => extension_loaded('curl'), 'mbstring' => extension_loaded('mbstring')],
                'di_vercel' => is_vercel(),
                'app_secret_diatur' => env_get('APP_SECRET') !== '',
                'cacert' => find_ca_file() ?: null,
                'tmp_dapat_ditulis' => is_writable(cache_dir()),
            ];
            try {
                http_call('GET', API_ROOT . '/pencarian/dosen/budi');
                $info['pddikti'] = 'terjangkau (HTTP 200)';
            } catch (PddiktiException $e) {
                $info['pddikti'] = ['gagal' => $e->getMessage()] + $e->extra;
            }
            kirim_json(200, $info);
            return;
        }

        if ($aksi === 'unduh' || $aksi === 'unduh-daftar') {
            if ($aksi === 'unduh') {
                $d = dosen_dari_permintaan();
                $sheets = excel_dosen($d);
                $file = 'riwayat-' . slug_nama($d['nama']) . '-' . tanggal_wib('Y-m-d') . '.xlsx';
            } else {
                $kw = trim((string) ($_GET['keyword'] ?? ''));
                $sheets = excel_daftar($kw);
                $file = 'daftar-dosen-pnl-' . slug_nama($kw) . '-' . tanggal_wib('Y-m-d') . '.xlsx';
            }
            xlsx_send(xlsx_build($sheets), $file);
            return;
        }

        if ($aksi === 'cari') {
            $data = cari_dosen(trim((string) ($_GET['keyword'] ?? '')));
        } elseif (in_array($aksi, ['profil', 'pendidikan', 'mengajar'], true)) {
            $data = detail_dosen($aksi, dosen_dari_permintaan());
        } else {
            throw new PddiktiException('Aksi tidak dikenal.', 400);
        }
        kirim_json(200, ['data' => $data, 'retrieved_at' => gmdate('c')]);
    } catch (PddiktiException $e) {
        $body = ['error' => $e->getMessage()];
        if (debug_on())
            $body += $e->extra;
        kirim_json($e->getCode() ?: 502, $body);
    }
}

$aksi = (string) ($_GET['aksi'] ?? '');
if ($aksi !== '') {
    jalankan_endpoint($aksi);
    exit;
}

require __DIR__ . '/../lib/view.php';