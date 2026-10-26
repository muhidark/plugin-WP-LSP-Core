<?php
/**
 * Test gate: parser & penyimpanan data SKKNI (tool Migrasi SKKNI).
 *
 * Jalankan dari CLI:
 *   php tests/test-skkni-import-parser.php
 *
 * Exit code 0 = semua lulus.
 */

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/wordpress/';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname( __DIR__, 4 ) . '/wp-load.php';

$core = LSP_Core_Management::get_instance();

$fail = 0;
function cek( $label, $actual, $expected ) {
    global $fail;
    $ok = ( $actual === $expected );
    if ( ! $ok ) { $fail++; }
    printf( "%s %-58s harap=%s dapat=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}
function cek_true( $label, $cond ) {
    global $fail;
    if ( ! $cond ) { $fail++; }
    printf( "%s %s\n", $cond ? 'PASS' : 'FAIL', $label );
}

echo "== A. Format baris ==\n";

$teks = "UNIT: J.63OPR00.004.2 | Menggunakan Perangkat Lunak Pengolah Kata Tingkat Dasar\n"
      . "ELEMEN: 1 | Membuat dokumen\n"
      . "KUK: 1.1 Aplikasi pengolah kata dibuka.\n"
      . "KUK: 1.2 Dokumen baru dibuat sesuai kebutuhan.\n"
      . "ELEMEN: 2 | Menyimpan dokumen\n"
      . "KUK: 2.1 Dokumen disimpan pada folder yang ditentukan.\n"
      . "# komentar diabaikan\n"
      . "UNIT: J.63OPR00.005.2 | Menggunakan Perangkat Lunak Lembar Sebar\n"
      . "ELEMEN: Mengelola sel dan data\n"
      . "KUK: 1.1 Sel diisi dan diformat.\n";

$p = $core->parse_skkni_import_text( $teks );
cek( 'A1 jumlah error', count( $p['errors'] ), 0 );
cek( 'A2 jumlah unit', count( $p['units'] ), 2 );
cek( 'A3 kode unit 1', $p['units'][0]['kode_unit'], 'J.63OPR00.004.2' );
cek( 'A4 judul unit 2', $p['units'][1]['judul_unit'], 'Menggunakan Perangkat Lunak Lembar Sebar' );
cek( 'A5 elemen unit 1', count( $p['units'][0]['elemen'] ), 2 );
cek( 'A6 nomor elemen 1', $p['units'][0]['elemen'][0]['nomor'], 1 );
cek( 'A7 nama elemen 1', $p['units'][0]['elemen'][0]['nama'], 'Membuat dokumen' );
cek( 'A8 KUK elemen 1', count( $p['units'][0]['elemen'][0]['kuk'] ), 2 );
cek( 'A9 KUK elemen 2 (teks verbatim termasuk nomor)', $p['units'][0]['elemen'][1]['kuk'][0], '2.1 Dokumen disimpan pada folder yang ditentukan.' );
cek_true( 'A10 elemen tanpa nomor diberi nomor otomatis', 1 === $p['units'][1]['elemen'][0]['nomor'] );
cek( 'A11 nama elemen tanpa nomor', $p['units'][1]['elemen'][0]['nama'], 'Mengelola sel dan data' );

echo "\n== B. Validasi kesalahan ==\n";

$p = $core->parse_skkni_import_text( "KUK: 1.1 Tanpa elemen.\n" );
cek_true( 'B1 KUK sebelum ELEMEN ditolak', ! empty( $p['errors'] ) );

$p = $core->parse_skkni_import_text( "UNIT: TANPAJUDUL\n" );
cek_true( 'B2 UNIT tanpa judul ditolak', ! empty( $p['errors'] ) );

$p = $core->parse_skkni_import_text( "UNIT: A.1 | Judul\nELEMEN: 1 | Elemen\n" );
cek_true( 'B3 elemen tanpa KUK ditolak', ! empty( $p['errors'] ) );
cek_true( 'B4 pesan menyebut KUK kosong', stripos( implode( ' ', $p['errors'] ), 'KUK' ) !== false );

$p = $core->parse_skkni_import_text( "UNIT: A.1 | Judul\nELEMEN: 1 | Elemen\nKUK: 1.1 Isi\nTEKS BEBAS\n" );
cek_true( 'B5 baris tak dikenal ditolak', ! empty( $p['errors'] ) );

$p = $core->parse_skkni_import_text( '' );
cek_true( 'B6 teks kosong ditolak', ! empty( $p['errors'] ) );

echo "\n== C. Format JSON ==\n";

$json = wp_json_encode( array( 'units' => array(
    array(
        'kode_unit'  => 'J.99UJI.001.1',
        'judul_unit' => 'Unit Uji JSON',
        'elemen'     => array(
            array( 'nomor' => 1, 'nama' => 'Elemen Uji', 'kuk' => array( '1.1 KUK uji satu', '1.2 KUK uji dua' ) ),
        ),
    ),
) ) );

$p = $core->parse_skkni_import_text( $json );
cek( 'C1 jumlah error', count( $p['errors'] ), 0 );
cek( 'C2 jumlah unit', count( $p['units'] ), 1 );
cek( 'C3 KUK terbaca', count( $p['units'][0]['elemen'][0]['kuk'] ), 2 );

$p = $core->parse_skkni_import_text( '{ rusak' );
cek_true( 'C4 JSON rusak ditolak', ! empty( $p['errors'] ) );

echo "\n== D. Simpan ke skema & baca ulang ==\n";

$id = wp_insert_post( array( 'post_type' => 'skema', 'post_title' => 'TEMP-SKKNI-IMPORT', 'post_status' => 'draft' ) );
$p  = $core->parse_skkni_import_text( $teks );
update_post_meta( $id, 'skkni_data', $p['units'] );
update_post_meta( $id, 'unit_kompetensi', implode( "\n", array_map( function ( $u ) { return $u['kode_unit'] . ' | ' . $u['judul_unit']; }, $p['units'] ) ) );

$balik = LSP_Core_Management::get_skema_skkni_data( $id );
cek( 'D1 unit terbaca kembali', count( $balik ), 2 );
cek( 'D2 elemen terbaca kembali', count( $balik[0]['elemen'] ), 2 );
cek( 'D3 KUK terbaca kembali', count( $balik[0]['elemen'][0]['kuk'] ), 2 );
cek( 'D4 meta unit_kompetensi tersinkron', get_post_meta( $id, 'unit_kompetensi', true ), "J.63OPR00.004.2 | Menggunakan Perangkat Lunak Pengolah Kata Tingkat Dasar\nJ.63OPR00.005.2 | Menggunakan Perangkat Lunak Lembar Sebar" );

// Baris status pada halaman Migrasi SKKNI
$rows = $core->collect_skkni_status_rows();
$row_test = null;
foreach ( $rows as $r ) {
    if ( (int) $r['id'] === (int) $id ) { $row_test = $r; break; }
}
cek_true( 'D5 skema uji muncul di tabel status', null !== $row_test );
cek( 'D6 status skema uji', $row_test['status'], 'lengkap' );
cek( 'D7 jumlah elemen di status', $row_test['elemen'], 3 );

wp_delete_post( $id, true );
cek_true( 'D8 fixture terhapus', get_post( $id ) === null );

echo "\n" . ( $fail === 0 ? "SEMUA LULUS\n" : "{$fail} GAGAL\n" );
exit( $fail === 0 ? 0 : 1 );
