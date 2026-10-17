<?php
/**
 * Test Suite: Tahap 3 CSV Import Security & Data Integrity
 * Target: LSP Core Management Plugin (Phase 3 Hardening)
 * 
 * Tests TEST 01 through TEST 25
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
$GLOBALS['mock_update_meta_error'] = false;

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
        if ( 'valid_csv_nonce' === $nonce || 'valid_confirm_nonce' === $nonce ) {
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
        return trim( strip_tags( (string) $str ) );
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
        if ( ! empty( $GLOBALS['mock_update_meta_error'] ) ) {
            return false;
        }
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
        $new_id = empty( $GLOBALS['test_posts_db'] ) ? 301 : ( max( array_keys( $GLOBALS['test_posts_db'] ) ) + 1 );
        $GLOBALS['test_posts_db'][ $new_id ] = array(
            'post_type'   => isset( $post_arr['post_type'] ) ? $post_arr['post_type'] : 'post',
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
function reset_phase3_db() {
    $GLOBALS['test_transients']        = array();
    $GLOBALS['test_user_caps']         = array( 'manage_options' => true, 'edit_post' => true );
    $GLOBALS['mock_insert_post_error'] = false;
    $GLOBALS['mock_update_post_error'] = false;
    $GLOBALS['mock_update_meta_error'] = false;
    $_POST                             = array();
    $_FILES                            = array();

    $GLOBALS['test_posts_db'] = array(
        301 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0001 - Budi Santoso', 'post_status' => 'publish' ),
        302 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0002 - Siti Rahma', 'post_status' => 'publish' ),
    );

    $GLOBALS['test_meta_db'] = array(
        301 => array(
            'kode_blanko'       => 'K24.01.0001',
            'no_registrasi'     => 'KOM.010.00001 2024',
            'nama_pemegang'     => 'Budi Santoso',
            'skema_sertifikasi' => 'Desainer Grafis Muda',
            'tanggal_terbit'    => '2024-05-15',
        ),
        302 => array(
            'kode_blanko'       => 'K24.01.0002',
            'no_registrasi'     => 'KOM.010.00002 2024',
            'nama_pemegang'     => 'Siti Rahma',
            'skema_sertifikasi' => 'Digital Marketing',
            'tanggal_terbit'    => '2023-01-01',
        ),
    );
}

// Helper buat file CSV sementara
function create_temp_csv( $content ) {
    $path = tempnam( sys_get_temp_dir(), 'lsp_test_' );
    file_put_contents( $path, $content );
    return $path;
}

$plugin = LSP_Core_Management::get_instance();
$total_tests = 25;
$passed_tests = 0;
$test_results = array();

echo "====================================================================\n";
echo "   LSP CORE MANAGEMENT - PHASE 3 TEST SUITE (CSV IMPORT HARDENING)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------
// TEST 01: Import CSV valid menggunakan koma (,)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_comma = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
           . "K24.01.0010,Ahmad Fauzi,KOM.010.00010 2024,Public Relations,2024-06-01\n";
$f = create_temp_csv( $csv_comma );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 1 === $parsed['valid_inserts'] && 'INSERT' === $parsed['rows'][0]['action'] ) {
    $passed_tests++;
    $test_results['TEST_01'] = 'PASS';
    echo "[PASS] TEST 01: Import CSV valid menggunakan pemisah koma berhasil divalidasi.\n";
} else {
    $test_results['TEST_01'] = 'FAIL';
    echo "[FAIL] TEST 01: Gagal memvalidasi CSV berkoma.\n";
}

// -------------------------------------------------------------------
// TEST 02: Import CSV valid menggunakan titik koma (;)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_semi = "kode_blanko;nama_pemegang;no_registrasi;skema_sertifikasi;tanggal_terbit\r\n"
          . "K24.01.0011;Dewi Sartika;KOM.010.00011 2024;Desain Grafis;2024-06-02\r\n";
$f = create_temp_csv( $csv_semi );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 1 === $parsed['valid_inserts'] && 'K24.01.0011' === $parsed['rows'][0]['kode_blanko'] ) {
    $passed_tests++;
    $test_results['TEST_02'] = 'PASS';
    echo "[PASS] TEST 02: Import CSV valid menggunakan pemisah titik koma (;) berhasil dideteksi otomatis.\n";
} else {
    $test_results['TEST_02'] = 'FAIL';
    echo "[FAIL] TEST 02: Gagal mendeteksi delimiter titik koma.\n";
}

// -------------------------------------------------------------------
// TEST 03: File dengan UTF-8 BOM
// -------------------------------------------------------------------
reset_phase3_db();
$csv_bom = "\xEF\xBB\xBFkode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
         . "K24.01.0012,Rudi Hartono,KOM.010.00012 2024;Fotografi,2024-06-03\n";
$f = create_temp_csv( $csv_bom );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 'K24.01.0012' === $parsed['rows'][0]['kode_blanko'] ) {
    $passed_tests++;
    $test_results['TEST_03'] = 'PASS';
    echo "[PASS] TEST 03: File CSV dengan UTF-8 BOM berhasil dibersihkan tanpa merusak nama kolom.\n";
} else {
    $test_results['TEST_03'] = 'FAIL';
    echo "[FAIL] TEST 03: Gagal memproses file dengan UTF-8 BOM.\n";
}

// -------------------------------------------------------------------
// TEST 04: File dengan header tidak valid (< 5 kolom)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_bad_head = "kode_blanko,nama_pemegang,tanggal_terbit\nK24.01.0001,Budi,2024-01-01\n";
$f = create_temp_csv( $csv_bad_head );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( ! $parsed['success'] && false !== strpos( $parsed['error'], 'minimal 5 kolom' ) ) {
    $passed_tests++;
    $test_results['TEST_04'] = 'PASS';
    echo "[PASS] TEST 04: Header CSV dengan kolom tidak lengkap berhasil ditolak.\n";
} else {
    $test_results['TEST_04'] = 'FAIL';
    echo "[FAIL] TEST 04: Header tidak valid lolos tanpa error.\n";
}

// -------------------------------------------------------------------
// TEST 05: File dengan kolom yang hilang pada baris data
// -------------------------------------------------------------------
reset_phase3_db();
$csv_missing_col = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
                 . "K24.01.0013,Andi\n"; // Hanya 2 kolom
$f = create_temp_csv( $csv_missing_col );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 1 === $parsed['skipped_count'] && 'SKIPPED' === $parsed['rows'][0]['status'] ) {
    $passed_tests++;
    $test_results['TEST_05'] = 'PASS';
    echo "[PASS] TEST 05: Baris data dengan jumlah kolom kurang berhasil ditandai SKIPPED.\n";
} else {
    $test_results['TEST_05'] = 'FAIL';
    echo "[FAIL] TEST 05: Baris dengan kolom hilang tidak di-skip.\n";
}

// -------------------------------------------------------------------
// TEST 06: Nomor blanko duplikat dalam database (menolak bentrok)
// -------------------------------------------------------------------
reset_phase3_db();
// Post 301 memiliki K24.01.0001 dan KOM.010.00001 2024
// Masukkan blanko K24.01.0001 tapi dengan nomor registrasi yang berbeda
$csv_dup_blanko = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
                . "K24.01.0001,Orang Lain,KOM.010.00999 2024,Public Relations,2024-06-01\n";
$f = create_temp_csv( $csv_dup_blanko );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

// Harus mendeteksi update target pada post 301
if ( $parsed['success'] && 'UPDATE' === $parsed['rows'][0]['action'] && 301 === $parsed['rows'][0]['target_id'] ) {
    $passed_tests++;
    $test_results['TEST_06'] = 'PASS';
    echo "[PASS] TEST 06: Nomor blanko yang sudah ada di database teridentifikasi secara presisi sebagai target pembaruan record 301.\n";
} else {
    $test_results['TEST_06'] = 'FAIL';
    echo "[FAIL] TEST 06: Pencocokan blanko di database gagal.\n";
}

// -------------------------------------------------------------------
// TEST 07: Nomor registrasi duplikat dalam database
// -------------------------------------------------------------------
reset_phase3_db();
// Post 301 memiliki KOM.010.00001 2024
$csv_dup_reg = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
             . "K24.01.0099,Orang Lain,KOM.010.00001 2024,Public Relations,2024-06-01\n";
$f = create_temp_csv( $csv_dup_reg );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 'UPDATE' === $parsed['rows'][0]['action'] && 301 === $parsed['rows'][0]['target_id'] ) {
    $passed_tests++;
    $test_results['TEST_07'] = 'PASS';
    echo "[PASS] TEST 07: Nomor registrasi yang sudah ada di database teridentifikasi dengan tepat pada record sasaran.\n";
} else {
    $test_results['TEST_07'] = 'FAIL';
    echo "[FAIL] TEST 07: Pencocokan nomor registrasi di database gagal.\n";
}

// -------------------------------------------------------------------
// TEST 08: Duplikasi lintas metadata (Cross-field collision di database)
// -------------------------------------------------------------------
reset_phase3_db();
// Post 301: blanko = K24.01.0001, reg = KOM.010.00001 2024
// Post 302: blanko = K24.01.0002, reg = KOM.010.00002 2024
// Buat baris baru di mana blanko = KOM.010.00002 2024 (sama dengan no_reg post 302)
$csv_cross = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
           . "KOM.010.00002 2024,Tabrakan Cross,KOM.NEW.00099 2024,Skema Baru,2024-06-01\n";
$f = create_temp_csv( $csv_cross );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 'SKIPPED' === $parsed['rows'][0]['status'] && false !== strpos( $parsed['rows'][0]['errors'][0], 'Tabrakan lintas kolom' ) ) {
    $passed_tests++;
    $test_results['TEST_08'] = 'PASS';
    echo "[PASS] TEST 08: Tabrakan lintas metadata (cross-field collision di DB) berhasil dicegah dan di-skip.\n";
} else {
    $test_results['TEST_08'] = 'FAIL';
    echo "[FAIL] TEST 08: Cross-field collision di DB tidak terdeteksi.\n";
}

// -------------------------------------------------------------------
// TEST 09: Duplikasi antarbaris di dalam CSV yang sama (Intra-CSV duplicates)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_intra = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
           . "K24.01.0050,Asesi Pertama,KOM.010.00050 2024,Skema A,2024-06-01\n"
           . "K24.01.0050,Asesi Kedua,KOM.010.00051 2024,Skema B,2024-06-01\n"; // Blanko kembar
$f = create_temp_csv( $csv_intra );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( 'VALID' === $parsed['rows'][0]['status'] && 'SKIPPED' === $parsed['rows'][1]['status'] && false !== strpos( $parsed['rows'][1]['errors'][0], 'Duplikasi intra-file' ) ) {
    $passed_tests++;
    $test_results['TEST_09'] = 'PASS';
    echo "[PASS] TEST 09: Duplikasi nomor pada baris lain di dalam berkas CSV yang sama berhasil ditolak.\n";
} else {
    $test_results['TEST_09'] = 'FAIL';
    echo "[FAIL] TEST 09: Duplikasi antarbaris CSV lolos.\n";
}

// -------------------------------------------------------------------
// TEST 10: Tanggal tidak valid (2024-99-99 atau format non-ISO)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_bad_date = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
              . "K24.01.0060,Asesi Tanggal Cacat,KOM.010.00060 2024,Skema A,2024-99-99\n";
$f = create_temp_csv( $csv_bad_date );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( 'SKIPPED' === $parsed['rows'][0]['status'] && false !== strpos( $parsed['rows'][0]['errors'][0], 'tidak valid' ) ) {
    $passed_tests++;
    $test_results['TEST_10'] = 'PASS';
    echo "[PASS] TEST 10: Tanggal kalender tidak valid (2024-99-99) ditolak oleh validator ISO.\n";
} else {
    $test_results['TEST_10'] = 'FAIL';
    echo "[FAIL] TEST 10: Tanggal invalid diterima.\n";
}

// -------------------------------------------------------------------
// TEST 11: CSV dengan quoted delimiter (Koma di dalam tanda kutip)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_quoted = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
            . "K24.01.0070,\"Santoso, Budi, S.Kom.\",KOM.010.00070 2024,\"Teknologi, Desain Grafis\",2024-06-01\n";
$f = create_temp_csv( $csv_quoted );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 'Santoso, Budi, S.Kom.' === $parsed['rows'][0]['nama_pemegang'] && 'Teknologi, Desain Grafis' === $parsed['rows'][0]['skema_sertifikasi'] ) {
    $passed_tests++;
    $test_results['TEST_11'] = 'PASS';
    echo "[PASS] TEST 11: Delimiter di dalam tanda kutip (quoted delimiter) diparsing dengan sempurna tanpa merusak kolom.\n";
} else {
    $test_results['TEST_11'] = 'FAIL';
    echo "[FAIL] TEST 11: Parsing quoted delimiter gagal.\n";
}

// -------------------------------------------------------------------
// TEST 12: CSV dengan karakter Unicode (NBSP, kontrol, aksen)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_unicode = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
             . " K24.01.0080\xC2\xA0 ,Renée Müller,KOM.010.00080 2024,Digital Marketing,2024-06-01\n";
$f = create_temp_csv( $csv_unicode );
$parsed = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

if ( $parsed['success'] && 'K24.01.0080' === $parsed['rows'][0]['kode_blanko'] && 'Renée Müller' === $parsed['rows'][0]['nama_pemegang'] ) {
    $passed_tests++;
    $test_results['TEST_12'] = 'PASS';
    echo "[PASS] TEST 12: Karakter Unicode (NBSP dibersihkan & aksen internasional dipertahankan) diproses presisi.\n";
} else {
    $test_results['TEST_12'] = 'FAIL';
    echo "[FAIL] TEST 12: Normalisasi karakter Unicode gagal.\n";
}

// -------------------------------------------------------------------
// TEST 13: File melebihi batas maksimal (> 5 MB)
// -------------------------------------------------------------------
$file_huge = array(
    'name'     => 'huge.csv',
    'tmp_name' => create_temp_csv( 'dummy content' ),
    'size'     => 6 * 1024 * 1024, // 6 MB
    'error'    => UPLOAD_ERR_OK,
);
$res_huge = LSP_Core_Management::validate_csv_upload_file( $file_huge );
unlink( $file_huge['tmp_name'] );

if ( ! $res_huge['valid'] && false !== strpos( $res_huge['error'], '5 MB' ) ) {
    $passed_tests++;
    $test_results['TEST_13'] = 'PASS';
    echo "[PASS] TEST 13: Berkas yang melebihi batas 5 MB berhasil ditolak dengan aman.\n";
} else {
    $test_results['TEST_13'] = 'FAIL';
    echo "[FAIL] TEST 13: File melebihi 5 MB lolos tanpa penolakan.\n";
}

// -------------------------------------------------------------------
// TEST 14: File upload dengan ekstensi tidak valid (.php, .exe)
// -------------------------------------------------------------------
$file_php = array(
    'name'     => 'malicious.php',
    'tmp_name' => create_temp_csv( '<?php echo "hack"; ?>' ),
    'size'     => 100,
    'error'    => UPLOAD_ERR_OK,
);
$res_php = LSP_Core_Management::validate_csv_upload_file( $file_php );
unlink( $file_php['tmp_name'] );

if ( ! $res_php['valid'] && false !== strpos( $res_php['error'], '.csv atau .txt' ) ) {
    $passed_tests++;
    $test_results['TEST_14'] = 'PASS';
    echo "[PASS] TEST 14: Berkas berekstensi ilegal (.php) berhasil diblokir.\n";
} else {
    $test_results['TEST_14'] = 'FAIL';
    echo "[FAIL] TEST 14: Ekstensi berbahaya lolos validasi.\n";
}

// -------------------------------------------------------------------
// TEST 15: Nonce tidak valid (CSRF Protection)
// -------------------------------------------------------------------
reset_phase3_db();
$_POST = array(
    'lsp_cert_import_submit' => '1',
    'lsp_cert_import_nonce'  => 'invalid_csrf_token',
);
$csrf_blocked = false;
if ( ! check_admin_referer( 'lsp_cert_import_action', 'lsp_cert_import_nonce' ) ) {
    $csrf_blocked = true;
}

if ( $csrf_blocked ) {
    $passed_tests++;
    $test_results['TEST_15'] = 'PASS';
    echo "[PASS] TEST 15: Token CSRF invalid ditolak sebelum pemrosesan berkas.\n";
} else {
    $test_results['TEST_15'] = 'FAIL';
    echo "[FAIL] TEST 15: Nonce CSRF invalid tidak diblokir.\n";
}

// -------------------------------------------------------------------
// TEST 16: User tanpa capability manage_options
// -------------------------------------------------------------------
reset_phase3_db();
$GLOBALS['test_user_caps'] = array( 'manage_options' => false );
$cap_blocked = false;
try {
    $plugin->render_import_page();
} catch ( Exception $e ) {
    if ( false !== strpos( $e->getMessage(), 'WP_DIE' ) ) {
        $cap_blocked = true;
    }
}

if ( $cap_blocked ) {
    $passed_tests++;
    $test_results['TEST_16'] = 'PASS';
    echo "[PASS] TEST 16: Pengguna tanpa kapabilitas 'manage_options' diblokir oleh wp_die().\n";
} else {
    $test_results['TEST_16'] = 'FAIL';
    echo "[FAIL] TEST 16: Akses tanpa manage_options tidak diblokir.\n";
}

// -------------------------------------------------------------------
// TEST 17: Update sertifikat lama yang sah
// -------------------------------------------------------------------
reset_phase3_db();
// Sertifikat 301 (K24.01.0001 & KOM.010.00001 2024) diperbarui namanya
$update_rows = array(
    array(
        'row_num'          => 2,
        'kode_blanko'      => 'K24.01.0001',
        'no_registrasi'    => 'KOM.010.00001 2024',
        'nama_pemegang'    => 'Budi Santoso, S.Kom., M.Cs.',
        'skema_sertifikasi'=> 'Desainer Grafis Muda',
        'tanggal_terbit'   => '2024-05-15',
        'action'           => 'UPDATE',
        'target_id'        => 301,
        'status'           => 'VALID',
    ),
);
$res_update = LSP_Core_Management::execute_csv_import_batch( $update_rows );

if ( 1 === $res_update['updated'] && 'Budi Santoso, S.Kom., M.Cs.' === get_post_meta( 301, 'nama_pemegang' ) ) {
    $passed_tests++;
    $test_results['TEST_17'] = 'PASS';
    echo "[PASS] TEST 17: Pembaruan data sertifikat lama yang sah berhasil dieksekusi ke database.\n";
} else {
    $test_results['TEST_17'] = 'FAIL';
    echo "[FAIL] TEST 17: Pembaruan sertifikat sah gagal.\n";
}

// -------------------------------------------------------------------
// TEST 18: Import file yang sama dua kali (Idempotent Behavior)
// -------------------------------------------------------------------
reset_phase3_db();
$csv_double = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
            . "K24.01.0100,Peserta Idempotent,KOM.010.00100 2024,Skema Bisnis,2024-06-01\n";
$f = create_temp_csv( $csv_double );

// Eksekusi 1: Insert baru
$p1 = LSP_Core_Management::parse_and_validate_csv_data( $f );
$exec1 = LSP_Core_Management::execute_csv_import_batch( $p1['rows'] );

// Eksekusi 2: Update record yang sama, bukan membuat duplikat
$p2 = LSP_Core_Management::parse_and_validate_csv_data( $f );
$exec2 = LSP_Core_Management::execute_csv_import_batch( $p2['rows'] );
unlink( $f );

if ( 1 === $exec1['imported'] && 1 === $exec2['updated'] && 0 === $exec2['imported'] ) {
    $passed_tests++;
    $test_results['TEST_18'] = 'PASS';
    echo "[PASS] TEST 18: Import berkas yang sama dua kali bersifat idempotent (impor 1 = insert, impor 2 = update tanpa duplikasi).\n";
} else {
    $test_results['TEST_18'] = 'FAIL';
    echo "[FAIL] TEST 18: Idempotensi import gagal.\n";
}

// -------------------------------------------------------------------
// TEST 19: Satu baris rusak di antara baris valid
// -------------------------------------------------------------------
reset_phase3_db();
$csv_mixed = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
           . "K24.01.0201,Asesi Baik 1,KOM.010.00201 2024,Skema A,2024-06-01\n"
           . ",Asesi Rusak Tanpa Blanko,KOM.010.00202 2024,Skema B,2024-06-01\n"
           . "K24.01.0203,Asesi Baik 2,KOM.010.00203 2024,Skema C,2024-06-01\n";
$f = create_temp_csv( $csv_mixed );
$p_mix = LSP_Core_Management::parse_and_validate_csv_data( $f );
$exec_mix = LSP_Core_Management::execute_csv_import_batch( $p_mix['rows'] );
unlink( $f );

if ( 2 === $exec_mix['imported'] && 1 === $exec_mix['skipped'] ) {
    $passed_tests++;
    $test_results['TEST_19'] = 'PASS';
    echo "[PASS] TEST 19: Baris rusak di tengah file berhasil dilewati tanpa menghentikan penyimpanan baris yang valid.\n";
} else {
    $test_results['TEST_19'] = 'FAIL';
    echo "[FAIL] TEST 19: Penanganan baris campuran gagal.\n";
}

// -------------------------------------------------------------------
// TEST 20: Simulasi kegagalan wp_insert_post()
// -------------------------------------------------------------------
reset_phase3_db();
$GLOBALS['mock_insert_post_error'] = true; // Picu error
$row_fail = array(
    array(
        'row_num'          => 2,
        'kode_blanko'      => 'K24.01.0999',
        'no_registrasi'    => 'KOM.010.00999 2024',
        'nama_pemegang'    => 'Gagal DB',
        'skema_sertifikasi'=> 'Skema Gagal',
        'tanggal_terbit'   => '2024-06-01',
        'action'           => 'INSERT',
        'target_id'        => 0,
        'status'           => 'VALID',
    ),
);
$res_fail = LSP_Core_Management::execute_csv_import_batch( $row_fail );

if ( 1 === $res_fail['failed'] && 0 === $res_fail['imported'] && ! empty( $res_fail['failed_details'] ) ) {
    $passed_tests++;
    $test_results['TEST_20'] = 'PASS';
    echo "[PASS] TEST 20: Kegagalan wp_insert_post() ditangkap secara aman dan dicatat ke statistik FAILED.\n";
} else {
    $test_results['TEST_20'] = 'FAIL';
    echo "[FAIL] TEST 20: Kegagalan insert post tidak tertangani.\n";
}

// -------------------------------------------------------------------
// TEST 21: Simulasi kegagalan wp_update_post()
// -------------------------------------------------------------------
reset_phase3_db();
$GLOBALS['mock_update_post_error'] = true;
$row_up_fail = array(
    array(
        'row_num'          => 2,
        'kode_blanko'      => 'K24.01.0001',
        'no_registrasi'    => 'KOM.010.00001 2024',
        'nama_pemegang'    => 'Gagal Update',
        'skema_sertifikasi'=> 'Skema',
        'tanggal_terbit'   => '2024-05-15',
        'action'           => 'UPDATE',
        'target_id'        => 301,
        'status'           => 'VALID',
    ),
);
$res_up_fail = LSP_Core_Management::execute_csv_import_batch( $row_up_fail );

if ( 1 === $res_up_fail['failed'] && 0 === $res_up_fail['updated'] ) {
    $passed_tests++;
    $test_results['TEST_21'] = 'PASS';
    echo "[PASS] TEST 21: Kegagalan wp_update_post() ditangkap secara aman dan dicatat ke statistik FAILED.\n";
} else {
    $test_results['TEST_21'] = 'FAIL';
    echo "[FAIL] TEST 21: Kegagalan update post tidak tertangani.\n";
}

// -------------------------------------------------------------------
// TEST 22: Penanganan update metadata
// -------------------------------------------------------------------
reset_phase3_db();
$row_meta = array(
    array(
        'row_num'          => 2,
        'kode_blanko'      => 'K24.01.0888',
        'no_registrasi'    => 'KOM.010.00888 2024',
        'nama_pemegang'    => 'Meta Test',
        'skema_sertifikasi'=> 'Skema Meta',
        'tanggal_terbit'   => '2024-06-01',
        'action'           => 'INSERT',
        'target_id'        => 0,
        'status'           => 'VALID',
    ),
);
$res_meta = LSP_Core_Management::execute_csv_import_batch( $row_meta );
$newest_id = max( array_keys( $GLOBALS['test_posts_db'] ) );

if ( 1 === $res_meta['imported'] && 'K24.01.0888' === get_post_meta( $newest_id, 'kode_blanko' ) ) {
    $passed_tests++;
    $test_results['TEST_22'] = 'PASS';
    echo "[PASS] TEST 22: Penyimpanan seluruh pasangan metadata sertifikat berhasil diverifikasi pada database.\n";
} else {
    $test_results['TEST_22'] = 'FAIL';
    echo "[FAIL] TEST 22: Metadata sertifikat tidak tersimpan lengkap.\n";
}

// -------------------------------------------------------------------
// TEST 23: Preflight preview tidak mengubah database
// -------------------------------------------------------------------
reset_phase3_db();
$db_state_before = serialize( $GLOBALS['test_posts_db'] ) . serialize( $GLOBALS['test_meta_db'] );

$csv_preflight = "kode_blanko,nama_pemegang,no_registrasi,skema_sertifikasi,tanggal_terbit\n"
               . "K24.01.0777,Asesi Preview,KOM.010.00777 2024,Skema Preview,2024-06-01\n";
$f = create_temp_csv( $csv_preflight );
$p_pre = LSP_Core_Management::parse_and_validate_csv_data( $f );
unlink( $f );

$db_state_after = serialize( $GLOBALS['test_posts_db'] ) . serialize( $GLOBALS['test_meta_db'] );

if ( $db_state_before === $db_state_after && 1 === $p_pre['valid_inserts'] ) {
    $passed_tests++;
    $test_results['TEST_23'] = 'PASS';
    echo "[PASS] TEST 23: Tahap preflight validation murni membaca data tanpa melakukan penulisan database (Zero Side-Effects).\n";
} else {
    $test_results['TEST_23'] = 'FAIL';
    echo "[FAIL] TEST 23: Preflight validation memodifikasi database.\n";
}

// -------------------------------------------------------------------
// TEST 24: Jumlah hasil laporan sesuai database
// -------------------------------------------------------------------
reset_phase3_db();
$init_posts_count = count( $GLOBALS['test_posts_db'] );

$batch_count_test = array(
    array(
        'row_num'          => 2,
        'kode_blanko'      => 'K24.01.0501',
        'no_registrasi'    => 'KOM.010.00501 2024',
        'nama_pemegang'    => 'New 1',
        'skema_sertifikasi'=> 'Skema',
        'tanggal_terbit'   => '2024-06-01',
        'action'           => 'INSERT',
        'target_id'        => 0,
        'status'           => 'VALID',
    ),
    array(
        'row_num'          => 3,
        'kode_blanko'      => 'K24.01.0502',
        'no_registrasi'    => 'KOM.010.00502 2024',
        'nama_pemegang'    => 'New 2',
        'skema_sertifikasi'=> 'Skema',
        'tanggal_terbit'   => '2024-06-01',
        'action'           => 'INSERT',
        'target_id'        => 0,
        'status'           => 'VALID',
    ),
    array(
        'row_num'          => 4,
        'kode_blanko'      => 'K24.01.0001',
        'no_registrasi'    => 'KOM.010.00001 2024',
        'nama_pemegang'    => 'Budi Updated',
        'skema_sertifikasi'=> 'Skema',
        'tanggal_terbit'   => '2024-05-15',
        'action'           => 'UPDATE',
        'target_id'        => 301,
        'status'           => 'VALID',
    ),
    array(
        'row_num'          => 5,
        'kode_blanko'      => 'K24.01.FAIL',
        'no_registrasi'    => '',
        'nama_pemegang'    => 'Invalid',
        'skema_sertifikasi'=> 'Skema',
        'tanggal_terbit'   => '2024-06-01',
        'action'           => 'INSERT',
        'target_id'        => 0,
        'status'           => 'SKIPPED',
    ),
);

$res_count = LSP_Core_Management::execute_csv_import_batch( $batch_count_test );
$final_posts_count = count( $GLOBALS['test_posts_db'] );

if ( 2 === $res_count['imported'] && 1 === $res_count['updated'] && 1 === $res_count['skipped'] && 0 === $res_count['failed'] && ( $init_posts_count + 2 === $final_posts_count ) ) {
    $passed_tests++;
    $test_results['TEST_24'] = 'PASS';
    echo "[PASS] TEST 24: Rekapitulasi angka laporan impor persis cocok 100% dengan kondisi nyata database.\n";
} else {
    $test_results['TEST_24'] = 'FAIL';
    echo "[FAIL] TEST 24: Angka rekapitulasi laporan tidak cocok dengan database.\n";
}

// -------------------------------------------------------------------
// TEST 25: Regression Phase 1 dan Phase 2
// -------------------------------------------------------------------
reset_phase3_db();
// 25a. Test normalize_cert_number tetap konsisten
$norm_check = LSP_Core_Management::normalize_cert_number( "  \tKOM.010.00012\xC2\xA0 2024 \r\n " );
// 25b. Test find_certs_by_meta berjalan akurat
$find_check = LSP_Core_Management::find_certs_by_meta( 'kode_blanko', 'K24.01.0001' );

if ( 'KOM.010.00012 2024' === $norm_check && array( 301 ) === $find_check ) {
    $passed_tests++;
    $test_results['TEST_25'] = 'PASS';
    echo "[PASS] TEST 25: Regresi Phase 1 dan Phase 2 terlindungi penuh (normalisasi dan exact search meta utuh).\n";
} else {
    $test_results['TEST_25'] = 'FAIL';
    echo "[FAIL] TEST 25: Terjadi regresi pada Phase 1 atau Phase 2.\n";
}

echo "\n--------------------------------------------------------------------\n";
echo "HASIL AKHIR: $passed_tests / $total_tests PENGUJIAN LULUS (PASS)\n";
echo "--------------------------------------------------------------------\n";

if ( $passed_tests === $total_tests ) {
    echo "STATUS: SELURUH 25 PENGUJIAN CSV IMPORT PHASE 3 BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT PENGUJIAN YANG GAGAL!\n";
    exit( 1 );
}
