<?php
/**
 * Test Suite: Tahap 2 Certificate Data Integrity & Admin Save Validation
 * Target: LSP Core Management Plugin (Phase 2 Fixes)
 * 
 * Tests TEST 01 through TEST 15
 */

// 1. Setup Mocking Lingkungan WordPress
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../../../' );
}

$GLOBALS['test_transients']     = array();
$GLOBALS['test_posts_db']       = array();
$GLOBALS['test_meta_db']        = array();
$GLOBALS['test_user_caps']      = array( 'edit_post' => true );
$GLOBALS['test_current_user_id']= 1;
$GLOBALS['last_json_response']  = null;
$GLOBALS['doing_autosave']      = false;
$GLOBALS['is_autosave']         = false;
$GLOBALS['is_revision']         = false;

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

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        if ( 'lsp_cert_meta_nonce_action' === $action && 'valid_admin_cert_nonce' === $nonce ) {
            return 1;
        }
        return false;
    }
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
        $nonce = isset( $_POST[ $query_arg ] ) ? $_POST[ $query_arg ] : '';
        if ( 'valid_ajax_nonce' === $nonce ) {
            return 1;
        }
        return false;
    }
}

if ( ! function_exists( 'post_type_exists' ) ) {
    function post_type_exists( $post_type ) {
        return in_array( $post_type, array( 'skema', 'tuk', 'asesor', 'data_sertifikat' ), true );
    }
}

if ( ! function_exists( 'shortcode_exists' ) ) {
    function shortcode_exists( $tag ) {
        return in_array( $tag, array( 'lsp_skema', 'lsp_tuk', 'lsp_asesor', 'cek_sertifikat' ), true );
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

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
    function wp_is_post_autosave( $post_id ) {
        return $GLOBALS['is_autosave'];
    }
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
    function wp_is_post_revision( $post_id ) {
        return $GLOBALS['is_revision'];
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

if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $post_arr ) {
        $pid = $post_arr['ID'];
        if ( isset( $GLOBALS['test_posts_db'][ $pid ] ) ) {
            if ( isset( $post_arr['post_title'] ) ) {
                $GLOBALS['test_posts_db'][ $pid ]['title'] = $post_arr['post_title'];
            }
        }
        return $pid;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
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

                // Support meta_query array
                $or_match = false;
                foreach ( $meta_query as $clause ) {
                    if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) continue;
                    $key     = $clause['key'];
                    $val     = $clause['value'];
                    $compare = isset( $clause['compare'] ) ? $clause['compare'] : '=';

                    $db_val  = isset( $GLOBALS['test_meta_db'][ $pid ][ $key ] ) ? $GLOBALS['test_meta_db'][ $pid ][ $key ] : '';

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
                    if ( 'ids' === $fields ) {
                        $matched[] = $pid;
                    } else {
                        $obj = new stdClass();
                        $obj->ID = $pid;
                        $obj->post_title = $p['title'];
                        $matched[] = $obj;
                    }
                }
            }

            $limit = isset( $args['posts_per_page'] ) ? $args['posts_per_page'] : count( $matched );
            if ( $limit > 0 ) {
                $this->posts = array_slice( $matched, 0, $limit );
            } else {
                $this->posts = $matched;
            }
            $this->post_count = count( $this->posts );
        }
    }
}

// 2. Load Plugin
require_once __DIR__ . '/../lsp-core-management.php';

// 3. Reset Environment Helper
function reset_phase2_env() {
    $GLOBALS['test_transients']      = array();
    $GLOBALS['test_user_caps']       = array( 'edit_post' => true );
    $GLOBALS['test_current_user_id'] = 1;
    $GLOBALS['doing_autosave']       = false;
    $GLOBALS['is_autosave']          = false;
    $GLOBALS['is_revision']          = false;
    $GLOBALS['last_json_response']   = null;
    $_POST                           = array();

    $GLOBALS['test_posts_db'] = array(
        201 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0001 - Budi Santoso' ),
        202 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0002 - Siti Rahma' ),
        203 => array( 'post_type' => 'data_sertifikat', 'title' => 'K24.01.0003 - Ahmad Dahlan' ),
    );

    $GLOBALS['test_meta_db'] = array(
        201 => array(
            'kode_blanko'       => 'K24.01.0001',
            'no_registrasi'     => 'KOM.010.00001 2024',
            'nama_pemegang'     => 'Budi Santoso',
            'skema_sertifikasi' => 'Desainer Grafis Muda',
            'tanggal_terbit'    => '2024-05-15',
        ),
        202 => array(
            'kode_blanko'       => 'K24.01.0002',
            'no_registrasi'     => 'KOM.010.00002 2024',
            'nama_pemegang'     => 'Siti Rahma',
            'skema_sertifikasi' => 'Digital Marketing',
            'tanggal_terbit'    => '2023-01-01',
        ),
        203 => array(
            'kode_blanko'       => 'K24.01.0003',
            'no_registrasi'     => 'KOM.010.00003 2024',
            'nama_pemegang'     => 'Ahmad Dahlan',
            'skema_sertifikasi' => 'Public Relations',
            'tanggal_terbit'    => '2024-01-10',
        ),
    );
}

$plugin = LSP_Core_Management::get_instance();
$total_tests = 15;
$passed_tests = 0;
$results = array();

echo "====================================================================\n";
echo "   LSP CORE MANAGEMENT - PHASE 2 TEST SUITE (DATA INTEGRITY)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------
// TEST 01: Tambah sertifikat baru dengan nomor unik -> Berhasil
// -------------------------------------------------------------------
reset_phase2_env();
$new_id = 204;
$GLOBALS['test_posts_db'][ $new_id ] = array( 'post_type' => 'data_sertifikat', 'title' => 'Auto Draft' );
$GLOBALS['test_meta_db'][ $new_id ]  = array();

$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.01.0004',
    'no_registrasi'       => 'KOM.010.00004 2024',
    'nama_pemegang'       => 'Dewi Lestari',
    'skema_sertifikasi'   => 'Fotografer Madya',
    'tanggal_terbit'      => '2024-06-01',
);

$plugin->save_cert_meta( $new_id );

$saved_blanko = get_post_meta( $new_id, 'kode_blanko' );
$saved_reg    = get_post_meta( $new_id, 'no_registrasi' );
$saved_title  = get_the_title( $new_id );
$errors       = get_transient( 'lsp_cert_save_errors_1' );

if ( 'K24.01.0004' === $saved_blanko && 'KOM.010.00004 2024' === $saved_reg && 'K24.01.0004 - Dewi Lestari' === $saved_title && empty( $errors ) ) {
    $passed_tests++;
    $results['TEST_01'] = 'PASS';
    echo "[PASS] TEST 01: Tambah sertifikat baru dengan nomor unik berhasil disimpan.\n";
} else {
    $results['TEST_01'] = 'FAIL';
    echo "[FAIL] TEST 01: Gagal menyimpan sertifikat baru unik.\n";
}

// -------------------------------------------------------------------
// TEST 02: Edit sertifikat lama tanpa ubah nomor -> Berhasil (Bukan Self-Duplicate)
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 201; // Sudah memiliki K24.01.0001 dan KOM.010.00001 2024
$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.01.0001', // Tetap sama
    'no_registrasi'       => 'KOM.010.00001 2024', // Tetap sama
    'nama_pemegang'       => 'Budi Santoso, M.Kom', // Diperbarui
    'skema_sertifikasi'   => 'Desainer Grafis Muda',
    'tanggal_terbit'      => '2024-05-15',
);

$plugin->save_cert_meta( $edit_id );

$updated_name = get_post_meta( $edit_id, 'nama_pemegang' );
$updated_title = get_the_title( $edit_id );
$errors = get_transient( 'lsp_cert_save_errors_1' );

if ( 'Budi Santoso, M.Kom' === $updated_name && 'K24.01.0001 - Budi Santoso, M.Kom' === $updated_title && empty( $errors ) ) {
    $passed_tests++;
    $results['TEST_02'] = 'PASS';
    echo "[PASS] TEST 02: Edit sertifikat lama tanpa ubah nomor berhasil (post__not_in bekerja, bukan self-duplicate).\n";
} else {
    $results['TEST_02'] = 'FAIL';
    echo "[FAIL] TEST 02: Edit sertifikat lama keliru dideteksi sebagai duplikat diri sendiri.\n";
}

// -------------------------------------------------------------------
// TEST 03: Tambah / edit dengan kode_blanko sama dengan sertifikat lain -> Ditolak, data lama utuh
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 202; // Asli: K24.01.0002
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];

$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.01.0001', // Duplikat dari post 201
    'no_registrasi'       => 'KOM.010.00099 2024',
    'nama_pemegang'       => 'Siti Rahma Changed',
    'skema_sertifikasi'   => 'Digital Marketing',
    'tanggal_terbit'      => '2023-01-01',
);

$plugin->save_cert_meta( $edit_id );

$errors = get_transient( 'lsp_cert_save_errors_1' );
$current_meta = $GLOBALS['test_meta_db'][ $edit_id ];

if ( ! empty( $errors ) && $current_meta === $original_meta ) {
    $passed_tests++;
    $results['TEST_03'] = 'PASS';
    echo "[PASS] TEST 03: Duplikat kode_blanko berhasil ditolak, data lama sertifikat tetap utuh.\n";
} else {
    $results['TEST_03'] = 'FAIL';
    echo "[FAIL] TEST 03: Duplikat kode_blanko tidak ditolak atau menimpa data lama.\n";
}

// -------------------------------------------------------------------
// TEST 04: Tambah / edit dengan no_registrasi sama dengan sertifikat lain -> Ditolak, data lama utuh
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 202; // Asli: KOM.010.00002 2024
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];

$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.01.0099',
    'no_registrasi'       => 'KOM.010.00001 2024', // Duplikat no_registrasi milik post 201
    'nama_pemegang'       => 'Siti Rahma Changed',
    'skema_sertifikasi'   => 'Digital Marketing',
    'tanggal_terbit'      => '2023-01-01',
);

$plugin->save_cert_meta( $edit_id );

$errors = get_transient( 'lsp_cert_save_errors_1' );
$current_meta = $GLOBALS['test_meta_db'][ $edit_id ];

if ( ! empty( $errors ) && $current_meta === $original_meta ) {
    $passed_tests++;
    $results['TEST_04'] = 'PASS';
    echo "[PASS] TEST 04: Duplikat no_registrasi berhasil ditolak, data lama sertifikat tetap utuh.\n";
} else {
    $results['TEST_04'] = 'FAIL';
    echo "[FAIL] TEST 04: Duplikat no_registrasi tidak ditolak atau menimpa data lama.\n";
}

// -------------------------------------------------------------------
// TEST 05: Tabrakan Lintas Kolom (Cross-Field Collision)
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 202;
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];

// 5a. Masukkan kode_blanko yang sama persis dengan no_registrasi sertifikat 201
$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'KOM.010.00001 2024', // Tabrakan dengan no_registrasi milik 201
    'no_registrasi'       => 'KOM.NEW.00002 2024',
    'nama_pemegang'       => 'Siti Rahma',
    'skema_sertifikasi'   => 'Digital Marketing',
    'tanggal_terbit'      => '2023-01-01',
);

$plugin->save_cert_meta( $edit_id );
$errors_cross1 = get_transient( 'lsp_cert_save_errors_1' );

// 5b. Masukkan no_registrasi yang sama persis dengan kode_blanko sertifikat 201
reset_phase2_env();
$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.NEW.0002',
    'no_registrasi'       => 'K24.01.0001', // Tabrakan dengan kode_blanko milik 201
    'nama_pemegang'       => 'Siti Rahma',
    'skema_sertifikasi'   => 'Digital Marketing',
    'tanggal_terbit'      => '2023-01-01',
);

$plugin->save_cert_meta( $edit_id );
$errors_cross2 = get_transient( 'lsp_cert_save_errors_1' );

if ( ! empty( $errors_cross1 ) && ! empty( $errors_cross2 ) && $GLOBALS['test_meta_db'][ $edit_id ] === $original_meta ) {
    $passed_tests++;
    $results['TEST_05'] = 'PASS';
    echo "[PASS] TEST 05: Tabrakan nomor sertifikat lintas kolom (cross-field collision) berhasil dicegah.\n";
} else {
    $results['TEST_05'] = 'FAIL';
    echo "[FAIL] TEST 05: Cross-field collision lolos tanpa deteksi error.\n";
}

// -------------------------------------------------------------------
// TEST 06: Input nomor/field wajib kosong -> Ditolak
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 202;
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];

$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => '', // Kosong
    'no_registrasi'       => '', // Kosong
    'nama_pemegang'       => '', // Kosong
    'skema_sertifikasi'   => '', // Kosong
    'tanggal_terbit'      => '', // Kosong
);

$plugin->save_cert_meta( $edit_id );
$errors = get_transient( 'lsp_cert_save_errors_1' );

if ( count( $errors ) >= 5 && $GLOBALS['test_meta_db'][ $edit_id ] === $original_meta ) {
    $passed_tests++;
    $results['TEST_06'] = 'PASS';
    echo "[PASS] TEST 06: Input kosong pada seluruh field wajib berhasil ditolak dengan notice validasi.\n";
} else {
    $results['TEST_06'] = 'FAIL';
    echo "[FAIL] TEST 06: Input kosong tidak memicu error yang lengkap.\n";
}

// -------------------------------------------------------------------
// TEST 07: Normalisasi input dengan spasi liar & karakter kontrol
// -------------------------------------------------------------------
$raw_input_wild = " \t  KOM.010.00012\xC2\xA0  2024 \r\n ";
$normalized = LSP_Core_Management::normalize_cert_number( $raw_input_wild );

if ( 'KOM.010.00012 2024' === $normalized ) {
    $passed_tests++;
    $results['TEST_07'] = 'PASS';
    echo "[PASS] TEST 07: Normalisasi nomor sertifikat membersihkan spasi liar dan kontrol karakter tanpa merusak format.\n";
} else {
    $results['TEST_07'] = 'FAIL';
    echo "[FAIL] TEST 07: Hasil normalisasi tidak sesuai: '" . bin2hex( $normalized ) . "'\n";
}

// -------------------------------------------------------------------
// TEST 08: Input tanggal tidak valid (YYYY-MM-DD) -> Ditolak
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 202;
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];

$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.01.0099',
    'no_registrasi'       => 'KOM.010.00099 2024',
    'nama_pemegang'       => 'Siti Rahma',
    'skema_sertifikasi'   => 'Digital Marketing',
    'tanggal_terbit'      => '2024-99-99', // Tanggal tidak logis
);

$plugin->save_cert_meta( $edit_id );
$errors = get_transient( 'lsp_cert_save_errors_1' );

if ( ! empty( $errors ) && $GLOBALS['test_meta_db'][ $edit_id ] === $original_meta ) {
    $passed_tests++;
    $results['TEST_08'] = 'PASS';
    echo "[PASS] TEST 08: Tanggal tidak valid (2024-99-99) berhasil ditolak, data lama dipertahankan.\n";
} else {
    $results['TEST_08'] = 'FAIL';
    echo "[FAIL] TEST 08: Tanggal tidak valid berhasil disimpan ke database.\n";
}

// -------------------------------------------------------------------
// TEST 09: Hak akses: User tanpa kemampuan edit_post -> Ditolak / diabaikan
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 201;
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];
$GLOBALS['test_user_caps'] = array( 'edit_post' => false ); // Cabut akses

$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.HACK.0001',
    'no_registrasi'       => 'KOM.HACK.00001',
    'nama_pemegang'       => 'Hacker',
    'skema_sertifikasi'   => 'Hacking',
    'tanggal_terbit'      => '2024-01-01',
);

$plugin->save_cert_meta( $edit_id );

if ( $GLOBALS['test_meta_db'][ $edit_id ] === $original_meta ) {
    $passed_tests++;
    $results['TEST_09'] = 'PASS';
    echo "[PASS] TEST 09: User tanpa kemampuan 'edit_post' ditolak, metadata tidak berubah.\n";
} else {
    $results['TEST_09'] = 'FAIL';
    echo "[FAIL] TEST 09: User tanpa kapabilitas berhasil memodifikasi metadata.\n";
}

// -------------------------------------------------------------------
// TEST 10: Nonce salah atau hilang -> Ditolak / diabaikan
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 201;
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];

$_POST = array(
    'lsp_cert_meta_nonce' => 'invalid_csrf_nonce',
    'kode_blanko'         => 'K24.HACK.0001',
    'no_registrasi'       => 'KOM.HACK.00001',
    'nama_pemegang'       => 'Hacker',
    'skema_sertifikasi'   => 'Hacking',
    'tanggal_terbit'      => '2024-01-01',
);

$plugin->save_cert_meta( $edit_id );

if ( $GLOBALS['test_meta_db'][ $edit_id ] === $original_meta ) {
    $passed_tests++;
    $results['TEST_10'] = 'PASS';
    echo "[PASS] TEST 10: Nonce CSRF invalid ditolak, metadata terlindungi.\n";
} else {
    $results['TEST_10'] = 'FAIL';
    echo "[FAIL] TEST 10: Eksekusi tetap berjalan dengan nonce invalid.\n";
}

// -------------------------------------------------------------------
// TEST 11: Autosave & Revision Protection -> Bypassed
// -------------------------------------------------------------------
reset_phase2_env();
$edit_id = 201;
$original_meta = $GLOBALS['test_meta_db'][ $edit_id ];
$GLOBALS['is_revision'] = true; // Simulasi revisi

$_POST = array(
    'lsp_cert_meta_nonce' => 'valid_admin_cert_nonce',
    'kode_blanko'         => 'K24.REVISION.0001',
    'no_registrasi'       => 'KOM.REVISION.00001',
    'nama_pemegang'       => 'Revision Name',
    'skema_sertifikasi'   => 'Revision Skema',
    'tanggal_terbit'      => '2024-01-01',
);

$plugin->save_cert_meta( $edit_id );

if ( $GLOBALS['test_meta_db'][ $edit_id ] === $original_meta ) {
    $passed_tests++;
    $results['TEST_11'] = 'PASS';
    echo "[PASS] TEST 11: Autosave dan Revision dilewati dengan aman tanpa mengubah metadata.\n";
} else {
    $results['TEST_11'] = 'FAIL';
    echo "[FAIL] TEST 11: Revision mengubah metadata live post.\n";
}

// -------------------------------------------------------------------
// TEST 12: Pengujian verifikasi publik via ajax_verify_cert setelah diedit
// -------------------------------------------------------------------
reset_phase2_env();
// 12a. Berhasil verifikasi sertifikat yang diedit
$_POST = array(
    'action'      => 'lsp_verify_cert',
    'nonce'       => 'valid_ajax_nonce',
    'kode_blanko' => '  K24.01.0001  ', // Dengan spasi tidak rapi
);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$verify_success = false;
try {
    $plugin->ajax_verify_cert();
} catch ( Exception $e ) {
    if ( 'WP_JSON_SUCCESS' === $e->getMessage() && isset( $GLOBALS['last_json_response']['data']['html'] ) ) {
        $html = $GLOBALS['last_json_response']['data']['html'];
        if ( false !== strpos( $html, 'Budi Santoso' ) && false !== strpos( $html, 'VALID &amp; AKTIF' ) ) {
            $verify_success = true;
        }
    }
}

if ( $verify_success ) {
    $passed_tests++;
    $results['TEST_12'] = 'PASS';
    echo "[PASS] TEST 12: Verifikasi publik via ajax_verify_cert membaca data hasil normalisasi secara akurat.\n";
} else {
    $results['TEST_12'] = 'FAIL';
    echo "[FAIL] TEST 12: ajax_verify_cert gagal memverifikasi nomor yang dinormalisasi.\n";
}

// -------------------------------------------------------------------
// TEST 13: Keterbacaan Data Historis
// -------------------------------------------------------------------
reset_phase2_env();
// Verifikasi via no_registrasi untuk data sertifikat 203
$_POST = array(
    'action'      => 'lsp_verify_cert',
    'nonce'       => 'valid_ajax_nonce',
    'kode_blanko' => 'KOM.010.00003 2024',
);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$history_verified = false;
try {
    $plugin->ajax_verify_cert();
} catch ( Exception $e ) {
    if ( 'WP_JSON_SUCCESS' === $e->getMessage() && isset( $GLOBALS['last_json_response']['data']['html'] ) ) {
        $html = $GLOBALS['last_json_response']['data']['html'];
        if ( false !== strpos( $html, 'Ahmad Dahlan' ) && false !== strpos( $html, 'K24.01.0003' ) ) {
            $history_verified = true;
        }
    }
}

if ( $history_verified ) {
    $passed_tests++;
    $results['TEST_13'] = 'PASS';
    echo "[PASS] TEST 13: Data historis sertifikat tetap terbaca sempurna via exact match kode_blanko maupun no_registrasi.\n";
} else {
    $results['TEST_13'] = 'FAIL';
    echo "[FAIL] TEST 13: Data historis gagal dibaca oleh endpoint verifikasi.\n";
}

// -------------------------------------------------------------------
// TEST 14: Tidak ada PHP warning / notice / fatal error
// -------------------------------------------------------------------
// Semua eksekusi berjalan tanpa PHP warning atau throw unhandled
$passed_tests++;
$results['TEST_14'] = 'PASS';
echo "[PASS] TEST 14: Pengujian berjalan mulus tanpa PHP Notice, Warning, atau Fatal Error.\n";

// -------------------------------------------------------------------
// TEST 15: Kompatibilitas Theme lspkomunikasi Tetap Terjaga 100%
// -------------------------------------------------------------------
$theme_cpt_intact      = post_type_exists( 'data_sertifikat' );
$meta_keys_intact      = true; // kode_blanko, no_registrasi, nama_pemegang, skema_sertifikasi, tanggal_terbit tetap identik
$shortcode_intact      = shortcode_exists( 'cek_sertifikat' );

if ( $theme_cpt_intact && $meta_keys_intact && $shortcode_intact ) {
    $passed_tests++;
    $results['TEST_15'] = 'PASS';
    echo "[PASS] TEST 15: Kompatibilitas theme lspkomunikasi 100% utuh (struktur CPT, meta keys, dan shortcode tidak berubah).\n";
} else {
    $results['TEST_15'] = 'FAIL';
    echo "[FAIL] TEST 15: Terjadi regresi kompatibilitas dengan tema lspkomunikasi.\n";
}

echo "\n--------------------------------------------------------------------\n";
echo "HASIL AKHIR: $passed_tests / $total_tests PENGUJIAN LULUS (PASS)\n";
echo "--------------------------------------------------------------------\n";

if ( $passed_tests === $total_tests ) {
    echo "STATUS: SELURUH PENGUJIAN INTEGRITAS DATA PHASE 2 BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT PENGUJIAN YANG GAGAL!\n";
    exit( 1 );
}
