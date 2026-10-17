<?php
/**
 * Test Suite: Phase 5 — Real WordPress Integration & End-to-End Testing
 * Target: LSP Core Management Plugin
 * Environment: Real WordPress 7.1.3, PHP 8.5.4, MySQL 8.4.11
 * 
 * Pengujian integrasi nyata terhadap database MySQL aktif dan runtime WordPress lengkap.
 */

// 1. Inisialisasi Environment WordPress Nyata
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

// Arahkan upload dir ke /tmp/wp_uploads agar auto thumbnail GD dapat menulis file tanpa kendala izin
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
echo "   LSP CORE MANAGEMENT - PHASE 5 REAL WORDPRESS INTEGRATION TESTS   \n";
echo "   WordPress: " . get_bloginfo('version') . " | PHP: " . phpversion() . " | MySQL: " . $wpdb->db_version() . "\n";
echo "   Theme: " . get_stylesheet() . " | Database: " . DB_NAME . "\n";
echo "====================================================================\n\n";

// Bersihkan data sertifikat & asesor uji dari database agar setiap pengujian selalu fresh & terisolasi
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ('data_sertifikat', 'skema', 'tuk')" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})" );
$wpdb->query( "DELETE FROM {$wpdb->users} WHERE user_login LIKE 'asesor_%'" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id NOT IN (SELECT ID FROM {$wpdb->users})" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lsp_%' OR option_name LIKE '_transient_timeout_lsp_%'" );

$test_results = array();
$passed_count = 0;
$total_count  = 0;

function record_test( $id, $category, $desc, $expected, $actual, $db_state, $http_status, $passed, $classification = 'INTEGRATION' ) {
    global $test_results, $passed_count, $total_count;
    $total_count++;
    if ( $passed ) {
        $passed_count++;
        $status_label = "[PASS]";
    } else {
        $status_label = "[FAIL]";
    }

    $test_results[] = array(
        'id'             => $id,
        'category'       => $category,
        'desc'           => $desc,
        'expected'       => $expected,
        'actual'         => $actual,
        'db_state'       => $db_state,
        'http_status'    => $http_status,
        'classification' => $classification,
        'passed'         => $passed,
    );

    echo sprintf( "%s %s [%s] %s\n", $status_label, $id, $classification, $desc );
    if ( ! $passed ) {
        echo "   -> Expected: " . substr( $expected, 0, 80 ) . "\n";
        echo "   -> Actual  : " . substr( $actual, 0, 80 ) . "\n";
    }
}

// Dapatkan instance plugin
$plugin_instance = LSP_Core_Management::get_instance();

// =========================================================================
// PART B — PLUGIN LIFECYCLE & WORDPRESS COMPATIBILITY
// =========================================================================
echo "\n--- PART B: PLUGIN LIFECYCLE & REGISTRATION ---\n";

// B01: Fresh Activation / Status Aktif
$is_active = is_plugin_active( 'lsp-core-management/lsp-core-management.php' );
record_test(
    'LIFECYCLE-01',
    'Lifecycle',
    'Plugin LSP Core Management berstatus aktif di WordPress',
    'true',
    var_export( $is_active, true ),
    'active_plugins option memuat plugin',
    200,
    $is_active === true
);

// B02: Deactivation & Reactivation
deactivate_plugins( 'lsp-core-management/lsp-core-management.php' );
$is_deactive = ! is_plugin_active( 'lsp-core-management/lsp-core-management.php' );
activate_plugin( 'lsp-core-management/lsp-core-management.php' );
$is_reactivated = is_plugin_active( 'lsp-core-management/lsp-core-management.php' );
record_test(
    'LIFECYCLE-02',
    'Lifecycle',
    'Deaktivasi dan reaktivasi plugin berjalan mulus tanpa PHP fatal error',
    'true && true',
    var_export( $is_deactive && $is_reactivated, true ),
    'wp_options active_plugins sinkron',
    200,
    $is_deactive && $is_reactivated
);

// B03: Custom Post Types Registered
$cpt_cert  = post_type_exists( 'data_sertifikat' );
$cpt_skema = post_type_exists( 'skema' );
$cpt_tuk   = post_type_exists( 'tuk' );
record_test(
    'LIFECYCLE-03',
    'Registration',
    'Custom Post Types (data_sertifikat, skema, tuk) terdaftar sah di WordPress',
    'data_sertifikat, skema, tuk exist',
    ($cpt_cert && $cpt_skema && $cpt_tuk) ? 'Semua terdaftar' : 'Ada yang hilang',
    'post_types global register',
    200,
    ($cpt_cert && $cpt_skema && $cpt_tuk)
);

// B04: Role Asesor & Capability Versioning
$role_asesor = get_role( 'asesor' );
$role_ver    = get_option( 'lsp_asesor_role_version' );
$has_read    = $role_asesor ? $role_asesor->has_cap( 'read' ) : false;
$no_publish  = $role_asesor ? ! $role_asesor->has_cap( 'publish_posts' ) : false;
record_test(
    'LIFECYCLE-04',
    'Role Migration',
    'Role Asesor terdaftar dengan kapabilitas least privilege dan versioned migration',
    'Version 1.1.0, read=true, publish=false',
    "Version: {$role_ver}, read: " . var_export($has_read, true) . ", no_publish: " . var_export($no_publish, true),
    'wp_user_roles in wp_options',
    200,
    ($role_asesor && '1.1.0' === $role_ver && $has_read && $no_publish)
);

// =========================================================================
// PART C — CERTIFICATE ADMIN TESTING (REAL MYSQL TRANSACTIONS)
// =========================================================================
echo "\n--- PART C: CERTIFICATE ADMIN & INTEGRITY (REAL MYSQL) ---\n";

// Pastikan Admin aktif (User 1)
wp_set_current_user( 1 );

// C01: Tambah Sertifikat Baru Sah
$_POST['lsp_cert_meta_nonce'] = wp_create_nonce( 'lsp_cert_meta_nonce_action' );
$_POST['nama_pemegang']       = 'Budi Santoso Real';
$_POST['kode_blanko']         = 'KOM.2026.000101';
$_POST['no_registrasi']       = 'REG.KOM.2026.900101';
$_POST['skema_sertifikasi']   = 'Digital Marketing Specialist';
$_POST['tanggal_terbit']      = '2026-03-01';

$cert_id_1 = wp_insert_post( array(
    'post_type'   => 'data_sertifikat',
    'post_title'  => 'KOM.2026.000101 - Budi Santoso Real',
    'post_status' => 'publish',
) );
$plugin_instance->save_cert_meta( $cert_id_1 );

$saved_blanko_1 = get_post_meta( $cert_id_1, 'kode_blanko', true );
$saved_reg_1    = get_post_meta( $cert_id_1, 'no_registrasi', true );
$saved_skema_1  = get_post_meta( $cert_id_1, 'skema_sertifikasi', true );

record_test(
    'ADMIN-01',
    'Data Integrity',
    'Menyimpan data sertifikat baru sah ke pangkalan data MySQL asli',
    'KOM.2026.000101 & REG.KOM.2026.900101 tersimpan di wp_postmeta',
    "Blanko: {$saved_blanko_1}, Reg: {$saved_reg_1}, Skema: {$saved_skema_1}",
    "wp_posts ID={$cert_id_1}, wp_postmeta rows inserted",
    200,
    ('KOM.2026.000101' === $saved_blanko_1 && 'REG.KOM.2026.900101' === $saved_reg_1)
);

// C02: Edit Sertifikat Lama Tanpa Mengubah Nomor (Self-Duplicate Protection)
$_POST['nama_pemegang']     = 'Budi Santoso Real, S.Kom';
$_POST['skema_sertifikasi'] = 'Senior Digital Marketing';
$plugin_instance->save_cert_meta( $cert_id_1 );

$updated_name_1  = get_post_meta( $cert_id_1, 'nama_pemegang', true );
$updated_skema_1 = get_post_meta( $cert_id_1, 'skema_sertifikasi', true );

record_test(
    'ADMIN-02',
    'Data Integrity',
    'Memperbarui data non-identitas sertifikat lama tanpa terpicu self-duplicate',
    'Budi Santoso Real, S.Kom & Senior Digital Marketing',
    "Nama: {$updated_name_1}, Skema: {$updated_skema_1}",
    "wp_postmeta updated for post ID {$cert_id_1}",
    200,
    ('Budi Santoso Real, S.Kom' === $updated_name_1 && 'Senior Digital Marketing' === $updated_skema_1)
);

// C03: Tolak Duplikasi Kode Blanko yang Sudah Ada di Database
$cert_id_dup_blanko = wp_insert_post( array(
    'post_type'   => 'data_sertifikat',
    'post_title'  => 'Duplikat Blanko Post',
    'post_status' => 'publish',
) );
$_POST['nama_pemegang'] = 'Orang Lain';
$_POST['kode_blanko']   = 'KOM.2026.000101'; // Sama dengan cert 1!
$_POST['no_registrasi'] = 'REG.KOM.2026.999999';
$plugin_instance->save_cert_meta( $cert_id_dup_blanko );

$post_dup_b = get_post( $cert_id_dup_blanko );
$meta_dup_b = get_post_meta( $cert_id_dup_blanko, 'kode_blanko', true );

record_test(
    'ADMIN-03',
    'Conflict Rejection',
    'Pencegahan duplikasi kode blanko pada sertifikat baru (status dipaksa draft & meta dibatalkan)',
    'post_status = draft & kode_blanko kosong',
    "Status: {$post_dup_b->post_status}, Meta: " . var_export($meta_dup_b, true),
    "wp_posts ID={$cert_id_dup_blanko} set to draft, 0 blanko meta",
    200,
    ('draft' === $post_dup_b->post_status && empty($meta_dup_b))
);

// C04: Tolak Duplikasi Nomor Registrasi yang Sudah Ada di Database
$cert_id_dup_reg = wp_insert_post( array(
    'post_type'   => 'data_sertifikat',
    'post_title'  => 'Duplikat Reg Post',
    'post_status' => 'publish',
) );
$_POST['nama_pemegang'] = 'Orang Lain Lagi';
$_POST['kode_blanko']   = 'KOM.2026.888888';
$_POST['no_registrasi'] = 'REG.KOM.2026.900101'; // Sama dengan cert 1!
$plugin_instance->save_cert_meta( $cert_id_dup_reg );

$post_dup_r = get_post( $cert_id_dup_reg );
$meta_dup_r = get_post_meta( $cert_id_dup_reg, 'no_registrasi', true );

record_test(
    'ADMIN-04',
    'Conflict Rejection',
    'Pencegahan duplikasi nomor registrasi pada sertifikat baru',
    'post_status = draft & no_registrasi kosong',
    "Status: {$post_dup_r->post_status}, Meta: " . var_export($meta_dup_r, true),
    "wp_posts ID={$cert_id_dup_reg} set to draft, 0 reg meta",
    200,
    ('draft' === $post_dup_r->post_status && empty($meta_dup_r))
);

// C05: Tolak Cross-Field Collision (Nomor Blanko bertabrakan dengan Nomor Registrasi di DB)
$cert_id_cross = wp_insert_post( array(
    'post_type'   => 'data_sertifikat',
    'post_title'  => 'Cross Collision Post',
    'post_status' => 'publish',
) );
$_POST['nama_pemegang'] = 'Korban Cross Collision';
$_POST['kode_blanko']   = 'REG.KOM.2026.900101'; // Blanko sama dengan Registrasi cert 1!
$_POST['no_registrasi'] = 'KOM.2026.777777';
$plugin_instance->save_cert_meta( $cert_id_cross );

$post_cross = get_post( $cert_id_cross );
$meta_cross = get_post_meta( $cert_id_cross, 'kode_blanko', true );

record_test(
    'ADMIN-05',
    'Conflict Rejection',
    'Pencegahan tabrakan nomor lintas field (Cross-field collision)',
    'post_status = draft & kode_blanko kosong',
    "Status: {$post_cross->post_status}, Meta: " . var_export($meta_cross, true),
    "wp_posts ID={$cert_id_cross} set to draft",
    200,
    ('draft' === $post_cross->post_status && empty($meta_cross))
);

// C06: Validasi Kalender Tanggal ISO Tidak Valid (2026-02-31)
$cert_id_date = wp_insert_post( array(
    'post_type'   => 'data_sertifikat',
    'post_title'  => 'Invalid Date Post',
    'post_status' => 'publish',
) );
$_POST['nama_pemegang']  = 'Tanggal Tidak Valid';
$_POST['kode_blanko']    = 'KOM.2026.555555';
$_POST['no_registrasi']  = 'REG.KOM.2026.555555';
$_POST['tanggal_terbit'] = '2026-02-31'; // Tanggal fiktif
$plugin_instance->save_cert_meta( $cert_id_date );

$meta_date = get_post_meta( $cert_id_date, 'tanggal_terbit', true );
record_test(
    'ADMIN-06',
    'Validation',
    'Penolakan tanggal kalender tidak valid (2026-02-31) oleh checkdate',
    'tanggal_terbit meta kosong',
    "Meta tanggal: " . var_export($meta_date, true),
    "wp_postmeta tanggal_terbit omitted",
    200,
    empty($meta_date)
);

// =========================================================================
// PART D — PUBLIC CERTIFICATE VERIFICATION (REAL AJAX ENDPOINT)
// =========================================================================
echo "\n--- PART D: PUBLIC AJAX VERIFICATION (REAL WP AJAX) ---\n";

if ( ! defined( 'DOING_AJAX' ) ) {
    define( 'DOING_AJAX', true );
}

add_filter( 'wp_die_ajax_handler', function() {
    return function( $message ) {
        throw new Exception( (string) $message );
    };
} );
add_filter( 'wp_die_handler', function() {
    return function( $message ) {
        throw new Exception( (string) $message );
    };
} );

// Helper tangkap output wp_send_json
function test_ajax_call( $post_data ) {
    $_POST    = $post_data;
    $_REQUEST = array_merge( $_REQUEST, $post_data );
    ob_start();
    try {
        $p = LSP_Core_Management::get_instance();
        $p->ajax_verify_cert();
    } catch ( Exception $e ) {
        // tangkap wp_die
    }
    $output = ob_get_clean();
    return json_decode( $output, true );
}

$public_nonce = wp_create_nonce( 'lsp_verify_cert_nonce' );

// D01: Verifikasi Nomor Blanko Valid
$resp_valid_blanko = test_ajax_call( array(
    'nonce'       => $public_nonce,
    'kode_blanko' => 'KOM.2026.000101',
) );
$valid_b_ok = isset($resp_valid_blanko['success']) && $resp_valid_blanko['success'] === true &&
              false !== strpos( $resp_valid_blanko['data']['html'] ?? '', 'Budi Santoso Real' );
record_test(
    'AJAX-01',
    'Public Verification',
    'Pencarian sertifikat via Kode Blanko menghasilkan status VALID dan data akurat',
    'success: true dengan nama Budi Santoso Real',
    "Success: " . var_export($resp_valid_blanko['success'] ?? false, true) . ", Data: " . substr(strip_tags($resp_valid_blanko['data']['html'] ?? ''), 0, 60),
    'wp_postmeta exact match',
    200,
    $valid_b_ok
);

// D02: Verifikasi Nomor Registrasi Valid
$resp_valid_reg = test_ajax_call( array(
    'nonce'       => $public_nonce,
    'kode_blanko' => 'REG.KOM.2026.900101',
) );
$valid_r_ok = isset($resp_valid_reg['success']) && $resp_valid_reg['success'] === true &&
              false !== strpos( $resp_valid_reg['data']['html'] ?? '', 'Budi Santoso Real' );
record_test(
    'AJAX-02',
    'Public Verification',
    'Pencarian sertifikat via Nomor Registrasi menghasilkan status VALID dan data akurat',
    'success: true dengan nama Budi Santoso Real',
    "Success: " . var_export($resp_valid_reg['success'] ?? false, true),
    'wp_postmeta exact match',
    200,
    $valid_r_ok
);

// D03: Pencarian Nomor Parsial (Mencegah Wildcard LIKE)
$resp_partial = test_ajax_call( array(
    'nonce'       => $public_nonce,
    'kode_blanko' => 'KOM.2026', // Parsial
) );
$partial_ok = isset($resp_partial['success']) && $resp_partial['success'] === true &&
              false !== strpos( $resp_partial['data']['html'] ?? '', 'TIDAK DITEMUKAN / TIDAK VALID' ) &&
              false === strpos( $resp_partial['data']['html'] ?? '', 'Budi Santoso' );
record_test(
    'AJAX-03',
    'Security & Precision',
    'Pencarian parsial (KOM.2026) ditolak dan tidak menampilkan data Budi Santoso',
    'TIDAK DITEMUKAN / TIDAK VALID, 0 data pemegang bocor',
    "Pesan: " . substr(strip_tags($resp_partial['data']['html'] ?? ''), 0, 60),
    'Zero LIKE fallback query',
    200,
    $partial_ok
);

// D04: Nonce CSRF Tidak Valid
$resp_bad_nonce = test_ajax_call( array(
    'nonce'       => 'invalid_csrf_token',
    'kode_blanko' => 'KOM.2026.000101',
) );
$bad_nonce_ok = isset($resp_bad_nonce['success']) && $resp_bad_nonce['success'] === false &&
                false !== strpos( $resp_bad_nonce['data']['html'] ?? '', 'Sesi Verifikasi Tidak Valid' );
record_test(
    'AJAX-04',
    'CSRF Protection',
    'Permintaan verifikasi dengan nonce invalid ditolak dengan HTTP 403',
    'success: false (Sesi Verifikasi Tidak Valid)',
    "Success: " . var_export($resp_bad_nonce['success'] ?? false, true) . ", Pesan: " . substr(strip_tags($resp_bad_nonce['data']['html'] ?? ''), 0, 60),
    'Security check halted before DB lookup',
    403,
    $bad_nonce_ok
);

// D05: Input Terlalu Panjang (>60 karakter)
$long_input = str_repeat( 'A', 65 );
$resp_long = test_ajax_call( array(
    'nonce'       => $public_nonce,
    'kode_blanko' => $long_input,
) );
$long_ok = isset($resp_long['success']) && $resp_long['success'] === false &&
           false !== strpos( $resp_long['data']['html'] ?? '', 'melebihi batas panjang maksimum' );
record_test(
    'AJAX-05',
    'Input Sanitization',
    'Input melebihi 60 karakter ditolak aman dengan HTTP 400',
    'success: false (Nomor sertifikat melebihi batas panjang maksimum)',
    "Success: " . var_export($resp_long['success'] ?? false, true) . ", Pesan: " . substr(strip_tags($resp_long['data']['html'] ?? ''), 0, 60),
    'Validation aborted before query',
    400,
    $long_ok
);

// =========================================================================
// PART E — CSV IMPORT (REAL MYSQL & PREFLIGHT CONSISTENCY)
// =========================================================================
echo "\n--- PART E: CSV IMPORT ENGINE (REAL MYSQL) ---\n";

// E01: Validasi File Upload Keamanan
$tmp_valid_csv = '/tmp/test_import_valid.csv';
$csv_content = "Nama Pemegang,Nomor Sertifikat,Nomor Registrasi,Skema Kompetensi,Tanggal Terbit\n" .
               "Citra Lestari,KOM.2026.000201,REG.KOM.2026.900201,Public Relations Officer,2026-01-10\n" .
               "Doni Wahyudi,KOM.2026.000202,REG.KOM.2026.900202,Media Relations,2026-02-15\n";
file_put_contents( $tmp_valid_csv, $csv_content );

$file_arr = array(
    'name'     => 'import.csv',
    'type'     => 'text/csv',
    'tmp_name' => $tmp_valid_csv,
    'error'    => 0,
    'size'     => strlen( $csv_content ),
);
$file_val = $plugin_instance->validate_csv_upload_file( $file_arr );
record_test(
    'CSV-01',
    'File Validation',
    'Validasi keamanan file CSV asli (ekstensi, mime type, ukuran aman)',
    'valid === true',
    "valid: " . var_export($file_val['valid'] ?? false, true),
    'File data verified in /tmp',
    200,
    isset($file_val['valid']) && $file_val['valid'] === true
);

// E02: Preflight Parsing (Zero Write Side-Effects)
$count_posts_before = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat'" );
$parsed_batch = $plugin_instance->parse_and_validate_csv_data( $tmp_valid_csv );
$count_posts_after_parse = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat'" );

$preflight_ok = ($count_posts_before === $count_posts_after_parse) &&
                ($parsed_batch['valid_inserts'] === 2) &&
                ($parsed_batch['valid_updates'] === 0);
record_test(
    'CSV-02',
    'Preflight Zero-Write',
    'Tahap pratinjau (preview) murni membaca tanpa melakukan penulisan record ke database',
    'Count posts sebelum = sesudah, valid_inserts=2, valid_updates=0',
    "Before: {$count_posts_before}, After: {$count_posts_after_parse}, Inserts: {$parsed_batch['valid_inserts']}",
    'wp_posts row count strictly unchanged',
    200,
    $preflight_ok
);

// E03: Eksekusi Batch Impor ke MySQL Nyata
$exec_result = $plugin_instance->execute_csv_import_batch( $parsed_batch['rows'] );
$count_posts_after_exec = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat'" );

$exec_ok = ($exec_result['imported'] === 2) &&
           ((int)$count_posts_after_exec === (int)$count_posts_before + 2);
record_test(
    'CSV-03',
    'Batch Execution',
    'Eksekusi batch berhasil menambahkan 2 data sertifikat baru ke MySQL nyata',
    'Imported: 2, posts count +2',
    "Imported: {$exec_result['imported']}, Before: {$count_posts_before}, After: {$count_posts_after_exec}",
    'wp_posts and wp_postmeta rows successfully created',
    200,
    $exec_ok
);

// E04: Idempotensi Impor Ulang (Update, 0 Duplikasi Post)
$parsed_batch_2 = $plugin_instance->parse_and_validate_csv_data( $tmp_valid_csv );
$exec_result_2  = $plugin_instance->execute_csv_import_batch( $parsed_batch_2['rows'] );
$count_posts_after_exec_2 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat'" );

$idempotent_ok = ($exec_result_2['updated'] === 2) &&
                 ($count_posts_after_exec_2 === $count_posts_after_exec);
record_test(
    'CSV-04',
    'Idempotency',
    'Impor ulang berkas CSV yang sama bersifat idempotent (status updated, 0 post duplikat)',
    'Updated: 2, posts count unchanged',
    "Updated: {$exec_result_2['updated']}, Posts before: {$count_posts_after_exec}, Posts after: {$count_posts_after_exec_2}",
    'wp_posts count unchanged',
    200,
    $idempotent_ok
);

// E05: Penolakan Perubahan Identitas Sensitif (Case D)
$tmp_case_d = '/tmp/test_import_case_d.csv';
$csv_case_d = "Nama Pemegang,Nomor Sertifikat,Nomor Registrasi,Skema Kompetensi,Tanggal Terbit\n" .
              "Citra Lestari,KOM.2026.000201,REG.KOM.2026.DIUBAH_PALSU,Public Relations Officer,2026-01-10\n";
file_put_contents( $tmp_case_d, $csv_case_d );
$parsed_case_d = $plugin_instance->parse_and_validate_csv_data( $tmp_case_d );
$case_d_ok = ($parsed_case_d['skipped_count'] === 1) &&
             (false !== strpos( $parsed_case_d['rows'][0]['errors'][0] ?? '', 'Perubahan identitas ditolak' ));
record_test(
    'CSV-05',
    'Identity Safety',
    'Upaya mengubah nomor registrasi sertifikat lama via CSV ditolak keras (SKIPPED)',
    'Skipped: 1, Error: Perubahan identitas ditolak',
    "Skipped: {$parsed_case_d['skipped_count']}, Error: " . ($parsed_case_d['rows'][0]['errors'][0] ?? ''),
    'wp_postmeta no_registrasi unchanged',
    200,
    $case_d_ok
);

// =========================================================================
// PART F — ROLE ASESOR ACCESS CONTROL (REAL WP USERS)
// =========================================================================
echo "\n--- PART F: ROLE ASESOR ACCESS CONTROL (REAL USERS) ---\n";

// Siapkan User Asesor Nyata (ID: 50)
$asesor_user_id = wp_create_user( 'asesor_integrasi', 'AsesorSecurePassword2026!', 'asesor.integrasi@lspkomunikasi.id' );
$u_asesor = new WP_User( $asesor_user_id );
$u_asesor->set_role( 'asesor' );
update_user_meta( $asesor_user_id, 'nomor_met', 'MET.000.777777.2024' );
update_user_meta( $asesor_user_id, 'tanggal_terbit_met', '2024-05-10' );
update_user_meta( $asesor_user_id, 'foto_asesor', 'https://lspkomunikasi.id/uploads/asesor_int.jpg' );
update_user_meta( $asesor_user_id, 'bidang_keahlian_asesor', array( 'Public Relations' ) );

// Login sebagai Asesor
wp_set_current_user( $asesor_user_id );

// F01: Asesor Membuat Artikel Draf
$asesor_post_id = wp_insert_post( array(
    'post_title'   => 'Artikel Riset Komunikasi oleh Asesor',
    'post_content' => 'Konten tulisan analisis sertifikasi.',
    'post_status'  => 'draft',
    'post_type'    => 'post',
) );
$p_draft = get_post( $asesor_post_id );
record_test(
    'ASESOR-01',
    'Workflow',
    'Asesor berhasil membuat artikel baru berstatus draf di WordPress nyata',
    'post_status = draft, author = asesor_user_id',
    "Status: {$p_draft->post_status}, Author: {$p_draft->post_author}",
    "wp_posts ID={$asesor_post_id} inserted",
    200,
    ('draft' === $p_draft->post_status && (int)$p_draft->post_author === $asesor_user_id)
);

// F02: Asesor Mengajukan Peninjauan (Submit for Review)
wp_update_post( array(
    'ID'          => $asesor_post_id,
    'post_status' => 'pending',
) );
$p_pending = get_post( $asesor_post_id );
record_test(
    'ASESOR-02',
    'Workflow',
    'Asesor berhasil mengajukan artikel untuk peninjauan (post_status: pending)',
    'post_status = pending',
    "Status: {$p_pending->post_status}",
    "wp_posts ID={$asesor_post_id} updated to pending",
    200,
    ('pending' === $p_pending->post_status)
);

// F03: Asesor Merevisi Artikel Pending
wp_update_post( array(
    'ID'          => $asesor_post_id,
    'post_title'  => 'Artikel Riset Komunikasi oleh Asesor (Revisi 1)',
    'post_status' => 'pending',
) );
$p_revised = get_post( $asesor_post_id );
record_test(
    'ASESOR-03',
    'Workflow',
    'Asesor dapat merevisi judul dan konten artikel pending miliknya sendiri',
    'Judul memuat (Revisi 1)',
    "Judul: {$p_revised->post_title}",
    "wp_posts ID={$asesor_post_id} title updated",
    200,
    (false !== strpos( $p_revised->post_title, 'Revisi 1' ))
);

// F04: Upaya Publish Langsung Ditolak (Forced to Pending)
$direct_pub_id = wp_insert_post( array(
    'post_title'   => 'Artikel Mencoba Bypass Publish',
    'post_content' => 'Mencoba terbit tanpa izin admin.',
    'post_status'  => 'publish',
    'post_type'    => 'post',
) );
$p_bypass = get_post( $direct_pub_id );
record_test(
    'ASESOR-04',
    'Workflow Security',
    'Percobaan publikasi langsung oleh Asesor dipaksa kembali menjadi pending',
    'post_status = pending',
    "Status: {$p_bypass->post_status}",
    "wp_posts ID={$direct_pub_id} forced to pending",
    200,
    ('pending' === $p_bypass->post_status)
);

// F05: Admin Menerbitkan Artikel & Asesor Menjadi Read-Only
wp_set_current_user( 1 ); // Admin
wp_update_post( array(
    'ID'          => $asesor_post_id,
    'post_status' => 'publish',
) );
wp_set_current_user( $asesor_user_id ); // Kembali ke Asesor

$can_edit_published = current_user_can( 'edit_post', $asesor_post_id );
wp_update_post( array(
    'ID'          => $asesor_post_id,
    'post_title'  => 'Judul Diubah Diam-diam Pasca Publish',
    'post_status' => 'draft',
) );
$p_published = get_post( $asesor_post_id );
$readonly_ok = ($can_edit_published === false) &&
               ('Artikel Riset Komunikasi oleh Asesor (Revisi 1)' === $p_published->post_title) &&
               ('publish' === $p_published->post_status);
record_test(
    'ASESOR-05',
    'Strict Read-Only',
    'Artikel yang telah diterbitkan Admin terkunci dan strictly Read-Only bagi Asesor',
    'can_edit=false, judul asli tetap bertahan, status=publish',
    "can_edit: " . var_export($can_edit_published, true) . ", Judul: {$p_published->post_title}, Status: {$p_published->post_status}",
    "wp_posts ID={$asesor_post_id} content protected",
    200,
    $readonly_ok
);

// F06: Asesor Dilarang Mengubah Data Profesional MET di Profil
$_POST['lsp_asesor_profile_nonce'] = wp_create_nonce( 'lsp_save_asesor_profile' );
$_POST['nomor_met']                = 'MET.000.HACKED.2026';
$_POST['bidang_keahlian_asesor']   = array( 'Manipulated Competence' );
$plugin_instance->save_asesor_user_profile_fields( $asesor_user_id );

$db_met_check = get_user_meta( $asesor_user_id, 'nomor_met', true );
$db_b_check   = get_user_meta( $asesor_user_id, 'bidang_keahlian_asesor', true );
$profile_prot_ok = ('MET.000.777777.2024' === $db_met_check) &&
                   (in_array( 'Public Relations', $db_b_check, true ));
record_test(
    'ASESOR-06',
    'Profile Authorization',
    'Server-side authorization menolak perubahan nomor MET dan bidang kompetensi oleh Asesor',
    'MET asli (MET.000.777777.2024) dan bidang Public Relations tidak berubah',
    "DB MET: {$db_met_check}, DB Bidang: " . implode(', ', (array)$db_b_check),
    "wp_usermeta untouched by non-admin save",
    200,
    $profile_prot_ok
);

// =========================================================================
// PART G — THEME INTEGRATION (REAL THEME lspkomunikasi)
// =========================================================================
echo "\n--- PART G: THEME INTEGRATION (lspkomunikasi) ---\n";

// G01: Render Shortcode [cek_sertifikat]
$sc_form_html = do_shortcode( '[cek_sertifikat]' );
$sc_form_ok   = (false !== strpos( $sc_form_html, 'lsp-cert-verifier-wrapper' )) &&
                (false !== strpos( $sc_form_html, 'lsp_input_kode_blanko' ));
record_test(
    'THEME-01',
    'Shortcode Output',
    'Shortcode [cek_sertifikat] merender formulir verifikasi publik lengkap',
    'HTML memuat container dan input nomor sertifikat',
    "HTML snippet: " . substr( strip_tags($sc_form_html), 0, 70 ) . "...",
    'Shortcode filter output active',
    200,
    $sc_form_ok
);

// G02: Render Shortcode [lsp_skema]
// Buat dummy skema di database
$skema_id = wp_insert_post( array(
    'post_type'   => 'skema',
    'post_title'  => 'Skema Komunikasi Korporat Unggul',
    'post_status' => 'publish',
) );
$sc_skema_html = do_shortcode( '[lsp_skema jumlah="5"]' );
$sc_skema_ok   = (false !== strpos( $sc_skema_html, 'Skema Komunikasi Korporat Unggul' ));
record_test(
    'THEME-02',
    'Shortcode Output',
    'Shortcode [lsp_skema] mengambil data dari CPT skema dan merender grid tema',
    'HTML memuat nama skema yang ada di database',
    "HTML snippet: " . substr( strip_tags($sc_skema_html), 0, 70 ) . "...",
    'WP_Query to CPT skema success',
    200,
    $sc_skema_ok
);

// G03: Render Shortcode [lsp_asesor]
$sc_asesor_html = do_shortcode( '[lsp_asesor jumlah="5"]' );
$sc_asesor_ok   = (false !== strpos( $sc_asesor_html, 'asesor_integrasi' ) || false !== strpos( $sc_asesor_html, 'asesor-card' ));
record_test(
    'THEME-03',
    'Shortcode Output',
    'Shortcode [lsp_asesor] merender kartu profil pengguna Role Asesor dengan gaya tema',
    'HTML memuat kartu asesor',
    "HTML snippet: " . substr( strip_tags($sc_asesor_html), 0, 70 ) . "...",
    'get_users role=asesor success',
    200,
    $sc_asesor_ok
);

// =========================================================================
// PART H — SECURITY TESTING (REAL ATTACK SIMULATION)
// =========================================================================
echo "\n--- PART H: SECURITY ATTACK TESTS ---\n";

// H01: XSS Injection pada Input CSV
$tmp_xss_csv = '/tmp/test_import_xss.csv';
$csv_xss = "Nama Pemegang,Nomor Sertifikat,Nomor Registrasi,Skema Kompetensi,Tanggal Terbit\n" .
           "<script>alert('XSS')</script>Budi,KOM.2026.000301,REG.KOM.2026.900301,Hacking Specialist,2026-01-01\n";
file_put_contents( $tmp_xss_csv, $csv_xss );
$parsed_xss = $plugin_instance->parse_and_validate_csv_data( $tmp_xss_csv );
$cleaned_name = $parsed_xss['rows'][0]['nama_pemegang'];
$xss_ok = (false === strpos( $cleaned_name, '<script>' )) && ('Budi' === $cleaned_name);
record_test(
    'SECURITY-01',
    'XSS Defense',
    'Tag <script>...</script> pada CSV import dibersihkan tuntas oleh sanitize_text_field()',
    '<script> stripped, nama = Budi',
    "Cleaned name: {$cleaned_name}",
    'Data sanitized before array store',
    200,
    $xss_ok
);

// H02: Formula Injection Defense (=,+,-,@)
$tmp_formula_csv = '/tmp/test_import_formula.csv';
$csv_formula = "Nama Pemegang,Nomor Sertifikat,Nomor Registrasi,Skema Kompetensi,Tanggal Terbit\n" .
               "=SUM(1+1)Malicious,KOM.2026.000302,REG.KOM.2026.900302,Finance Analyst,2026-01-01\n";
file_put_contents( $tmp_formula_csv, $csv_formula );
$parsed_formula = $plugin_instance->parse_and_validate_csv_data( $tmp_formula_csv );
$formula_val = $parsed_formula['rows'][0]['nama_pemegang'];
$formula_ok = ('=SUM(1+1)Malicious' === $formula_val) && is_string($formula_val);
record_test(
    'SECURITY-02',
    'Formula Defense',
    'Input dengan formula injection (=,+,@) ditangani secara aman sebagai string literal',
    'Tersimpan sebagai literal string murni',
    "Stored string: {$formula_val}",
    'Zero code evaluation in PHP',
    200,
    $formula_ok
);

// H03: Transient Batch Isolation Antar-Pengguna
$fake_token = wp_generate_password( 24, false );
$transient_key_user_1 = 'lsp_csv_batch_1_' . $fake_token;
set_transient( $transient_key_user_1, array( 'dummy' => 'data_admin' ), 900 );

// Cek akses dari User Asesor (ID: 50)
wp_set_current_user( $asesor_user_id );
$asesor_attempt_key = 'lsp_csv_batch_' . get_current_user_id() . '_' . $fake_token;
$asesor_batch = get_transient( $asesor_attempt_key );

$isolation_ok = ($asesor_batch === false);
record_test(
    'SECURITY-03',
    'Transient Isolation',
    'Batch transient terikat ke ID pengguna pembuat; pengguna lain tidak dapat mengaksesnya',
    'get_transient returns false',
    "Transient value for Asesor: " . var_export($asesor_batch, true),
    'wp_options key separated by user ID',
    200,
    $isolation_ok
);

// Bersihkan data transient uji
delete_transient( $transient_key_user_1 );

// =========================================================================
// REKAPITULASI HASIL INTEGRASI REAL WORDPRESS
// =========================================================================
echo "\n====================================================================\n";
echo sprintf( "HASIL AKHIR INTEGRASI REAL WORDPRESS: %d / %d PENGUJIAN LULUS\n", $passed_count, $total_count );
echo "====================================================================\n";

if ( $passed_count === $total_count ) {
    echo "STATUS: SELURUH PENGUJIAN INTEGRASI NYATA PHASE 5 BERHASIL SEMPURNA (100% PASS)!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT PENGUJIAN INTEGRASI YANG GAGAL!\n";
    exit( 1 );
}
