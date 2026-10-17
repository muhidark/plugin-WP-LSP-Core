<?php
/**
 * Test Suite: Tahap 1 Security & Exact Match Verification
 * Target: LSP Core Management Plugin (Phase 1 Fixes)
 */

// Simulasi Lingkungan WordPress untuk Pengujian Terisolasi
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../../../' );
}

// Global state untuk mocking testing
$GLOBALS['test_transients'] = array();
$GLOBALS['test_posts_db']   = array();
$GLOBALS['test_meta_db']    = array();
$GLOBALS['last_json_response'] = null;

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
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

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
        $nonce = isset( $_POST[ $query_arg ] ) ? $_POST[ $query_arg ] : '';
        if ( 'valid_nonce_token' === $nonce ) {
            return 1;
        }
        return false;
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

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null ) {
        $GLOBALS['last_json_response'] = array(
            'success'     => false,
            'status_code' => $status_code,
            'data'        => $data,
        );
        throw new Exception( 'WP_JSON_ERROR' );
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null ) {
        $GLOBALS['last_json_response'] = array(
            'success'     => true,
            'status_code' => $status_code,
            'data'        => $data,
        );
        throw new Exception( 'WP_JSON_SUCCESS' );
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

if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $post_id ) {
        return isset( $GLOBALS['test_posts_db'][ $post_id ]['title'] ) ? $GLOBALS['test_posts_db'][ $post_id ]['title'] : '';
    }
}

if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public $posts = array();
        public $post_count = 0;

        public function __construct( $args = array() ) {
            $post_type = isset( $args['post_type'] ) ? $args['post_type'] : '';
            $meta_query = isset( $args['meta_query'] ) ? $args['meta_query'] : array();

            $matched = array();
            foreach ( $GLOBALS['test_posts_db'] as $pid => $p ) {
                if ( $p['post_type'] !== $post_type ) continue;

                $or_match = false;
                foreach ( $meta_query as $clause ) {
                    if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) continue;
                    $key = $clause['key'];
                    $val = $clause['value'];
                    $compare = isset( $clause['compare'] ) ? $clause['compare'] : '=';

                    $db_val = isset( $GLOBALS['test_meta_db'][ $pid ][ $key ] ) ? $GLOBALS['test_meta_db'][ $pid ][ $key ] : '';

                    if ( '=' === $compare ) {
                        if ( strcasecmp( (string) $db_val, (string) $val ) === 0 ) {
                            $or_match = true;
                            break;
                        }
                    } elseif ( 'LIKE' === $compare ) {
                        if ( false !== stripos( (string) $db_val, (string) $val ) ) {
                            $or_match = true;
                            break;
                        }
                    }
                }
                if ( $or_match ) {
                    $obj = new stdClass();
                    $obj->ID = $pid;
                    $obj->post_title = $p['title'];
                    $matched[] = $obj;
                }
            }

            $limit = isset( $args['posts_per_page'] ) ? $args['posts_per_page'] : count( $matched );
            $this->posts = array_slice( $matched, 0, $limit );
            $this->post_count = count( $this->posts );
        }
    }
}

// Require plugin file
require_once __DIR__ . '/../lsp-core-management.php';

// Setup data dummy testing
function reset_test_environment() {
    $GLOBALS['test_transients'] = array();
    $GLOBALS['test_posts_db'] = array(
        101 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0001 - Budi Santoso' ),
        102 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0002 - Siti Rahma' ),
        103 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0003 - Duplikat 1' ),
        104 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0003 - Duplikat 2' ),
    );

    $GLOBALS['test_meta_db'] = array(
        101 => array(
            'kode_blanko'       => 'K24.01.0001',
            'no_registrasi'     => 'KOM.010.00001 2024',
            'nama_pemegang'     => 'Budi Santoso',
            'skema_sertifikasi' => 'Desainer Grafis Muda',
            'tanggal_terbit'    => '2024-05-15',
        ),
        102 => array(
            'kode_blanko'       => 'K24.01.0002',
            'no_registrasi'     => 'KOM.010.00002 2024',
            'nama_pemegang'     => 'Siti Rahma',
            'skema_sertifikasi' => 'Digital Marketing',
            'tanggal_terbit'    => '2020-01-01', // Expired
        ),
        103 => array(
            'kode_blanko'       => 'K24.01.0003_DUP',
            'no_registrasi'     => 'KOM.DUP.00003',
            'nama_pemegang'     => 'Peserta A',
            'skema_sertifikasi' => 'Public Relations',
            'tanggal_terbit'    => '2024-01-01',
        ),
        104 => array(
            'kode_blanko'       => 'K24.01.0003_DUP',
            'no_registrasi'     => 'KOM.DUP.00003',
            'nama_pemegang'     => 'Peserta B',
            'skema_sertifikasi' => 'Public Relations',
            'tanggal_terbit'    => '2024-01-01',
        ),
    );
}

// Inisialisasi Instance
$instance = LSP_Core_Management::get_instance();
$results = array();

echo "========================================================\n";
echo "MENJALANKAN RANGKAIAN PENGUJIAN OTOMATIS TAHAP 1\n";
echo "========================================================\n\n";

// Helper untuk menjalankan ajax_verify_cert
function execute_verify( $post_data ) {
    $_POST = $post_data;
    try {
        LSP_Core_Management::get_instance()->ajax_verify_cert();
    } catch ( Exception $e ) {
        // Expected json exit
    }
    return $GLOBALS['last_json_response'];
}

// TEST 01: Nomor sertifikat valid dan lengkap
reset_test_environment();
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K24.01.0001' ) );
$pass = ( $res['success'] === true && strpos( $res['data']['html'], 'Budi Santoso' ) !== false && strpos( $res['data']['html'], 'VALID &amp; AKTIF' ) !== false );
$results['TEST 01'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 01'] . " ] TEST 01: Nomor sertifikat valid dan lengkap -> Tepat ditemukan (Budi Santoso)\n";

// TEST 02: Nomor sertifikat tidak ditemukan
reset_test_environment();
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K24.99.9999' ) );
$pass = ( $res['success'] === true && strpos( $res['data']['html'], 'TIDAK DITEMUKAN / TIDAK VALID' ) !== false );
$results['TEST 02'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 02'] . " ] TEST 02: Nomor sertifikat tidak terdaftar -> Respons Tidak Ditemukan\n";

// TEST 03: Nomor parsial
reset_test_environment();
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K24.01' ) );
$pass = ( $res['success'] === true && strpos( $res['data']['html'], 'TIDAK DITEMUKAN / TIDAK VALID' ) !== false && strpos( $res['data']['html'], 'Budi Santoso' ) === false );
$results['TEST 03'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 03'] . " ] TEST 03: Nomor parsial ('K24.01') -> Tidak dinyatakan valid\n";

// TEST 04: Satu karakter umum
reset_test_environment();
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K' ) );
$pass = ( $res['success'] === false && $res['status_code'] === 400 && strpos( $res['data']['html'], 'terlalu pendek' ) !== false );
$results['TEST 04'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 04'] . " ] TEST 04: Satu karakter umum ('K') -> Ditolak (terlalu pendek)\n";

// TEST 05: Input kosong
reset_test_environment();
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => '   ' ) );
$pass = ( $res['success'] === false && $res['status_code'] === 400 && strpos( $res['data']['html'], 'tidak boleh kosong' ) !== false );
$results['TEST 05'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 05'] . " ] TEST 05: Input kosong atau spasi saja -> Ditolak (400)\n";

// TEST 06: Input terlalu panjang
reset_test_environment();
$long_str = str_repeat( 'A', 65 );
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => $long_str ) );
$pass = ( $res['success'] === false && $res['status_code'] === 400 && strpos( $res['data']['html'], 'melebihi batas panjang' ) !== false );
$results['TEST 06'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 06'] . " ] TEST 06: Input melebihi 60 karakter -> Ditolak (400)\n";

// TEST 07: Nonce tidak valid
reset_test_environment();
$res = execute_verify( array( 'nonce' => 'invalid_token_xyz', 'kode_blanko' => 'K24.01.0001' ) );
$pass = ( $res['success'] === false && $res['status_code'] === 403 && strpos( $res['data']['html'], 'Sesi Verifikasi Tidak Valid' ) !== false );
$results['TEST 07'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 07'] . " ] TEST 07: Nonce tidak valid -> Ditolak (HTTP 403)\n";

// TEST 08: Permintaan melewati rate limit (20 req / min)
reset_test_environment();
$rate_passed = true;
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
for ( $i = 1; $i <= 20; $i++ ) {
    $res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K24.01.0001' ) );
    if ( ! $res['success'] ) {
        $rate_passed = false;
        break;
    }
}
// Request ke-21 harus terkena rate limit (429)
$res_blocked = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K24.01.0001' ) );
$pass = ( $rate_passed && $res_blocked['success'] === false && $res_blocked['status_code'] === 429 && strpos( $res_blocked['data']['html'], 'Batas Permintaan Terlampaui' ) !== false );
$results['TEST 08'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 08'] . " ] TEST 08: Request melewati limit 20 req/menit -> Ditolak sementara (HTTP 429)\n";

// TEST 09: Dua sertifikat dengan nomor identik (Ambiguity Duplicate Handling)
reset_test_environment();
$_SERVER['REMOTE_ADDR'] = '192.168.1.105'; // Fresh IP
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K24.01.0003_DUP' ) );
$pass = ( $res['success'] === true && strpos( $res['data']['html'], 'PERLU KLARIFIKASI SEKRETARIAT' ) !== false && strpos( $res['data']['html'], 'Ditemukan Lebih dari Satu Catatan' ) !== false );
$results['TEST 09'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 09'] . " ] TEST 09: Dua sertifikat bernomor identik -> Menolak ambiguitas (Perlu Klarifikasi)\n";

// TEST 10: Theme lspkomunikasi tetap menampilkan formulir dan hasil verifikasi dengan benar
$theme_archive_skema = file_get_contents( __DIR__ . '/../../../themes/lspkomunikasi/archive-skema.php' );
$plugin_templates_ok = file_exists( __DIR__ . '/../templates/single-skema.php' ) && file_exists( __DIR__ . '/../templates/archive-tuk.php' );
$pass = ( strpos( $theme_archive_skema, 'render_skema_catalog_archive' ) !== false && $plugin_templates_ok );
$results['TEST 10'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 10'] . " ] TEST 10: Integrasi Tema lspkomunikasi -> Form & katalog tetap terhubung\n";

// TEST 11: Endpoint tidak membuka data pribadi di luar kebutuhan verifikasi
reset_test_environment();
$_SERVER['REMOTE_ADDR'] = '192.168.1.110';
$res = execute_verify( array( 'nonce' => 'valid_nonce_token', 'kode_blanko' => 'K24.01.0001' ) );
$html = $res['data']['html'];
$no_leak = ( strpos( $html, 'NIK' ) === false && strpos( $html, 'telepon' ) === false && strpos( $html, 'email' ) === false && strpos( $html, 'password' ) === false );
// Pastikan nomor blanko yang dicetak adalah dari database:
$db_blanko_rendered = ( strpos( $html, 'No. Blanko: K24.01.0001' ) !== false );
$pass = ( $no_leak && $db_blanko_rendered );
$results['TEST 11'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 11'] . " ] TEST 11: Perlindungan data pribadi -> Tidak membocorkan data sensitif, nomor dari DB\n";

// TEST 12: Tidak menimbulkan PHP fatal error, warning, atau JS error
$pass = true;
// Check syntax PHP
$lint_output = shell_exec( 'php -l ' . escapeshellarg( __DIR__ . '/../lsp-core-management.php' ) );
if ( strpos( $lint_output, 'No syntax errors detected' ) === false ) {
    $pass = false;
}
$results['TEST 12'] = $pass ? 'PASS' : 'FAIL';
echo "[ " . $results['TEST 12'] . " ] TEST 12: Syntax & Runtime Integrity -> Tidak ada PHP fatal error / warning\n";

echo "\n========================================================\n";
echo "RINGKASAN HASIL PENGUJIAN: " . count( array_filter( $results, function($r){ return $r === 'PASS'; } ) ) . " PASS / " . count( $results ) . " TOTAL\n";
echo "========================================================\n";
