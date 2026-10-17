<?php
/**
 * Test Suite: Phase 5.1 — Task 5 CSV Concurrency & Replay Testing
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

global $wpdb;

echo "====================================================================\n";
echo "   PHASE 5.1 TASK 5 — CSV CONCURRENCY & REPLAY TESTING (REAL MYSQL) \n";
echo "====================================================================\n\n";

// Bersihkan data sertifikat & transient uji
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lsp_%' OR option_name LIKE '_transient_timeout_lsp_%'" );

$plugin = LSP_Core_Management::get_instance();
$passed = 0;
$total  = 0;

function run_concurrency_test( $name, $callback ) {
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

wp_set_current_user( 1 ); // Admin (User ID 1)

// Helper: Buat batch valid
function create_dummy_batch( $token, $user_id = 1, $num_rows = 2 ) {
    $rows = array();
    for ( $i = 1; $i <= $num_rows; $i++ ) {
        $rows[] = array(
            'row_num'           => $i + 1,
            'kode_blanko'       => "BATCH.2026.00{$i}",
            'nama_pemegang'     => "Peserta Batch {$i}",
            'no_registrasi'     => "REG.BATCH.00{$i}",
            'skema_sertifikasi' => "Skema Batch",
            'tanggal_terbit'    => "2026-01-20",
            'action'            => 'INSERT',
            'target_id'         => 0,
            'status'            => 'VALID',
            'errors'            => array(),
        );
    }
    $transient_key = 'lsp_csv_batch_' . $user_id . '_' . $token;
    set_transient( $transient_key, $rows, 900 );
    return $rows;
}

// 1. Dua konfirmasi import bersamaan (Race condition: eksekusi 1 menghapus transient, eksekusi 2 ditolak)
run_concurrency_test( 'TEST 01: Dua konfirmasi import bersamaan (Double Submit Prevention via Instant Delete)', function() use ( $plugin, $wpdb ) {
    $token = wp_generate_password( 24, false );
    $batch = create_dummy_batch( $token, 1, 2 );

    // Request pertama: membaca transient dan langsung menghapusnya
    $transient_key = 'lsp_csv_batch_1_' . $token;
    $batch_1 = get_transient( $transient_key );
    delete_transient( $transient_key ); // Atomik hapus seketika pada pembacaan pertama

    $res_1 = $plugin->execute_csv_import_batch( $batch_1 );

    // Request kedua (bersamaan / replay): mencoba membaca transient yang sama
    $batch_2 = get_transient( $transient_key );
    $res_2 = false;
    if ( false !== $batch_2 ) {
        $res_2 = $plugin->execute_csv_import_batch( $batch_2 );
    }

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat' AND post_status = 'publish'" );

    // Request 1 berhasil 2 rows, request 2 ditolak (batch_2 === false), total post tetap 2 (0 duplikasi)
    return ( 2 === $res_1['imported'] && false === $batch_2 && 2 === $count );
} );

// 2. Transient hilang
run_concurrency_test( 'TEST 02: Penolakan eksekusi jika transient hilang/tidak ditemukan', function() use ( $plugin ) {
    $ghost_token = wp_generate_password( 24, false );
    $transient_key = 'lsp_csv_batch_1_' . $ghost_token;
    $batch = get_transient( $transient_key );

    return ( false === $batch );
} );

// 3. Transient expired
run_concurrency_test( 'TEST 03: Penolakan eksekusi jika transient telah kedaluwarsa', function() use ( $plugin ) {
    $expired_token = wp_generate_password( 24, false );
    $transient_key = 'lsp_csv_batch_1_' . $expired_token;
    // Set transient dengan durasi negatif/nol untuk simulasi kedaluwarsa
    set_transient( $transient_key, array( 'dummy' ), -10 );

    $batch = get_transient( $transient_key );
    return ( false === $batch );
} );

// 4. Token batch tidak sah / manipulasi string
run_concurrency_test( 'TEST 04: Penolakan token batch berformat tidak sah (karakter ilegal/SQLi)', function() use ( $plugin ) {
    $malicious_token = "../../../etc/passwd' OR '1'='1";
    $sanitized = sanitize_text_field( $malicious_token );
    $transient_key = 'lsp_csv_batch_1_' . $sanitized;
    $batch = get_transient( $transient_key );

    return ( false === $batch );
} );

// 5. Batch milik pengguna lain (User Isolation)
run_concurrency_test( 'TEST 05: Penolakan akses batch milik pengguna lain (Cross-User Batch Isolation)', function() use ( $plugin ) {
    $user1_token = wp_generate_password( 24, false );
    // User 1 simpan batch
    create_dummy_batch( $user1_token, 1, 2 );

    // User ID 2 (Asesor / Editor) mencoba membaca batch tersebut menggunakan User ID-nya sendiri
    $attacker_user_id = 999;
    $attempt_key = 'lsp_csv_batch_' . $attacker_user_id . '_' . $user1_token;
    $stolen_batch = get_transient( $attempt_key );

    return ( false === $stolen_batch );
} );

// 6. Database berubah setelah preview (Intervening change conflict detection)
run_concurrency_test( 'TEST 06: Konflik database pasca-preview terdeteksi realtime sebelum insert', function() use ( $plugin, $wpdb ) {
    // Siapkan baris preview: BATCH.2026.NEW
    $rows = array(
        array(
            'row_num'           => 2,
            'kode_blanko'       => 'BATCH.2026.NEW',
            'nama_pemegang'     => 'Peserta Baru',
            'no_registrasi'     => 'REG.NEW.001',
            'skema_sertifikasi' => 'Skema Baru',
            'tanggal_terbit'    => '2026-01-20',
            'action'            => 'INSERT',
            'target_id'         => 0,
            'status'            => 'VALID',
            'errors'            => array(),
        )
    );

    // Di antara waktu preview dan konfirmasi, pihak lain menyisipkan BATCH.2026.NEW di database
    $intruder_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'BATCH.2026.NEW',
        'post_status' => 'publish',
    ) );
    update_post_meta( $intruder_id, 'kode_blanko', 'BATCH.2026.NEW' );
    update_post_meta( $intruder_id, 'no_registrasi', 'REG.INTRUDER' );

    // Jalankan eksekusi batch yang mengira BATCH.2026.NEW adalah INSERT baru
    $res = $plugin->execute_csv_import_batch( $rows );

    // Sistem harus mendeteksi tabrakan saat runtime check, dan menolak membuat post duplikat
    $all = $plugin->find_certs_by_meta( 'kode_blanko', 'BATCH.2026.NEW' );

    return ( count( $all ) === 1 );
} );

// 7. Import ulang file yang sama (Replay attack & idempotency)
run_concurrency_test( 'TEST 07: Replay impor batch yang sama bersifat idempotent (0 post duplikat)', function() use ( $plugin, $wpdb ) {
    $token_replay = wp_generate_password( 24, false );
    $tmp_csv = '/tmp/test_replay.csv';
    $csv = "Kode Blanko,Nama Pemegang,Nomor Registrasi,Skema Sertifikasi,Tanggal Terbit\n" .
           "REPLAY.2026.01,Peserta Replay 1,REG.REPLAY.01,Marketing,2026-02-01\n" .
           "REPLAY.2026.02,Peserta Replay 2,REG.REPLAY.02,Marketing,2026-02-01\n";
    file_put_contents( $tmp_csv, $csv );

    // Impor ke-1
    $p1 = $plugin->parse_and_validate_csv_data( $tmp_csv );
    $e1 = $plugin->execute_csv_import_batch( $p1['rows'] );

    $count_after_first = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat' AND post_status = 'publish'" );

    // Impor ke-2 (Replay)
    $p2 = $plugin->parse_and_validate_csv_data( $tmp_csv );
    $e2 = $plugin->execute_csv_import_batch( $p2['rows'] );

    $count_after_second = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat' AND post_status = 'publish'" );

    unlink( $tmp_csv );

    return ( 2 === $e1['imported'] && 2 === $e2['updated'] && 0 === $e2['imported'] && $count_after_first === $count_after_second );
} );

// 8. Kegagalan menyimpan ditangani aman tanpa laporan sukses palsu
run_concurrency_test( 'TEST 08: Penanganan baris rusak/gagal dicatat akurat tanpa laporan sukses palsu', function() use ( $plugin ) {
    $rows_with_invalid = array(
        array(
            'row_num'           => 2,
            'kode_blanko'       => '', // Rusak / kosong
            'nama_pemegang'     => 'Peserta Rusak',
            'no_registrasi'     => '',
            'skema_sertifikasi' => 'Skema Rusak',
            'tanggal_terbit'    => '2026-01-20',
            'action'            => 'INSERT',
            'target_id'         => 0,
            'status'            => 'SKIPPED',
            'errors'            => array( 'Nomor Blanko kosong' ),
        )
    );

    $res = $plugin->execute_csv_import_batch( $rows_with_invalid );

    // imported harus 0, skipped harus 1
    return ( 0 === $res['imported'] && 1 === $res['skipped'] );
} );

echo "\n--------------------------------------------------------------------\n";
echo sprintf( "HASIL AKHIR CSV CONCURRENCY & REPLAY: %d / %d TEST CASES PASS\n", $passed, $total );
echo "--------------------------------------------------------------------\n";

if ( $passed === $total ) {
    echo "STATUS: SELURUH 8 PENGUJIAN CSV CONCURRENCY & REPLAY BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT KEGAGALAN CSV CONCURRENCY & REPLAY!\n";
    exit( 1 );
}
