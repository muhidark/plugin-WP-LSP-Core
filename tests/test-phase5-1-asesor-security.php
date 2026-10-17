<?php
/**
 * Test Suite: Phase 5.1 — Task 4 Asesor Security & REST API Testing
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

// Inisialisasi REST Server WordPress
if ( ! defined( 'REST_REQUEST' ) ) {
    define( 'REST_REQUEST', true );
}
$wp_rest_server = rest_get_server();

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
echo "   PHASE 5.1 TASK 4 — ASESOR SECURITY & REST API (REAL USERS)       \n";
echo "====================================================================\n\n";

// Bersihkan data dummy lama
$wpdb->query( "DELETE FROM {$wpdb->users} WHERE user_login IN ('asesor_sec_test', 'user_lain_test')" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id NOT IN (SELECT ID FROM {$wpdb->users})" );

$plugin = LSP_Core_Management::get_instance();
$passed = 0;
$total  = 0;

function run_asesor_test( $name, $callback ) {
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

// 1. Setup User Asesor & User Lain oleh Administrator
wp_set_current_user( 1 );
$asesor_id = wp_create_user( 'asesor_sec_test', 'AsesorSecPass2026!', 'asesor.sec@lspkomunikasi.id' );
$u_asesor  = new WP_User( $asesor_id );
$u_asesor->set_role( 'asesor' );
wp_update_user( array(
    'ID'           => $asesor_id,
    'first_name'   => 'Asesor',
    'last_name'    => 'Resmi BNSP',
    'display_name' => 'Asesor Resmi BNSP',
) );
update_user_meta( $asesor_id, 'nomor_met', 'MET.000.888888.2024' );
update_user_meta( $asesor_id, 'bidang_keahlian_asesor', array( 'Public Relations' ) );
update_user_meta( $asesor_id, 'foto_asesor', 'https://lspkomunikasi.id/uploads/foto_asli.jpg' );

$other_user_id = wp_create_user( 'user_lain_test', 'OtherUserPass2026!', 'other@lspkomunikasi.id' );
$u_other = new WP_User( $other_user_id );
$u_other->set_role( 'editor' );

// 1. Asesor dapat membuat artikel draf
wp_set_current_user( $asesor_id );
$draft_post_id = 0;
run_asesor_test( 'TEST 01: Asesor dapat membuat artikel draft', function() use ( $asesor_id, &$draft_post_id ) {
    $draft_post_id = wp_insert_post( array(
        'post_title'   => 'Draft Riset Asesor',
        'post_content' => 'Konten draf riset komunikasi.',
        'post_status'  => 'draft',
        'post_type'    => 'post',
    ) );
    $p = get_post( $draft_post_id );
    return ( 'draft' === $p->post_status && (int) $p->post_author === $asesor_id );
} );

// 2. Asesor dapat mengedit draft milik sendiri
run_asesor_test( 'TEST 02: Asesor dapat mengedit draft milik sendiri', function() use ( &$draft_post_id ) {
    wp_update_post( array(
        'ID'         => $draft_post_id,
        'post_title' => 'Draft Riset Asesor Diperbarui',
    ) );
    $p = get_post( $draft_post_id );
    return ( 'Draft Riset Asesor Diperbarui' === $p->post_title );
} );

// 3. Asesor dapat mengajukan artikel untuk review (status pending) dan mengeditnya
run_asesor_test( 'TEST 03: Asesor dapat mengirimkan untuk review dan mengedit artikel pending miliknya', function() use ( &$draft_post_id ) {
    wp_update_post( array(
        'ID'          => $draft_post_id,
        'post_status' => 'pending',
    ) );
    $p_pending = get_post( $draft_post_id );

    wp_update_post( array(
        'ID'         => $draft_post_id,
        'post_title' => 'Pending Riset Asesor (Revisi Pra-Review)',
    ) );
    $p_revised = get_post( $draft_post_id );

    return ( 'pending' === $p_pending->post_status && 'Pending Riset Asesor (Revisi Pra-Review)' === $p_revised->post_title );
} );

// 4. Asesor TIDAK DAPAT mem-publish langsung (dipaksa jadi pending)
run_asesor_test( 'TEST 04: Percobaan publish langsung oleh Asesor dipaksa menjadi pending', function() {
    $bypass_id = wp_insert_post( array(
        'post_title'   => 'Coba Bypass Publish',
        'post_content' => 'Mencoba terbit langsung tanpa admin.',
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ) );
    $p = get_post( $bypass_id );
    return ( 'pending' === $p->post_status );
} );

// 5. Asesor TIDAK DAPAT mengedit artikel yang sudah berstatus 'publish' (Strictly Read-Only)
run_asesor_test( 'TEST 05: Artikel yang telah dipublish Admin strictly Read-Only bagi Asesor', function() use ( $asesor_id, &$draft_post_id ) {
    // Admin menerbitkan artikel
    wp_set_current_user( 1 );
    wp_update_post( array(
        'ID'          => $draft_post_id,
        'post_status' => 'publish',
    ) );

    // Kembali login sebagai Asesor
    wp_set_current_user( $asesor_id );
    $can_edit = current_user_can( 'edit_post', $draft_post_id );

    // Coba manipulasi konten artikel yang sudah publish via update
    wp_update_post( array(
        'ID'           => $draft_post_id,
        'post_title'   => 'Manipulasi Judul Publish',
        'post_content' => 'Manipulasi konten',
    ) );
    $p = get_post( $draft_post_id );

    return ( false === $can_edit && 'Pending Riset Asesor (Revisi Pra-Review)' === $p->post_title && 'publish' === $p->post_status );
} );

// 6. Asesor TIDAK DAPAT mengedit artikel milik orang lain
run_asesor_test( 'TEST 06: Asesor dilarang keras mengedit artikel milik pengguna lain', function() use ( $other_user_id, $asesor_id ) {
    // User lain buat post
    wp_set_current_user( $other_user_id );
    $other_post_id = wp_insert_post( array(
        'post_title'   => 'Post Milik Editor Lain',
        'post_content' => 'Konten editor.',
        'post_status'  => 'draft',
        'post_type'    => 'post',
    ) );

    // Asesor coba edit
    wp_set_current_user( $asesor_id );
    $can_edit = current_user_can( 'edit_post', $other_post_id );

    return ( false === $can_edit );
} );

// 7. Asesor memiliki izin upload_files untuk media sendiri
run_asesor_test( 'TEST 07: Asesor memiliki kapabilitas upload_files', function() {
    return current_user_can( 'upload_files' );
} );

// 8. Asesor dapat mengubah email dan password akunnya sendiri
run_asesor_test( 'TEST 08: Asesor diizinkan mengubah email dan password akunnya sendiri', function() use ( $asesor_id ) {
    $res = @wp_update_user( array(
        'ID'         => $asesor_id,
        'user_email' => 'asesor.baru@lspkomunikasi.id',
        'user_pass'  => 'NewSecurePass2026!#',
    ) );
    $u = get_userdata( $asesor_id );
    return ( ! is_wp_error( $res ) && 'asesor.baru@lspkomunikasi.id' === $u->user_email );
} );

// 9. Asesor TIDAK DAPAT mengubah Nomor MET (Server-side Enforcement)
run_asesor_test( 'TEST 09: Server-side otorisasi menolak modifikasi Nomor MET oleh Asesor', function() use ( $asesor_id, $plugin ) {
    $_POST['lsp_asesor_profile_nonce'] = wp_create_nonce( 'lsp_save_asesor_profile' );
    $_POST['nomor_met']                = 'MET.HACKED.999999';
    $plugin->save_asesor_user_profile_fields( $asesor_id );

    $saved_met = get_user_meta( $asesor_id, 'nomor_met', true );
    return ( 'MET.000.888888.2024' === $saved_met );
} );

// 10. Asesor TIDAK DAPAT mengubah Bidang Kompetensi (Server-side Enforcement)
run_asesor_test( 'TEST 10: Server-side otorisasi menolak modifikasi Bidang Kompetensi oleh Asesor', function() use ( $asesor_id, $plugin ) {
    $_POST['bidang_keahlian_asesor'] = array( 'Fake Competence' );
    $plugin->save_asesor_user_profile_fields( $asesor_id );

    $saved_bidang = get_user_meta( $asesor_id, 'bidang_keahlian_asesor', true );
    return ( in_array( 'Public Relations', (array) $saved_bidang, true ) && ! in_array( 'Fake Competence', (array) $saved_bidang, true ) );
} );

// 11. Asesor TIDAK DAPAT mengubah Foto Resmi Asesor
run_asesor_test( 'TEST 11: Server-side otorisasi menolak modifikasi Foto Resmi oleh Asesor', function() use ( $asesor_id, $plugin ) {
    $_POST['foto_asesor'] = 'https://malicious.com/fake.jpg';
    $plugin->save_asesor_user_profile_fields( $asesor_id );

    $saved_foto = get_user_meta( $asesor_id, 'foto_asesor', true );
    return ( 'https://lspkomunikasi.id/uploads/foto_asli.jpg' === $saved_foto );
} );

// 12. Asesor TIDAK DAPAT mengubah Nama Resmi Asesor (protect_asesor_user_data)
run_asesor_test( 'TEST 12: Server-side otorisasi melindungi Nama Resmi profil Asesor dari perubahan mandiri', function() use ( $asesor_id ) {
    wp_update_user( array(
        'ID'           => $asesor_id,
        'first_name'   => 'Manipulated First',
        'last_name'    => 'Manipulated Last',
        'display_name' => 'Hacker Name',
    ) );
    $u = get_userdata( $asesor_id );
    return ( 'Asesor' === $u->first_name && 'Resmi BNSP' === $u->last_name );
} );

// 13. REST API Protection: Asesor tidak dapat memodifikasi CPT Sertifikat via REST API
run_asesor_test( 'TEST 13: Proteksi REST API — Asesor dilarang mengakses/membuat CPT Data Sertifikat', function() use ( $asesor_id ) {
    $request = new WP_REST_Request( 'POST', '/wp/v2/data_sertifikat' );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( json_encode( array( 'title' => 'Hacked Cert' ) ) );

    $response = rest_do_request( $request );
    // Karena CPT data_sertifikat tidak show_in_rest atau ditolak otorisasi, statusnya harus 404 atau 401/403
    $status = $response->get_status();
    return ( in_array( $status, array( 401, 403, 404 ), true ) );
} );

// 14. REST API Protection: Asesor mencoba publish post via REST API otomatis dipaksa pending
run_asesor_test( 'TEST 14: Proteksi REST API — Upaya publish artikel via REST API tetap dipaksa menjadi pending', function() use ( $asesor_id ) {
    $request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
    $request->set_param( 'title', 'Artikel via REST API' );
    $request->set_param( 'content', 'Mencoba status publish via REST' );
    $request->set_param( 'status', 'publish' );

    $response = rest_do_request( $request );
    $data = $response->get_data();

    // Respons berhasil membuat post tapi statusnya bukan 'publish' melainkan 'pending' atau 'draft'
    $post_id = $data['id'] ?? 0;
    if ( $post_id > 0 ) {
        $p = get_post( $post_id );
        return ( 'pending' === $p->post_status || 'draft' === $p->post_status );
    }
    // Jika REST posts memerlukan nonce dan menolak dengan 401/403, juga aman
    return ( in_array( $response->get_status(), array( 401, 403 ), true ) );
} );

echo "\n--------------------------------------------------------------------\n";
echo sprintf( "HASIL AKHIR KEAMANAN ROLE ASESOR: %d / %d TEST CASES PASS\n", $passed, $total );
echo "--------------------------------------------------------------------\n";

if ( $passed === $total ) {
    echo "STATUS: SELURUH 14 PENGUJIAN KEAMANAN ROLE ASESOR BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT KEGAGALAN KEAMANAN ROLE ASESOR!\n";
    exit( 1 );
}
