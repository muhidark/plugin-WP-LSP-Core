<?php
/**
 * Test Suite: Phase 5.1 — Task 3 Certificate Integrity Testing
 * Environment: Real WordPress 7.1.3, PHP 8.5.4, MySQL 8.4.11
 */

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

define( 'WP_USE_THEMES', true );
require_once '/var/www/html/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

add_filter( 'upload_dir', function( $dirs ) {
    $dirs['basedir'] = '/tmp/wp_uploads';
    $dirs['baseurl'] = 'http://localhost/wp-content/uploads';
    $dirs['path']    = '/tmp/wp_uploads/' . date( 'Y/m' );
    $dirs['url']     = 'http://localhost/wp-content/uploads/' . date( 'Y/m' );
    if ( ! file_exists( $dirs['path'] ) ) {
        wp_mkdir_p( $dirs['path'] );
    }
    return $dirs;
} );

global $wpdb;

echo "====================================================================\n";
echo "   PHASE 5.1 TASK 3 — CERTIFICATE INTEGRITY TESTING (REAL MYSQL)    \n";
echo "====================================================================\n\n";

// Bersihkan data uji
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})" );

$plugin = LSP_Core_Management::get_instance();
$passed = 0;
$total  = 0;
$created_cert_id = 0;

function run_integrity_test( $name, $callback ) {
    global $passed, $total;
    $total++;
    try {
        $res = call_user_func( $callback );
        if ( $res ) {
            $passed++;
            echo "[PASS] {$name}\n";
        } else {
            echo "[FAIL] {$name}\n";
        }
    } catch ( Exception $e ) {
        echo "[FAIL] {$name} (Exception: " . $e->getMessage() . ")\n";
    }
}

wp_set_current_user( 1 ); // Admin

// 1. Tambah sertifikat baru
run_integrity_test( 'TEST 01: Tambah sertifikat baru sah ke MySQL', function() use ( $plugin, $wpdb, &$created_cert_id ) {
    $_POST['lsp_cert_meta_nonce'] = wp_create_nonce( 'lsp_cert_meta_nonce_action' );
    $_POST['nama_pemegang']       = 'Peserta Integritas 1';
    $_POST['kode_blanko']         = 'CERT.2026.001';
    $_POST['no_registrasi']       = 'REG.2026.001';
    $_POST['skema_sertifikasi']   = 'Public Relations Officer';
    $_POST['tanggal_terbit']      = '2026-04-10';

    $created_cert_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'CERT.2026.001',
        'post_status' => 'publish',
    ) );
    $plugin->save_cert_meta( $created_cert_id );

    $saved_blanko = get_post_meta( $created_cert_id, 'kode_blanko', true );
    $saved_reg    = get_post_meta( $created_cert_id, 'no_registrasi', true );
    $saved_nama   = get_post_meta( $created_cert_id, 'nama_pemegang', true );

    return ( 'CERT.2026.001' === $saved_blanko && 'REG.2026.001' === $saved_reg && 'Peserta Integritas 1' === $saved_nama );
} );

// 2. Edit sertifikat lama (Update non-identitas)
run_integrity_test( 'TEST 02: Edit sertifikat lama tanpa mengubah nomor (Self-Duplicate Protection)', function() use ( $plugin, $wpdb, &$created_cert_id ) {
    $id = $created_cert_id;

    $_POST['nama_pemegang']     = 'Peserta Integritas 1, M.I.Kom';
    $_POST['skema_sertifikasi'] = 'Senior PR Officer';
    $plugin->save_cert_meta( $id );

    $saved_blanko = get_post_meta( $id, 'kode_blanko', true );
    $saved_nama   = get_post_meta( $id, 'nama_pemegang', true );
    $saved_skema  = get_post_meta( $id, 'skema_sertifikasi', true );

    return ( 'CERT.2026.001' === $saved_blanko && 'Peserta Integritas 1, M.I.Kom' === $saved_nama && 'Senior PR Officer' === $saved_skema );
} );

// 3. Duplikasi nomor blanko
run_integrity_test( 'TEST 03: Penolakan duplikasi nomor blanko yang sudah terdaftar', function() use ( $plugin, $wpdb ) {
    $dup_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'Duplikat Blanko',
        'post_status' => 'publish',
    ) );
    $_POST['nama_pemegang'] = 'Peserta Palsu';
    $_POST['kode_blanko']   = 'CERT.2026.001'; // Kembar dengan post 1
    $_POST['no_registrasi'] = 'REG.2026.999';
    $plugin->save_cert_meta( $dup_id );

    $p = get_post( $dup_id );
    $meta_blanko = get_post_meta( $dup_id, 'kode_blanko', true );

    return ( 'draft' === $p->post_status && empty( $meta_blanko ) );
} );

// 4. Duplikasi nomor registrasi
run_integrity_test( 'TEST 04: Penolakan duplikasi nomor registrasi yang sudah terdaftar', function() use ( $plugin, $wpdb ) {
    $dup_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'Duplikat Reg',
        'post_status' => 'publish',
    ) );
    $_POST['nama_pemegang'] = 'Peserta Palsu 2';
    $_POST['kode_blanko']   = 'CERT.2026.888';
    $_POST['no_registrasi'] = 'REG.2026.001'; // Kembar dengan post 1
    $plugin->save_cert_meta( $dup_id );

    $p = get_post( $dup_id );
    $meta_reg = get_post_meta( $dup_id, 'no_registrasi', true );

    return ( 'draft' === $p->post_status && empty( $meta_reg ) );
} );

// 5. Cross-field collision
run_integrity_test( 'TEST 05: Penolakan tabrakan nomor lintas field (Cross-field collision)', function() use ( $plugin, $wpdb ) {
    $cross_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'Cross Collision',
        'post_status' => 'publish',
    ) );
    $_POST['nama_pemegang'] = 'Peserta Cross';
    $_POST['kode_blanko']   = 'REG.2026.001'; // Blanko sama dengan nomor registrasi post 1
    $_POST['no_registrasi'] = 'CERT.2026.777';
    $plugin->save_cert_meta( $cross_id );

    $p = get_post( $cross_id );
    $meta_blanko = get_post_meta( $cross_id, 'kode_blanko', true );

    return ( 'draft' === $p->post_status && empty( $meta_blanko ) );
} );

// 6. Input tanggal tidak valid
run_integrity_test( 'TEST 06: Penolakan tanggal kalender ISO tidak valid (2026-02-30)', function() use ( $plugin, $wpdb ) {
    $date_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'Invalid Date Post',
        'post_status' => 'publish',
    ) );
    $_POST['nama_pemegang']  = 'Peserta Date Invalid';
    $_POST['kode_blanko']    = 'CERT.2026.555';
    $_POST['no_registrasi']  = 'REG.2026.555';
    $_POST['tanggal_terbit'] = '2026-02-30'; // Tanggal tidak valid
    $plugin->save_cert_meta( $date_id );

    $meta_date = get_post_meta( $date_id, 'tanggal_terbit', true );

    return empty( $meta_date );
} );

// 7. Import sertifikat baru via CSV
$tmp_csv = '/tmp/test_integrity_import.csv';
run_integrity_test( 'TEST 07: Import sertifikat baru via CSV', function() use ( $plugin, $wpdb, $tmp_csv ) {
    $csv = "Kode Blanko,Nama Pemegang,Nomor Registrasi,Skema Sertifikasi,Tanggal Terbit\n" .
           "CERT.2026.101,Peserta CSV Baru,REG.2026.101,Media Relations,2026-01-15\n";
    file_put_contents( $tmp_csv, $csv );

    $parsed = $plugin->parse_and_validate_csv_data( $tmp_csv );
    $exec   = $plugin->execute_csv_import_batch( $parsed['rows'] );

    $found = $plugin->find_certs_by_meta( 'kode_blanko', 'CERT.2026.101' );

    return ( 1 === $exec['imported'] && ! empty( $found ) );
} );

// 8. Update sertifikat lama via CSV
run_integrity_test( 'TEST 08: Update non-identitas sertifikat lama via CSV', function() use ( $plugin, $wpdb, $tmp_csv ) {
    $csv = "Kode Blanko,Nama Pemegang,Nomor Registrasi,Skema Sertifikasi,Tanggal Terbit\n" .
           "CERT.2026.101,Peserta CSV Baru S.Sos,REG.2026.101,Senior Media Relations,2026-01-15\n";
    file_put_contents( $tmp_csv, $csv );

    $parsed = $plugin->parse_and_validate_csv_data( $tmp_csv );
    $exec   = $plugin->execute_csv_import_batch( $parsed['rows'] );

    $ids = $plugin->find_certs_by_meta( 'kode_blanko', 'CERT.2026.101' );
    $nama = get_post_meta( $ids[0], 'nama_pemegang', true );

    return ( 1 === $exec['updated'] && 'Peserta CSV Baru S.Sos' === $nama );
} );

// 9. Import file yang sama dua kali (Idempotency)
run_integrity_test( 'TEST 09: Import file yang sama dua kali bersifat idempotent (0 post duplikat)', function() use ( $plugin, $wpdb, $tmp_csv ) {
    $count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat' AND post_status = 'publish'" );

    $parsed = $plugin->parse_and_validate_csv_data( $tmp_csv );
    $exec   = $plugin->execute_csv_import_batch( $parsed['rows'] );

    $count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat' AND post_status = 'publish'" );

    return ( 1 === $exec['updated'] && 0 === $exec['imported'] && $count_before === $count_after );
} );

// 10. Konflik antara preview dan execution (Realtime Conflict Protection)
run_integrity_test( 'TEST 10: Konflik data yang terjadi antara tahap preview dan execution digagalkan realtime', function() use ( $plugin, $wpdb ) {
    // 10.1 Buat baris preview yang awalnya valid INSERT
    $conflict_csv = '/tmp/test_conflict.csv';
    $csv = "Nama Pemegang,Nomor Sertifikat,Nomor Registrasi,Skema Kompetensi,Tanggal Terbit\n" .
           "Peserta Race,CERT.2026.999,REG.2026.999,Broadcast,2026-01-15\n";
    file_put_contents( $conflict_csv, $csv );
    $parsed = $plugin->parse_and_validate_csv_data( $conflict_csv );

    // 10.2 Tiba-tiba di database disisipkan CERT.2026.999 oleh transaksi lain SEBELUM eksekusi batch
    $inserted_by_other = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'CERT.2026.999',
        'post_status' => 'publish',
    ) );
    update_post_meta( $inserted_by_other, 'kode_blanko', 'CERT.2026.999' );
    update_post_meta( $inserted_by_other, 'no_registrasi', 'REG.DIFFERENT' );

    // 10.3 Sekarang jalankan execute_csv_import_batch dengan $parsed['rows'] yang lama
    // execute_csv_import_batch melakukan double check realtime sebelum INSERT
    $exec = $plugin->execute_csv_import_batch( $parsed['rows'] );

    // Seharusnya terdeteksi konflik dan di-skip atau di-fail, BUKAN membuat post baru kembar
    $all_certs_with_blanko = $plugin->find_certs_by_meta( 'kode_blanko', 'CERT.2026.999' );

    unlink( $conflict_csv );

    // Harus tetap hanya ada 1 post dengan nomor blanko tersebut di database
    return ( count( $all_certs_with_blanko ) === 1 );
} );

if ( file_exists( $tmp_csv ) ) {
    unlink( $tmp_csv );
}

echo "\n--------------------------------------------------------------------\n";
echo sprintf( "HASIL AKHIR INTEGRITAS SERTIFIKAT: %d / %d TEST CASES PASS\n", $passed, $total );
echo "--------------------------------------------------------------------\n";

if ( $passed === $total ) {
    echo "STATUS: SELURUH 10 PENGUJIAN INTEGRITAS SERTIFIKAT BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT KEGAGALAN INTEGRITAS SERTIFIKAT!\n";
    exit( 1 );
}
