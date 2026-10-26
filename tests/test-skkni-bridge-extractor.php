<?php
/**
 * Test gate: jembatan SKKNI Extractor → plugin (skkni_data).
 *
 * Memakai berkas JSON hasil `app/lsp_exporter.py` bila tersedia; bila tidak,
 * memakai fixture internal dengan bentuk yang sama.
 *
 * Jalankan: php tests/test-skkni-bridge-extractor.php [berkas.json]
 */

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/wordpress/';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname( __DIR__, 4 ) . '/wp-load.php';

$core = LSP_Core_Management::get_instance();

$berkas = isset( $argv[1] ) ? $argv[1] : '/tmp/extractor_lsp.json';
$dari_ekstraktor = is_readable( $berkas );

if ( $dari_ekstraktor ) {
    $units_in = json_decode( file_get_contents( $berkas ), true );
} else {
    // Fixture: bentuk keluaran unit_ke_lsp_dict() dari SKKNI Extractor.
    $units_in = array(
        array(
            'kode_unit'  => 'J.63OPR00.004.2',
            'judul_unit' => 'Menggunakan Perangkat Lunak Pengolah Kata Tingkat Dasar',
            'elemen'     => array(
                array( 'nomor' => 1, 'nama' => 'Membuat dokumen', 'kuk' => array( '1.1 Aplikasi pengolah kata dibuka.', '1.2 Dokumen baru dibuat.' ) ),
            ),
        ),
    );
}

$fail = 0;
function cek( $label, $actual, $expected ) {
    global $fail;
    $ok = ( $actual === $expected );
    if ( ! $ok ) { $fail++; }
    printf( "%s %-60s harap=%s dapat=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}
function cek_true( $label, $cond ) {
    global $fail;
    if ( ! $cond ) { $fail++; }
    printf( "%s %s\n", $cond ? 'PASS' : 'FAIL', $label );
}

echo $dari_ekstraktor ? "Sumber: berkas ekstraktor ($berkas)\n" : "Sumber: fixture internal (berkas ekstraktor tidak ada)\n";
echo "Unit di berkas: " . count( $units_in ) . "\n\n";

$n_elemen_in = 0; $n_kuk_in = 0;
foreach ( $units_in as $u ) {
    $n_elemen_in += count( $u['elemen'] );
    foreach ( $u['elemen'] as $e ) { $n_kuk_in += count( $e['kuk'] ); }
}
printf( "Isi berkas: %d elemen, %d KUK\n\n", $n_elemen_in, $n_kuk_in );

echo "== A. Parser menerima JSON ekstraktor ==\n";
$p = $core->parse_skkni_import_text( wp_json_encode( $units_in ) );
cek( 'A1 jumlah error', count( $p['errors'] ), 0 );
cek( 'A2 jumlah unit', count( $p['units'] ), count( $units_in ) );

$n_elemen = 0; $n_kuk = 0;
foreach ( $p['units'] as $u ) {
    $n_elemen += count( $u['elemen'] );
    foreach ( $u['elemen'] as $e ) { $n_kuk += count( $e['kuk'] ); }
}
cek( 'A3 jumlah elemen', $n_elemen, $n_elemen_in );
cek( 'A4 jumlah KUK', $n_kuk, $n_kuk_in );
cek( 'A5 kode unit pertama', $p['units'][0]['kode_unit'], $units_in[0]['kode_unit'] );
cek_true( 'A6 deskripsi unit ikut terbawa', ! empty( $p['units'][0]['deskripsi_unit'] ) || empty( $units_in[0]['deskripsi_unit'] ) );
cek_true( 'A7 KUK pertama memuat nomor', preg_match( '/^\d+\.\d+/', $p['units'][0]['elemen'][0]['kuk'][0] ) === 1 );

echo "\n== B. Bentuk alternatif ==\n";
$satu = $core->parse_skkni_import_text( wp_json_encode( $units_in[0] ) );
cek( 'B1 satu unit (objek) diterima', count( $satu['units'] ), 1 );
cek( 'B2 tanpa error', count( $satu['errors'] ), 0 );

$terbungkus = $core->parse_skkni_import_text( wp_json_encode( array( 'unit_kompetensi' => $units_in ) ) );
cek( 'B3 bentuk {unit_kompetensi:[...]} diterima', count( $terbungkus['units'] ), count( $units_in ) );

// Alias gaya mentah: elemen_kompetensi / nomor_elemen / nama_elemen / KUK objek
$mentah = array( array(
    'kode_unit'  => 'X.00XXX00.001.1',
    'judul_unit' => 'Unit Alias',
    'elemen_kompetensi' => array( array(
        'nomor_elemen' => 1,
        'nama_elemen'  => 'Elemen Alias',
        'kuk'          => array( array( 'nomor_kuk' => '1.1', 'uraian_kuk' => 'Uraian KUK alias.' ) ),
    ) ),
) );
$alias = $core->parse_skkni_import_text( wp_json_encode( $mentah ) );
cek( 'B4 alias mentah tanpa error', count( $alias['errors'] ), 0 );
cek( 'B5 nama elemen alias', $alias['units'][0]['elemen'][0]['nama'], 'Elemen Alias' );
cek( 'B6 KUK objek digabung nomor+uraian', $alias['units'][0]['elemen'][0]['kuk'][0], '1.1 Uraian KUK alias.' );

echo "\n== C. Simpan ke skema & baca ulang ==\n";
$id = wp_insert_post( array( 'post_type' => 'skema', 'post_title' => 'TEMP-BRIDGE-TEST', 'post_status' => 'draft' ) );

$lines = array();
foreach ( $p['units'] as $u ) { $lines[] = $u['kode_unit'] . ' | ' . $u['judul_unit']; }
update_post_meta( $id, 'skkni_data', $p['units'] );
update_post_meta( $id, 'unit_kompetensi', implode( "\n", $lines ) );

$balik = LSP_Core_Management::get_skema_skkni_data( $id );
cek( 'C1 unit tersimpan', count( $balik ), count( $units_in ) );
$n_kuk_balik = 0;
foreach ( $balik as $u ) { foreach ( $u['elemen'] as $e ) { $n_kuk_balik += count( $e['kuk'] ); } }
cek( 'C2 KUK tersimpan utuh', $n_kuk_balik, $n_kuk_in );
$status_row = null;
foreach ( $core->collect_skkni_status_rows() as $r ) {
    if ( (int) $r['id'] === (int) $id ) { $status_row = $r; break; }
}
cek( 'C3 status skema di tabel', $status_row ? $status_row['status'] : 'tidak ada', 'lengkap' );
cek( 'C4 elemen terhitung di status', $status_row ? $status_row['elemen'] : -1, $n_elemen_in );

wp_delete_post( $id, true );
cek_true( 'C5 fixture terhapus', get_post( $id ) === null );

echo "\n" . ( $fail === 0 ? "SEMUA LULUS\n" : "{$fail} GAGAL\n" );
exit( $fail === 0 ? 0 : 1 );
