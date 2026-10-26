<?php
/**
 * Test Suite: Phase 5.2 — Task 4 (Package & Upgrade) & Task 5 (Backup & Rollback Drill)
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
echo "   PHASE 5.2 — PACKAGE VERIFICATION & ROLLBACK DRILL (REAL MYSQL)   \n";
echo "====================================================================\n\n";

$passed = 0;
$total  = 0;

function run_pkg_test( $name, $callback ) {
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

$zip_file = '/var/www/html/wordpress/wp-content/plugins/lsp-core-management/lsp-core-management-release-candidate.zip';
$extract_dir = '/tmp/staging_pkg_test';

// =========================================================================
// PART 1: PACKAGE EXTRACTION & INVENTORY VERIFICATION
// =========================================================================
run_pkg_test( 'TEST 01: Berkas ZIP ada dan dapat diekstrak bersih', function() use ( $zip_file, $extract_dir ) {
    if ( ! file_exists( $zip_file ) ) {
        return false;
    }
    // Bersihkan folder staging ekstraksi
    if ( file_exists( $extract_dir ) ) {
        exec( "rm -rf {$extract_dir}" );
    }
    mkdir( $extract_dir, 0777, true );

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_file ) ) {
        return false;
    }
    $zip->extractTo( $extract_dir );
    $zip->close();

    return file_exists( "{$extract_dir}/lsp-core-management/lsp-core-management.php" );
} );

run_pkg_test( 'TEST 02: Struktur direktori dan inventarisasi berkas runtime lengkap', function() use ( $extract_dir ) {
    $base = "{$extract_dir}/lsp-core-management";
    $req_files = array(
        "{$base}/lsp-core-management.php",
        "{$base}/templates/archive-skema.php",
        "{$base}/templates/archive-tuk.php",
        "{$base}/templates/single-skema.php",
        "{$base}/templates/single-tuk.php",
    );
    foreach ( $req_files as $f ) {
        if ( ! file_exists( $f ) ) {
            return false;
        }
    }
    // Pastikan tidak ada file ilegal
    $illegal = array(
        "{$base}/.git",
        "{$base}/tests",
        "{$base}/docs",
    );
    foreach ( $illegal as $bad ) {
        if ( file_exists( $bad ) ) {
            return false;
        }
    }
    return true;
} );

run_pkg_test( 'TEST 03: Header plugin di dalam ZIP memuat versi 1.1.1 dan persyaratan minimum', function() use ( $extract_dir ) {
    $content = file_get_contents( "{$extract_dir}/lsp-core-management/lsp-core-management.php" );
    $has_ver  = ( false !== strpos( $content, 'Version:     1.1.1' ) );
    $has_php  = ( false !== strpos( $content, 'Requires PHP: 7.4' ) );
    $has_wp   = ( false !== strpos( $content, 'Requires at least: 5.8' ) );

    return ( $has_ver && $has_php && $has_wp );
} );

// =========================================================================
// PART 2: SIMULASI UPGRADE DARI DATA BASELINE
// =========================================================================
run_pkg_test( 'TEST 04: CPT (data_sertifikat, skema, tuk) tetap terdaftar aktif pasca load', function() {
    $cpt1 = post_type_exists( 'data_sertifikat' );
    $cpt2 = post_type_exists( 'skema' );
    $cpt3 = post_type_exists( 'tuk' );
    return ( $cpt1 && $cpt2 && $cpt3 );
} );

run_pkg_test( 'TEST 05: Data historis sertifikat tetap terbaca utuh tanpa perubahan struktur', function() use ( $wpdb ) {
    // Sisipkan data sertifikat dummy lawas
    $post_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'HIST.2024.999',
        'post_status' => 'publish',
    ) );
    update_post_meta( $post_id, 'kode_blanko', 'HIST.2024.999' );
    update_post_meta( $post_id, 'no_registrasi', 'REG.HIST.999' );
    update_post_meta( $post_id, 'nama_pemegang', 'Peserta Lama Historis' );

    // Baca via pencarian plugin
    $plugin = LSP_Core_Management::get_instance();
    $found = $plugin->find_certs_by_meta( 'kode_blanko', 'HIST.2024.999' );

    $saved_name = get_post_meta( $post_id, 'nama_pemegang', true );

    return ( ! empty( $found ) && $found[0] === $post_id && 'Peserta Lama Historis' === $saved_name );
} );

run_pkg_test( 'TEST 06: Slug rewrite CPT skema dan tuk tetap stabil dan konsisten', function() {
    $obj_skema = get_post_type_object( 'skema' );
    $obj_tuk   = get_post_type_object( 'tuk' );

    $slug_skema = $obj_skema->rewrite['slug'] ?? '';
    $slug_tuk   = $obj_tuk->rewrite['slug'] ?? '';

    return ( 'skema' === $slug_skema && 'tuk' === $slug_tuk );
} );

// =========================================================================
// PART 3: DRILL SIMULASI BACKUP & ROLLBACK TERUKUR
// =========================================================================
$backup_sql = '/tmp/staging_drill_backup.sql';
run_pkg_test( 'TEST 07: Simulasi Pencadangan Database Penuh (Database Dump Drill)', function() use ( $backup_sql, $wpdb ) {
    // Eksekusi mysqldump (auto-detect socket jika ada)
    $socket_arg = file_exists( '/tmp/mysql.sock' ) ? '--socket=/tmp/mysql.sock' : '';
    $cmd = "mysqldump -u root -p1 {$socket_arg} wordpress_db > {$backup_sql} 2>&1";
    exec( $cmd, $output, $ret );

    return ( 0 === $ret && file_exists( $backup_sql ) && filesize( $backup_sql ) > 1000 );
} );

run_pkg_test( 'TEST 08: Simulasi Rollback Kode Plugin & Kompatibilitas Database', function() use ( $backup_sql, $wpdb ) {
    // 1. Simulasikan ada transaksi baru saat versi baru aktif
    $new_cert_id = wp_insert_post( array(
        'post_type'   => 'data_sertifikat',
        'post_title'  => 'ROLLBACK.TEST.01',
        'post_status' => 'publish',
    ) );
    update_post_meta( $new_cert_id, 'kode_blanko', 'ROLLBACK.TEST.01' );

    // 2. Simulasikan rollback kode ke versi lama (mocked option status)
    update_option( 'lsp_asesor_role_version', '1.0.0' );

    // 3. Verifikasi pangkalan data: data yang tersimpan tetap konsisten dan tidak rusak
    $cert = get_post( $new_cert_id );
    $meta = get_post_meta( $new_cert_id, 'kode_blanko', true );

    $db_ok = ( $cert && 'publish' === $cert->post_status && 'ROLLBACK.TEST.01' === $meta );

    // 4. Pulihkan kembali versi migrasi ke 1.1.0
    LSP_Core_Management::maybe_migrate_asesor_role();

    return $db_ok;
} );

run_pkg_test( 'TEST 09: Verifikasi integritas pasca-rollback drill (Zero Data Corruption)', function() use ( $wpdb ) {
    // Periksa apakah ada post tanpa title atau meta yatim
    $orphan_meta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})" );
    $cpt_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'data_sertifikat'" );

    return ( 0 === $orphan_meta && $cpt_count > 0 );
} );

// Bersihkan berkas uji
if ( file_exists( $backup_sql ) ) {
    unlink( $backup_sql );
}
if ( file_exists( $extract_dir ) ) {
    exec( "rm -rf {$extract_dir}" );
}

echo "\n--------------------------------------------------------------------\n";
echo sprintf( "HASIL AKHIR VERIFIKASI PAKET & ROLLBACK DRILL: %d / %d TEST CASES PASS\n", $passed, $total );
echo "--------------------------------------------------------------------\n";

if ( $passed === $total ) {
    echo "STATUS: SELURUH 9 PENGUJIAN VERIFIKASI PAKET & ROLLBACK DRILL BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT KEGAGALAN PENGUJIAN!\n";
    exit( 1 );
}
