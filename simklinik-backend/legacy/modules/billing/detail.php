<?php
/**
 * Detail tagihan (read-only) — tampil sebagai modal premium dari Billing index.
 * Mode modal (?modal=1 / fetch): kirim fragment saja, tanpa header & sidebar.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/billing_lib.php';
require_once __DIR__ . '/../../includes/icons.php'; // app_icon() dipakai juga di mode modal (tanpa header.php)
require_role('kasir', 'admin', 'superadmin', 'registrasi', 'dokter');

$modal = isset($_GET['modal'])
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$kunjunganId = (int) ($_GET['kunjungan_id'] ?? 0);

$kj = db()->prepare(
    "SELECT k.*, p.no_mr, p.nama AS pasien, po.kode AS poli_kode, po.nama AS poli, d.nama AS dokter,
            a.nama AS asuransi_nama, c.nama AS corporate_nama,
            kp.kode AS kode_batal_kode, kp.nama AS kode_batal_nama
     FROM kunjungan k
     JOIN pasien p ON p.id = k.pasien_id
     JOIN poli po ON po.id = k.poli_id
     LEFT JOIN dokter d ON d.id = k.dokter_id
     LEFT JOIN asuransi a ON a.id = k.asuransi_id
     LEFT JOIN corporate c ON c.id = k.corporate_id
     LEFT JOIN kode_pembatalan kp ON kp.id = k.kode_pembatalan_id
     WHERE k.id = ?");
$kj->execute([$kunjunganId]);
$kj = $kj->fetch();

if (!$kj) {
    if ($modal) { echo '<div class="bd-empty">' . e(t('common.err_visit_not_found')) . '</div>'; exit; }
    set_flash('danger', t('common.err_visit_not_found'));
    legacy_redirect('modules/billing/index.php');
}

// Billing tersimpan (jika ada)
$billing = db()->prepare("SELECT * FROM billing WHERE kunjungan_id=?");
$billing->execute([$kunjunganId]);
$billing = $billing->fetch() ?: null;
$isFinal = $billing && $billing['status'] === 'final';

// Query riwayat pembayaran (jika invoice sudah ada)
$pmts = [];
$invRow = db()->prepare("SELECT id FROM invoice WHERE kunjungan_id=?");
$invRow->execute([$kunjunganId]);
$invId = $invRow->fetchColumn();
if ($invId) {
    $sPmt = db()->prepare("SELECT pm.*, b.nama_bank FROM pembayaran pm LEFT JOIN bank b ON b.id=pm.bank_id WHERE pm.invoice_id=? AND pm.status='valid' ORDER BY pm.id");
    $sPmt->execute([$invId]);
    $pmts = $sPmt->fetchAll();
}

// Rincian: jika final pakai detail tersimpan; jika belum, hitung live
if ($isFinal) {
    $s = db()->prepare("SELECT tgl_layanan,kategori,item_code,deskripsi,hasil,qty,tarif,subtotal FROM billing_detail WHERE billing_id=? ORDER BY id");
    $s->execute([$billing['id']]);
    $detailLines = $s->fetchAll();
    $subtotal = (float) $billing['subtotal'];
    $diskon   = (float) $billing['diskon'];
    $total    = (float) $billing['total'];
    $administrasi = 0;
    foreach ($detailLines as $l) if ($l['kategori'] === 'administrasi') $administrasi += (float) $l['subtotal'];
} else {
    $detailLines  = collect_billing_lines($kunjunganId);
    $svcOnly = array_sum(array_column($detailLines, 'subtotal'));

    // Cek apakah ada biaya administrasi manual yang sudah tersimpan (dari draft billing)
    $admTersimpanRow = db()->prepare(
        "SELECT bd.subtotal FROM billing_detail bd JOIN billing b ON b.id=bd.billing_id
         WHERE b.kunjungan_id=? AND bd.kategori='administrasi' LIMIT 1");
    $admTersimpanRow->execute([$kunjunganId]);
    $admTersimpanVal = $admTersimpanRow->fetchColumn();
    $hasSavedAdmin = ($billing !== null) || ($admTersimpanVal !== false);

    if ($hasSavedAdmin) {
        $administrasi = ($admTersimpanVal !== false) ? (float) $admTersimpanVal : 0;
    } elseif (($kj['jenis_registrasi'] ?? '') === 'rawat_inap' && $svcOnly > 0) {
        $administrasi = round(0.40 * $svcOnly);
    } else {
        $administrasi = 0;
    }

    $subtotal = $svcOnly + $administrasi;
    $diskon   = (float) ($billing['diskon'] ?? 0);
    $total    = pembulatan_billing(max(0, $subtotal - $diskon), 500);
}

$svcOnly = array_sum(array_column(array_filter($detailLines, fn($l) => $l['kategori'] !== 'administrasi'), 'subtotal'));

$statusBadge = [
    'menunggu'   => 'badge-orange',
    'periksa'    => 'badge-blue',
    'penunjang'  => 'badge-blue',
    'farmasi'    => 'badge-blue',
    'billing'    => 'badge-blue',
    'pembayaran' => 'badge-orange',
    'selesai'    => 'badge-green',
    'batal'      => 'badge-red',
];

$penj = strtolower(trim($kj['jenis_penjamin'] ?? ''));
$optKey = 'common.options.' . str_replace(['/', ' '], '_', $penj);
$trans = t($optKey);
$penjDisp = ($trans !== $optKey) ? $trans : strtoupper($kj['jenis_penjamin'] ?? 'UMUM');
if ($kj['jenis_penjamin'] === 'asuransi' && !empty($kj['asuransi_nama'])) {
    $penjDisp .= ' (' . $kj['asuransi_nama'] . ')';
} elseif ($kj['jenis_penjamin'] === 'corporate' && !empty($kj['corporate_nama'])) {
    $penjDisp .= ' (' . $kj['corporate_nama'] . ')';
}
if (!empty($kj['no_jaminan'])) {
    $penjDisp .= ' - ' . $kj['no_jaminan'];
}

if (!$modal) {
    $pageTitle = t('pages.billing_detail');
    require_once __DIR__ . '/../../includes/header.php';
    echo '<a href="' . legacy_url('modules/billing/index.php') . '" class="btn btn-light btn-sm">' . app_icon('arrowleft') . ' ' . e(t('common.back')) . '</a>';
    echo '<div class="card" style="margin-top:14px">';
}
?>
<div class="bill-detail">

  <!-- Hero: identitas pasien & status -->
  <div class="bd-hero">
    <div class="bd-hero-glow"></div>
    <div class="bd-hero-main">
      <div class="bd-avatar"><?= app_icon('idcard') ?></div>
      <div>
        <div class="bd-pasien"><?= e($kj['pasien']) ?></div>
        <div class="bd-meta">
          <span><?= app_icon('user') ?> No. MR <b><?= e($kj['no_mr']) ?></b></span>
          <?php if (!empty($kj['no_antrian'])): ?>
            <span><?= app_icon('ticket') ?> <?= e(t('common.queue')) ?> <b><?= e($kj['poli_kode'] ?? '') ?>-<?= str_pad((string)$kj['no_antrian'], 3, '0', STR_PAD_LEFT) ?></b></span>
          <?php endif; ?>
          <span><?= app_icon('calendar') ?> <?= tgl_id($kj['tgl_kunjungan']) ?></span>
          <span><?= app_icon('hospital') ?> <?= e($kj['poli']) ?></span>
          <span><?= app_icon('pelayanan') ?> <?= e($kj['dokter'] ?? '-') ?></span>
          <span><?= app_icon('shield') ?> <?= e($penjDisp) ?></span>
        </div>
      </div>
    </div>
    <div class="bd-hero-side">
      <div class="bd-kunjungan">No. <?= e($kj['no_kunjungan']) ?></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
        <?php if (($kj['jenis_registrasi'] ?? '') === 'rawat_inap'): ?>
          <span class="badge badge-purple">Rawat Inap (<?= (int)($kj['lama_rawat'] ?? 1) ?> hr)</span>
        <?php else: ?>
          <span class="badge badge-blue">Rawat Jalan</span>
        <?php endif; ?>
        <span class="badge <?= $statusBadge[$kj['status']] ?? 'badge-gray' ?>"><?= e(status_label($kj['status'])) ?></span>
        <?php if ($isFinal): ?><span class="badge badge-green"><?= e(t('common.final')) ?></span><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($kj['keluhan_awal'])): ?>
  <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 14px;font-size:13.5px;display:flex;align-items:flex-start;gap:8px">
    <span style="color:var(--primary);margin-top:2px"><?= app_icon('alert') ?></span>
    <div>
      <span style="font-weight:700;color:var(--text);margin-right:6px"><?= e(t('common.initial_complaint')) ?>:</span>
      <span style="color:var(--text)"><?= nl2br(e($kj['keluhan_awal'])) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($kj['status'] === 'batal'): ?>
  <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;color:#991b1b;font-size:13.5px;display:flex;align-items:flex-start;gap:8px">
    <span style="margin-top:2px"><?= app_icon('close') ?></span>
    <div>
      <span style="font-weight:700"><?= e(t('common.cancellation_code')) ?>:</span>
      <span><?= e(!empty($kj['kode_batal_kode']) ? $kj['kode_batal_kode'] . ' — ' . cancellation_reason_label($kj['kode_batal_kode'], $kj['kode_batal_nama'] ?? '') : ($kj['alasan_batal'] ?: t('common.cancelled'))) ?></span>
      <?php if (!empty($kj['alasan_batal']) && !empty($kj['kode_batal_kode'])): ?>
        <div style="margin-top:4px;font-size:12.5px;color:#b91c1c"><b><?= e(t('common.extra_notes')) ?>:</b> <?= nl2br(e($kj['alasan_batal'])) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Rincian layanan -->
  <div class="bd-section">
    <div class="bd-section-title"><?= app_icon('billing') ?> <?= e(t('common.service_breakdown')) ?></div>
    <div class="bd-table-wrap">
      <table class="bd-table">
        <thead>
          <tr><th style="width:110px"><?= e(t('common.date')) ?></th><th><?= e(t('common.category')) ?></th><th><?= e(t('common.code')) ?></th><th><?= e(t('common.description')) ?></th><th class="num"><?= e(t('common.qty')) ?></th>
              <th class="num"><?= e(t('common.rate')) ?></th><th class="num"><?= e(t('common.subtotal')) ?></th></tr>
        </thead>
        <tbody>
          <?php if (!$detailLines): ?>
            <tr><td colspan="7" class="bd-empty"><?= e(t('common.no_services_yet')) ?></td></tr>
          <?php else: foreach ($detailLines as $l):
            if ($l['kategori'] === 'administrasi') continue;
            $dVal = !empty($l['tgl_layanan']) ? date('d/m/Y', strtotime($l['tgl_layanan'])) : date('d/m/Y', strtotime($kj['tgl_kunjungan']));
          ?>
            <tr>
              <td><span style="font-size:0.875rem;color:var(--muted)"><?= e($dVal) ?></span></td>
              <td><span class="badge badge-gray"><?= e(billing_kategori_label($l['kategori'])) ?></span></td>
              <td><code class="bd-code"><?= e($l['item_code'] ?? '') ?></code></td>
              <td>
                <div style="font-weight:600"><?= e($l['deskripsi']) ?></div>
                <?php if (!empty($l['hasil'])): ?>
                  <div style="font-size:12px;color:var(--muted);margin-top:2px">Hasil: <?= e($l['hasil']) ?></div>
                <?php endif; ?>
                <?php if (!empty($l['catatan'])): ?>
                  <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= e($l['catatan']) ?></div>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $l['qty'] ?></td>
              <td class="num"><?= rupiah($l['tarif']) ?></td>
              <td class="num bold"><?= rupiah($l['subtotal']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($billing['hasil_diagnostik'])): ?>
  <div class="bd-section" style="margin-top:16px">
    <div class="bd-section-title"><?= app_icon('pelayanan') ?> <?= e(t('common.diagnostic_results')) ?></div>
    <div style="background:var(--bg-secondary, #f8fafc);border:1px solid var(--border, #e2e8f0);border-radius:8px;padding:12px 14px;white-space:pre-wrap;font-size:14px;color:var(--text, #1e293b);line-height:1.5">
      <?= e($billing['hasil_diagnostik']) ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Ringkasan total -->
  <div class="bd-summary">
    <div class="bd-sum-row"><span><?= e(t('common.service_subtotal')) ?></span><b><?= rupiah($svcOnly) ?></b></div>
    <?php if ($administrasi > 0): ?>
      <div class="bd-sum-row"><span><?= e(($kj['jenis_registrasi'] ?? '') === 'rawat_inap' ? t('common.admin_fee_inpatient') : t('common.admin_fee')) ?></span><b><?= rupiah($administrasi) ?></b></div>
    <?php endif; ?>
    <?php if ($diskon > 0): ?>
      <div class="bd-sum-row disc"><span><?= e(t('common.discount')) ?></span><b>&minus; <?= rupiah($diskon) ?></b></div>
    <?php endif; ?>
    <?php if ($kj['jenis_penjamin'] !== 'umum'): 
      $penjNama = $kj['asuransi_nama'] ?: ($kj['corporate_nama'] ?: strtoupper($kj['jenis_penjamin']));
      $coverPenjamin = (float) ($billing['cover_penjamin'] ?? 0);
      if ($coverPenjamin <= 0 && $isFinal) $coverPenjamin = $total;
      $sisaPasien = max(0, $total - $coverPenjamin);
    ?>
      <div class="bd-sum-row" style="color:var(--primary)">
        <span><?= e(t('common.guarantor_cover_label', ['name' => $penjNama])) ?></span>
        <b><?= rupiah($coverPenjamin) ?></b>
      </div>
      <div class="bd-sum-row">
        <span><?= e(t('common.patient_cover')) ?></span>
        <b><?= rupiah($sisaPasien) ?></b>
      </div>
    <?php endif; ?>

    <div class="bd-sum-total" style="margin-top:6px">
      <span><?= e(t('common.total_bill_upper')) ?></span>
      <span class="bd-total-val"><?= rupiah($total) ?></span>
    </div>
  </div>

  <?php if ($modal): ?>
  <div class="bd-actions">
    <button type="button" class="btn btn-light" data-modal-close><?= app_icon('close') ?> <?= e(t('common.close')) ?></button>
    <?php if ($kj['status'] === 'pembayaran' && has_role('kasir', 'admin', 'superadmin')): ?>
      <a class="btn" href="<?= legacy_url('modules/keuangan/index.php') ?>"><?= app_icon('keuangan') ?> <?= e(t('common.to_payment')) ?></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php
if (!$modal) {
    echo '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
}
