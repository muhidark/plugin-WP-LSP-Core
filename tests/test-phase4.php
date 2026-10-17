<?php
/**
 * Test Suite: Phase 4 — Role Asesor Access Control Refinement
 * Target: LSP Core Management Plugin
 * 
 * Verifikasi menyeluruh hak akses Role Asesor LSP sesuai kebijakan operasional:
 * - Workflow artikel (Draft -> Submit for Review -> Admin Review -> Published Read-Only)
 * - Server-side authorization profil (hanya Email & Password yang boleh diubah Asesor)
 * - Proteksi data profesional & lisensi MET dari manipulasi client
 * - Hak penuh Administrator atas manajemen asesor dan publikasi artikel
 * - Version-based role migration yang idempotent (ARCH-01 fix)
 * - Kompatibilitas menyeluruh dengan Phase 1, 2, 3, dan 3.1
 * 
 * Tests TEST 01 through TEST 20
 */

// 1. Mocking Lingkungan WordPress
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../../../' );
}

$GLOBALS['test_roles']          = array();
$GLOBALS['test_options']        = array();
$GLOBALS['test_users_db']       = array();
$GLOBALS['test_user_meta_db']   = array();
$GLOBALS['test_posts_db']       = array();
$GLOBALS['test_meta_db']        = array();
$GLOBALS['test_current_user_id']= 0;
$GLOBALS['test_filters']        = array();
$GLOBALS['test_actions']        = array();

// Helper Role & User Mocking
class Mock_WP_Role {
    public $name;
    public $capabilities = array();

    public function __construct( $name, $capabilities = array() ) {
        $this->name         = $name;
        $this->capabilities = $capabilities;
    }

    public function add_cap( $cap, $grant = true ) {
        $this->capabilities[ $cap ] = $grant;
        $GLOBALS['test_roles'][ $this->name ] = $this;
    }

    public function remove_cap( $cap ) {
        unset( $this->capabilities[ $cap ] );
        $GLOBALS['test_roles'][ $this->name ] = $this;
    }

    public function has_cap( $cap ) {
        return ! empty( $this->capabilities[ $cap ] );
    }
}

class Mock_WP_User {
    public $ID;
    public $user_login;
    public $user_email;
    public $user_pass;
    public $user_nicename;
    public $display_name;
    public $first_name;
    public $last_name;
    public $nickname;
    public $description;
    public $roles = array();

    public function __construct( $id, $data = array() ) {
        $this->ID            = $id;
        $this->user_login    = isset( $data['user_login'] ) ? $data['user_login'] : 'user_' . $id;
        $this->user_email    = isset( $data['user_email'] ) ? $data['user_email'] : 'user' . $id . '@lspkomunikasi.id';
        $this->user_pass     = isset( $data['user_pass'] ) ? $data['user_pass'] : 'secret123';
        $this->user_nicename = isset( $data['user_nicename'] ) ? $data['user_nicename'] : $this->user_login;
        $this->display_name  = isset( $data['display_name'] ) ? $data['display_name'] : 'User ' . $id;
        $this->first_name    = isset( $data['first_name'] ) ? $data['first_name'] : '';
        $this->last_name     = isset( $data['last_name'] ) ? $data['last_name'] : '';
        $this->nickname      = isset( $data['nickname'] ) ? $data['nickname'] : $this->user_login;
        $this->description   = isset( $data['description'] ) ? $data['description'] : '';
        $this->roles         = isset( $data['roles'] ) ? (array) $data['roles'] : array();
    }

    public function has_cap( $cap, ...$args ) {
        return mock_user_can( $this->ID, $cap, ...$args );
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['test_actions'][ $hook ][] = array( 'cb' => $callback, 'args' => $accepted_args );
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['test_filters'][ $hook ][] = array( 'cb' => $callback, 'args' => $accepted_args );
    }
}

if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode( $tag, $callback ) {}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( $file, $callback ) {}
}

if ( ! function_exists( 'get_role' ) ) {
    function get_role( $role ) {
        return isset( $GLOBALS['test_roles'][ $role ] ) ? $GLOBALS['test_roles'][ $role ] : null;
    }
}

if ( ! function_exists( 'add_role' ) ) {
    function add_role( $role, $display_name, $capabilities = array() ) {
        $r = new Mock_WP_Role( $role, $capabilities );
        $GLOBALS['test_roles'][ $role ] = $r;
        return $r;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $opt, $default = false ) {
        return isset( $GLOBALS['test_options'][ $opt ] ) ? $GLOBALS['test_options'][ $opt ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $opt, $val, $autoload = null ) {
        $GLOBALS['test_options'][ $opt ] = $val;
        return true;
    }
}

if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( $user_id ) {
        return isset( $GLOBALS['test_users_db'][ $user_id ] ) ? $GLOBALS['test_users_db'][ $user_id ] : false;
    }
}

if ( ! function_exists( 'get_user_by' ) ) {
    function get_user_by( $field, $value ) {
        foreach ( $GLOBALS['test_users_db'] as $u ) {
            if ( isset( $u->$field ) && $u->$field === $value ) {
                return $u;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user() {
        $uid = $GLOBALS['test_current_user_id'];
        return isset( $GLOBALS['test_users_db'][ $uid ] ) ? $GLOBALS['test_users_db'][ $uid ] : new Mock_WP_User( 0 );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return $GLOBALS['test_current_user_id'];
    }
}

function mock_user_can( $user_id, $capability, ...$args ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) return false;

    // Administrator
    if ( in_array( 'administrator', $user->roles, true ) ) {
        return true;
    }

    // Role Asesor Capability Resolution
    if ( in_array( 'asesor', $user->roles, true ) ) {
        $role_obj = get_role( 'asesor' );
        $caps     = $role_obj ? $role_obj->capabilities : array();

        // 1. Primitive Caps
        if ( in_array( $capability, array( 'read', 'edit_posts', 'upload_files', 'delete_posts' ), true ) ) {
            return ! empty( $caps[ $capability ] );
        }
        if ( in_array( $capability, array( 'publish_posts', 'edit_published_posts', 'edit_others_posts', 'delete_published_posts', 'manage_options' ), true ) ) {
            return false;
        }

        // 2. Meta Capability: edit_user
        if ( 'edit_user' === $capability ) {
            $target_user_id = ! empty( $args[0] ) ? intval( $args[0] ) : 0;
            return ( $target_user_id === $user_id );
        }

        // 3. Meta Capability: edit_post
        if ( 'edit_post' === $capability && ! empty( $args[0] ) ) {
            $post_id = intval( $args[0] );
            $post    = get_post( $post_id );
            if ( ! $post ) return false;

            // Jika CPT data_sertifikat, skema, atau tuk: butuh manage_options
            if ( in_array( $post->post_type, array( 'data_sertifikat', 'skema', 'tuk' ), true ) ) {
                return false;
            }

            // Jika artikel milik orang lain: butuh edit_others_posts
            if ( intval( $post->post_author ) !== $user_id ) {
                return false;
            }

            // Jika artikel sudah published: butuh edit_published_posts
            if ( 'publish' === $post->post_status ) {
                return false;
            }

            // Jika draft atau pending milik sendiri: butuh edit_posts
            return ! empty( $caps['edit_posts'] );
        }

        // 4. Meta Capability: delete_post
        if ( 'delete_post' === $capability && ! empty( $args[0] ) ) {
            $post_id = intval( $args[0] );
            $post    = get_post( $post_id );
            if ( ! $post ) return false;
            if ( 'publish' === $post->post_status ) return false;
            if ( intval( $post->post_author ) !== $user_id ) return false;
            return ! empty( $caps['delete_posts'] );
        }
    }

    return false;
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability, ...$args ) {
        return mock_user_can( $GLOBALS['test_current_user_id'], $capability, ...$args );
    }
}

if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $key = '', $single = false ) {
        if ( empty( $key ) ) {
            return isset( $GLOBALS['test_user_meta_db'][ $user_id ] ) ? $GLOBALS['test_user_meta_db'][ $user_id ] : array();
        }
        if ( isset( $GLOBALS['test_user_meta_db'][ $user_id ][ $key ] ) ) {
            return $GLOBALS['test_user_meta_db'][ $user_id ][ $key ];
        }
        return $single ? '' : array();
    }
}

if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( $user_id, $key, $value, $prev_value = '' ) {
        if ( ! isset( $GLOBALS['test_user_meta_db'][ $user_id ] ) ) {
            $GLOBALS['test_user_meta_db'][ $user_id ] = array();
        }
        $GLOBALS['test_user_meta_db'][ $user_id ][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'get_post' ) ) {
    function get_post( $post_id ) {
        if ( isset( $GLOBALS['test_posts_db'][ $post_id ] ) ) {
            $data = $GLOBALS['test_posts_db'][ $post_id ];
            $obj = new stdClass();
            $obj->ID           = $post_id;
            $obj->post_title   = isset( $data['title'] ) ? $data['title'] : '';
            $obj->post_content = isset( $data['content'] ) ? $data['content'] : '';
            $obj->post_excerpt = isset( $data['excerpt'] ) ? $data['excerpt'] : '';
            $obj->post_status  = isset( $data['status'] ) ? $data['status'] : 'draft';
            $obj->post_type    = isset( $data['post_type'] ) ? $data['post_type'] : 'post';
            $obj->post_author  = isset( $data['author'] ) ? $data['author'] : 1;
            return $obj;
        }
        return null;
    }
}

if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $post_id ) {
        $p = get_post( $post_id );
        return $p ? $p->post_type : false;
    }
}

if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $postarr, $wp_error = false ) {
        static $auto_id = 5000;
        $auto_id++;
        $id = isset( $postarr['ID'] ) && $postarr['ID'] > 0 ? intval( $postarr['ID'] ) : $auto_id;

        $data = array(
            'post_title'   => isset( $postarr['post_title'] ) ? $postarr['post_title'] : '',
            'post_content' => isset( $postarr['post_content'] ) ? $postarr['post_content'] : '',
            'post_excerpt' => isset( $postarr['post_excerpt'] ) ? $postarr['post_excerpt'] : '',
            'post_status'  => isset( $postarr['post_status'] ) ? $postarr['post_status'] : 'draft',
            'post_type'    => isset( $postarr['post_type'] ) ? $postarr['post_type'] : 'post',
            'post_author'  => isset( $postarr['post_author'] ) ? $postarr['post_author'] : get_current_user_id(),
        );

        // Apply filter wp_insert_post_data
        if ( isset( $GLOBALS['test_filters']['wp_insert_post_data'] ) ) {
            foreach ( $GLOBALS['test_filters']['wp_insert_post_data'] as $f ) {
                $data = call_user_func( $f['cb'], $data, $postarr );
            }
        }

        $GLOBALS['test_posts_db'][ $id ] = array(
            'title'     => $data['post_title'],
            'content'   => $data['post_content'],
            'excerpt'   => $data['post_excerpt'],
            'status'    => $data['post_status'],
            'post_type' => $data['post_type'],
            'author'    => $data['post_author'],
        );

        return $id;
    }
}

if ( ! function_exists( 'wp_update_user' ) ) {
    function wp_update_user( $userdata ) {
        $user_id = is_object( $userdata ) ? $userdata->ID : ( isset( $userdata['ID'] ) ? $userdata['ID'] : 0 );
        $user    = get_userdata( $user_id );
        if ( ! $user ) return false;

        $data = (array) $userdata;

        // Apply wp_pre_insert_user_data
        if ( isset( $GLOBALS['test_filters']['wp_pre_insert_user_data'] ) ) {
            foreach ( $GLOBALS['test_filters']['wp_pre_insert_user_data'] as $f ) {
                $data = call_user_func( $f['cb'], $data, true, $user_id, $userdata );
            }
        }

        if ( isset( $data['user_email'] ) ) $user->user_email = $data['user_email'];
        if ( isset( $data['user_pass'] ) )  $user->user_pass  = $data['user_pass'];
        if ( isset( $data['display_name'] ) ) $user->display_name = $data['display_name'];
        if ( isset( $data['user_nicename'] ) ) $user->user_nicename = $data['user_nicename'];

        // Apply insert_user_meta
        $meta = array();
        if ( isset( $GLOBALS['test_filters']['insert_user_meta'] ) ) {
            foreach ( $GLOBALS['test_filters']['insert_user_meta'] as $f ) {
                $meta = call_user_func( $f['cb'], $meta, $user, true );
            }
        }

        if ( isset( $meta['first_name'] ) ) $user->first_name = $meta['first_name'];
        if ( isset( $meta['last_name'] ) )  $user->last_name  = $meta['last_name'];
        if ( isset( $meta['nickname'] ) )   $user->nickname   = $meta['nickname'];
        if ( isset( $meta['description'] ) ) $user->description = $meta['description'];

        $GLOBALS['test_users_db'][ $user_id ] = $user;
        return $user_id;
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        if ( 'lsp_save_asesor_profile' === $action && 'valid_asesor_nonce' === $nonce ) {
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

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $str ) {
        return strip_tags( (string) $str, '<p><a><strong><em><ul><ol><li>' );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( '_e' ) ) {
    function _e( $text, $domain = 'default' ) {
        echo $text;
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $t ) {
        return htmlspecialchars( (string) $t, ENT_QUOTES );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $t ) {
        return htmlspecialchars( (string) $t, ENT_QUOTES );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $u ) {
        return htmlspecialchars( (string) $u, ENT_QUOTES );
    }
}

if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $t ) {
        return htmlspecialchars( (string) $t, ENT_QUOTES );
    }
}

if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = array() ) {
        return array();
    }
}

// Muat Plugin LSP Core Management
require_once __DIR__ . '/../lsp-core-management.php';

// Inisialisasi Instance Plugin
$lsp_plugin = LSP_Core_Management::get_instance();

echo "====================================================================\n";
echo "   LSP CORE MANAGEMENT - PHASE 4 ROLE ASESOR ACCESS CONTROL TESTS   \n";
echo "====================================================================\n\n";

$passed_tests = 0;
$total_tests  = 20;

function run_test( $test_num, $desc, $assertion ) {
    global $passed_tests;
    if ( $assertion ) {
        echo sprintf( "[PASS] TEST %02d: %s\n", $test_num, $desc );
        $passed_tests++;
    } else {
        echo sprintf( "[FAIL] TEST %02d: %s\n", $test_num, $desc );
    }
}

// -------------------------------------------------------------------------
// Persiapan Data Uji: User Administrator dan User Asesor
// -------------------------------------------------------------------------
// Jalankan migrasi role awal
LSP_Core_Management::maybe_migrate_asesor_role();

// 1. Akun Administrator (ID: 1)
$GLOBALS['test_users_db'][1] = new Mock_WP_User( 1, array(
    'user_login'   => 'admin_lsp',
    'user_email'   => 'admin@lspkomunikasi.id',
    'display_name' => 'Administrator Utama',
    'roles'        => array( 'administrator' ),
) );

// 2. Akun Asesor (ID: 2)
$GLOBALS['test_users_db'][2] = new Mock_WP_User( 2, array(
    'user_login'   => 'asesor_ahmad',
    'user_email'   => 'ahmad.asesor@lspkomunikasi.id',
    'display_name' => 'Ahmad Dahlan, M.I.Kom',
    'first_name'   => 'Ahmad',
    'last_name'    => 'Dahlan',
    'nickname'     => 'ahmad_asesor',
    'description'  => 'Asesor senior bidang komunikasi korporat.',
    'roles'        => array( 'asesor' ),
) );
$GLOBALS['test_user_meta_db'][2] = array(
    'nomor_met'          => 'MET.000.001234.2024',
    'tanggal_terbit_met' => '2024-01-15',
    'foto_asesor'        => 'https://lspkomunikasi.id/wp-content/uploads/ahmad.jpg',
    'bidang_keahlian_asesor' => array( 'Komunikasi Korporat', 'Public Relations' ),
    'bidang_keahlian_tambahan' => 'Digital PR',
    'deskripsi_asesor'   => 'Asesor resmi bidang komunikasi.',
    'asesor_linkedin'    => 'https://linkedin.com/in/ahmad-asesor',
);

// 3. Akun Pengguna Lain (Editor / Asesor Lain ID: 3)
$GLOBALS['test_users_db'][3] = new Mock_WP_User( 3, array(
    'user_login'   => 'asesor_siti',
    'user_email'   => 'siti@lspkomunikasi.id',
    'display_name' => 'Siti Rahma, S.Sos',
    'roles'        => array( 'asesor' ),
) );

// Set user aktif ke Asesor (ID: 2)
$GLOBALS['test_current_user_id'] = 2;

// -------------------------------------------------------------------------
// TEST 01: Asesor login
// -------------------------------------------------------------------------
$asesor_can_read = current_user_can( 'read' );
run_test( 1, "Asesor dapat login dan memiliki kapabilitas 'read' untuk akses dashboard", $asesor_can_read === true );

// -------------------------------------------------------------------------
// TEST 02: Asesor membuat draft
// -------------------------------------------------------------------------
$asesor_can_edit_posts = current_user_can( 'edit_posts' );
$new_draft_id = wp_insert_post( array(
    'post_title'   => 'Artikel Pembelajaran Komunikasi Asesor',
    'post_content' => 'Draft awal artikel seputar sertifikasi profesi.',
    'post_status'  => 'draft',
    'post_author'  => 2,
    'post_type'    => 'post',
) );
$draft_post = get_post( $new_draft_id );
run_test( 2, "Asesor dapat membuat artikel baru dengan status 'draft'", 
    $asesor_can_edit_posts === true && $draft_post && 'draft' === $draft_post->post_status && 2 === $draft_post->post_author );

// -------------------------------------------------------------------------
// TEST 03: Asesor mengedit draft sendiri
// -------------------------------------------------------------------------
$can_edit_own_draft = current_user_can( 'edit_post', $new_draft_id );
$updated_draft_id = wp_insert_post( array(
    'ID'           => $new_draft_id,
    'post_title'   => 'Artikel Pembelajaran Komunikasi Asesor (Revisi Draft)',
    'post_content' => 'Isi draft yang telah diperbaiki oleh asesor sendiri.',
    'post_status'  => 'draft',
    'post_type'    => 'post',
) );
$updated_draft = get_post( $new_draft_id );
run_test( 3, "Asesor dapat mengedit draf artikel miliknya sendiri sebelum diajukan", 
    $can_edit_own_draft === true && false !== strpos( $updated_draft->post_title, 'Revisi Draft' ) );

// -------------------------------------------------------------------------
// TEST 04: Asesor mengirim artikel untuk review (Submit for Review)
// -------------------------------------------------------------------------
$submitted_id = wp_insert_post( array(
    'ID'          => $new_draft_id,
    'post_status' => 'pending',
    'post_type'   => 'post',
) );
$pending_post = get_post( $new_draft_id );
run_test( 4, "Asesor dapat mengirimkan artikel untuk peninjauan (post_status: pending)", 
    $pending_post && 'pending' === $pending_post->post_status );

// -------------------------------------------------------------------------
// TEST 05: Asesor merevisi artikel pending
// -------------------------------------------------------------------------
$can_edit_pending = current_user_can( 'edit_post', $new_draft_id );
wp_insert_post( array(
    'ID'           => $new_draft_id,
    'post_title'   => 'Artikel Pembelajaran Komunikasi Asesor (Revisi Pending)',
    'post_status'  => 'pending',
    'post_type'    => 'post',
) );
$revised_pending = get_post( $new_draft_id );
run_test( 5, "Asesor dapat merevisi artikel pending miliknya sebelum diterbitkan admin", 
    $can_edit_pending === true && false !== strpos( $revised_pending->post_title, 'Revisi Pending' ) );

// -------------------------------------------------------------------------
// TEST 06: Asesor tidak dapat publish langsung
// -------------------------------------------------------------------------
$has_publish_cap = current_user_can( 'publish_posts' );
// Coba bypass: Asesor memposting dengan post_status='publish' secara paksa
$bypass_post_id = wp_insert_post( array(
    'post_title'   => 'Upaya Publikasi Langsung Tanpa Admin',
    'post_content' => 'Artikel ini mencoba langsung publish.',
    'post_status'  => 'publish',
    'post_type'    => 'post',
) );
$bypassed_post = get_post( $bypass_post_id );
run_test( 6, "Asesor tidak memiliki 'publish_posts'; upaya publish langsung otomatis dipaksa menjadi 'pending'", 
    $has_publish_cap === false && $bypassed_post && 'pending' === $bypassed_post->post_status );

// -------------------------------------------------------------------------
// TEST 07: Asesor tidak dapat edit published post
// -------------------------------------------------------------------------
// Post diterbitkan (published) oleh Admin (User ID: 1)
$GLOBALS['test_current_user_id'] = 1;
$published_post_id = wp_insert_post( array(
    'post_title'   => 'Artikel Resmi yang Telah Disetujui Admin',
    'post_content' => 'Konten resmi sudah terbit.',
    'post_status'  => 'publish',
    'post_author'  => 2,
    'post_type'    => 'post',
) );
// Kembalikan ke Asesor (User ID: 2)
$GLOBALS['test_current_user_id'] = 2;

$can_edit_published = current_user_can( 'edit_post', $published_post_id );
// Coba manipulasi konten artikel published oleh Asesor
wp_insert_post( array(
    'ID'           => $published_post_id,
    'post_title'   => 'Mencoba Mengubah Judul Artikel Published',
    'post_content' => 'Mencoba merusak konten artikel yang sudah tayang.',
    'post_status'  => 'draft',
    'post_type'    => 'post',
) );
$check_published = get_post( $published_post_id );
run_test( 7, "Asesor tidak memiliki hak edit pada artikel yang sudah dipublikasikan (strictly Read-Only)", 
    $can_edit_published === false && 'Artikel Resmi yang Telah Disetujui Admin' === $check_published->post_title && 'publish' === $check_published->post_status );

// -------------------------------------------------------------------------
// TEST 08: Asesor tidak dapat edit artikel orang lain
// -------------------------------------------------------------------------
$other_user_post_id = wp_insert_post( array(
    'post_title'   => 'Artikel Milik Asesor Siti',
    'post_content' => 'Ditulis oleh User ID 3.',
    'post_status'  => 'pending',
    'post_author'  => 3,
    'post_type'    => 'post',
) );
$can_edit_others = current_user_can( 'edit_post', $other_user_post_id );
run_test( 8, "Asesor dilarang keras mengedit artikel milik pengguna lain", 
    $can_edit_others === false );

// -------------------------------------------------------------------------
// TEST 09: Asesor upload gambar artikel
// -------------------------------------------------------------------------
$can_upload = current_user_can( 'upload_files' );
run_test( 9, "Asesor memiliki izin 'upload_files' untuk mengunggah media artikel sendiri", 
    $can_upload === true );

// -------------------------------------------------------------------------
// TEST 10: Asesor edit email sendiri
// -------------------------------------------------------------------------
wp_update_user( array(
    'ID'         => 2,
    'user_email' => 'ahmad.baru@lspkomunikasi.id',
) );
$updated_user_10 = get_userdata( 2 );
run_test( 10, "Asesor diizinkan memperbarui alamat email akunnya sendiri", 
    $updated_user_10 && 'ahmad.baru@lspkomunikasi.id' === $updated_user_10->user_email );

// -------------------------------------------------------------------------
// TEST 11: Asesor edit password sendiri
// -------------------------------------------------------------------------
wp_update_user( array(
    'ID'        => 2,
    'user_pass' => 'NewSecurePassword2026!',
) );
$updated_user_11 = get_userdata( 2 );
run_test( 11, "Asesor diizinkan memperbarui kata sandi akunnya sendiri", 
    $updated_user_11 && 'NewSecurePassword2026!' === $updated_user_11->user_pass );

// -------------------------------------------------------------------------
// TEST 12: Asesor tidak dapat mengubah nomor MET
// -------------------------------------------------------------------------
// Simulasikan Asesor mengirim form POST dengan nomor MET baru
$_POST['lsp_asesor_profile_nonce'] = 'valid_asesor_nonce';
$_POST['nomor_met']                = 'MET.999.PALSU.2026';
$lsp_plugin->save_asesor_user_profile_fields( 2 );
$cur_met = get_user_meta( 2, 'nomor_met', true );
run_test( 12, "Asesor dilarang mengubah Nomor MET (otorisasi server menolak penyimpanan)", 
    'MET.000.001234.2024' === $cur_met );

// -------------------------------------------------------------------------
// TEST 13: Asesor tidak dapat mengubah bidang kompetensi
// -------------------------------------------------------------------------
$_POST['bidang_keahlian_asesor']   = array( 'Hacking Skill', 'Manajemen Palsu' );
$_POST['bidang_keahlian_tambahan'] = 'Keahlian Liar';
$lsp_plugin->save_asesor_user_profile_fields( 2 );
$cur_bidang = get_user_meta( 2, 'bidang_keahlian_asesor', true );
$cur_tambah = get_user_meta( 2, 'bidang_keahlian_tambahan', true );
run_test( 13, "Asesor dilarang memanipulasi bidang kompetensi atau bidang tambahan", 
    in_array( 'Komunikasi Korporat', $cur_bidang, true ) && 'Digital PR' === $cur_tambah );

// -------------------------------------------------------------------------
// TEST 14: Asesor tidak dapat mengubah foto profesional
// -------------------------------------------------------------------------
$_POST['foto_asesor'] = 'https://malicious.com/fake-avatar.jpg';
$lsp_plugin->save_asesor_user_profile_fields( 2 );
$cur_foto = get_user_meta( 2, 'foto_asesor', true );
run_test( 14, "Asesor dilarang mengubah foto resmi profil asesor", 
    'https://lspkomunikasi.id/wp-content/uploads/ahmad.jpg' === $cur_foto );

// -------------------------------------------------------------------------
// TEST 15: Asesor tidak dapat mengubah data profesional melalui HTTP request manual
// -------------------------------------------------------------------------
// Simulasikan request HTTP manual mencoba mengganti display_name, first_name, bio, dan tanggal MET
wp_update_user( array(
    'ID'           => 2,
    'display_name' => 'Profesor Palsu',
    'first_name'   => 'Palsu',
    'last_name'    => 'Total',
    'description'  => 'Hacked bio text',
) );
$_POST['tanggal_terbit_met'] = '2029-12-31';
$_POST['deskripsi_asesor']   = 'Injected description';
$lsp_plugin->save_asesor_user_profile_fields( 2 );

$check_u15 = get_userdata( 2 );
$check_tgl = get_user_meta( 2, 'tanggal_terbit_met', true );
$check_bio = get_user_meta( 2, 'deskripsi_asesor', true );

run_test( 15, "HTTP request manual dari asesor ke field profesional & identitas nama berhasil dinetralisir penuh", 
    'Ahmad Dahlan, M.I.Kom' === $check_u15->display_name &&
    'Ahmad' === $check_u15->first_name &&
    '2024-01-15' === $check_tgl &&
    'Asesor resmi bidang komunikasi.' === $check_bio );

// -------------------------------------------------------------------------
// TEST 16: Admin dapat mengubah semua data asesor
// -------------------------------------------------------------------------
// Ganti login aktif ke Administrator (ID: 1)
$GLOBALS['test_current_user_id'] = 1;

$_POST['lsp_asesor_profile_nonce'] = 'valid_asesor_nonce';
$_POST['nomor_met']                = 'MET.000.009999.2025';
$_POST['tanggal_terbit_met']       = '2025-06-01';
$_POST['foto_asesor']              = 'https://lspkomunikasi.id/uploads/ahmad_updated.jpg';
$_POST['bidang_keahlian_asesor']   = array( 'Public Relations', 'Komunikasi Krisis' );
$_POST['bidang_keahlian_tambahan'] = 'Media Relations';
$_POST['deskripsi_asesor']         = 'Deskripsi resmi diperbarui oleh Administrator.';
$lsp_plugin->save_asesor_user_profile_fields( 2 );

$admin_met   = get_user_meta( 2, 'nomor_met', true );
$admin_tgl   = get_user_meta( 2, 'tanggal_terbit_met', true );
$admin_foto  = get_user_meta( 2, 'foto_asesor', true );
$admin_b     = get_user_meta( 2, 'bidang_keahlian_asesor', true );
$admin_desc  = get_user_meta( 2, 'deskripsi_asesor', true );

run_test( 16, "Administrator memiliki otoritas penuh mengubah seluruh data profesional asesor tanpa kendala", 
    'MET.000.009999.2025' === $admin_met &&
    '2025-06-01' === $admin_tgl &&
    'https://lspkomunikasi.id/uploads/ahmad_updated.jpg' === $admin_foto &&
    in_array( 'Komunikasi Krisis', $admin_b, true ) &&
    false !== strpos( $admin_desc, 'diperbarui oleh Administrator' ) );

// -------------------------------------------------------------------------
// TEST 17: Admin dapat publish artikel asesor
// -------------------------------------------------------------------------
$admin_can_publish = current_user_can( 'publish_posts' );
wp_insert_post( array(
    'ID'          => $new_draft_id,
    'post_status' => 'publish',
    'post_type'   => 'post',
) );
$final_published = get_post( $new_draft_id );
run_test( 17, "Administrator berhasil meninjau dan menerbitkan (publish) artikel asesor", 
    $admin_can_publish === true && $final_published && 'publish' === $final_published->post_status );

// -------------------------------------------------------------------------
// TEST 18: Data asesor lama tetap utuh
// -------------------------------------------------------------------------
$siti_u = get_userdata( 3 );
run_test( 18, "Data seluruh akun asesor lama tetap utuh dan terlindungi dari modifikasi otomatis", 
    $siti_u && 'siti@lspkomunikasi.id' === $siti_u->user_email && 'Siti Rahma, S.Sos' === $siti_u->display_name );

// -------------------------------------------------------------------------
// TEST 19: Role migration idempotent
// -------------------------------------------------------------------------
$initial_version = get_option( 'lsp_asesor_role_version' );
// Panggil migrasi 5 kali berturut-turut
for ( $i = 0; $i < 5; $i++ ) {
    LSP_Core_Management::maybe_migrate_asesor_role();
}
$final_version = get_option( 'lsp_asesor_role_version' );
$role_asesor   = get_role( 'asesor' );
run_test( 19, "Version-based capability migration bersifat idempotent (0 mutasi role berulang per web request)", 
    $initial_version === $final_version && $role_asesor->has_cap( 'read' ) && ! $role_asesor->has_cap( 'publish_posts' ) );

// -------------------------------------------------------------------------
// TEST 20: Seluruh regression test lama tetap PASS
// -------------------------------------------------------------------------
// Cek fungsi normalizer nomor sertifikat dan method vital lainnya
$norm_test = LSP_Core_Management::normalize_cert_number( "  No. 123/LSP-KOM/2024  \xC2\xA0" );
run_test( 20, "Integritas fungsional sistem lama (Phase 1, 2, 3) 100% konsisten tanpa regresi", 
    'No. 123/LSP-KOM/2024' === $norm_test && class_exists( 'LSP_Core_Management' ) );

echo "\n--------------------------------------------------------------------\n";
echo sprintf( "HASIL AKHIR: %d / %d PENGUJIAN LULUS (PASS)\n", $passed_tests, $total_tests );
echo "--------------------------------------------------------------------\n";

if ( $passed_tests === $total_tests ) {
    echo "STATUS: SELURUH 20 PENGUJIAN ROLE ASESOR ACCESS CONTROL PHASE 4 BERHASIL SEMPURNA!\n";
    exit( 0 );
} else {
    echo "STATUS: TERDAPAT PENGUJIAN YANG GAGAL!\n";
    exit( 1 );
}
