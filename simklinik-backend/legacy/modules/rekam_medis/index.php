<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('dokter', 'superadmin');
$pageTitle = t('pages.medical_records');

$rows = db()->query(
    "SELECT p.id, p.no_mr, p.nama, p.jenis_kelamin, p.tgl_lahir, p.no_passport, p.alergi,
            COUNT(k.id) AS jml, MAX(k.tgl_kunjungan) AS last_visit
     FROM pasien p
     LEFT JOIN kunjungan k ON k.pasien_id = p.id
     GROUP BY p.id ORDER BY p.nama")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  /* Styling kustom embedding khusus halaman Rekam Medis */
  .rm-embedded-search {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
  }
  .rm-embedded-search-label {
    font-size: 13.5px;
    color: var(--muted, #64748b);
    font-weight: 500;
    white-space: nowrap;
  }
  .rm-embedded-search-input {
    padding: 7px 12px;
    border: 1px solid var(--border, #e7ecf3);
    border-radius: 8px;
    font-size: 14px;
    min-width: 240px;
    background: var(--input-bg, #ffffff);
    color: var(--text, #1e293b);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    font-family: inherit;
  }
  .rm-embedded-search-input::placeholder {
    color: #94a3b8;
  }
  .rm-embedded-search-input:focus {
    border-color: var(--primary, #2563eb);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
  }
  /* Sembunyikan search box bawaan DataTables di halaman ini */
  .table-wrap .dataTables_filter {
    display: none !important;
  }
</style>

<div class="page-toolbar">
  <div>
    <div class="pt-title"><?= e(t('pages.medical_records_patients')) ?></div>
    <div class="pt-sub"><?= e(t('pages.patients_count', ['count' => count($rows)])) ?></div>
  </div>
</div>

<!-- Embedded Searchbar Element -->
<div id="rmSearchWrap" class="rm-embedded-search">
  <label class="rm-embedded-search-label" for="rmSearchInput"><?= e(t('datatable.search') ?: 'Cari :') ?></label>
  <input type="search" id="rmSearchInput" class="rm-embedded-search-input" placeholder="<?= e(t('common.search_patient_placeholder')) ?>" autocomplete="off" oninput="filterRekamMedisTable(this.value)">
</div>

<div class="table-wrap">
  <table id="rekamMedisTable" class="datatable dt-noscroll no-auto-num" style="width:100%">
    <thead>
      <tr><th><?= e(t('common.mr_no')) ?></th><th><?= e(t('common.patient_name')) ?></th><th><?= e(t('common.gender')) ?></th><th style="text-align:left;">No. Passport</th><th><?= e(t('common.allergy')) ?></th>
          <th><?= e(t('common.visit_count')) ?></th><th><?= e(t('common.last_visit')) ?></th><th class="col-actions"><?= e(t('common.action')) ?></th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td><b><?= e($p['no_mr']) ?></b></td>
          <td><?= e($p['nama']) ?></td>
          <td><?= $p['jenis_kelamin'] === 'L' ? e(t('common.gender_m_short')) : e(t('common.gender_f_short')) ?></td>
          <td><?= e($p['no_passport'] ?? '-') ?></td>
          <td><?= !empty($p['alergi']) ? '<span class="badge badge-red">' . e($p['alergi']) . '</span>' : '-' ?></td>
          <td><?= (int) $p['jml'] ?></td>
          <td><?= $p['last_visit'] ? tgl_id($p['last_visit']) : '-' ?></td>
          <td class="cell-actions"><div class="cell-actions-inner"><a class="btn btn-sm" href="<?= legacy_url('modules/rekam_medis/pasien.php?pasien_id=' . $p['id']) ?>"><?= app_icon('rekam') ?> <?= e(t('common.view')) ?></a></div></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
// Fungsi pencarian kustom (embedded) khusus halaman Rekam Medis
function filterRekamMedisTable(query) {
  var q = (query || '').trim().toLowerCase();
  var tableEl = document.getElementById('rekamMedisTable');
  if (!tableEl) return;

  // Use DataTables search API if DataTables is initialized
  if (window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable(tableEl)) {
    $(tableEl).DataTable().search(q).draw();
    return;
  }

  // Fallback direct DOM row filtering
  var rows = tableEl.querySelectorAll('tbody tr');
  rows.forEach(function (tr) {
    var text = tr.textContent.toLowerCase();
    tr.style.display = text.indexOf(q) !== -1 ? '' : 'none';
  });
}

$(function () {
  var $wrap = $('#rmSearchWrap');
  var $table = $('#rekamMedisTable');
  if ($table.length && $wrap.length) {
    var $topRow = $table.closest('.dataTables_wrapper, .table-wrap').find('.dt-top-row');
    if ($topRow.length) {
      $topRow.append($wrap);
    }
  }
});
</script>
