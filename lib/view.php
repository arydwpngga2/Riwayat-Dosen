<?php
declare(strict_types=1);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$q = trim((string) ($_GET['q'] ?? ''));
$tok = trim((string) ($_GET['d'] ?? ''));

// Lokasi aset statis: di Vercel disajikan dari /assets (folder public/), di lokal dari public/assets.
$assets = is_vercel()
    ? '/assets'
    : rtrim((string) preg_replace('#/api$#', '', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/public/assets';

$view = 'home';
$error = null;
$hasil = [];
$dosen = null;
$pend = [];
$meng = [];
$errPend = null;
$errMeng = null;

try {
    if ($tok !== '') {
        $dosen = dosen_from_token($tok);
        if ($dosen === null) {
            $error = 'Tautan tidak valid atau sudah kedaluwarsa. Silakan cari nama dosen kembali.';
        } else {
            $view = 'detail';
            try {
                $pend = records_from(detail_dosen('pendidikan', $dosen));
            } catch (PddiktiException $e) {
                $errPend = $e->getMessage();
            }
            try {
                $meng = records_from(detail_dosen('mengajar', $dosen));
            } catch (PddiktiException $e) {
                $errMeng = $e->getMessage();
            }
        }
    } elseif ($q !== '') {
        $hasil = cari_dosen($q);
    }
} catch (PddiktiException $e) {
    $error = $e->getMessage();
}

$pend = urut_pendidikan($pend);

/** Cadangan bila nama field tidak dikenali: tampilkan pasangan kunci-nilai skalar. */
function fallback_pairs(array $rec): array
{
    $out = [];
    foreach ($rec as $k => $v) {
        if ((is_string($v) || is_numeric($v)) && trim((string) $v) !== '') {
            $out[ucfirst(str_replace('_', ' ', (string) $k))] = trim((string) $v);
        }
    }
    return $out;
}

$mengSusun = susun_mengajar($meng);
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riwayat Dosen PNL<?= $dosen ? ' · ' . h($dosen['nama']) : '' ?></title>
    <link rel="stylesheet" href="<?= h($assets) ?>/css/style.css">
</head>

<body class="<?= $view === 'detail' ? 'is-detail' : ($q !== '' ? 'is-results' : '') ?>">
    <div class="page">

        <header class="top">
            <a href="./">Politeknik Negeri Lhokseumawe</a>
            <span>Direktori Dosen</span>
        </header>

        <?php if ($view === 'home'): ?>
            <div class="hero">
                <h1>Riwayat pendidikan<br>dosen PNL</h1>
                <p>Telusuri jenjang, kampus, dan tahun kelulusan dosen Politeknik Negeri Lhokseumawe.</p>
            </div>

            <form class="search" method="get" action="">
                <input type="search" name="q" value="<?= h($q) ?>" placeholder="Ketik nama dosen…" minlength="3" required
                    autocomplete="off" <?= $q === '' ? 'autofocus' : '' ?>>
                <button type="submit">Cari</button>
            </form>

            <?php if ($error): ?>
                <div class="note"><?= h($error) ?></div>
            <?php elseif ($q !== ''): ?>
                <?php if (!$hasil): ?>
                    <p class="empty" style="margin-top:32px">Tidak ditemukan dosen PNL dengan nama “<?= h($q) ?>”.</p>
                <?php else: ?>
                    <div class="count"><span><?= count($hasil) ?> dosen ditemukan</span>
                        <a class="dl" href="?aksi=unduh-daftar&amp;keyword=<?= urlencode($q) ?>">Unduh daftar (.xlsx)</a>
                    </div>
                    <ul class="list">
                        <?php foreach ($hasil as $d):
                            $info = array_filter([$d['nama_prodi'], $d['nidn'] !== '' ? 'NIDN ' . $d['nidn'] : '']); ?>
                            <li>
                                <a href="?d=<?= h($d['token']) ?>">
                                    <span>
                                        <div class="nama"><?= h($d['nama']) ?></div>
                                        <?php if ($info): ?>
                                            <div class="info"><?= h(implode(' · ', $info)) ?></div><?php endif; ?>
                                    </span>
                                    <span class="arrow">→</span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

        <?php else: ?>
            <a class="back" href="javascript:history.back()">← Kembali</a>

            <div class="profile">
                <h1><?= h($dosen['nama']) ?></h1>
                <p class="dlrow"><a class="dl" href="?aksi=unduh&amp;d=<?= h($tok) ?>">Unduh Excel (.xlsx)</a></p>
                <div class="facts">
                    <?php if ($dosen['nama_prodi'] !== ''): ?><span><b><?= h($dosen['nama_prodi']) ?></b></span><?php endif; ?>
                    <?php if ($dosen['nidn'] !== ''): ?><span>NIDN <b><?= h($dosen['nidn']) ?></b></span><?php endif; ?>
                    <?php if ($dosen['nuptk'] !== ''): ?><span>NUPTK <b><?= h($dosen['nuptk']) ?></b></span><?php endif; ?>
                </div>
            </div>

            <section class="block">
                <h2>Riwayat pendidikan</h2>
                <?php if ($errPend): ?>
                    <div class="note"><?= h($errPend) ?></div>
                <?php elseif (!$pend): ?>
                    <p class="empty">Belum ada data riwayat pendidikan.</p>
                <?php else: ?>
                    <ol class="timeline">
                        <?php foreach ($pend as $rec):
                            $p = normalisasi_pendidikan($rec); ?>
                            <li>
                                <?php if (!array_filter($p)): ?>
                                    <dl class="kv"><?php foreach (fallback_pairs($rec) as $k => $v): ?>
                                            <dt><?= h($k) ?></dt>
                                            <dd><?= h($v) ?></dd>
                                        <?php endforeach; ?>
                                    </dl>
                                <?php else: ?>
                                    <?php if ($p['tahun'] !== ''): ?>
                                        <div class="year"><?= h($p['tahun']) ?></div><?php endif; ?>
                                    <div class="jenjang"><?= h($p['jenjang'] ?: 'Pendidikan') ?>
                                        <?php if ($p['gelar'] !== ''): ?><small>· <?= h($p['gelar']) ?></small><?php endif; ?>
                                    </div>
                                    <?php if ($p['pt'] !== ''): ?>
                                        <div class="pt"><?= h($p['pt']) ?></div><?php endif; ?>
                                    <?php if ($p['bidang'] !== ''): ?>
                                        <div class="bidang"><?= h($p['bidang']) ?></div><?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <section class="block">
                <h2>Riwayat mengajar <?php if ($meng): ?><span class="badge"><?= count($meng) ?> kelas</span><?php endif; ?>
                </h2>
                <?php if ($errMeng): ?>
                    <div class="note"><?= h($errMeng) ?></div>
                <?php elseif (!$meng): ?>
                    <p class="empty">Belum ada data riwayat mengajar.</p>
                <?php elseif (!$mengSusun['cols']): ?>
                    <p class="empty">Data mengajar diterima, tetapi tidak ada kolom yang dapat ditampilkan.</p>
                <?php else: ?>
                    <?php foreach ($mengSusun['groups'] as $sem => $baris): ?>
                        <div class="semester">
                            <h3><?= h((string) $sem) ?> <span class="badge"><?= count($baris) ?></span></h3>
                            <div class="scroll">
                                <table class="mk">
                                    <thead>
                                        <tr>
                                            <?php foreach ($mengSusun['cols'] as $c): ?>
                                                <th<?= preg_match('/sks|jml|jumlah/i', $c) ? ' class="num"' : '' ?>>
                                                    <?= h(label_kolom($c)) ?></th>
                                                <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($baris as $r): ?>
                                            <tr>
                                                <?php foreach ($mengSusun['cols'] as $c):
                                                    $v = $r[$c] ?? ''; ?>
                                                    <td<?= preg_match('/sks|jml|jumlah/i', $c) ? ' class="num"' : '' ?>>
                                                        <?= $v !== '' ? h($v) : '<span class="empty">—</span>' ?></td>
                                                    <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (isset($_GET['raw']) && debug_allowed()): ?>
                    <details class="raw" open>
                        <summary>Data mentah (3 baris pertama)</summary>
                        <pre><?= h(json_encode(array_slice($meng, 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <footer>Sumber: situs PDDikti. Data dapat berbeda dengan arsip kepegawaian kampus.</footer>
    </div>
    <script src="<?= h($assets) ?>/js/script.js" defer></script>
</body>

</html>