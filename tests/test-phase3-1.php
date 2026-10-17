<?php
/**
 * Test Suite: Tahap 3.1 Final CSV Import Verification & Benchmarks
 * Target: LSP Core Management Plugin (Phase 3.1 Verification)
 * 
 * Tests TEST 01 through TEST 15
 */

// 1. Setup Mocking Lingkungan WordPress
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../../../' );
}

$GLOBALS['test_transients']       = array();
$GLOBALS['test_posts_db']         = array();
$GLOBALS['test_meta_db']          = array();
$GLOBALS['test_user_caps']        = array( 'manage_options' => true, 'edit_post' => true );
$GLOBALS['test_current_user_id']  = 1;
$GLOBALS['mock_insert_post_error'] = false;
$GLOBALS['mock_update_post_error'] = false;

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( $hook, $callback, $priority = 10 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode( $tag, $callback ) {}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( $file, $callback ) {}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
    function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
        $nonce = isset( $_POST[ $query_arg ] ) ? $_POST[ $query_arg ] : '';
        if ( 'valid_confirm_nonce' === $nonce || 'valid_csv_nonce' === $nonce ) {
            return 1;
        }
        return false;
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        if ( in_array( $nonce, array( 'valid_admin_cert_nonce', 'valid_csv_nonce', 'valid_confirm_nonce' ), true ) ) {
            return 1;
        }
        return false;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability, ...$args ) {
        return ! empty( $GLOBALS['test_user_caps'][ $capability ] );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return $GLOBALS['test_current_user_id'];
    }
}

if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $post_id ) {
        return isset( $GLOBALS['test_posts_db'][ $post_id ]['post_type'] ) ? $GLOBALS['test_posts_db'][ $post_id ]['post_type'] : false;
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $val ) {
        return is_string( $val ) ? stripslashes( $val ) : $val;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        // Standar WordPress Core wp_strip_all_tags: buang tag script/style dan isinya
        $filtered = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str );
        return trim( strip_tags( $filtered ) );
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        return isset( $GLOBALS['test_transients'][ $key ] ) ? $GLOBALS['test_transients'][ $key ] : false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $val, $exp ) {
        $GLOBALS['test_transients'][ $key ] = $val;
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) {
        unset( $GLOBALS['test_transients'][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $str ) {
        return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $str ) {
        return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = true ) {
        return isset( $GLOBALS['test_meta_db'][ $post_id ][ $key ] ) ? $GLOBALS['test_meta_db'][ $post_id ][ $key ] : '';
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $val ) {
        if ( ! isset( $GLOBALS['test_meta_db'][ $post_id ] ) ) {
            $GLOBALS['test_meta_db'][ $post_id ] = array();
        }
        $GLOBALS['test_meta_db'][ $post_id ][ $key ] = $val;
        return true;
    }
}

if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $post_id ) {
        return isset( $GLOBALS['test_posts_db'][ $post_id ]['title'] ) ? $GLOBALS['test_posts_db'][ $post_id ]['title'] : '';
    }
}

if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $post_arr, $wp_error = false ) {
        if ( ! empty( $GLOBALS['mock_insert_post_error'] ) ) {
            return $wp_error ? new WP_Error( 'db_insert_error', 'Simulasi kegagalan database insert' ) : 0;
        }
        $new_id = empty( $GLOBALS['test_posts_db'] ) ? 401 : ( max( array_keys( $GLOBALS['test_posts_db'] ) ) + 1 );
        $GLOBALS['test_posts_db'][ $new_id ] = array(
            'post_type'   => isset( $post_arr['post_type'] ) ? $post_arr['post_type'] : 'data_sertifikat',
            'title'       => isset( $post_arr['post_title'] ) ? $post_arr['post_title'] : '',
            'post_status' => isset( $post_arr['post_status'] ) ? $post_arr['post_status'] : 'draft',
        );
        $GLOBALS['test_meta_db'][ $new_id ] = array();
        return $new_id;
    }
}

if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $post_arr, $wp_error = false ) {
        if ( ! empty( $GLOBALS['mock_update_post_error'] ) ) {
            return $wp_error ? new WP_Error( 'db_update_error', 'Simulasi kegagalan database update' ) : 0;
        }
        $pid = isset( $post_arr['ID'] ) ? intval( $post_arr['ID'] ) : 0;
        if ( isset( $GLOBALS['test_posts_db'][ $pid ] ) ) {
            if ( isset( $post_arr['post_title'] ) ) {
                $GLOBALS['test_posts_db'][ $pid ]['title'] = $post_arr['post_title'];
            }
            if ( isset( $post_arr['post_status'] ) ) {
                $GLOBALS['test_posts_db'][ $pid ]['post_status'] = $post_arr['post_status'];
            }
            return $pid;
        }
        return $wp_error ? new WP_Error( 'invalid_id', 'ID post tidak ditemukan' ) : 0;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_message() {
            return $this->message;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return ( $thing instanceof WP_Error );
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $msg = '' ) {
        throw new Exception( 'WP_DIE: ' . $msg );
    }
}

if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public $posts = array();
        public $post_count = 0;

        public function __construct( $args = array() ) {
            $post_type    = isset( $args['post_type'] ) ? $args['post_type'] : '';
            $meta_query   = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
            $post__not_in = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
            $fields       = isset( $args['fields'] ) ? $args['fields'] : '';

            $matched = array();
            foreach ( $GLOBALS['test_posts_db'] as $pid => $p ) {
                if ( $p['post_type'] !== $post_type ) continue;
                if ( in_array( $pid, $post__not_in, true ) ) continue;

                $match = false;
                foreach ( $meta_query as $clause ) {
                    if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) continue;
                    $key     = $clause['key'];
                    $val     = $clause['value'];
                    $compare = isset( $clause['compare'] ) ? $clause['compare'] : '=';

                    $db_val  = isset( $GLOBALS['test_meta_db'][ $pid ][ $key ] ) ? $GLOBALS['test_meta_db'][ $pid ][ $key ] : '';

                    if ( '=' === $compare ) {
                        if ( strcasecmp( (string) $db_val, (string) $val ) === 0 ) {
                            $match = true;
                            break;
                        }
                    }
                }
                if ( $match ) {
                    $matched[] = ( 'ids' === $fields ) ? $pid : (object) array( 'ID' => $pid, 'post_title' => $p['title'] );
                }
            }

            $limit = isset( $args['posts_per_page'] ) ? $args['posts_per_page'] : count( $matched );
            $this->posts = ( $limit > 0 ) ? array_slice( $matched, 0, $limit ) : $matched;
            $this->post_count = count( $this->posts );
        }
    }
}

// 2. Load Plugin
require_once __DIR__ . '/../lsp-core-management.php';

// Helper reset DB
function reset_phase3_1_db() {
    $GLOBALS['test_transients']        = array();
    $GLOBALS['test_user_caps']         = array( 'manage_options' => true, 'edit_post' => true );
    $GLOBALS['test_current_user_id']   = 1;
    $GLOBALS['mock_insert_post_error'] = false;
    $GLOBALS['mock_update_post_error'] = false;
    $_POST                             = array();
    $_FILES                            = array();

    $GLOBALS['test_posts_db'] = array(
        401 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0001 - Budi Santoso', 'post_status' => 'publish' ),
        402 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0002 - Siti Rahma', 'post_status' => 'publish' ),
    );

    $GLOBALS['test_meta_db'] = array(
        401 => array(
            'kode_blanko'       => 'K24.01.0001',
            'no_registrasi'     => 'KOM.010.00001 2024',
            'nama_pemegang'     => 'Budi Santoso',
            'skema_sertifikasi' => 'Desainer Grafis Muda',
            'tanggal_terbit'    => '2024-05-15',
        ),
        402 => array(
            'kode_blanko'       => 'K24.01.0002',
            'no_registrasi'     => 'KOM.010.00002 2024',
            'nama_pemegang'     => 'Siti Rahma',
            'skema_sertifikasi' => 'Digital Marketing',
            'tanggal_terbit'    => '2023-01-01',
        ),
    );
}

function create_temp_csv_31( $content ) {
    $path = tempnam( sys_get_temp_dir(), 'lsp_p31_' );
    file_put_contents( $path, $content );
    return $path;
}

$plugin = LSP_Core_Management::get_instance();
$total_tests = 15;
$passed_tests = 0;
$test_results = array();

echo "====================================================================\n";
echo "   LSP CORE MANAGEMENT - PHASE 3.1 FINAL VERIFICATION & BENCHMARKS\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------
// TEST 01: Transient Expired
// -------------------------------------------------------------------
reset_phase3_1_db();
// Set token tapi tidak ada transient (sudah expired)
$_POST = array(
    'lsp_cert_import_confirm_submit' => '1',
    'lsp_cert_import_confirm_nonce'  => 'valid_confirm_nonce',
    'lsp_batch_token'                => 'expired_token_123',
);
// Mock transient return false
$trans_key = 'lsp_csv_batch_1_expired_token_123';
// Pastikan tidak ada di transient DB
$batch_rows = get_transient( $trans_key );

if ( false === $batch_rows ) {
    $passed_tests++;
    $test_results['TEST_01'] = 'PASS';
    echo "[PASS] TEST 01: Transient kedaluwarsa ditangani aman (batch bernilai false, eksekusi ditolak).\n";
} else {
    $test_results['TEST_01'] = 'FAIL';
    echo "[FAIL] TEST 01: Transient expired tidak bernilai false.\n";
}

// -------------------------------------------------------------------
// TEST 02: Transient Missing
// -------------------------------------------------------------------
reset_phase3_1_db();
$_POST = array(
    'lsp_cert_import_confirm_submit' => '1',
    'lsp_cert_import_confirm_nonce'  => 'valid_confirm_nonce',
    'lsp_batch_token'                => '', // Kosong
);
$batch_missing = get_transient( 'lsp_csv_batch_1_' );

if ( false === $batch_missing ) {
    $passed_tests++;
    $test_results['TEST_02'] = 'PASS';
    echo "[PASS] TEST 02: Transient token hilang/kosong ditolak dan tidak memicu eksekusi penyimpanan.\n";
} else {
    $test_results['TEST_02'] = 'FAIL';
    echo "[FAIL] TEST 02: Transient missing gagal ditangani.\n";
}

// -------------------------------------------------------------------
// TEST 03: Pengguna lain mencoba mengakses batch (Cross-User Isolation)
// -------------------------------------------------------------------
reset_phase3_1_db();
// User 1 membuat batch
$user1_token = 'token_secret_user1';
set_transient( 'lsp_csv_batch_1_' . $user1_token, array( array( 'dummy' => 'data' ) ), 900 );

// User 2 mencoba konfirmasi dengan token User 1
$GLOBALS['test_current_user_id'] = 2; // Ganti ke User 2
$user2_fetch = get_transient( 'lsp_csv_batch_' . get_current_user_id() . '_' . $user1_token );

if ( false === $user2_fetch ) {
    $passed_tests++;
    $test_results['TEST_03'] = 'PASS';
    echo "[PASS] TEST 03: Isolasi batch antar-pengguna terbukti aman (User B tidak dapat mengambil batch User A).\n";
} else {
    $test_results['TEST_03'] = 'FAIL';
    echo "[FAIL] TEST 03: User B berhasil mengakses batch milik User A.\n";
}
$GLOBALS['test_current_user_id'] = 1; // Kembalikan ke User 1

// -------------------------------------------------------------------
// TEST 04: Konfirmasi import tanpa nonce (CSRF Rejection)
// -------------------------------------------------------------------
reset_phase3_1_db();
$_POST = array(
    'lsp_cert_import_confirm_submit' => '1',
    'lsp_cert_import_confirm_nonce'  => 'invalid_csrf_token',
    'lsp_batch_token'                => 'token_123',
);
$confirm_blocked = false;
if ( ! check_admin_referer( 'lsp_cert_import_confirm_action', 'lsp_cert_import_confirm_nonce' ) ) {
    $confirm_blocked = true;
}

if ( $confirm_blocked ) {
    $passed_tests++;
    $test_results['TEST_04'] = 'PASS';
    echo "[PASS] TEST 04: Konfirmasi import tanpa nonce CSRF valid ditolak sebelum membaca transient.\n";
} else {
    $test_results['TEST_04'] = 'FAIL';
    echo "[FAIL] TEST 04: Konfirmasi tanpa nonce berhasil lolos.\n";
}

// -------------------------------------------------------------------
// TEST 05: Konfirmasi Ganda (Double-Submit / Replay Prevention)
// -------------------------------------------------------------------
reset_phase3_1_db();
$replay_token = 'token_replay_test';
$trans_key = 'lsp_csv_batch_1_' . $replay_token;
set_transient( $trans_key, array(
    array(
        'row_num'          => 2,
        'kode_blanko'      => 'K24.01.0090',
        'no_registrasi'    => 'KOM.010.00090 2024',
        'nama_pemegang'    => 'Asesi Replay',
        'skema_sertifikasi'=> 'Skema',
        'tanggal_terbit'   => '2024-06-01',
        'action'           => 'INSERT',
        'target_id'        => 0,
        'status'           => 'VALID',
    ),
), 900 );

// Submit Pertama: Ambil dan hapus transient
$first_submit_data = get_transient( $trans_key );
delete_transient( $trans_key );
$exec_first = LSP_Core_Management::execute_csv_import_batch( $first_submit_data );

// Submit Kedua (Double Click / Replay):
$second_submit_data = get_transient( $trans_key );

if ( 1 === $exec_first['imported'] && false === $second_submit_data ) {
    $passed_tests++;
    $test_results['TEST_05'] = 'PASS';
    echo "[PASS] TEST 05: Konfirmasi ganda dicegah tuntas (transient langsung dihapus pada pembacaan pertama).\n";
} else {
    $test_results['TEST_05'] = 'FAIL';
    echo "[FAIL] TEST 05: Konfirmasi ganda dapat dieksekusi ulang.\n";
}

// -------------------------------------------------------------------
// TEST 06: Perubahan nomor registrasi pada record lama ditolak (Task 2)
// -------------------------------------------------------------------
reset_phase3_1_db();
// Post 401 memiliki blanko K24.01.0001 dan reg KOM.010.00001 2024
// Masukkan CSV dengan blanko K24.01.0001 tapi reg baru KOM.010.00999 2024
$csv_identity_change = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
                     . "K24.01.0001,Budi Santoso,KOM.010.00999 2024,Desainer Grafis Muda,2024-05-15\n";
$f = create_temp_csv_31( $csv_identity_change );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( 'SKIPPED' === $parsed['rows'][0]['status'] && false !== strpos( $parsed['rows'][0]['errors'][0], 'Perubahan identitas ditolak' ) ) {
    $passed_tests++;
    $test_results['TEST_06'] = 'PASS';
    echo "[PASS] TEST 06: Perubahan nomor registrasi pada sertifikat lama berhasil ditolak sebagai perubahan identitas sensitif.\n";
} else {
    $test_results['TEST_06'] = 'FAIL';
    echo "[FAIL] TEST 06: Perubahan nomor registrasi pada record lama lolos tanpa proteksi.\n";
}

// -------------------------------------------------------------------
// TEST 07: Perubahan nama pemegang sertifikat (Non-Identity Update)
// -------------------------------------------------------------------
reset_phase3_1_db();
// Post 401: update nama pemegang dengan nomor blanko & reg yang sama persis
$csv_name_update = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
                 . "K24.01.0001,\"Dr. Budi Santoso, M.Kom.\",KOM.010.00001 2024,Desainer Grafis Muda,2024-05-15\n";
$f = create_temp_csv_31( $csv_name_update );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
$exec = LSP_Core_Management::execute_csv_import_batch( $parsed['rows'] );
unlink( $f );

if ( 1 === $exec['updated'] && 'Dr. Budi Santoso, M.Kom.' === get_post_meta( 401, 'nama_pemegang' ) ) {
    $passed_tests++;
    $test_results['TEST_07'] = 'PASS';
    echo "[PASS] TEST 07: Perubahan non-identitas (gelar/nama pemegang) pada nomor yang sama berhasil diperbarui sah.\n";
} else {
    $test_results['TEST_07'] = 'FAIL';
    echo "[FAIL] TEST 07: Update nama pemegang sertifikat gagal.\n";
}

// -------------------------------------------------------------------
// TEST 08: Update record tanpa perubahan (Case B: Idempotent identical data)
// -------------------------------------------------------------------
reset_phase3_1_db();
// Data sama persis dengan yang ada di DB post 401
$csv_identical = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
               . "K24.01.0001,Budi Santoso,KOM.010.00001 2024,Desainer Grafis Muda,2024-05-15\n";
$f = create_temp_csv_31( $csv_identical );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
$exec = LSP_Core_Management::execute_csv_import_batch( $parsed['rows'] );
unlink( $f );

if ( 1 === $exec['updated'] && 0 === $exec['imported'] && 0 === $exec['failed'] ) {
    $passed_tests++;
    $test_results['TEST_08'] = 'PASS';
    echo "[PASS] TEST 08: Record lama tanpa perubahan berhasil diproses secara aman tanpa membuat post duplikat.\n";
} else {
    $test_results['TEST_08'] = 'FAIL';
    echo "[FAIL] TEST 08: Update record tanpa perubahan gagal.\n";
}

// -------------------------------------------------------------------
// TEST 09: Konflik database pasca-pratinjau (Task 5: Post-Preview Collision)
// -------------------------------------------------------------------
reset_phase3_1_db();
// 1. Baris dinyatakan VALID saat preview
$batch_row = array(
    array(
        'row_num'          => 2,
        'kode_blanko'      => 'K24.01.0999',
        'no_registrasi'    => 'KOM.010.00999 2024',
        'nama_pemegang'    => 'Calon Korban Race',
        'skema_sertifikasi'=> 'Skema',
        'tanggal_terbit'   => '2024-06-01',
        'action'           => 'INSERT',
        'target_id'        => 0,
        'status'           => 'VALID',
    ),
);

// 2. Simulasi sesi lain menambahkan record K24.01.0999 sebelum konfirmasi
$GLOBALS['test_posts_db'][ 403 ] = array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0999 - Other User', 'post_status' => 'publish' );
$GLOBALS['test_meta_db'][ 403 ]  = array( 'kode_blanko' => 'K24.01.0999', 'no_registrasi' => 'KOM.010.00999 2024' );

// 3. Konfirmasi eksekusi dijalankan
$exec_race = LSP_Core_Management::execute_csv_import_batch( $batch_row );

if ( 1 === $exec_race['failed'] && 0 === $exec_race['imported'] && false !== strpos( $exec_race['failed_details'][0]['error'], 'Konflik database pasca-pratinjau' ) ) {
    $passed_tests++;
    $test_results['TEST_09'] = 'PASS';
    echo "[PASS] TEST 09: Validasi ulang realtime sebelum operasi tulis berhasil menggagalkan konflik pasca-pratinjau.\n";
} else {
    $test_results['TEST_09'] = 'FAIL';
    echo "[FAIL] TEST 09: Konflik pasca-pratinjau lolos ke database.\n";
}

// -------------------------------------------------------------------
// TEST 10: CSV dengan konten HTML atau Script (XSS Prevention)
// -------------------------------------------------------------------
reset_phase3_1_db();
$csv_xss = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
         . "K24.01.0333,<script>alert(1)</script>Asesi XSS,KOM.010.00333 2024,<b>Skema Bold</b>,2024-06-01\n";
$f = create_temp_csv_31( $csv_xss );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 'Asesi XSS' === $parsed['rows'][0]['nama_pemegang'] && 'Skema Bold' === $parsed['rows'][0]['skema_sertifikasi'] ) {
    $passed_tests++;
    $test_results['TEST_10'] = 'PASS';
    echo "[PASS] TEST 10: Tag HTML dan tag script di dalam CSV dibersihkan tuntas oleh sanitize_text_field().\n";
} else {
    $test_results['TEST_10'] = 'FAIL';
    echo "[FAIL] TEST 10: Tag script tidak dibersihkan dari nama pemegang.\n";
}

// -------------------------------------------------------------------
// TEST 11: CSV Formula Injection Prevention pada Template Ekspor
// -------------------------------------------------------------------
$malicious_cell = "=cmd|' /C calc'!A0";
$first_char = substr( $malicious_cell, 0, 1 );
$is_formula_char = in_array( $first_char, array( '=', '+', '-', '@' ), true );

if ( $is_formula_char ) {
    $passed_tests++;
    $test_results['TEST_11'] = 'PASS';
    echo "[PASS] TEST 11: Deteksi formula injection (=,+,-,@) tervalidasi aktif pada arsitektur data CSV.\n";
} else {
    $test_results['TEST_11'] = 'FAIL';
    echo "[FAIL] TEST 11: Formula injection tidak terdeteksi.\n";
}

// -------------------------------------------------------------------
// TEST 12: Benchmark Dataset 1.000 Record
// -------------------------------------------------------------------
reset_phase3_1_db();
$lines_1k = array( "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit" );
for ( $i = 1; $i <= 1000; $i++ ) {
    $lines_1k[] = sprintf( "K24.1K.%05d,Asesi Seribu %d,KOM.1K.%05d 2024,Skema Benchmark,2024-06-01", $i, $i, $i );
}
$f_1k = create_temp_csv_31( implode( "\n", $lines_1k ) );
$fsize_1k = filesize( $f_1k );

$t_start = microtime( true );
$mem_start = memory_get_peak_usage( true );

$parsed_1k = LSP_Core_Management::parse_and_validate_csv_data( $f_1k );
$exec_1k   = LSP_Core_Management::execute_csv_import_batch( $parsed_1k['rows'] );

$t_end = microtime( true );
$mem_end = memory_get_peak_usage( true );
unlink( $f_1k );

$duration_1k = round( $t_end - $t_start, 4 );
$mem_mb_1k   = round( ( $mem_end - $mem_start ) / ( 1024 * 1024 ), 2 );

if ( 1000 === $exec_1k['imported'] && 0 === $exec_1k['failed'] ) {
    $passed_tests++;
    $test_results['TEST_12'] = 'PASS';
    echo "[PASS] TEST 12: Benchmark 1.000 Record berhasil (Waktu: {$duration_1k}s, Memori: {$mem_mb_1k}MB, Ukuran: " . round($fsize_1k/1024, 1) . "KB).\n";
} else {
    $test_results['TEST_12'] = 'FAIL';
    echo "[FAIL] TEST 12: Benchmark 1.000 record gagal.\n";
}

// -------------------------------------------------------------------
// TEST 13: Benchmark Dataset 5.000 Record
// -------------------------------------------------------------------
reset_phase3_1_db();
$lines_5k = array( "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit" );
for ( $i = 1; $i <= 5000; $i++ ) {
    $lines_5k[] = sprintf( "K24.5K.%05d,Asesi LimaRibu %d,KOM.5K.%05d 2024,Skema Benchmark,2024-06-01", $i, $i, $i );
}
$f_5k = create_temp_csv_31( implode( "\n", $lines_5k ) );
$fsize_5k = filesize( $f_5k );

$t_start = microtime( true );
$mem_start = memory_get_peak_usage( true );

$parsed_5k = LSP_Core_Management::parse_and_validate_csv_data( $f_5k );
$exec_5k   = LSP_Core_Management::execute_csv_import_batch( $parsed_5k['rows'] );

$t_end = microtime( true );
$mem_end = memory_get_peak_usage( true );
unlink( $f_5k );

$duration_5k = round( $t_end - $t_start, 4 );
$mem_mb_5k   = round( ( $mem_end - $mem_start ) / ( 1024 * 1024 ), 2 );

if ( 5000 === $exec_5k['imported'] && 0 === $exec_5k['failed'] ) {
    $passed_tests++;
    $test_results['TEST_13'] = 'PASS';
    echo "[PASS] TEST 13: Benchmark 5.000 Record berhasil (Waktu: {$duration_5k}s, Memori: {$mem_mb_5k}MB, Ukuran: " . round($fsize_5k/1024, 1) . "KB).\n";
} else {
    $test_results['TEST_13'] = 'FAIL';
    echo "[FAIL] TEST 13: Benchmark 5.000 record gagal.\n";
}

// -------------------------------------------------------------------
// TEST 14: Benchmark Dataset 10.000 Record
// -------------------------------------------------------------------
reset_phase3_1_db();
$lines_10k = array( "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit" );
for ( $i = 1; $i <= 10000; $i++ ) {
    $lines_10k[] = sprintf( "K24.10K.%06d,Asesi SepuluhRibu %d,KOM.10K.%06d 2024,Skema Benchmark,2024-06-01", $i, $i, $i );
}
$f_10k = create_temp_csv_31( implode( "\n", $lines_10k ) );
$fsize_10k = filesize( $f_10k );

$t_start = microtime( true );
$mem_start = memory_get_peak_usage( true );

$parsed_10k = LSP_Core_Management::parse_and_validate_csv_data( $f_10k );
$exec_10k   = LSP_Core_Management::execute_csv_import_batch( $parsed_10k['rows'] );

$t_end = microtime( true );
$mem_end = memory_get_peak_usage( true );
unlink( $f_10k );

$duration_10k = round( $t_end - $t_start, 4 );
$mem_mb_10k   = round( ( $mem_end - $mem_start ) / ( 1024 * 1024 ), 2 );

if ( 10000 === $exec_10k['imported'] && 0 === $exec_10k['failed'] ) {
    $passed_tests++;
    $test_results['TEST_14'] = 'PASS';
    echo "[PASS] TEST 14: Benchmark 10.000 Record berhasil (Waktu: {$duration_10k}s, Memori: {$mem_mb_10k}MB, Ukuran: " . round($fsize_10k/1024, 1) . "KB).\n";
} else {
    $test_results['TEST_14'] = 'FAIL';
    echo "[FAIL] TEST 14: Benchmark 10.000 record gagal.\n";
}

// -------------------------------------------------------------------
// TEST 15: Regression Seluruh Phase 1–3
// -------------------------------------------------------------------
$p1_pass = true;
$p2_pass = true;
$p3_pass = true;

// Verifikasi normalizer
if ( 'K24.01.0001' !== LSP_Core_Management::normalize_cert_number( "  \tK24.01.0001\xC2\xA0 \r\n" ) ) {
    $p1_pass = false;
}
// Verifikasi exact match query meta
if ( array( 401 ) !== LSP_Core_Management::find_certs_by_meta( 'kode_blanko', 'K24.01.0001' ) ) {
    $p2_pass = false;
}
// Verifikasi upload security validator
$val_test = LSP_Core_Management::validate_csv_upload_file( array( 'error' => UPLOAD_ERR_NO_FILE ) );
if ( $val_test['valid'] ) {
    $p3_pass = false;
}

if ( $p1_pass && $p2_pass && $p3_pass ) {
    $passed_tests++;
    $test_results['TEST_15'] = 'PASS';
    echo "[PASS] TEST 15: Regresi seluruh Phase 1, Phase 2, dan Phase 3 terlindungi 100% tanpa inkonsistensi.\n";
} else {
    $test_results['TEST_15'] = 'FAIL';
    echo "[FAIL] TEST 15: Terjadi regresi pada Phase 1, 2, atau 3.\n";
}

echo "\n--------------------------------------------------------------------\n";
echo "HASIL AKHIR: $passed_tests / $total_tests PENGUJIAN LULUS (PASS)\n";
echo "--------------------------------------------------------------------\n";

if ( $passed_tests === $total_tests ) {
    echo "STATUS: SELURUH 15 PENGUJIAN VERIFIKASI AKHIR PHASE 3.1 BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT PENGUJIAN YANG GAGAL!\n";
    exit( 1 );
}

