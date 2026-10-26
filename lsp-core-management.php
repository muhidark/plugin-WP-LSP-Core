<?php
/**
 * Plugin Name: LSP Core Management & Sertifikat Checker
 * Plugin URI:  https://lspkomunikasi.id/
 * Description: Plugin inti (Core) untuk Website Lembaga Sertifikasi Profesi (LSP): Manajemen Skema Sertifikasi, Tempat Uji Kompetensi (TUK + Google Maps), Manajemen User Asesor (Multi-Bidang, Masa Berlaku MET 3 Tahun, & Approval Artikel), serta Sistem Verifikasi Keabsahan Sertifikat BNSP.
 * Version:     1.1.0-RC1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Muhadi Ganteng
 * Author URI:  https://instagram.com/yuk.sepedaan
 * Text Domain: lsp-core-management
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'LSP_Core_Management' ) ) :

require_once ( function_exists( 'plugin_dir_path' ) ? plugin_dir_path( __FILE__ ) : dirname( __FILE__ ) . '/' ) . 'includes/class-skema.php';

class LSP_Core_Management {

    const ASESOR_ROLE_VERSION = '1.1.0';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Mengambil data unit kompetensi lengkap beserta Elemen & KUK dari SKKNI
     *
     * @param int $post_id ID Skema
     * @return array
     */
    public static function get_skema_skkni_data( $post_id ) {
        return LSP_Core_Skema::get_skema_skkni_data( $post_id );
    }

    public function __construct() {
        // 1. Register Custom Post Types (Skema, TUK, Data Sertifikat) & Custom User Role (Asesor)
        add_action( 'init', array( $this, 'register_cpts_and_roles' ) );

        // 2. Meta Boxes & Save Handlers (Skema, TUK, Data Sertifikat)
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post_skema', array( $this, 'save_skema_meta' ) );
        add_action( 'save_post_tuk', array( $this, 'save_tuk_meta' ) );
        add_action( 'save_post_data_sertifikat', array( $this, 'save_cert_meta' ) );

        // 3. Admin Columns (Skema, TUK, Data Sertifikat)
        add_filter( 'manage_skema_posts_columns', array( $this, 'skema_custom_columns' ) );
        add_action( 'manage_skema_posts_custom_column', array( $this, 'skema_custom_column_content' ), 10, 2 );

        add_filter( 'manage_tuk_posts_columns', array( $this, 'tuk_custom_columns' ) );
        add_action( 'manage_tuk_posts_custom_column', array( $this, 'tuk_custom_column_content' ), 10, 2 );

        add_filter( 'manage_data_sertifikat_posts_columns', array( $this, 'cert_custom_columns' ) );
        add_action( 'manage_data_sertifikat_posts_custom_column', array( $this, 'cert_custom_column_content' ), 10, 2 );

        // 4. User Profile Fields & Proteksi Otorisasi untuk Role Asesor
        add_action( 'show_user_profile', array( $this, 'render_asesor_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_asesor_user_profile_fields' ) );
        add_action( 'user_new_form', array( $this, 'render_asesor_new_user_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_asesor_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_asesor_user_profile_fields' ) );
        add_action( 'user_register', array( $this, 'save_asesor_user_profile_fields' ) );

        // Proteksi Otorisasi Server-Side untuk Profil & Artikel Asesor (Least Privilege)
        add_filter( 'wp_pre_insert_user_data', array( $this, 'protect_asesor_user_data' ), 10, 4 );
        add_filter( 'insert_user_meta', array( $this, 'protect_asesor_user_meta' ), 10, 3 );
        add_filter( 'wp_insert_post_data', array( $this, 'enforce_asesor_post_workflow' ), 10, 2 );
        add_filter( 'map_meta_cap', array( $this, 'restrict_lsp_cpts_from_asesor' ), 10, 4 );

        add_filter( 'manage_users_columns', array( $this, 'asesor_user_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'asesor_user_column_content' ), 10, 3 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Batasi Asesor agar hanya melihat media miliknya sendiri saat upload gambar artikel
        add_filter( 'ajax_query_attachments_args', array( $this, 'restrict_asesor_media_library' ) );
        // Notifikasi di Admin jika ada artikel Asesor yang menunggu persetujuan (Pending Review)
        add_action( 'admin_notices', array( $this, 'pending_asesor_posts_notice' ) );
        // Notifikasi Validasi & Pencegahan Duplikasi Data Sertifikat
        add_action( 'admin_notices', array( $this, 'display_cert_admin_notices' ) );
        add_filter( 'redirect_post_location', array( $this, 'filter_cert_save_redirect_location' ), 10, 2 );

        // 5. Query Filter untuk Archive Skema (/skema/)
        add_action( 'pre_get_posts', array( $this, 'skema_archive_query' ) );

        // 6. Admin Menus (Import CSV Sertifikat, Pintasan Asesor LSP, Pengaturan LSP, & Panduan Penggunaan)
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
        add_action( 'admin_init', array( $this, 'handle_csv_template_download' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_guide_widget' ) );

        // 7. Shortcodes Frontend & Normalizer Sintaks Shortcode
        add_filter( 'the_content', array( $this, 'normalize_lsp_shortcodes_in_content' ), 7 );
        add_filter( 'widget_text_content', array( $this, 'normalize_lsp_shortcodes_in_content' ), 7 );
        add_shortcode( 'lsp_skema', array( $this, 'shortcode_skema' ) );
        add_shortcode( 'lsp_tuk', array( $this, 'shortcode_tuk' ) );
        add_shortcode( 'lsp_asesor', array( $this, 'shortcode_asesor' ) );
        add_shortcode( 'cek_sertifikat', array( $this, 'shortcode_cek_sertifikat' ) );

        // 8. AJAX Handler untuk Verifikasi Sertifikat
        add_action( 'wp_ajax_lsp_verify_cert', array( $this, 'ajax_verify_cert' ) );
        add_action( 'wp_ajax_nopriv_lsp_verify_cert', array( $this, 'ajax_verify_cert' ) );

        // 9. Template Loader & Fallback Content Filter (Agar bekerja di tema apa saja)
        add_filter( 'template_include', array( $this, 'template_loader' ) );

        // 10. Frontend Assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
    }

    /**
     * ====================================================================
     * 1. REGISTER CUSTOM POST TYPES (SKEMA, TUK, SERTIFIKAT) & USER ROLE (ASESOR)
     * ====================================================================
     */
    public function register_cpts_and_roles() {
        // A. Custom Post Type: Skema Sertifikasi (Induk Menu LSP Core)
        register_post_type( 'skema', array(
            'labels'      => array(
                'name'               => __( 'Skema Sertifikasi', 'lsp-core-management' ),
                'singular_name'      => __( 'Skema', 'lsp-core-management' ),
                'menu_name'          => __( 'LSP Core', 'lsp-core-management' ),
                'all_items'          => __( 'Skema Sertifikasi', 'lsp-core-management' ),
                'add_new'            => __( 'Tambah Skema', 'lsp-core-management' ),
                'add_new_item'       => __( 'Tambah Skema Baru', 'lsp-core-management' ),
                'edit_item'          => __( 'Edit Skema', 'lsp-core-management' ),
                'view_item'          => __( 'Lihat Skema', 'lsp-core-management' ),
                'search_items'       => __( 'Cari Skema', 'lsp-core-management' ),
                'not_found'          => __( 'Tidak ada skema ditemukan.', 'lsp-core-management' ),
            ),
            'public'        => true,
            'has_archive'   => true,
            'menu_icon'     => 'dashicons-awards',
            'menu_position' => 24,
            'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
            'rewrite'       => array( 'slug' => 'skema' ),
            'show_in_rest'  => true,
        ) );

        // B. Custom Post Type: Tempat Uji Kompetensi (TUK) di bawah menu LSP Core
        register_post_type( 'tuk', array(
            'labels'      => array(
                'name'               => __( 'Tempat Uji Kompetensi', 'lsp-core-management' ),
                'singular_name'      => __( 'TUK', 'lsp-core-management' ),
                'menu_name'          => __( 'TUK', 'lsp-core-management' ),
                'all_items'          => __( 'TUK', 'lsp-core-management' ),
                'add_new'            => __( 'Tambah TUK', 'lsp-core-management' ),
                'add_new_item'       => __( 'Tambah TUK Baru', 'lsp-core-management' ),
                'edit_item'          => __( 'Edit TUK', 'lsp-core-management' ),
                'view_item'          => __( 'Lihat TUK', 'lsp-core-management' ),
                'search_items'       => __( 'Cari TUK', 'lsp-core-management' ),
                'not_found'          => __( 'Tidak ada TUK ditemukan.', 'lsp-core-management' ),
            ),
            'public'        => true,
            'has_archive'   => true,
            'menu_icon'     => 'dashicons-location-alt',
            'show_in_menu'  => 'edit.php?post_type=skema',
            'supports'      => array( 'title', 'editor', 'thumbnail' ),
            'rewrite'       => array( 'slug' => 'tuk' ),
            'show_in_rest'  => true,
        ) );

        // C. Custom Post Type: Data Sertifikat (Verifikasi Keabsahan)
        register_post_type( 'data_sertifikat', array(
            'labels'      => array(
                'name'               => __( 'Data Sertifikat', 'lsp-core-management' ),
                'singular_name'      => __( 'Sertifikat', 'lsp-core-management' ),
                'menu_name'          => __( 'Sertifikat LSP', 'lsp-core-management' ),
                'name_admin_bar'     => __( 'Sertifikat', 'lsp-core-management' ),
                'add_new'            => __( 'Tambah Sertifikat', 'lsp-core-management' ),
                'add_new_item'       => __( 'Tambah Data Sertifikat Baru', 'lsp-core-management' ),
                'new_item'           => __( 'Sertifikat Baru', 'lsp-core-management' ),
                'edit_item'          => __( 'Edit Data Sertifikat', 'lsp-core-management' ),
                'view_item'          => __( 'Lihat Sertifikat', 'lsp-core-management' ),
                'all_items'          => __( 'Semua Sertifikat', 'lsp-core-management' ),
                'search_items'       => __( 'Cari Sertifikat', 'lsp-core-management' ),
                'not_found'          => __( 'Tidak ada sertifikat ditemukan.', 'lsp-core-management' ),
                'not_found_in_trash' => __( 'Tidak ada sertifikat di kotak sampah.', 'lsp-core-management' ),
            ),
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'menu_icon'       => 'dashicons-id-alt',
            'query_var'       => false,
            'rewrite'         => false,
            'capability_type' => 'post',
            'has_archive'     => false,
            'hierarchical'    => false,
            'menu_position'   => 26,
            'supports'        => array( 'title' ),
        ) );

        // D. Custom User Role: Asesor LSP ('asesor') via Version-Based Migration (Idempotent, Zero Repeated Writes)
        self::maybe_migrate_asesor_role();
    }

    /**
     * Version-Based Capability Migration untuk Role Asesor LSP.
     * Mencegah pemanggilan add_cap/remove_cap dan update_option berulang pada setiap web request (ARCH-01).
     */
    public static function maybe_migrate_asesor_role() {
        $current_version = get_option( 'lsp_asesor_role_version', '' );
        if ( self::ASESOR_ROLE_VERSION === $current_version && function_exists( 'get_role' ) && get_role( 'asesor' ) ) {
            return;
        }

        // Definisi kapabilitas Role Asesor sesuai prinsip least privilege:
        // Dapat login, membuat draft, upload media, submit for review, dan merevisi artikel pending miliknya.
        // TIDAK memiliki hak publish, mengedit artikel orang lain, atau mengedit artikel yang sudah terbit.
        $asesor_caps = array(
            'read'                   => true,
            'edit_posts'             => true,
            'upload_files'           => true,
            'delete_posts'           => true,
            'publish_posts'          => false,
            'edit_published_posts'   => false,
            'edit_others_posts'      => false,
            'delete_published_posts' => false,
            'delete_others_posts'    => false,
            'read_private_posts'     => false,
            'manage_categories'      => false,
        );

        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $role = get_role( 'asesor' );
        if ( ! $role ) {
            if ( function_exists( 'add_role' ) ) {
                add_role( 'asesor', __( 'Asesor LSP', 'lsp-core-management' ), $asesor_caps );
            }
        } else {
            foreach ( $asesor_caps as $cap => $grant ) {
                if ( $grant ) {
                    $role->add_cap( $cap );
                } else {
                    $role->remove_cap( $cap );
                }
            }
        }

        if ( function_exists( 'update_option' ) ) {
            update_option( 'lsp_asesor_role_version', self::ASESOR_ROLE_VERSION );
        }
    }

    /**
     * ====================================================================
     * 2. META BOXES: SKEMA SERTIFIKASI & TUK (+ GOOGLE MAPS)
     * ====================================================================
     */
    public function register_meta_boxes() {
        // Meta Box Skema Sertifikasi
        add_meta_box(
            'lsp_skema_meta',
            __( 'Data Detail Skema Sertifikasi', 'lsp-core-management' ),
            array( $this, 'render_skema_meta_box' ),
            'skema',
            'normal',
            'high'
        );

        // Meta Box TUK (Tempat Uji Kompetensi + Google Maps)
        add_meta_box(
            'lsp_tuk_meta',
            __( 'Data Detail & Peta Lokasi TUK', 'lsp-core-management' ),
            array( $this, 'render_tuk_meta_box' ),
            'tuk',
            'normal',
            'high'
        );

        // Meta Box Data Sertifikat
        add_meta_box(
            'lsp_cert_detail_box',
            __( 'Rincian Data Sertifikat Pemegang', 'lsp-core-management' ),
            array( $this, 'render_cert_meta_box' ),
            'data_sertifikat',
            'normal',
            'high'
        );
    }

    /**
     * 2A. Render & Save Meta Box Skema (Facade to LSP_Core_Skema)
     */
    public function render_skema_meta_box( $post ) {
        return LSP_Core_Skema::get_instance()->render_skema_meta_box( $post );
    }

    public function save_skema_meta( $post_id ) {
        return LSP_Core_Skema::get_instance()->save_skema_meta( $post_id );
    }

    /**
     * Otomatis membuat / memperbarui gambar sampul (Featured Image) Skema jika belum ada foto kustom
     */
    public function maybe_generate_skema_auto_thumbnail( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'skema' !== $post->post_type || 'auto-draft' === $post->post_status ) {
            return;
        }

        if ( empty( trim( $post->post_title ) ) || 'Auto Draft' === $post->post_title ) {
            return;
        }

        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $is_auto = get_post_meta( $thumb_id, '_lsp_auto_thumb', true );
            $t_title = get_the_title( $thumb_id );
            // Jika gambar yang terpasang adalah foto kustom yang diupload manual oleh user, jangan ditimpa
            if ( ! $is_auto && strpos( (string) $t_title, 'skema-' ) !== 0 ) {
                return;
            }
        }

        $level   = get_post_meta( $post_id, 'level_skema', true ) ?: get_post_meta( $post_id, 'level', true );
        $kemasan = get_post_meta( $post_id, 'jenis_kemasan', true );
        $kode    = get_post_meta( $post_id, 'kode_skema', true );

        $badge_parts = array();
        if ( ! empty( $level ) ) {
            $badge_parts[] = 'LEVEL ' . $level;
        }
        if ( ! empty( $kemasan ) ) {
            $badge_parts[] = strtoupper( $kemasan );
        }
        $badge_text = ! empty( $badge_parts ) ? implode( ' • ', $badge_parts ) : 'SKEMA SERTIFIKASI';
        $subtitle   = ! empty( $kode ) ? 'Kode: ' . $kode : 'Standar Kompetensi Kerja Nasional (SKKNI)';

        $schemes   = array( 'maroon', 'navy', 'teal', 'purple', 'slate', 'crimson' );
        $bg_scheme = $schemes[ $post_id % count( $schemes ) ];

        $slug     = $post->post_name ?: ( ! empty( $post->post_title ) ? sanitize_title( $post->post_title ) : 'post-' . $post_id );
        $filename = 'skema-' . sanitize_file_name( $slug ) . '.jpg';

        $new_thumb_id = self::generate_auto_cover_image( $filename, $badge_text, $post->post_title, $subtitle, $bg_scheme, $thumb_id );
        if ( $new_thumb_id ) {
            set_post_thumbnail( $post_id, $new_thumb_id );
            update_post_meta( $new_thumb_id, '_lsp_auto_thumb', '1' );
        }
    }

    /**
     * Generator Gambar Sampul (Auto-Cover Thumbnail 800x600) menggunakan PHP GD
     */
    public static function generate_auto_cover_image( $filename, $badge_text, $full_title, $subtitle, $bg_scheme = 'maroon', $existing_attach_id = 0 ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) {
            return 0;
        }

        $file_path = trailingslashit( $upload_dir['path'] ) . $filename;
        $width     = 800;
        $height    = 600;

        $im = imagecreatetruecolor( $width, $height );
        imagesavealpha( $im, true );

        $schemes = array(
            'maroon'  => array( array( 114, 22, 26 ), array( 168, 32, 38 ), array( 248, 155, 41 ) ),
            'navy'    => array( array( 15, 23, 42 ), array( 30, 58, 138 ), array( 248, 155, 41 ) ),
            'teal'    => array( array( 17, 94, 89 ), array( 13, 148, 136 ), array( 251, 191, 36 ) ),
            'purple'  => array( array( 76, 29, 149 ), array( 109, 40, 217 ), array( 248, 155, 41 ) ),
            'slate'   => array( array( 30, 41, 59 ), array( 51, 65, 85 ), array( 248, 155, 41 ) ),
            'crimson' => array( array( 136, 19, 55 ), array( 190, 18, 60 ), array( 251, 191, 36 ) ),
        );
        $pal = isset( $schemes[ $bg_scheme ] ) ? $schemes[ $bg_scheme ] : $schemes['maroon'];

        for ( $y = 0; $y < $height; $y++ ) {
            $ratio = $y / $height;
            $r     = (int) ( $pal[0][0] * ( 1 - $ratio ) + $pal[1][0] * $ratio );
            $g     = (int) ( $pal[0][1] * ( 1 - $ratio ) + $pal[1][1] * $ratio );
            $b     = (int) ( $pal[0][2] * ( 1 - $ratio ) + $pal[1][2] * $ratio );
            $col   = imagecolorallocate( $im, $r, $g, $b );
            imageline( $im, 0, $y, $width, $y, $col );
        }

        $accent_alpha = imagecolorallocatealpha( $im, $pal[2][0], $pal[2][1], $pal[2][2], 105 );
        $white_alpha  = imagecolorallocatealpha( $im, 255, 255, 255, 115 );
        imagefilledellipse( $im, (int) ( $width * 0.85 ), (int) ( $height * 0.2 ), (int) ( $width * 0.45 ), (int) ( $width * 0.45 ), $accent_alpha );
        imagefilledellipse( $im, (int) ( $width * 0.12 ), (int) ( $height * 0.88 ), (int) ( $width * 0.35 ), (int) ( $width * 0.35 ), $white_alpha );

        $accent_col = imagecolorallocate( $im, $pal[2][0], $pal[2][1], $pal[2][2] );
        $white      = imagecolorallocate( $im, 255, 255, 255 );
        $light_gray = imagecolorallocate( $im, 226, 232, 240 );
        $dark_text  = imagecolorallocate( $im, 26, 25, 25 );

        imagefilledrectangle( $im, 0, $height - 12, $width, $height, $accent_col );

        // Pecah judul menjadi 2 baris proporsional
        $words = explode( ' ', trim( wp_strip_all_tags( $full_title ) ) );
        $line1 = '';
        $line2 = '';
        foreach ( $words as $w ) {
            if ( strlen( $line1 . ' ' . $w ) <= 24 || empty( $line1 ) ) {
                $line1 = trim( $line1 . ' ' . $w );
            } else {
                $line2 = trim( $line2 . ' ' . $w );
            }
        }
        if ( strlen( $line2 ) > 28 ) {
            $line2 = substr( $line2, 0, 25 ) . '...';
        }

        $font_bold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $font_reg  = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

        if ( file_exists( $font_bold ) ) {
            imagettftext( $im, 13, 0, 48, 52, $light_gray, $font_bold, 'LSP KOMUNIKASI INDONESIA • TERLISENSI BNSP' );

            if ( ! empty( $badge_text ) ) {
                $bbox = imagettfbbox( 14, 0, $font_bold, $badge_text );
                $bw   = abs( $bbox[4] - $bbox[0] ) + 36;
                imagefilledrectangle( $im, 48, 95, 48 + $bw, 136, $accent_col );
                imagettftext( $im, 14, 0, 66, 122, $dark_text, $font_bold, $badge_text );
            }

            imagettftext( $im, 29, 0, 48, 220, $white, $font_bold, $line1 );
            if ( ! empty( $line2 ) ) {
                imagettftext( $im, 29, 0, 48, 270, $white, $font_bold, $line2 );
            }

            imagefilledrectangle( $im, 48, 308, 160, 314, $accent_col );

            if ( ! empty( $subtitle ) && file_exists( $font_reg ) ) {
                imagettftext( $im, 16, 0, 48, 358, $light_gray, $font_reg, $subtitle );
            }

            if ( file_exists( $font_reg ) ) {
                imagettftext( $im, 13, 0, 48, $height - 40, $light_gray, $font_reg, 'Standar Kompetensi Kerja Nasional Indonesia (SKKNI)' );
            }
        } else {
            // Fallback dengan built-in system fonts jika TTF tidak ditemukan
            imagestring( $im, 4, 48, 40, 'LSP KOMUNIKASI INDONESIA • TERLISENSI BNSP', $light_gray );
            if ( ! empty( $badge_text ) ) {
                imagefilledrectangle( $im, 48, 80, 48 + ( strlen( $badge_text ) * 10 ) + 20, 115, $accent_col );
                imagestring( $im, 4, 58, 90, $badge_text, $dark_text );
            }
            imagestring( $im, 5, 48, 160, substr( $full_title, 0, 40 ), $white );
            if ( strlen( $full_title ) > 40 ) {
                imagestring( $im, 5, 48, 190, substr( $full_title, 40, 40 ), $white );
            }
            if ( ! empty( $subtitle ) ) {
                imagestring( $im, 4, 48, 250, substr( $subtitle, 0, 60 ), $light_gray );
            }
        }

        imagejpeg( $im, $file_path, 92 );
        imagedestroy( $im );

        if ( $existing_attach_id ) {
            $attach_data = wp_generate_attachment_metadata( $existing_attach_id, $file_path );
            wp_update_attachment_metadata( $existing_attach_id, $attach_data );
            return $existing_attach_id;
        }

        $filetype   = wp_check_filetype( basename( $file_path ), null );
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . basename( $file_path ),
            'post_mime_type' => $filetype['type'],
            'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attach_id   = wp_insert_attachment( $attachment, $file_path );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
        wp_update_attachment_metadata( $attach_id, $attach_data );
        update_post_meta( $attach_id, '_lsp_auto_thumb', '1' );

        return $attach_id;
    }

    /**
     * Render Banner Visual / Fallback HTML-CSS apabila belum ada Featured Image
     */
    public static function render_dynamic_cover_html( $post_id, $badge_text, $title, $subtitle ) {
        $gradients = array(
            'linear-gradient(135deg, #72161a 0%, #a82026 100%)',
            'linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%)',
            'linear-gradient(135deg, #115e59 0%, #0d9488 100%)',
            'linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%)',
            'linear-gradient(135deg, #1e293b 0%, #334155 100%)',
            'linear-gradient(135deg, #881337 0%, #be123c 100%)',
        );
        $bg_grad = $gradients[ $post_id % count( $gradients ) ];
        ob_start();
        ?>
        <div class="lsp-auto-cover-banner" style="width:100%; height:100%; background:<?php echo esc_attr( $bg_grad ); ?>; padding:18px 20px; box-sizing:border-box; position:relative; overflow:hidden; display:flex; flex-direction:column; justify-content:center; color:#fff; border-bottom:4px solid #f89b29;">
            <div style="position:absolute; right:-30px; top:-35px; width:130px; height:130px; border-radius:50%; background:rgba(248,155,41,0.16); pointer-events:none;"></div>
            <div style="position:absolute; left:-25px; bottom:-35px; width:100px; height:100px; border-radius:50%; background:rgba(255,255,255,0.08); pointer-events:none;"></div>
            <div style="font-size:9px; font-weight:700; letter-spacing:0.6px; color:#e2e8f0; margin-bottom:8px; text-transform:uppercase;">LSP KOMUNIKASI INDONESIA &bull; TERLISENSI BNSP</div>
            <?php if ( ! empty( $badge_text ) ) : ?>
                <div style="margin-bottom:8px;">
                    <span style="display:inline-block; background:#f89b29; color:#1a1919; font-size:9.5px; font-weight:800; padding:2px 8px; border-radius:2px; text-transform:uppercase;"><?php echo esc_html( $badge_text ); ?></span>
                </div>
            <?php endif; ?>
            <div style="font-size:17px; font-weight:800; line-height:1.25; color:#fff; margin-bottom:8px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;"><?php echo esc_html( $title ); ?></div>
            <div style="width:42px; height:3px; background:#f89b29; margin-bottom:7px;"></div>
            <?php if ( ! empty( $subtitle ) ) : ?>
                <div style="font-size:10.5px; color:#cbd5e1; font-weight:500;"><?php echo esc_html( $subtitle ); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 2B. Render & Save Meta Box TUK (+ Google Maps)
     */
    public function render_tuk_meta_box( $post ) {
        wp_nonce_field( 'lsp_tuk_save_meta', 'lsp_tuk_meta_nonce' );

        $kode_tuk   = get_post_meta( $post->ID, 'kode_tuk', true );
        $jenis_tuk  = get_post_meta( $post->ID, 'jenis_tuk', true );
        $alamat_tuk = get_post_meta( $post->ID, 'alamat_tuk', true );
        $kontak_tuk = get_post_meta( $post->ID, 'kontak_tuk', true );
        $gmaps_tuk  = get_post_meta( $post->ID, 'gmaps_tuk', true );
        ?>
        <style>
            .lsp-meta-row { margin-bottom: 20px; }
            .lsp-meta-row label { font-weight: bold; display: block; margin-bottom: 8px; font-size: 14px; }
            .lsp-meta-row input[type="text"], .lsp-meta-row select, .lsp-meta-row textarea { width: 100%; max-width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            .lsp-meta-row p.description { color: #666; font-size: 12px; margin-top: 5px; font-style: italic; }
        </style>

        <div class="lsp-meta-row">
            <label for="kode_tuk">Kode TUK (Opsional)</label>
            <input type="text" id="kode_tuk" name="kode_tuk" value="<?php echo esc_attr( $kode_tuk ); ?>" placeholder="Contoh: TUK-KOM-001" />
        </div>

        <div class="lsp-meta-row">
            <label for="jenis_tuk">Jenis TUK</label>
            <select id="jenis_tuk" name="jenis_tuk">
                <option value="">-- Pilih Jenis TUK --</option>
                <?php
                $opsi_tuk = array(
                    'TUK Sewaktu'      => 'TUK Sewaktu',
                    'TUK Tempat Kerja' => 'TUK Tempat Kerja',
                    'TUK Mandiri'      => 'TUK Mandiri',
                );
                foreach ( $opsi_tuk as $val => $lbl ) {
                    echo '<option value="' . esc_attr( $val ) . '" ' . selected( $jenis_tuk, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
                }
                ?>
            </select>
        </div>

        <div class="lsp-meta-row">
            <label for="alamat_tuk">Alamat Lengkap TUK</label>
            <textarea id="alamat_tuk" name="alamat_tuk" rows="3" placeholder="Contoh: Gedung Pusat Komunikasi Lt. 3, Jl. Jenderal Sudirman No. 45, Jakarta Pusat"><?php echo esc_textarea( $alamat_tuk ); ?></textarea>
        </div>

        <div class="lsp-meta-row">
            <label for="kontak_tuk">Nomor Telepon / Kontak PIC TUK (Opsional)</label>
            <input type="text" id="kontak_tuk" name="kontak_tuk" value="<?php echo esc_attr( $kontak_tuk ); ?>" placeholder="Contoh: 021-5551234 / 0812-3456-7890 (Bpk. Budi)" />
        </div>

        <div class="lsp-meta-row">
            <label for="gmaps_tuk">🗺️ Peta Google Maps (Kode Embed &lt;iframe&gt; atau Link URL Google Maps)</label>
            <textarea id="gmaps_tuk" name="gmaps_tuk" rows="4" placeholder='Tempelkan kode <iframe src="https://www.google.com/maps/embed?pb=..." ...></iframe> dari Google Maps (Bagikan -> Sematkan Peta) ATAU masukkan URL link Google Maps (https://maps.app.goo.gl/...)'><?php echo esc_textarea( $gmaps_tuk ); ?></textarea>
            <p class="description">
                <strong>Cara Memasukkan Peta Interaktif:</strong> Buka Google Maps &rarr; Klik <strong>Bagikan (Share)</strong> &rarr; Pilih tab <strong>Sematkan Peta (Embed a map)</strong> &rarr; Salin HTML <code>&lt;iframe ...&gt;</code> lalu tempelkan di sini. Anda juga bisa menempelkan tautan link Google Maps biasa.
            </p>
            <?php if ( ! empty( $gmaps_tuk ) ) : ?>
                <div style="margin-top: 12px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <strong style="display:block; margin-bottom:8px; font-size:12px; color:#475569;">Pratinjau Peta TUK:</strong>
                    <?php echo $this->render_gmaps_output( $gmaps_tuk, 220 ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_tuk_meta( $post_id ) {
        if ( ! isset( $_POST['lsp_tuk_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['lsp_tuk_meta_nonce'], 'lsp_tuk_save_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['kode_tuk'] ) ) {
            update_post_meta( $post_id, 'kode_tuk', sanitize_text_field( $_POST['kode_tuk'] ) );
        }
        if ( isset( $_POST['jenis_tuk'] ) ) {
            update_post_meta( $post_id, 'jenis_tuk', sanitize_text_field( $_POST['jenis_tuk'] ) );
        }
        if ( isset( $_POST['alamat_tuk'] ) ) {
            update_post_meta( $post_id, 'alamat_tuk', sanitize_textarea_field( $_POST['alamat_tuk'] ) );
        }
        if ( isset( $_POST['kontak_tuk'] ) ) {
            update_post_meta( $post_id, 'kontak_tuk', sanitize_text_field( $_POST['kontak_tuk'] ) );
        }
        if ( isset( $_POST['gmaps_tuk'] ) ) {
            $raw_map = trim( wp_unslash( $_POST['gmaps_tuk'] ) );
            $allowed_html = array(
                'iframe' => array(
                    'src'             => true,
                    'width'           => true,
                    'height'          => true,
                    'style'           => true,
                    'frameborder'     => true,
                    'allowfullscreen' => true,
                    'loading'         => true,
                    'referrerpolicy'  => true,
                    'title'           => true,
                ),
            );
            if ( false !== stripos( $raw_map, '<iframe' ) ) {
                update_post_meta( $post_id, 'gmaps_tuk', wp_kses( $raw_map, $allowed_html ) );
            } else {
                update_post_meta( $post_id, 'gmaps_tuk', esc_url_raw( $raw_map ) );
            }
        }
    }

    /**
     * Helper: Render Google Maps (baik berupa <iframe> maupun URL Link)
     */
    public function render_gmaps_output( $gmaps_value, $height = 300 ) {
        $gmaps_value = trim( (string) $gmaps_value );
        if ( empty( $gmaps_value ) ) {
            return '';
        }

        // Jika berupa kode embed <iframe>
        if ( false !== stripos( $gmaps_value, '<iframe' ) ) {
            $iframe = preg_replace( '/width=["\'][^"\']*["\']/i', 'width="100%"', $gmaps_value );
            $iframe = preg_replace( '/height=["\'][^"\']*["\']/i', 'height="' . intval( $height ) . '"', $iframe );
            return '<div class="lsp-tuk-map-embed" style="border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; line-height: 0;">' . $iframe . '</div>';
        }

        // Jika berupa URL Google Maps Embed langsung
        if ( false !== stripos( $gmaps_value, 'google.com/maps/embed' ) ) {
            return '<div class="lsp-tuk-map-embed" style="border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; line-height: 0;">
                <iframe src="' . esc_url( $gmaps_value ) . '" width="100%" height="' . intval( $height ) . '" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>';
        }

        // Jika berupa URL tautan Google Maps standar (maps.app.goo.gl / google.com/maps/place/...)
        return '<div class="lsp-tuk-map-link" style="margin-top: 10px;">
            <a href="' . esc_url( $gmaps_value ) . '" target="_blank" rel="noopener noreferrer" style="display: inline-flex; align-items: center; gap: 8px; background: #0284c7; color: #ffffff; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 13.5px;">
                <i class="fas fa-map-marked-alt"></i> Buka Lokasi di Google Maps &rarr;
            </a>
        </div>';
    }

    /**
     * 2C. Render & Save Meta Box Data Sertifikat
     */
    public function render_cert_meta_box( $post ) {
        wp_nonce_field( 'lsp_cert_meta_nonce_action', 'lsp_cert_meta_nonce' );

        $kode_blanko    = get_post_meta( $post->ID, 'kode_blanko', true );
        $nama_pemegang  = get_post_meta( $post->ID, 'nama_pemegang', true );
        $skema          = get_post_meta( $post->ID, 'skema_sertifikasi', true );
        $no_registrasi  = get_post_meta( $post->ID, 'no_registrasi', true );
        $tanggal_terbit = get_post_meta( $post->ID, 'tanggal_terbit', true );
        $pendaftaran_id = get_post_meta( $post->ID, 'pendaftaran_id', true );

        // Dukungan pre-fill dari halaman pendaftaran asesi (?pendaftaran_id=X)
        if ( empty( $nama_pemegang ) && ! empty( $_GET['pendaftaran_id'] ) ) {
            $p_prefill = self::find_registered_pendaftaran( '', '', intval( $_GET['pendaftaran_id'] ) );
            if ( $p_prefill ) {
                $nama_pemegang  = $p_prefill['nama_lengkap'];
                $skema          = $p_prefill['skema_title'] ?? $skema;
                $tanggal_terbit = current_time( 'Y-m-d' );
                $pendaftaran_id = $p_prefill['id'];
            }
        }

        // Cek status validasi keterdaftaran asesi
        $p_match = null;
        if ( ! empty( $pendaftaran_id ) ) {
            $p_match = self::find_registered_pendaftaran( '', '', intval( $pendaftaran_id ) );
        }
        if ( ! $p_match && ! empty( $nama_pemegang ) ) {
            $p_match = self::find_registered_pendaftaran( $nama_pemegang, $skema );
        }

        // Ambil daftar seluruh asesi terdaftar untuk autocomplete / datalist
        $all_asesi = array();
        global $wpdb;
        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) ) {
            $tbl_p = $wpdb->prefix . 'lsp_pendaftaran';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl_p'" ) === $tbl_p ) {
                $all_asesi = $wpdb->get_results( 
                    "SELECT p.id, p.nama_lengkap, p.nik, s.post_title AS skema_title 
                     FROM {$tbl_p} p 
                     LEFT JOIN {$wpdb->prefix}lsp_kegiatan k ON p.kegiatan_id = k.id 
                     LEFT JOIN {$wpdb->posts} s ON k.skema_id = s.ID 
                     ORDER BY p.nama_lengkap ASC", 
                    ARRAY_A 
                );
            }
        }

        // Hitung masa berlaku 3 tahun jika tanggal terbit sudah ada
        $info_berlaku = '';
        if ( ! empty( $tanggal_terbit ) ) {
            $time_terbit  = strtotime( $tanggal_terbit );
            $time_expired = strtotime( '+3 years', $time_terbit );
            $is_expired   = time() > $time_expired;

            if ( $is_expired ) {
                $info_berlaku = '<div style="margin-top:10px; padding:10px; background:#fff2f0; border-left:4px solid #ff4d4f; border-radius:4px;">
                    <strong style="color:#cf1322;">⚠️ KADALUARSA</strong> — Masa berlaku 3 tahun telah habis pada ' . esc_html( $this->format_indo_date( $time_expired ) ) . '.
                </div>';
            } else {
                $info_berlaku = '<div style="margin-top:10px; padding:10px; background:#f6ffed; border-left:4px solid #52c41a; border-radius:4px;">
                    <strong style="color:#389e0d;">🟢 AKTIF & VALID</strong> — Berlaku hingga ' . esc_html( $this->format_indo_date( $time_expired ) ) . ' (3 tahun sejak terbit).
                </div>';
            }
        }

        // Ambil daftar skema dari CPT skema
        $skema_posts = get_posts( array(
            'post_type'      => 'skema',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <style>
            .lsp-cert-field { margin-bottom: 20px; }
            .lsp-cert-field label { display: block; font-weight: 700; margin-bottom: 6px; font-size: 14px; color: #1a1919; }
            .lsp-cert-field input[type="text"], .lsp-cert-field input[type="date"], .lsp-cert-field select { width: 100%; max-width: 600px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
            .lsp-cert-field .description { color: #666; font-size: 12px; margin-top: 4px; font-style: italic; }
        </style>

        <!-- Banner Keabsahan Pendaftaran Asesi -->
        <?php if ( $p_match ) : ?>
            <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:12px 16px; margin-bottom:20px; color:#166534; font-size:13px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:18px;">🛡️</span>
                    <div>
                        <strong>Asesi Terverifikasi Resmi:</strong> Terhubung dengan Berkas Pendaftaran <strong>#<?php echo intval( $p_match['id'] ); ?></strong> (<?php echo esc_html( $p_match['nama_lengkap'] ); ?> &bull; NIK: <code><?php echo esc_html( $p_match['nik'] ); ?></code> &bull; Skema: <?php echo esc_html( $p_match['skema_title'] ?? '-' ); ?>).
                    </div>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lsp-pendaftaran&action=view&id=' . intval( $p_match['id'] ) ) ); ?>" target="_blank" class="button button-small" style="text-decoration:none;">Buka Berkas Pendaftaran &rarr;</a>
            </div>
        <?php elseif ( ! empty( $nama_pemegang ) ) : ?>
            <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:12px 16px; margin-bottom:20px; color:#991b1b; font-size:13px;">
                <strong>⚠️ Peserta Belum Terdaftar:</strong> Nama <em>"<?php echo esc_html( $nama_pemegang ); ?>"</em> belum ditemukan di sistem pendaftaran sertifikasi. Sertifikat <strong>hanya dapat diterbitkan</strong> untuk peserta yang telah terdaftar resmi pada modul Pendaftaran.
            </div>
        <?php else : ?>
            <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:12px 16px; margin-bottom:20px; color:#334155; font-size:13px;">
                <strong>🛡️ Sistem Pengamanan Sertifikat:</strong> Pilih atau ketik nama peserta terdaftar dari daftar di bawah. Data pemegang sertifikat wajib terhubung dengan pendaftaran asesi resmi di sistem.
            </div>
        <?php endif; ?>

        <div class="lsp-cert-field">
            <label for="kode_blanko"><?php _e( 'Nomor / Kode Blanko Sertifikat (Wajib & Unik)', 'lsp-core-management' ); ?> *</label>
            <input type="text" id="kode_blanko" name="kode_blanko" value="<?php echo esc_attr( $kode_blanko ); ?>" placeholder="Contoh: K24.01.000123 / BNSP-00123" required />
            <p class="description"><?php _e( 'Nomor seri / kode blanko unik yang tertera pada sertifikat fisik. Digunakan oleh publik untuk verifikasi.', 'lsp-core-management' ); ?></p>
        </div>

        <div class="lsp-cert-field">
            <label for="nama_pemegang"><?php _e( 'Nama Lengkap Pemegang Sertifikat (Asesi)', 'lsp-core-management' ); ?> *</label>
            <input type="text" id="nama_pemegang" name="nama_pemegang" list="registered_asesi_list" value="<?php echo esc_attr( $nama_pemegang ); ?>" placeholder="Pilih atau ketik nama asesi terdaftar..." required autocomplete="off" />
            <datalist id="registered_asesi_list">
                <?php foreach ( $all_asesi as $as ) : ?>
                    <option value="<?php echo esc_attr( $as['nama_lengkap'] ); ?>" label="NIK: <?php echo esc_attr( $as['nik'] ); ?> &bull; <?php echo esc_attr( $as['skema_title'] ?? '' ); ?>">
                <?php endforeach; ?>
            </datalist>
            <p class="description"><?php _e( 'Pilih dari daftar peserta pendaftaran atau ketik nama lengkap asesi.', 'lsp-core-management' ); ?></p>
        </div>

        <div class="lsp-cert-field">
            <label for="no_registrasi"><?php _e( 'Nomor Registrasi Sertifikat', 'lsp-core-management' ); ?> *</label>
            <input type="text" id="no_registrasi" name="no_registrasi" value="<?php echo esc_attr( $no_registrasi ); ?>" placeholder="Contoh: KOM.010.00012 2024" required />
        </div>

        <div class="lsp-cert-field">
            <label for="skema_sertifikasi"><?php _e( 'Skema Sertifikasi', 'lsp-core-management' ); ?> *</label>
            <input type="text" id="skema_sertifikasi" name="skema_sertifikasi" list="skema_list" value="<?php echo esc_attr( $skema ); ?>" placeholder="Pilih atau ketik nama skema sertifikasi..." required />
            <datalist id="skema_list">
                <?php foreach ( $skema_posts as $sp ) : ?>
                    <option value="<?php echo esc_attr( $sp->post_title ); ?>">
                <?php endforeach; ?>
            </datalist>
            <p class="description"><?php _e( 'Pilih dari daftar skema atau ketik nama skema yang bersangkutan.', 'lsp-core-management' ); ?></p>
        </div>

        <div class="lsp-cert-field">
            <label for="tanggal_terbit"><?php _e( 'Tanggal Terbit Sertifikat', 'lsp-core-management' ); ?> *</label>
            <input type="date" id="tanggal_terbit" name="tanggal_terbit" value="<?php echo esc_attr( $tanggal_terbit ); ?>" required />
            <p class="description"><?php _e( 'Masa berlaku sertifikat otomatis dihitung selama 3 tahun sejak tanggal terbit ini.', 'lsp-core-management' ); ?></p>
            <?php echo $info_berlaku; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var asesiMap = <?php 
                $map = array();
                foreach ( $all_asesi as $as ) {
                    $map[ $as['nama_lengkap'] ] = $as['skema_title'] ?? '';
                }
                echo json_encode( $map );
            ?>;
            var namaInput = document.getElementById('nama_pemegang');
            var skemaInput = document.getElementById('skema_sertifikasi');
            if (namaInput && skemaInput) {
                namaInput.addEventListener('change', function() {
                    var val = this.value.trim();
                    if (asesiMap[val] && (!skemaInput.value || skemaInput.value.trim() === '')) {
                        skemaInput.value = asesiMap[val];
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Normalisasi Nomor Identitas Sertifikat (Kode Blanko / No. Registrasi)
     * Membersihkan seluruh variasi whitespace Unicode (\p{Z}) dan karakter kontrol (\p{C})
     * tanpa merusak tanda baca resmi (. / - _).
     */
    public static function normalize_cert_number( $number ) {
        if ( ! is_scalar( $number ) ) {
            return '';
        }
        // 1. Hapus seluruh karakter kontrol Unicode (Cc, Cf, Cs, Co, Cn)
        $clean = preg_replace( '/\p{C}+/u', '', (string) $number );
        // 2. Satukan seluruh variasi Unicode whitespace (\p{Z}) dan whitespace standar (\s) menjadi spasi ASCII tunggal
        $clean = preg_replace( '/[\p{Z}\s]+/u', ' ', $clean );
        $clean = sanitize_text_field( $clean );
        return trim( $clean );
    }

    /**
     * Cari Data Asesi Terdaftar di Sistem Pendaftaran LSP Certification Management
     * Menggunakan exact match atau pembersihan gelar akademis (S.Kom, M.M, Dr, dll)
     *
     * @param string $nama Nama pemegang sertifikat
     * @param string $skema Nama/judul skema sertifikasi (opsional)
     * @param int    $pendaftaran_id ID pendaftaran jika sudah diketahui
     * @return array|null Data peserta pendaftaran jika ditemukan
     */
    public static function find_registered_pendaftaran( $nama = '', $skema = '', $pendaftaran_id = 0 ) {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
            // Lingkungan mock testing unit standalone
            return array( 'id' => 1, 'nama_lengkap' => $nama, 'nik' => '1234567890' );
        }

        $table = $wpdb->prefix . 'lsp_pendaftaran';
        // Pastikan tabel pendaftaran ada
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return null;
        }

        // Jika kelas database lsp-certification-management tersedia, gunakan fungsinya
        if ( class_exists( 'LSP_Cert_Database' ) && method_exists( 'LSP_Cert_Database', 'find_pendaftaran_by_name' ) ) {
            $res = LSP_Cert_Database::find_pendaftaran_by_name( $nama, $skema, $pendaftaran_id );
            return apply_filters( 'lsp_validate_cert_pendaftaran', $res, $nama, $skema, $pendaftaran_id );
        }

        // 1. Jika dicari dengan ID
        if ( intval( $pendaftaran_id ) > 0 ) {
            $sql = "SELECT p.*, k.skema_id, k.tanggal_pelaksanaan, s.post_title AS skema_title 
                    FROM {$table} p
                    LEFT JOIN {$wpdb->prefix}lsp_kegiatan k ON p.kegiatan_id = k.id
                    LEFT JOIN {$wpdb->posts} s ON k.skema_id = s.ID
                    WHERE p.id = %d LIMIT 1";
            $row = $wpdb->get_row( $wpdb->prepare( $sql, intval( $pendaftaran_id ) ), ARRAY_A );
            return apply_filters( 'lsp_validate_cert_pendaftaran', $row ?: null, $nama, $skema, $pendaftaran_id );
        }

        $nama_raw = trim( (string) $nama );
        if ( '' === $nama_raw ) {
            return null;
        }

        // Pembersih gelar akademis
        $clean_gelar = preg_replace( '/\b(S\.Kom|S\.T|S\.E|S\.Pd|S\.Sos|S\.Si|S\.H|S\.Ked|M\.M|M\.Kom|M\.T|M\.Pd|M\.Si|Dr|Dra|Drs|Ir|Prof)\b\.?/i', '', $nama_raw );
        $nama_clean  = trim( preg_replace( '/[,\s\.]+$/', '', trim( $clean_gelar ) ) );

        $sql = "SELECT p.*, k.skema_id, k.tanggal_pelaksanaan, s.post_title AS skema_title 
                FROM {$table} p
                LEFT JOIN {$wpdb->prefix}lsp_kegiatan k ON p.kegiatan_id = k.id
                LEFT JOIN {$wpdb->posts} s ON k.skema_id = s.ID
                WHERE LOWER(TRIM(p.nama_lengkap)) = LOWER(%s)
                   OR LOWER(TRIM(p.nama_lengkap)) = LOWER(%s)";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $nama_raw, $nama_clean ), ARRAY_A );
        if ( empty( $rows ) ) {
            return apply_filters( 'lsp_validate_cert_pendaftaran', null, $nama, $skema, $pendaftaran_id );
        }

        if ( 1 === count( $rows ) ) {
            return apply_filters( 'lsp_validate_cert_pendaftaran', $rows[0], $nama, $skema, $pendaftaran_id );
        }

        // Jika ada nama kembar di beberapa kegiatan, prioritaskan yang skemanya cocok
        if ( ! empty( $skema ) ) {
            $skema_lower = strtolower( trim( $skema ) );
            foreach ( $rows as $row ) {
                if ( ! empty( $row['skema_title'] ) && strtolower( trim( $row['skema_title'] ) ) === $skema_lower ) {
                    return apply_filters( 'lsp_validate_cert_pendaftaran', $row, $nama, $skema, $pendaftaran_id );
                }
            }
        }

        return apply_filters( 'lsp_validate_cert_pendaftaran', $rows[0], $nama, $skema, $pendaftaran_id );
    }

    /**
     * Tampilkan Notifikasi Validasi & Error Duplikasi pada WP-Admin
     */
    public function display_cert_admin_notices() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return;

        $errors = get_transient( 'lsp_cert_save_errors_' . $user_id );
        if ( ! empty( $errors ) && is_array( $errors ) ) {
            delete_transient( 'lsp_cert_save_errors_' . $user_id );
            ?>
            <div class="notice notice-error is-dismissible" style="border-left-width: 4px; border-left-color: #dc2626;">
                <p><strong>⚠️ Gagal Menyimpan Data Sertifikat:</strong></p>
                <ul style="list-style: disc; margin-left: 25px; margin-top: 5px;">
                    <?php foreach ( $errors as $err ) : ?>
                        <li><?php echo wp_kses_post( $err ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p style="font-size: 12px; color: #64748b; margin-top: 5px;">Data lama pada database tetap dipertahankan dan tidak mengalami perubahan.</p>
            </div>
            <?php
        }
    }

    /**
     * Hapus parameter message default WordPress ("Post updated / published")
     * jika terjadi error validasi saat simpan sertifikat
     */
    public function filter_cert_save_redirect_location( $location, $post_id ) {
        if ( 'data_sertifikat' === get_post_type( $post_id ) ) {
            $user_id = get_current_user_id();
            if ( $user_id && get_transient( 'lsp_cert_save_errors_' . $user_id ) ) {
                return remove_query_arg( 'message', $location );
            }
        }
        return $location;
    }

    public function save_cert_meta( $post_id ) {
        // 1. Verifikasi Nonce
        if ( ! isset( $_POST['lsp_cert_meta_nonce'] ) || ! wp_verify_nonce( $_POST['lsp_cert_meta_nonce'], 'lsp_cert_meta_nonce_action' ) ) {
            return;
        }

        // 2. Proteksi Autosave & Revisi
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // 3. Verifikasi Tipe Postingan
        if ( 'data_sertifikat' !== get_post_type( $post_id ) ) {
            return;
        }

        // 4. Verifikasi Hak Akses Pengguna (Capabilities)
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // 5. Sanitasi & Normalisasi Input
        $raw_blanko = isset( $_POST['kode_blanko'] ) ? wp_unslash( $_POST['kode_blanko'] ) : '';
        $raw_nama   = isset( $_POST['nama_pemegang'] ) ? wp_unslash( $_POST['nama_pemegang'] ) : '';
        $raw_reg    = isset( $_POST['no_registrasi'] ) ? wp_unslash( $_POST['no_registrasi'] ) : '';
        $raw_skema  = isset( $_POST['skema_sertifikasi'] ) ? wp_unslash( $_POST['skema_sertifikasi'] ) : '';
        $raw_tgl    = isset( $_POST['tanggal_terbit'] ) ? wp_unslash( $_POST['tanggal_terbit'] ) : '';

        $kode_blanko    = self::normalize_cert_number( $raw_blanko );
        $no_registrasi  = self::normalize_cert_number( $raw_reg );
        $nama_pemegang  = sanitize_text_field( trim( $raw_nama ) );
        $skema          = sanitize_text_field( trim( $raw_skema ) );
        $tanggal_terbit = sanitize_text_field( trim( $raw_tgl ) );

        $errors = array();

        // 6. Validasi Input Wajib & Batasan Panjang Karakter
        if ( '' === $kode_blanko ) {
            $errors[] = __( 'Nomor / Kode Blanko Sertifikat wajib diisi.', 'lsp-core-management' );
        } elseif ( mb_strlen( $kode_blanko, 'UTF-8' ) < 3 || mb_strlen( $kode_blanko, 'UTF-8' ) > 60 ) {
            $errors[] = __( 'Panjang Kode Blanko harus antara 3 hingga 60 karakter.', 'lsp-core-management' );
        }

        if ( '' === $no_registrasi ) {
            $errors[] = __( 'Nomor Registrasi Sertifikat wajib diisi.', 'lsp-core-management' );
        } elseif ( mb_strlen( $no_registrasi, 'UTF-8' ) < 3 || mb_strlen( $no_registrasi, 'UTF-8' ) > 60 ) {
            $errors[] = __( 'Panjang Nomor Registrasi harus antara 3 hingga 60 karakter.', 'lsp-core-management' );
        }

        if ( '' === $nama_pemegang ) {
            $errors[] = __( 'Nama Lengkap Pemegang Sertifikat (Asesi) wajib diisi.', 'lsp-core-management' );
        }

        if ( '' === $skema ) {
            $errors[] = __( 'Skema Sertifikasi wajib diisi.', 'lsp-core-management' );
        }

        // 7. Validasi Format & Keabsahan Tanggal Terbit (YYYY-MM-DD)
        if ( '' === $tanggal_terbit ) {
            $errors[] = __( 'Tanggal Terbit Sertifikat wajib diisi.', 'lsp-core-management' );
        } else {
            $d = DateTime::createFromFormat( 'Y-m-d', $tanggal_terbit );
            if ( ! $d || $d->format( 'Y-m-d' ) !== $tanggal_terbit ) {
                $errors[] = __( 'Format Tanggal Terbit tidak valid. Gunakan format YYYY-MM-DD (contoh: 2024-05-15).', 'lsp-core-management' );
            }
        }

        // 8. Deteksi Duplikasi Identitas (Mengecualikan post saat ini)
        // A. Keunikan Kode Blanko
        if ( '' !== $kode_blanko ) {
            $conflict_blanko = new WP_Query( array(
                'post_type'              => 'data_sertifikat',
                'post_status'            => 'any',
                'post__not_in'           => array( $post_id ),
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    array(
                        'key'     => 'kode_blanko',
                        'value'   => $kode_blanko,
                        'compare' => '=',
                    ),
                ),
            ) );
            if ( ! empty( $conflict_blanko->posts ) ) {
                $other_id = $conflict_blanko->posts[0];
                $errors[] = sprintf(
                    __( 'Nomor Blanko <strong>"%s"</strong> sudah terdaftar pada sertifikat lain (ID Post: %d — "%s"). Nomor blanko fisik harus unik.', 'lsp-core-management' ),
                    esc_html( $kode_blanko ),
                    $other_id,
                    esc_html( get_the_title( $other_id ) )
                );
            }
        }

        // B. Keunikan Nomor Registrasi
        if ( '' !== $no_registrasi ) {
            $conflict_reg = new WP_Query( array(
                'post_type'              => 'data_sertifikat',
                'post_status'            => 'any',
                'post__not_in'           => array( $post_id ),
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    array(
                        'key'     => 'no_registrasi',
                        'value'   => $no_registrasi,
                        'compare' => '=',
                    ),
                ),
            ) );
            if ( ! empty( $conflict_reg->posts ) ) {
                $other_id = $conflict_reg->posts[0];
                $errors[] = sprintf(
                    __( 'Nomor Registrasi <strong>"%s"</strong> sudah terdaftar pada sertifikat lain (ID Post: %d — "%s"). Nomor registrasi harus unik.', 'lsp-core-management' ),
                    esc_html( $no_registrasi ),
                    $other_id,
                    esc_html( get_the_title( $other_id ) )
                );
            }
        }

        // C. Pengecekan Duplikasi Lintas Metadata (Cross-Field Collision)
        if ( '' !== $kode_blanko ) {
            $cross_1 = new WP_Query( array(
                'post_type'              => 'data_sertifikat',
                'post_status'            => 'any',
                'post__not_in'           => array( $post_id ),
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    array(
                        'key'     => 'no_registrasi',
                        'value'   => $kode_blanko,
                        'compare' => '=',
                    ),
                ),
            ) );
            if ( ! empty( $cross_1->posts ) ) {
                $other_id = $cross_1->posts[0];
                $errors[] = sprintf(
                    __( 'Nomor Blanko <strong>"%s"</strong> bertabrakan dengan Nomor Registrasi pada sertifikat lain (ID Post: %d — "%s"). Identitas sertifikat tidak boleh bentrok antar-kolom.', 'lsp-core-management' ),
                    esc_html( $kode_blanko ),
                    $other_id,
                    esc_html( get_the_title( $other_id ) )
                );
            }
        }

        if ( '' !== $no_registrasi ) {
            $cross_2 = new WP_Query( array(
                'post_type'              => 'data_sertifikat',
                'post_status'            => 'any',
                'post__not_in'           => array( $post_id ),
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    array(
                        'key'     => 'kode_blanko',
                        'value'   => $no_registrasi,
                        'compare' => '=',
                    ),
                ),
            ) );
            if ( ! empty( $cross_2->posts ) ) {
                $other_id = $cross_2->posts[0];
                $errors[] = sprintf(
                    __( 'Nomor Registrasi <strong>"%s"</strong> bertabrakan dengan Nomor Blanko pada sertifikat lain (ID Post: %d — "%s"). Identitas sertifikat tidak boleh bentrok antar-kolom.', 'lsp-core-management' ),
                    esc_html( $no_registrasi ),
                    $other_id,
                    esc_html( get_the_title( $other_id ) )
                );
            }
        }

        // D. Validasi Pengamanan: Peserta Wajib Terdaftar di Sistem Pendaftaran LSP
        $pendaftaran_match = self::find_registered_pendaftaran( $nama_pemegang, $skema );
        if ( ! $pendaftaran_match ) {
            $errors[] = sprintf(
                __( 'Peserta <strong>"%s"</strong> tidak ditemukan di sistem pendaftaran sertifikasi. Sertifikat hanya dapat diterbitkan untuk peserta yang telah terdaftar resmi.', 'lsp-core-management' ),
                esc_html( $nama_pemegang )
            );
        }

        // 9. Eksekusi Penyimpanan atau Pencegahan Konflik
        if ( ! empty( $errors ) ) {
            // TERJADI KONFLIK ATAU VALIDASI GAGAL:
            // - Jangan menimpa sertifikat lain.
            // - Jangan menghapus data lama.
            // - Pertahankan metadata lama.
            // - Simpan notifikasi error untuk ditampilkan ke admin.
            set_transient( 'lsp_cert_save_errors_' . get_current_user_id(), $errors, 45 );

            // Sinkronisasi status & judul post:
            // Jangan biarkan sertifikat yang gagal validasi terbit (publish) dalam kondisi data cacat/duplikat
            $old_blanko = get_post_meta( $post_id, 'kode_blanko', true );
            $old_nama   = get_post_meta( $post_id, 'nama_pemegang', true );

            remove_action( 'save_post_data_sertifikat', array( $this, 'save_cert_meta' ) );
            $revert_data = array( 'ID' => $post_id );
            // Jika record baru atau belum pernah punya blanko yang valid, paksa kembali ke status 'draft'
            if ( empty( $old_blanko ) ) {
                $revert_data['post_status'] = 'draft';
            }
            // Kembalikan judul post ke data lama yang valid jika ada
            if ( ! empty( $old_blanko ) && ! empty( $old_nama ) ) {
                $revert_data['post_title'] = $old_blanko . ' - ' . $old_nama;
            }
            wp_update_post( $revert_data );
            add_action( 'save_post_data_sertifikat', array( $this, 'save_cert_meta' ) );

            return;
        }

        // VALIDASI BERHASIL: Simpan metadata baru
        update_post_meta( $post_id, 'kode_blanko', $kode_blanko );
        update_post_meta( $post_id, 'nama_pemegang', $nama_pemegang );
        update_post_meta( $post_id, 'no_registrasi', $no_registrasi );
        update_post_meta( $post_id, 'skema_sertifikasi', $skema );
        update_post_meta( $post_id, 'tanggal_terbit', $tanggal_terbit );
        if ( ! empty( $pendaftaran_match['id'] ) ) {
            update_post_meta( $post_id, 'pendaftaran_id', intval( $pendaftaran_match['id'] ) );
        }

        // Update post title otomatis: "KODE_BLANKO - NAMA_PEMEGANG"
        $auto_title = $kode_blanko . ' - ' . $nama_pemegang;
        if ( get_the_title( $post_id ) !== $auto_title ) {
            remove_action( 'save_post_data_sertifikat', array( $this, 'save_cert_meta' ) );
            wp_update_post( array(
                'ID'         => $post_id,
                'post_title' => $auto_title,
            ) );
            add_action( 'save_post_data_sertifikat', array( $this, 'save_cert_meta' ) );
        }
    }

    /**
     * ====================================================================
     * 3. ADMIN COLUMNS: SKEMA, TUK, & DATA SERTIFIKAT
     * ====================================================================
     */
    public function skema_custom_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            $new_columns[ $key ] = $title;
            if ( 'title' === $key ) {
                $new_columns['kode_skema']    = __( 'Kode Skema', 'lsp-core-management' );
                $new_columns['level_skema']   = __( 'Level', 'lsp-core-management' );
                $new_columns['jenis_kemasan'] = __( 'Jenis Kemasan', 'lsp-core-management' );
                $new_columns['biaya']         = __( 'Biaya', 'lsp-core-management' );
            }
        }
        return $new_columns;
    }

    public function skema_custom_column_content( $column, $post_id ) {
        if ( 'kode_skema' === $column ) {
            $kode = get_post_meta( $post_id, 'kode_skema', true );
            echo $kode ? '<code>' . esc_html( $kode ) . '</code>' : '<span style="color:#aaa;">—</span>';
        } elseif ( 'level_skema' === $column ) {
            $level = get_post_meta( $post_id, 'level_skema', true );
            if ( empty( $level ) ) {
                $level = get_post_meta( $post_id, 'level', true );
            }
            echo $level ? '<strong style="display:inline-block; padding: 2px 8px; border-radius: 4px; font-weight:700; font-size:11px; color:#72161a; background:#fff8f0; border:1px solid #f89b29;">Level ' . esc_html( $level ) . '</strong>' : '<span style="color:#aaa;">—</span>';
        } elseif ( 'jenis_kemasan' === $column ) {
            $kemasan = get_post_meta( $post_id, 'jenis_kemasan', true );
            if ( $kemasan ) {
                $color = '#f89b29';
                if ( 'KKNI' === $kemasan ) $color = '#0284c7';
                elseif ( 'Okupasi' === $kemasan ) $color = '#72161a';
                elseif ( 'Klaster' === $kemasan ) $color = '#16a34a';
                echo '<span style="display:inline-block; padding: 3px 10px; border-radius: 12px; font-weight:700; font-size:11px; color:#fff; background:' . esc_attr( $color ) . '; text-transform:uppercase;">' . esc_html( $kemasan ) . '</span>';
            } else {
                echo '<span style="color:#aaa;">—</span>';
            }
        } elseif ( 'biaya' === $column ) {
            $biaya = get_post_meta( $post_id, 'biaya_sertifikasi', true );
            echo $biaya ? '<strong>Rp ' . number_format( (float) $biaya, 0, ',', '.' ) . '</strong>' : '<span style="color:#aaa;">—</span>';
        }
    }

    public function tuk_custom_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            $new_columns[ $key ] = $title;
            if ( 'title' === $key ) {
                $new_columns['kode_tuk']   = __( 'Kode TUK', 'lsp-core-management' );
                $new_columns['jenis_tuk']  = __( 'Jenis TUK', 'lsp-core-management' );
                $new_columns['alamat_tuk'] = __( 'Alamat', 'lsp-core-management' );
                $new_columns['gmaps_tuk']  = __( 'Google Maps', 'lsp-core-management' );
            }
        }
        return $new_columns;
    }

    public function tuk_custom_column_content( $column, $post_id ) {
        if ( 'kode_tuk' === $column ) {
            $kode = get_post_meta( $post_id, 'kode_tuk', true );
            echo $kode ? '<code>' . esc_html( $kode ) . '</code>' : '<span style="color:#aaa;">—</span>';
        } elseif ( 'jenis_tuk' === $column ) {
            $jenis = get_post_meta( $post_id, 'jenis_tuk', true );
            echo $jenis ? '<span style="display:inline-block; padding: 2px 8px; border-radius: 4px; font-weight:600; font-size:11px; background:#e0f2fe; color:#0284c7;">' . esc_html( $jenis ) . '</span>' : '<span style="color:#aaa;">—</span>';
        } elseif ( 'alamat_tuk' === $column ) {
            $alamat = get_post_meta( $post_id, 'alamat_tuk', true );
            echo $alamat ? esc_html( wp_trim_words( $alamat, 10, '...' ) ) : '<span style="color:#aaa;">—</span>';
        } elseif ( 'gmaps_tuk' === $column ) {
            $gmaps = get_post_meta( $post_id, 'gmaps_tuk', true );
            if ( ! empty( $gmaps ) ) {
                echo '<span style="color:#16a34a; font-weight:700; font-size:12px;">🗺️ Tersedia</span>';
            } else {
                echo '<span style="color:#aaa; font-size:12px;">Belum diset</span>';
            }
        }
    }

    public function cert_custom_columns( $columns ) {
        $new = array();
        $new['cb']             = $columns['cb'];
        $new['kode_blanko']    = __( 'No. Blanko', 'lsp-core-management' );
        $new['nama_pemegang']  = __( 'Nama Pemegang', 'lsp-core-management' );
        $new['skema']          = __( 'Skema Sertifikasi', 'lsp-core-management' );
        $new['no_reg']         = __( 'No. Registrasi', 'lsp-core-management' );
        $new['tgl_terbit']     = __( 'Tgl. Terbit', 'lsp-core-management' );
        $new['tgl_kadaluarsa'] = __( 'Tgl. Berakhir (+3 Th)', 'lsp-core-management' );
        $new['status_cert']    = __( 'Status Keabsahan', 'lsp-core-management' );
        return $new;
    }

    public function cert_custom_column_content( $column, $post_id ) {
        $kode_blanko    = get_post_meta( $post_id, 'kode_blanko', true );
        $nama_pemegang  = get_post_meta( $post_id, 'nama_pemegang', true );
        $skema          = get_post_meta( $post_id, 'skema_sertifikasi', true );
        $no_reg         = get_post_meta( $post_id, 'no_registrasi', true );
        $tanggal_terbit = get_post_meta( $post_id, 'tanggal_terbit', true );

        switch ( $column ) {
            case 'kode_blanko':
                $edit_link = get_edit_post_link( $post_id );
                echo '<a href="' . esc_url( $edit_link ) . '"><strong><code>' . esc_html( $kode_blanko ?: '—' ) . '</code></strong></a>';
                break;
            case 'nama_pemegang':
                echo '<strong>' . esc_html( $nama_pemegang ?: get_the_title( $post_id ) ) . '</strong>';
                break;
            case 'skema':
                echo esc_html( $skema ?: '—' );
                break;
            case 'no_reg':
                echo '<code>' . esc_html( $no_reg ?: '—' ) . '</code>';
                break;
            case 'tgl_terbit':
                echo $tanggal_terbit ? esc_html( $this->format_indo_date( strtotime( $tanggal_terbit ) ) ) : '—';
                break;
            case 'tgl_kadaluarsa':
                if ( $tanggal_terbit ) {
                    $time_exp = strtotime( '+3 years', strtotime( $tanggal_terbit ) );
                    echo esc_html( $this->format_indo_date( $time_exp ) );
                } else {
                    echo '—';
                }
                break;
            case 'status_cert':
                if ( $tanggal_terbit ) {
                    $time_exp   = strtotime( '+3 years', strtotime( $tanggal_terbit ) );
                    $is_expired = time() > $time_exp;
                    if ( $is_expired ) {
                        echo '<span style="display:inline-block; padding:4px 10px; background:#ff4d4f; color:#fff; border-radius:12px; font-weight:700; font-size:11px;">⚠️ KADALUARSA</span>';
                    } else {
                        echo '<span style="display:inline-block; padding:4px 10px; background:#52c41a; color:#fff; border-radius:12px; font-weight:700; font-size:11px;">🟢 VALID (AKTIF)</span>';
                    }
                } else {
                    echo '<span style="color:#999;">Data Belum Lengkap</span>';
                }
                break;
        }
    }

    /**
     * ====================================================================
     * 4. MODUL USER ASESOR LSP (CUSTOM USER META, MULTI-BIDANG, MASA BERLAKU MET 3 THN, SOSMED & DESKRIPSI)
     * ====================================================================
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( in_array( $hook, array( 'profile.php', 'user-edit.php', 'user-new.php' ), true ) ) {
            wp_enqueue_media();
        }
    }

    public function restrict_asesor_media_library( $query ) {
        $user = wp_get_current_user();
        if ( $user && in_array( 'asesor', (array) $user->roles, true ) && ! current_user_can( 'edit_others_posts' ) ) {
            $query['author'] = $user->ID;
        }
        return $query;
    }

    public function pending_asesor_posts_notice() {
        if ( ! current_user_can( 'publish_posts' ) ) {
            return;
        }
        $pending_posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'pending',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        if ( ! empty( $pending_posts ) ) {
            $count = count( $pending_posts );
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>📝 Persetujuan Artikel Asesor:</strong> Terdapat <strong>' . intval( $count ) . '</strong> artikel yang menunggu peninjauan (Pending Review) untuk diterbitkan. ';
            echo '<a href="' . esc_url( admin_url( 'edit.php?post_status=pending&post_type=post' ) ) . '" class="button button-small" style="margin-left:8px;">Tinjau &amp; Setujui Artikel</a>';
            echo '</p></div>';
        }
    }

    public function render_asesor_new_user_fields( $type ) {
        if ( 'add-new-user' !== $type ) return;
        $this->render_asesor_fields_markup( null );
    }

    public function render_asesor_user_profile_fields( $user ) {
        $this->render_asesor_fields_markup( $user );
    }

    private function render_asesor_fields_markup( $user = null ) {
        $is_admin = current_user_can( 'manage_options' );
        $user_id  = $user ? $user->ID : 0;

        // Hanya output nonce jika pengguna adalah Administrator yang berhak menyimpan data
        if ( $is_admin ) {
            wp_nonce_field( 'lsp_save_asesor_profile', 'lsp_asesor_profile_nonce' );
        }

        $nomor_met          = $user_id ? get_user_meta( $user_id, 'nomor_met', true ) : '';
        $tanggal_terbit_met = $user_id ? get_user_meta( $user_id, 'tanggal_terbit_met', true ) : '';
        $foto_asesor        = $user_id ? get_user_meta( $user_id, 'foto_asesor', true ) : '';
        $bidang_keahlian    = $user_id ? get_user_meta( $user_id, 'bidang_keahlian_asesor', true ) : array();
        if ( ! is_array( $bidang_keahlian ) ) {
            $bidang_keahlian = ! empty( $bidang_keahlian ) ? array_map( 'trim', explode( ',', (string) $bidang_keahlian ) ) : array();
        }
        $bidang_tambahan    = $user_id ? get_user_meta( $user_id, 'bidang_keahlian_tambahan', true ) : '';
        $deskripsi_asesor   = $user_id ? get_user_meta( $user_id, 'deskripsi_asesor', true ) : '';
        $asesor_linkedin    = $user_id ? get_user_meta( $user_id, 'asesor_linkedin', true ) : '';
        $asesor_instagram   = $user_id ? get_user_meta( $user_id, 'asesor_instagram', true ) : '';
        $asesor_facebook    = $user_id ? get_user_meta( $user_id, 'asesor_facebook', true ) : '';
        $asesor_website     = $user_id ? get_user_meta( $user_id, 'asesor_website', true ) : '';

        // Cek status masa berlaku MET (3 tahun dari tanggal dikeluarkan)
        $status_met_html = '';
        if ( ! empty( $tanggal_terbit_met ) ) {
            $time_terbit  = strtotime( $tanggal_terbit_met );
            $time_expired = strtotime( '+3 years', $time_terbit );
            $is_expired   = time() > $time_expired;

            if ( $is_expired ) {
                $status_met_html = '<div style="margin-top:8px; padding:10px 14px; background:#fff2f0; border-left:4px solid #ff4d4f; border-radius:4px; max-width:520px;">
                    <strong style="color:#cf1322;">⚠️ SERTIFIKAT ASESOR KADALUARSA</strong><br>
                    <span style="font-size:12.5px; color:#555;">Masa berlaku 3 tahun telah berakhir pada <strong>' . esc_html( $this->format_indo_date( $time_expired ) ) . '</strong>.</span>
                </div>';
            } else {
                $status_met_html = '<div style="margin-top:8px; padding:10px 14px; background:#f6ffed; border-left:4px solid #52c41a; border-radius:4px; max-width:520px;">
                    <strong style="color:#389e0d;">🟢 SERTIFIKAT ASESOR AKTIF &amp; VALID</strong><br>
                    <span style="font-size:12.5px; color:#555;">Berlaku hingga <strong>' . esc_html( $this->format_indo_date( $time_expired ) ) . '</strong> (3 tahun sejak tanggal terbit).</span>
                </div>';
            }
        }

        // Ambil semua Skema Sertifikasi untuk pilihan Multi-Checkbox Bidang Keahlian
        $skema_posts = get_posts( array(
            'post_type'      => 'skema',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div id="lsp-asesor-profile-section" style="background: #ffffff; border: 1px solid #ccd0d4; border-left: 4px solid #72161a; border-radius: 6px; padding: 20px 25px; margin: 25px 0;">
            <h2 style="margin-top: 0; color: #72161a; display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-businessman" style="font-size: 24px; width: 24px; height: 24px;"></span>
                <?php _e( 'Data Kompetensi & Profil Asesor LSP', 'lsp-core-management' ); ?>
            </h2>

            <?php if ( ! $is_admin ) : ?>
                <div style="margin-bottom: 20px; padding: 12px 18px; background: #f0f9ff; border-left: 4px solid #0284c7; border-radius: 4px;">
                    <strong style="color: #0369a1; font-size: 13.5px;">ℹ️ Informasi Lisensi & Data Profesional Asesor LSP</strong>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #334155; line-height: 1.5;">
                        Data profesional di bawah ini bersifat <strong>hanya-baca (read-only)</strong> dan dikelola terpusat oleh Administrator LSP sesuai regulasi BNSP. Anda hanya diperkenankan memperbarui <strong>Alamat Email</strong> dan <strong>Kata Sandi</strong> akun pada formulir profil standar WordPress. Untuk permohonan pembaruan lisensi MET atau kompetensi, silakan hubungi Administrator LSP.
                    </p>
                </div>
            <?php else : ?>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e( 'Lengkapi informasi lisensi MET BNSP, masa berlaku sertifikat asesor, multi-bidang keahlian, deskripsi, dan tautan sosial media untuk ditampilkan di halaman Asesor.', 'lsp-core-management' ); ?>
                </p>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <!-- 1. Foto Profil Asesor -->
                <tr>
                    <th><label for="foto_asesor"><?php _e( 'Foto Resmi Asesor', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                            <div id="foto_asesor_preview" style="width: 80px; height: 80px; border-radius: 50%; overflow: hidden; background: #f1f5f9; border: 2px solid #cbd5e1; display: flex; align-items: center; justify-content: center;">
                                <?php if ( ! empty( $foto_asesor ) ) : ?>
                                    <img src="<?php echo esc_url( $foto_asesor ); ?>" style="width: 100%; height: 100%; object-fit: cover;" />
                                <?php else : ?>
                                    <span class="dashicons dashicons-admin-users" style="font-size: 36px; width: 36px; height: 36px; color: #94a3b8;"></span>
                                <?php endif; ?>
                            </div>
                            <?php if ( $is_admin ) : ?>
                                <div style="flex: 1; min-width: 260px;">
                                    <input type="text" name="foto_asesor" id="foto_asesor" value="<?php echo esc_attr( $foto_asesor ); ?>" class="regular-text" placeholder="URL foto atau pilih dari Media Library..." />
                                    <div style="margin-top: 8px; display: flex; gap: 8px;">
                                        <button type="button" class="button" id="btn_upload_foto_asesor"><?php _e( 'Pilih / Unggah Foto', 'lsp-core-management' ); ?></button>
                                        <button type="button" class="button" id="btn_remove_foto_asesor" <?php echo empty( $foto_asesor ) ? 'style="display:none;"' : ''; ?>><?php _e( 'Hapus Foto', 'lsp-core-management' ); ?></button>
                                    </div>
                                    <p class="description"><?php _e( 'Jika dikosongkan, sistem otomatis menggunakan Gravatar akun.', 'lsp-core-management' ); ?></p>
                                </div>
                            <?php else : ?>
                                <div style="flex: 1; min-width: 260px;">
                                    <span style="font-size: 13px; color: #64748b;"><?php _e( 'Foto resmi profil Asesor dikelola oleh Administrator LSP.', 'lsp-core-management' ); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- 2. Nomor MET -->
                <tr>
                    <th><label for="nomor_met"><?php _e( 'Nomor Registrasi / Sertifikat MET', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <?php if ( $is_admin ) : ?>
                            <input type="text" name="nomor_met" id="nomor_met" value="<?php echo esc_attr( $nomor_met ); ?>" class="regular-text" placeholder="Contoh: MET.000.001234.2024" />
                            <p class="description"><?php _e( 'Nomor Registrasi Asesor Kompetensi yang diterbitkan oleh BNSP.', 'lsp-core-management' ); ?></p>
                        <?php else : ?>
                            <input type="text" value="<?php echo esc_attr( $nomor_met ?: 'Belum diisi oleh Administrator' ); ?>" class="regular-text" readonly disabled style="background: #f8fafc; cursor: not-allowed; font-weight: 600;" />
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- 3. Tanggal Dikeluarkan Sertifikat MET (Expired 3 Tahun) -->
                <tr>
                    <th><label for="tanggal_terbit_met"><?php _e( 'Tanggal Dikeluarkan Sertifikat MET', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <?php if ( $is_admin ) : ?>
                            <input type="date" name="tanggal_terbit_met" id="tanggal_terbit_met" value="<?php echo esc_attr( $tanggal_terbit_met ); ?>" class="regular-text" style="max-width: 240px;" />
                            <p class="description"><?php _e( 'Sertifikat kompetensi Asesor berlaku selama 3 tahun sejak tanggal dikeluarkan.', 'lsp-core-management' ); ?></p>
                        <?php else : ?>
                            <input type="text" value="<?php echo esc_attr( ! empty( $tanggal_terbit_met ) ? $this->format_indo_date( strtotime( $tanggal_terbit_met ) ) : 'Belum diisi' ); ?>" class="regular-text" readonly disabled style="max-width: 240px; background: #f8fafc; cursor: not-allowed;" />
                        <?php endif; ?>
                        <?php echo $status_met_html; ?>
                    </td>
                </tr>

                <!-- 4. Bidang Keahlian (Multi-Select / Bisa Lebih dari Satu) -->
                <tr>
                    <th><label><?php _e( 'Bidang / Skema Keahlian', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <?php if ( $is_admin ) : ?>
                            <?php if ( ! empty( $skema_posts ) ) : ?>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 12px 15px; border-radius: 4px; background: #f8fafc; max-width: 550px; margin-bottom: 10px;">
                                    <strong style="display: block; margin-bottom: 8px; font-size: 12.5px; color: #334155;">Pilih dari Skema Sertifikasi LSP:</strong>
                                    <?php foreach ( $skema_posts as $skema_item ) : 
                                        $checked = in_array( $skema_item->post_title, $bidang_keahlian, true );
                                    ?>
                                        <label style="display: block; margin-bottom: 6px; font-size: 13.5px;">
                                            <input type="checkbox" name="bidang_keahlian_asesor[]" value="<?php echo esc_attr( $skema_item->post_title ); ?>" <?php checked( $checked ); ?> />
                                            <?php echo esc_html( $skema_item->post_title ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <label for="bidang_keahlian_tambahan" style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">
                                <?php _e( 'Bidang Keahlian Lainnya / Tambahan (Pisahkan dengan tanda koma):', 'lsp-core-management' ); ?>
                            </label>
                            <input type="text" name="bidang_keahlian_tambahan" id="bidang_keahlian_tambahan" value="<?php echo esc_attr( $bidang_tambahan ); ?>" class="large-text" style="max-width: 550px;" placeholder="Contoh: Public Relations, Digital Marketing, Komunikasi Korporat" />
                            <p class="description"><?php _e( 'Anda dapat mencentang beberapa skema di atas sekaligus dan/atau menuliskan beberapa bidang keahlian tambahan yang dipisahkan koma.', 'lsp-core-management' ); ?></p>
                        <?php else : ?>
                            <?php 
                            $all_b = $this->get_asesor_all_bidang( $user_id );
                            if ( ! empty( $all_b ) ) :
                                echo '<div style="display:flex; flex-wrap:wrap; gap:6px; max-width:550px;">';
                                foreach ( $all_b as $b ) :
                                    echo '<span style="display:inline-block; background:#fff8f0; color:#72161a; border:1px solid #f89b29; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600;">' . esc_html( $b ) . '</span>';
                                endforeach;
                                echo '</div>';
                            else :
                                echo '<span style="color:#94a3b8; font-size:13px;">' . __( '— Belum ada bidang keahlian yang ditetapkan oleh Administrator —', 'lsp-core-management' ) . '</span>';
                            endif;
                            ?>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- 5. Deskripsi / Biografi / Catatan Bebas Asesor -->
                <tr>
                    <th><label for="deskripsi_asesor"><?php _e( 'Deskripsi / Biografi & Pengalaman Asesor', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <?php if ( $is_admin ) : ?>
                            <textarea name="deskripsi_asesor" id="deskripsi_asesor" rows="4" class="large-text" style="max-width: 550px;" placeholder="Tuliskan deskripsi singkat, latar belakang profesional, pengalaman industri, atau informasi tambahan lainnya..."><?php echo esc_textarea( $deskripsi_asesor ); ?></textarea>
                            <p class="description"><?php _e( 'Teks deskripsi ini akan ditampilkan pada kartu / profil Asesor di halaman depan.', 'lsp-core-management' ); ?></p>
                        <?php else : ?>
                            <textarea readonly disabled rows="4" class="large-text" style="max-width: 550px; background: #f8fafc; cursor: not-allowed;"><?php echo esc_textarea( $deskripsi_asesor ?: __( 'Belum ada deskripsi profil yang ditambahkan oleh Administrator.', 'lsp-core-management' ) ); ?></textarea>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- 6. Tautan Sosial Media & Profesional Asesor -->
                <tr>
                    <th><label><?php _e( 'Tautan Sosial Media & Portofolio', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; max-width: 550px;">
                            <div>
                                <label for="asesor_linkedin" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">🔗 LinkedIn URL</label>
                                <input type="url" name="asesor_linkedin" id="asesor_linkedin" value="<?php echo esc_attr( $asesor_linkedin ); ?>" class="regular-text" style="width: 100%;<?php echo ! $is_admin ? ' background:#f8fafc; cursor:not-allowed;' : ''; ?>" placeholder="https://linkedin.com/in/username" <?php echo ! $is_admin ? 'readonly disabled' : ''; ?> />
                            </div>
                            <div>
                                <label for="asesor_instagram" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">📸 Instagram URL</label>
                                <input type="url" name="asesor_instagram" id="asesor_instagram" value="<?php echo esc_attr( $asesor_instagram ); ?>" class="regular-text" style="width: 100%;<?php echo ! $is_admin ? ' background:#f8fafc; cursor:not-allowed;' : ''; ?>" placeholder="https://instagram.com/username" <?php echo ! $is_admin ? 'readonly disabled' : ''; ?> />
                            </div>
                            <div>
                                <label for="asesor_facebook" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">📘 Facebook URL</label>
                                <input type="url" name="asesor_facebook" id="asesor_facebook" value="<?php echo esc_attr( $asesor_facebook ); ?>" class="regular-text" style="width: 100%;<?php echo ! $is_admin ? ' background:#f8fafc; cursor:not-allowed;' : ''; ?>" placeholder="https://facebook.com/username" <?php echo ! $is_admin ? 'readonly disabled' : ''; ?> />
                            </div>
                            <div>
                                <label for="asesor_website" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">🌐 Website / Link Lainnya</label>
                                <input type="url" name="asesor_website" id="asesor_website" value="<?php echo esc_attr( $asesor_website ); ?>" class="regular-text" style="width: 100%;<?php echo ! $is_admin ? ' background:#f8fafc; cursor:not-allowed;' : ''; ?>" placeholder="https://website-anda.com" <?php echo ! $is_admin ? 'readonly disabled' : ''; ?> />
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <?php if ( $is_admin ) : ?>
            <script>
            jQuery(document).ready(function($){
                var mediaUploader;
                $('#btn_upload_foto_asesor').on('click', function(e) {
                    e.preventDefault();
                    if (mediaUploader) {
                        mediaUploader.open();
                        return;
                    }
                    mediaUploader = wp.media({
                        title: 'Pilih Foto Resmi Asesor',
                        button: { text: 'Gunakan Foto Ini' },
                        multiple: false
                    });
                    mediaUploader.on('select', function() {
                        var attachment = mediaUploader.state().get('selection').first().toJSON();
                        $('#foto_asesor').val(attachment.url);
                        $('#foto_asesor_preview').html('<img src="' + attachment.url + '" style="width:100%; height:100%; object-fit:cover;" />');
                        $('#btn_remove_foto_asesor').show();
                    });
                    mediaUploader.open();
                });

                $('#btn_remove_foto_asesor').on('click', function(e) {
                    e.preventDefault();
                    $('#foto_asesor').val('');
                    $('#foto_asesor_preview').html('<span class="dashicons dashicons-admin-users" style="font-size:36px; width:36px; height:36px; color:#94a3b8;"></span>');
                    $(this).hide();
                });
            });
            </script>
        <?php endif; ?>
        <?php
    }

    public function save_asesor_user_profile_fields( $user_id ) {
        if ( ! isset( $_POST['lsp_asesor_profile_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['lsp_asesor_profile_nonce'], 'lsp_save_asesor_profile' ) ) return;
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;

        // Otorisasi Ketat Server-Side (TASK 3 & TASK 5):
        // Hanya pengguna dengan kapabilitas 'manage_options' (Administrator) yang diizinkan memperbarui data profesional Asesor.
        // Asesor yang mengedit profilnya sendiri di profile.php DITOLAK keras untuk mengubah data lisensi/profesional,
        // meskipun request dikirim langsung secara manual via HTTP POST.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['foto_asesor'] ) ) {
            update_user_meta( $user_id, 'foto_asesor', esc_url_raw( trim( $_POST['foto_asesor'] ) ) );
        }
        if ( isset( $_POST['nomor_met'] ) ) {
            update_user_meta( $user_id, 'nomor_met', sanitize_text_field( trim( $_POST['nomor_met'] ) ) );
        }
        if ( isset( $_POST['tanggal_terbit_met'] ) ) {
            update_user_meta( $user_id, 'tanggal_terbit_met', sanitize_text_field( trim( $_POST['tanggal_terbit_met'] ) ) );
        }

        // Simpan array checkbox bidang keahlian
        $bidang_selected = array();
        if ( isset( $_POST['bidang_keahlian_asesor'] ) && is_array( $_POST['bidang_keahlian_asesor'] ) ) {
            $bidang_selected = array_map( 'sanitize_text_field', wp_unslash( $_POST['bidang_keahlian_asesor'] ) );
        }
        update_user_meta( $user_id, 'bidang_keahlian_asesor', $bidang_selected );

        if ( isset( $_POST['bidang_keahlian_tambahan'] ) ) {
            update_user_meta( $user_id, 'bidang_keahlian_tambahan', sanitize_text_field( $_POST['bidang_keahlian_tambahan'] ) );
        }
        if ( isset( $_POST['deskripsi_asesor'] ) ) {
            update_user_meta( $user_id, 'deskripsi_asesor', wp_kses_post( $_POST['deskripsi_asesor'] ) );
        }
        if ( isset( $_POST['asesor_linkedin'] ) ) {
            update_user_meta( $user_id, 'asesor_linkedin', esc_url_raw( trim( $_POST['asesor_linkedin'] ) ) );
        }
        if ( isset( $_POST['asesor_instagram'] ) ) {
            update_user_meta( $user_id, 'asesor_instagram', esc_url_raw( trim( $_POST['asesor_instagram'] ) ) );
        }
        if ( isset( $_POST['asesor_facebook'] ) ) {
            update_user_meta( $user_id, 'asesor_facebook', esc_url_raw( trim( $_POST['asesor_facebook'] ) ) );
        }
        if ( isset( $_POST['asesor_website'] ) ) {
            update_user_meta( $user_id, 'asesor_website', esc_url_raw( trim( $_POST['asesor_website'] ) ) );
        }
    }

    /**
     * Lindungi data profil akun Asesor agar display_name dan user_nicename tidak dapat dimanipulasi
     * oleh Asesor itu sendiri (hanya boleh diubah Administrator). Asesor hanya boleh mengubah email & password.
     */
    public function protect_asesor_user_data( $data, $update, $user_id, $userdata ) {
        if ( ! $update || empty( $user_id ) ) {
            return $data;
        }

        $user = get_userdata( $user_id );
        if ( $user && in_array( 'asesor', (array) $user->roles, true ) ) {
            // Jika bukan administrator (Asesor mengedit profilnya sendiri)
            if ( ! current_user_can( 'manage_options' ) ) {
                $data['display_name']  = $user->display_name;
                $data['user_nicename'] = $user->user_nicename;
            }
        }

        return $data;
    }

    /**
     * Lindungi user meta standar (nama depan, nama belakang, biografi standar) milik akun Asesor
     * agar tidak dapat diubah oleh Asesor sendiri, serta cegah manipulasi meta field profesional.
     */
    public function protect_asesor_user_meta( $meta, $user, $update ) {
        if ( ! $update || empty( $user->ID ) ) {
            return $meta;
        }

        if ( in_array( 'asesor', (array) $user->roles, true ) ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                $orig_user = get_userdata( $user->ID );
                if ( $orig_user ) {
                    $meta['first_name']  = $orig_user->first_name;
                    $meta['last_name']   = $orig_user->last_name;
                    $meta['nickname']    = $orig_user->nickname;
                    $meta['description'] = $orig_user->description;
                }

                $protected_keys = array(
                    'nomor_met',
                    'tanggal_terbit_met',
                    'foto_asesor',
                    'bidang_keahlian_asesor',
                    'bidang_keahlian_tambahan',
                    'deskripsi_asesor',
                    'asesor_linkedin',
                    'asesor_instagram',
                    'asesor_facebook',
                    'asesor_website',
                );
                foreach ( $protected_keys as $key ) {
                    unset( $meta[ $key ] );
                }
            }
        }

        return $meta;
    }

    /**
     * Penegakan Workflow Artikel Asesor:
     * Asesor hanya boleh menyimpan Draft atau Submit for Review (Pending).
     * Jika artikel sudah dipublikasikan (Publish) oleh Admin, artikel bersifat strictly Read-Only bagi Asesor.
     */
    public function enforce_asesor_post_workflow( $data, $postarr ) {
        if ( 'post' === $data['post_type'] ) {
            $user = wp_get_current_user();
            if ( $user && in_array( 'asesor', (array) $user->roles, true ) && ! current_user_can( 'manage_options' ) ) {
                // 1. Asesor tidak boleh mem-publish langsung; status harus pending atau draft
                if ( in_array( $data['post_status'], array( 'publish', 'future', 'private' ), true ) ) {
                    $data['post_status'] = 'pending';
                }

                // 2. Jika post target sudah berstatus 'publish', asesor dilarang mengubah konten (Read-Only)
                if ( ! empty( $postarr['ID'] ) ) {
                    $orig_post = get_post( $postarr['ID'] );
                    if ( $orig_post && 'publish' === $orig_post->post_status ) {
                        $data['post_status']  = 'publish';
                        $data['post_title']   = $orig_post->post_title;
                        $data['post_content'] = $orig_post->post_content;
                        $data['post_excerpt'] = $orig_post->post_excerpt;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Proteksi CPT LSP (Data Sertifikat, Skema, TUK) dari Role Asesor
     */
    public function restrict_lsp_cpts_from_asesor( $caps, $cap, $user_id, $args ) {
        if ( empty( $user_id ) ) {
            return $caps;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( 'asesor', (array) $user->roles, true ) ) {
            return $caps;
        }

        if ( in_array( $cap, array( 'edit_post', 'delete_post', 'read_post' ), true ) && ! empty( $args[0] ) ) {
            $post_id   = $args[0];
            $post_type = get_post_type( $post_id );
            if ( in_array( $post_type, array( 'data_sertifikat', 'skema', 'tuk' ), true ) ) {
                return array( 'manage_options' );
            }
        }

        return $caps;
    }

    /**
     * Helper: Gabungkan daftar bidang keahlian Asesor (dari checkbox skema + input tambahan koma)
     */
    public function get_asesor_all_bidang( $user_id ) {
        $bidang_arr = get_user_meta( $user_id, 'bidang_keahlian_asesor', true );
        if ( ! is_array( $bidang_arr ) ) {
            $bidang_arr = ! empty( $bidang_arr ) ? array_map( 'trim', explode( ',', (string) $bidang_arr ) ) : array();
        }
        $tambahan = get_user_meta( $user_id, 'bidang_keahlian_tambahan', true );
        if ( ! empty( $tambahan ) ) {
            $extra = array_filter( array_map( 'trim', explode( ',', $tambahan ) ) );
            $bidang_arr = array_unique( array_merge( $bidang_arr, $extra ) );
        }
        return array_values( array_filter( $bidang_arr ) );
    }

    /**
     * Kolom Kustom di Tabel Users WP-Admin
     */
    public function asesor_user_columns( $columns ) {
        $columns['nomor_met']     = __( 'No. MET Asesor', 'lsp-core-management' );
        $columns['status_met']    = __( 'Status & Masa Berlaku MET', 'lsp-core-management' );
        $columns['bidang_asesor'] = __( 'Bidang Keahlian', 'lsp-core-management' );
        return $columns;
    }

    public function asesor_user_column_content( $value, $column_name, $user_id ) {
        $user = get_userdata( $user_id );
        $nomor_met = get_user_meta( $user_id, 'nomor_met', true );
        $tgl_met   = get_user_meta( $user_id, 'tanggal_terbit_met', true );

        // Tampilkan hanya jika user memiliki role 'asesor' atau punya data nomor_met
        $is_asesor = ( $user && in_array( 'asesor', (array) $user->roles, true ) ) || ! empty( $nomor_met );
        if ( ! $is_asesor ) {
            if ( in_array( $column_name, array( 'nomor_met', 'status_met', 'bidang_asesor' ), true ) ) {
                return '<span style="color:#ccc;">—</span>';
            }
            return $value;
        }

        if ( 'nomor_met' === $column_name ) {
            return $nomor_met ? '<code><strong>' . esc_html( $nomor_met ) . '</strong></code>' : '<span style="color:#aaa;">Belum diisi</span>';
        }

        if ( 'status_met' === $column_name ) {
            if ( empty( $tgl_met ) ) {
                return '<span style="color:#999; font-size:12px;">Tgl terbit belum diisi</span>';
            }
            $time_exp   = strtotime( '+3 years', strtotime( $tgl_met ) );
            $is_expired = time() > $time_exp;
            $exp_str    = esc_html( $this->format_indo_date( $time_exp ) );

            if ( $is_expired ) {
                return '<span style="display:inline-block; padding:3px 8px; background:#ff4d4f; color:#fff; border-radius:10px; font-weight:700; font-size:11px;">⚠️ KADALUARSA</span><br><small style="color:#cf1322;">Habis: ' . $exp_str . '</small>';
            } else {
                return '<span style="display:inline-block; padding:3px 8px; background:#52c41a; color:#fff; border-radius:10px; font-weight:700; font-size:11px;">🟢 AKTIF</span><br><small style="color:#555;">s/d ' . $exp_str . '</small>';
            }
        }

        if ( 'bidang_asesor' === $column_name ) {
            $all_bidang = $this->get_asesor_all_bidang( $user_id );
            if ( empty( $all_bidang ) ) {
                return '<span style="color:#aaa;">—</span>';
            }
            $badges = array();
            foreach ( $all_bidang as $b ) {
                $badges[] = '<span style="display:inline-block; background:#fff8f0; color:#72161a; border:1px solid #f89b29; padding:1px 7px; border-radius:10px; font-size:11px; margin:2px 2px 2px 0;">' . esc_html( $b ) . '</span>';
            }
            return implode( '', $badges );
        }

        return $value;
    }

    /**
     * ====================================================================
     * 5. FILTER QUERY UNTUK ARCHIVE SKEMA (/skema/)
     * ====================================================================
     */
    public function skema_archive_query( $query ) {
        if ( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'skema' ) ) {
            $query->set( 'posts_per_page', 9 );
            $meta_query = array();

            if ( ! empty( $_GET['kemasan'] ) ) {
                $kemasan = sanitize_text_field( wp_unslash( $_GET['kemasan'] ) );
                if ( in_array( $kemasan, array( 'KKNI', 'Okupasi', 'Klaster' ), true ) ) {
                    $meta_query[] = array(
                        'key'     => 'jenis_kemasan',
                        'value'   => $kemasan,
                        'compare' => '=',
                    );
                }
            }

            if ( ! empty( $_GET['q'] ) ) {
                $keyword = sanitize_text_field( wp_unslash( $_GET['q'] ) );
                $query->set( 's', $keyword );
            }

            if ( ! empty( $_GET['urutkan'] ) ) {
                $urutkan = sanitize_text_field( wp_unslash( $_GET['urutkan'] ) );
                switch ( $urutkan ) {
                    case 'nama_asc':
                        $query->set( 'orderby', 'title' );
                        $query->set( 'order', 'ASC' );
                        break;
                    case 'nama_desc':
                        $query->set( 'orderby', 'title' );
                        $query->set( 'order', 'DESC' );
                        break;
                    case 'biaya_asc':
                        $query->set( 'meta_key', 'biaya_sertifikasi' );
                        $query->set( 'orderby', 'meta_value_num' );
                        $query->set( 'order', 'ASC' );
                        break;
                    case 'biaya_desc':
                        $query->set( 'meta_key', 'biaya_sertifikasi' );
                        $query->set( 'orderby', 'meta_value_num' );
                        $query->set( 'order', 'DESC' );
                        break;
                    case 'acak':
                        $query->set( 'orderby', 'rand' );
                        break;
                    default:
                        $query->set( 'orderby', 'date' );
                        $query->set( 'order', 'DESC' );
                        break;
                }
            }

            if ( ! empty( $meta_query ) ) {
                $query->set( 'meta_query', $meta_query );
            }
        }
    }

    /**
     * ====================================================================
     * 6. ADMIN MENUS: IMPORT CSV SERTIFIKAT, DIREKTORI ASESOR, & PENGATURAN LSP
     * ====================================================================
     */
    public function register_admin_menus() {
        // Submenu Import CSV di bawah Sertifikat LSP
        add_submenu_page(
            'edit.php?post_type=data_sertifikat',
            __( 'Import Data Sertifikat (CSV)', 'lsp-core-management' ),
            __( 'Import CSV', 'lsp-core-management' ),
            'manage_options',
            'lsp-cert-import',
            array( $this, 'render_import_page' )
        );

        // Submenu Asesor LSP di bawah LSP Core (edit.php?post_type=skema)
        add_submenu_page(
            'edit.php?post_type=skema',
            __( 'Manajemen Asesor LSP', 'lsp-core-management' ),
            __( 'Asesor LSP', 'lsp-core-management' ),
            'manage_options',
            'lsp-asesor-directory',
            array( $this, 'render_asesor_admin_page' )
        );

        // Submenu Kelayakan & Migrasi Data SKKNI
        add_submenu_page(
            'edit.php?post_type=skema',
            __( 'Kelayakan & Migrasi Data SKKNI', 'lsp-core-management' ),
            __( 'Migrasi SKKNI', 'lsp-core-management' ),
            'manage_options',
            'lsp-skkni-migration',
            array( $this, 'render_skkni_migration_page' )
        );

        // Daftarkan halaman Pengaturan & Panduan (diakses via Tab Skema Sertifikasi)
        add_submenu_page(
            'edit.php?post_type=skema',
            __( 'Pengaturan Core LSP', 'lsp-core-management' ),
            __( 'Pengaturan LSP', 'lsp-core-management' ),
            'manage_options',
            'lsp-core-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'edit.php?post_type=skema',
            __( 'Panduan Penggunaan Tema & Plugin LSP', 'lsp-core-management' ),
            __( 'Panduan LSP', 'lsp-core-management' ),
            'edit_posts',
            'lsp-documentation-guide',
            array( $this, 'render_documentation_page' )
        );

        // Filter submenu agar sidebar LSP Core rapi & fokus: Skema Sertifikasi, Asesor LSP, TUK
        add_action( 'admin_menu', array( $this, 'filter_lsp_core_submenus' ), 999 );
        add_action( 'all_admin_notices', array( $this, 'render_skema_admin_tabs' ) );
    }

    /**
     * Rapikan susunan submenu di bawah LSP Core agar tepat 3 menu:
     * 1. Skema Sertifikasi
     * 2. Asesor LSP
     * 3. TUK
     * (Menu tambahan dimasukkan ke dalam bentuk Tab Navigasi)
     */
    public function filter_lsp_core_submenus() {
        global $submenu, $menu;

        // Pastikan nama menu induk adalah LSP Core
        if ( is_array( $menu ) ) {
            foreach ( $menu as $k => $m ) {
                if ( isset( $m[2] ) && 'edit.php?post_type=skema' === $m[2] ) {
                    $menu[ $k ][0] = 'LSP Core';
                    break;
                }
            }
        }

        if ( isset( $submenu['edit.php?post_type=skema'] ) && is_array( $submenu['edit.php?post_type=skema'] ) ) {
            $allowed_slugs = array( 'edit.php?post_type=skema', 'lsp-asesor-directory', 'edit.php?post_type=tuk' );
            $clean_sub = array();

            // 1. Skema Sertifikasi
            $clean_sub[] = array(
                __( 'Skema Sertifikasi', 'lsp-core-management' ),
                'edit_posts',
                'edit.php?post_type=skema',
                __( 'Skema Sertifikasi', 'lsp-core-management' ),
            );

            // 2. Asesor LSP
            $clean_sub[] = array(
                __( 'Asesor LSP', 'lsp-core-management' ),
                'manage_options',
                'lsp-asesor-directory',
                __( 'Asesor LSP', 'lsp-core-management' ),
            );

            // 3. TUK
            $clean_sub[] = array(
                __( 'TUK', 'lsp-core-management' ),
                'edit_posts',
                'edit.php?post_type=tuk',
                __( 'TUK', 'lsp-core-management' ),
            );

            // 4. Migrasi SKKNI
            $clean_sub[] = array(
                __( 'Migrasi SKKNI', 'lsp-core-management' ),
                'manage_options',
                'lsp-skkni-migration',
                __( 'Migrasi SKKNI', 'lsp-core-management' ),
            );

            $submenu['edit.php?post_type=skema'] = $clean_sub;
        }
    }

    /**
     * Tampilkan Navigasi Tab pada halaman-halaman Skema Sertifikasi:
     * [Semua Skema] [Tambah Skema] [Pengaturan LSP] [Panduan LSP] [Pakar Skema]
     */
    public function render_skema_admin_tabs() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $is_skema_pt   = ( 'skema' === $screen->post_type );
        $is_skema_page = ( isset( $_GET['page'] ) && in_array( $_GET['page'], array( 'lsp-core-settings', 'lsp-documentation-guide', 'lsp-smart-advisor-admin' ), true ) );

        if ( ! $is_skema_pt && ! $is_skema_page ) {
            return;
        }

        $active_tab = 'list';
        if ( 'post-new.php' === $GLOBALS['pagenow'] && $is_skema_pt ) {
            $active_tab = 'new';
        } elseif ( isset( $_GET['page'] ) && 'lsp-core-settings' === $_GET['page'] ) {
            $active_tab = 'settings';
        } elseif ( isset( $_GET['page'] ) && 'lsp-documentation-guide' === $_GET['page'] ) {
            $active_tab = 'guide';
        } elseif ( isset( $_GET['page'] ) && 'lsp-smart-advisor-admin' === $_GET['page'] ) {
            $active_tab = 'smart';
        }
        ?>
        <div class="wrap lsp-skema-tabs-wrapper" style="margin-top: 15px; margin-bottom: 20px;">
            <nav class="nav-tab-wrapper wp-clearfix" style="border-bottom: 2px solid #cbd5e1;">
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=skema' ) ); ?>" class="nav-tab <?php echo 'list' === $active_tab ? 'nav-tab-active' : ''; ?>" style="font-size: 13.5px; font-weight: 600;">
                    📋 Daftar Skema
                </a>
                <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=skema' ) ); ?>" class="nav-tab <?php echo 'new' === $active_tab ? 'nav-tab-active' : ''; ?>" style="font-size: 13.5px; font-weight: 600;">
                    ➕ Tambah Skema Baru
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lsp-core-settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>" style="font-size: 13.5px; font-weight: 600;">
                    ⚙️ Pengaturan LSP
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lsp-documentation-guide' ) ); ?>" class="nav-tab <?php echo 'guide' === $active_tab ? 'nav-tab-active' : ''; ?>" style="font-size: 13.5px; font-weight: 600;">
                    📖 Panduan LSP
                </a>
                <?php if ( class_exists( 'LSP_Smart_Advisor' ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=lsp-smart-advisor-admin' ) ); ?>" class="nav-tab <?php echo 'smart' === $active_tab ? 'nav-tab-active' : ''; ?>" style="font-size: 13.5px; font-weight: 600;">
                        🧠 Pakar Skema
                    </a>
                <?php endif; ?>
            </nav>
        </div>
        <?php
    }

    /**
     * Widget Pintasan Panduan & Shortcode di Dashboard Utama WP-Admin
     */
    public function register_dashboard_guide_widget() {
        wp_add_dashboard_widget(
            'lsp_dashboard_guide_widget',
            '📖 Pusat Panduan & Shortcode LSP Komunikasi',
            array( $this, 'render_dashboard_guide_widget' )
        );
    }

    public function render_dashboard_guide_widget() {
        $pdf_url   = plugins_url( 'docs/Panduan-Tema-dan-Plugin-LSP-Komunikasi.pdf', __FILE__ );
        $guide_url = admin_url( 'admin.php?page=lsp-documentation-guide' );
        ?>
        <div style="font-size:13px; line-height:1.6; color:#334155;">
            <p style="margin-top:0;">Selamat datang di panel pengelolaan <strong>LSP Komunikasi Indonesia</strong>. Gunakan pintasan di bawah ini untuk mempelajari cara mengelola Skema, TUK, Asesor, Sertifikat, dan Berkas Download:</p>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin:12px 0;">
                <a href="<?php echo esc_url( $guide_url ); ?>" class="button button-primary">📖 Buka Panduan Interaktif</a>
                <a href="<?php echo esc_url( $pdf_url ); ?>" class="button" target="_blank" download>📥 Unduh Buku Panduan (PDF)</a>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-top:10px;">
                <strong style="display:block; margin-bottom:6px; color:#0f172a;">⚡ Daftar Cepat Shortcode Utama:</strong>
                <code style="display:block; margin-bottom:4px; background:#fff; padding:4px 6px; border:1px solid #cbd5e1; border-radius:4px;">[lsp_skema jumlah="3" acak="ya"]</code>
                <code style="display:block; margin-bottom:4px; background:#fff; padding:4px 6px; border:1px solid #cbd5e1; border-radius:4px;">[lsp_berita jumlah="3" acak="ya"]</code>
                <code style="display:block; margin-bottom:4px; background:#fff; padding:4px 6px; border:1px solid #cbd5e1; border-radius:4px;">[lsp_asesor jumlah="3" acak="ya"]</code>
                <code style="display:block; margin-bottom:4px; background:#fff; padding:4px 6px; border:1px solid #cbd5e1; border-radius:4px;">[lsp_tuk] &nbsp;|&nbsp; [cek_sertifikat] &nbsp;|&nbsp; [lsp_download]</code>
            </div>
        </div>
        <?php
    }

    /**
     * Halaman Pusat Panduan & Dokumentasi Interaktif di WP-Admin
     */
    public function render_documentation_page() {
        $pdf_url = plugins_url( 'docs/Panduan-Tema-dan-Plugin-LSP-Komunikasi.pdf', __FILE__ );
        ?>
        <div class="wrap" style="max-width: 1120px; margin-top: 20px;">
            <!-- Header Banner -->
            <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; padding: 28px 32px; border-radius: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; box-shadow: 0 10px 25px rgba(15,23,42,0.12); border-bottom: 4px solid #429f46;">
                <div>
                    <span style="background: rgba(74, 222, 128, 0.15); color: #4ade80; border: 1px solid rgba(74,222,128,0.3); padding: 4px 12px; border-radius: 50px; font-size: 11.5px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">Buku Manual & Dokumentasi Resmi</span>
                    <h1 style="color: #fff; font-size: 26px; font-weight: 800; margin: 10px 0 6px; padding: 0;">📖 Panduan Penggunaan Tema & Plugin LSP Komunikasi</h1>
                    <p style="margin: 0; color: #cbd5e1; font-size: 14px;">Dokumentasi terpadu bagi Administrator, Editor, dan Asesor untuk mengelola fitur website di masa mendatang.</p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="<?php echo esc_url( $pdf_url ); ?>" class="button button-primary button-hero" target="_blank" download style="background: #429f46; border-color: #358239; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; min-height: 42px; line-height: 40px; padding: 0 20px; border-radius: 8px;">
                        📥 Unduh Buku Panduan (.PDF)
                    </a>
                    <button type="button" onclick="window.print();" class="button button-secondary" style="min-height: 42px; padding: 0 16px; border-radius: 8px; font-weight: 600;">
                        🖨️ Cetak Halaman Ini
                    </button>
                </div>
            </div>

            <!-- Navigasi Tab Dokumentasi -->
            <div class="lsp-doc-tabs" style="display: flex; gap: 8px; flex-wrap: wrap; margin: 22px 0 18px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px;">
                <button type="button" class="lsp-doc-tab active" data-target="tab-shortcode">⚡ Daftar Shortcode (1-Klik Salin)</button>
                <button type="button" class="lsp-doc-tab" data-target="tab-arsitektur">🏗️ Arsitektur & Tema</button>
                <button type="button" class="lsp-doc-tab" data-target="tab-skema">🎓 Skema & TUK</button>
                <button type="button" class="lsp-doc-tab" data-target="tab-asesor">👨‍🏫 Akun & Role Asesor</button>
                <button type="button" class="lsp-doc-tab" data-target="tab-sertifikat">🏅 Cek Sertifikat & Download</button>
            </div>

            <!-- TAB 1: DAFTAR SHORTCODE & 1-CLICK COPY -->
            <div id="tab-shortcode" class="lsp-doc-panel active">
                <div class="lsp-doc-card">
                    <h2 style="margin-top:0; color:#0f172a; font-size:19px;">⚡ Referensi Lengkap Shortcode (Klik Tombol "Salin" untuk Menempelkan ke Halaman)</h2>
                    <p style="color:#475569; font-size:13.5px; margin-bottom:18px;">
                        <strong>Fitur Otomatis Tombol <code>[Lihat Semua ... &rarr;]</code>:</strong> Apabila jumlah total item di database lebih banyak daripada angka pada atribut <code>jumlah="..."</code>, maka di bagian bawah grid akan otomatis muncul tombol <strong>Lihat Semua Skema &rarr;</strong>, <strong>Lihat Semua Berita &rarr;</strong>, <strong>Lihat Semua Asesor &rarr;</strong>, <strong>Lihat Semua TUK &rarr;</strong>, atau <strong>Lihat Semua Download &rarr;</strong>.
                    </p>

                    <table class="widefat striped" style="border-radius: 8px; overflow: hidden; border: 1px solid #cbd5e1;">
                        <thead>
                            <tr style="background: #f1f5f9;">
                                <th style="width: 20%; font-weight: 700;">Modul / Fitur</th>
                                <th style="width: 38%; font-weight: 700;">Kode Shortcode</th>
                                <th style="width: 30%; font-weight: 700;">Penjelasan & Parameter</th>
                                <th style="width: 12%; text-align:center; font-weight: 700;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $shortcodes_list = array(
                                array(
                                    'modul' => '🎓 Katalog Skema (Acak 3)',
                                    'code'  => '[lsp_skema jumlah="3" acak="ya"]',
                                    'desc'  => 'Menampilkan 3 kartu Skema secara acak + tombol otomatis "Lihat Semua Skema →".',
                                ),
                                array(
                                    'modul' => '🎓 Katalog Skema (Semua)',
                                    'code'  => '[lsp_skema jumlah="-1"]',
                                    'desc'  => 'Menampilkan seluruh Skema Sertifikasi (bisa ditambah kemasan="KKNI").',
                                ),
                                array(
                                    'modul' => '📰 Berita & Artikel (Acak 3)',
                                    'code'  => '[lsp_berita jumlah="3" acak="ya"]',
                                    'desc'  => 'Menampilkan 3 kartu Berita dengan Auto-Cover Banner, tanggal, penulis, & waktu baca + tombol "Lihat Semua Berita →".',
                                ),
                                array(
                                    'modul' => '📰 Berita & Artikel (Terbaru)',
                                    'code'  => '[lsp_berita jumlah="6"]',
                                    'desc'  => 'Menampilkan 6 berita terbaru berdasarkan tanggal terbit (bisa ditambah kategori="pengumuman").',
                                ),
                                array(
                                    'modul' => '👨‍🏫 Direktori Asesor (Acak 3)',
                                    'code'  => '[lsp_asesor jumlah="3" acak="ya" hanya_aktif="ya"]',
                                    'desc'  => 'Menampilkan 3 kartu Asesor secara acak + tombol otomatis "Lihat Semua Asesor →".',
                                ),
                                array(
                                    'modul' => '👨‍🏫 Direktori Semua Asesor',
                                    'code'  => '[lsp_asesor jumlah="-1"]',
                                    'desc'  => 'Menampilkan seluruh Asesor Kompetensi lengkap dengan badge MET, status validitas, & bidang skema.',
                                ),
                                array(
                                    'modul' => '🏢 Tempat Uji Kompetensi (TUK)',
                                    'code'  => '[lsp_tuk]',
                                    'desc'  => 'Menampilkan akordion seluruh TUK beserta alamat, kontak PIC, & peta Google Maps interaktif. Mendukung jumlah="3" acak="ya".',
                                ),
                                array(
                                    'modul' => '🏅 Verifikasi / Cek Sertifikat',
                                    'code'  => '[cek_sertifikat]',
                                    'desc'  => 'Menampilkan kotak pencarian & verifikasi keabsahan sertifikat secara realtime (AJAX).',
                                ),
                                array(
                                    'modul' => '📥 Portal Download Lengkap',
                                    'code'  => '[lsp_download_area]',
                                    'desc'  => 'Menampilkan halaman Portal Unduhan lengkap dengan kotak pencarian dan tab kategori berkas.',
                                ),
                                array(
                                    'modul' => '📥 Widget Download Ringkas',
                                    'code'  => '[lsp_download jumlah="3" acak="ya" filter="tidak"]',
                                    'desc'  => 'Menampilkan 3 kartu dokumen unduhan tanpa bar pencarian + tombol otomatis "Lihat Semua Download →".',
                                ),
                                array(
                                    'modul' => '🧠 Pakar Rekomendasi Skema',
                                    'code'  => '[lsp_rekomendasi_skema jumlah_pertanyaan="10" jumlah_hasil="3"]',
                                    'desc'  => 'Menampilkan asisten pakar pintar (kuis 10 soal acak) untuk merekomendasikan 2-3 skema sertifikasi terbaik bagi calon asesi.',
                                ),
                            );
                            foreach ( $shortcodes_list as $sc ) :
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $sc['modul'] ); ?></strong></td>
                                <td><code style="font-size: 13px; background: #ecfdf5; color: #166534; padding: 5px 10px; border-radius: 5px; border: 1px solid #bbf7d0; display: inline-block; font-weight: 700;"><?php echo esc_html( $sc['code'] ); ?></code></td>
                                <td style="color:#475569; font-size:12.5px;"><?php echo esc_html( $sc['desc'] ); ?></td>
                                <td style="text-align:center;">
                                    <button type="button" class="button button-small lsp-copy-sc-btn" data-code="<?php echo esc_attr( $sc['code'] ); ?>">📋 Salin</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: ARSITEKTUR & TEMA -->
            <div id="tab-arsitektur" class="lsp-doc-panel">
                <div class="lsp-doc-card">
                    <h2 style="margin-top:0;">🏗️ Arsitektur Pemisahan Tema & Plugin</h2>
                    <p>Sistem website ini memisahkan <strong>Tampilan Visual (Tema)</strong> dan <strong>Data Inti LSP (Plugin)</strong> agar data tetap aman 100% di masa depan:</p>
                    <ul style="line-height: 1.8; list-style: disc; padding-left: 22px;">
                        <li><strong>Tema <code>lspkomunikasi</code></strong>: Mengatur tampilan header, dropdown sub-menu (dengan indikator panah otomatis saat di-<em>hover</em>), halaman Profil LSP, serta kartu Berita/Artikel dengan fitur <strong>Auto-Cover Banner</strong>.</li>
                        <li><strong>Plugin <code>lsp-core-management</code></strong>: Menyimpan & mengelola 4 modul utama: <strong>Skema Sertifikasi</strong>, <strong>TUK + Google Maps</strong>, <strong>User Role Asesor</strong>, dan <strong>Data & Cek Sertifikat</strong>.</li>
                        <li><strong>Plugin <code>lsp-download-manager</code></strong>: Menyimpan & mengelola <strong>Portal Unduhan Dokumen</strong> beserta statistik jumlah unduhan.</li>
                    </ul>
                    <hr style="margin: 20px 0;">
                    <h3>🎨 Cara Kerja Auto-Cover Banner (Sampul Gambar Otomatis)</h3>
                    <p>Saat Anda menerbitkan <strong>Berita (Post)</strong> atau <strong>Skema Sertifikasi</strong> tanpa mengunggah <em>Featured Image (Gambar Unggulan)</em>, sistem secara otomatis menghasilkan gambar fisik <code>.jpg</code> (800&times;600 px) berisi Judul, Kategori/Kode Skema, dan Level menggunakan pustaka grafis server dan menyimpannya ke Media Library. Jika Anda mengunggah foto sendiri, sistem akan menampilkan foto yang Anda unggah.</p>
                </div>
            </div>

            <!-- TAB 3: SKEMA & TUK -->
            <div id="tab-skema" class="lsp-doc-panel">
                <div class="lsp-doc-card">
                    <h2 style="margin-top:0;">🎓 Panduan Mengelola Skema Sertifikasi & TUK</h2>
                    <h3>1. Menambah Skema Sertifikasi (<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=skema' ) ); ?>">Buka Menu Skema &rarr;</a>)</h3>
                    <ul style="line-height: 1.8; list-style: disc; padding-left: 22px;">
                        <li><strong>Level Skema (1–9):</strong> Isi angka <code>1</code> s/d <code>9</code> pada kolom Level Skema. Pada kartu skema akan tampil bersih sebagai <code>Level: [Angka]</code>.</li>
                        <li><strong>Daftar Unit Kompetensi:</strong> Tulis satu unit per baris dengan format <code>KODE_UNIT | Judul Unit Kompetensi</code> (contoh: <code>M.70PRO00.001.2 | Menyusun Rencana Kehumasan</code>). Sistem otomatis mengubahnya menjadi tabel rapi di halaman detail skema.</li>
                    </ul>
                    <hr style="margin: 20px 0;">
                    <h3>2. Menambah Tempat Uji Kompetensi & Peta Google Maps (<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=tuk' ) ); ?>">Buka Menu TUK &rarr;</a>)</h3>
                    <ul style="line-height: 1.8; list-style: disc; padding-left: 22px;">
                        <li><strong>Menampilkan Peta Interaktif:</strong> Buka lokasi di Google Maps &rarr; klik <strong>Bagikan (Share)</strong> &rarr; pilih tab <strong>Sematkan Peta (Embed a map)</strong> &rarr; klik <strong>Salin HTML</strong> (kode <code>&lt;iframe src="..."&gt;</code>) lalu tempelkan ke kolom <strong>Peta Lokasi Google Maps</strong>.</li>
                        <li><strong>Alternatif Link Maps:</strong> Anda juga dapat menempelkan link URL Google Maps biasa, dan sistem otomatis menampilkan tombol <em>"🗺️ Buka Lokasi di Google Maps"</em>.</li>
                    </ul>
                </div>
            </div>

            <!-- TAB 4: AKUN & ROLE ASESOR -->
            <div id="tab-asesor" class="lsp-doc-panel">
                <div class="lsp-doc-card">
                    <h2 style="margin-top:0;">👨‍🏫 Panduan Mengelola Akun Asesor (<a href="<?php echo esc_url( admin_url( 'admin.php?page=lsp-asesor-directory' ) ); ?>">Buka Direktori Asesor &rarr;</a>)</h2>
                    <p>Asesor bukan sekadar daftar statis, melainkan <strong>User WordPress</strong> dengan Role <strong><code>Asesor LSP (asesor)</code></strong>.</p>
                    <ul style="line-height: 1.8; list-style: disc; padding-left: 22px;">
                        <li><strong>Menambah Asesor Baru:</strong> Buka <strong>Users &rarr; Add New User</strong>, pilih Role <strong>Asesor LSP</strong>, lalu isi Foto Profil Asesor, Nomor MET, Tanggal Terbit Sertifikat Asesor, dan centang Bidang/Skema Keahlian.</li>
                        <li><strong>Perhitungan Masa Berlaku MET (+3 Tahun):</strong> Sistem otomatis menghitung tanggal kadaluarsa 3 tahun dari tanggal terbit dan menampilkan indikator <strong>🟢 AKTIF & VALID</strong> atau <strong>⚠️ KADALUARSA</strong>.</li>
                        <li><strong>Alur Menulis Artikel oleh Asesor:</strong> Asesor dapat login ke WP-Admin dan menulis artikel di menu <strong>Posts</strong>, namun tombol yang tersedia adalah <strong>"Submit for Review (Ajukan Peninjauan)"</strong>. Artikel baru akan tampil di publik setelah disetujui/di-<em>publish</em> oleh Administrator atau Editor.</li>
                    </ul>
                </div>
            </div>

            <!-- TAB 5: CEK SERTIFIKAT & DOWNLOAD -->
            <div id="tab-sertifikat" class="lsp-doc-panel">
                <div class="lsp-doc-card">
                    <h2 style="margin-top:0;">🏅 Panduan Data Sertifikat, Import CSV, & Portal Download</h2>
                    <h3>1. Data Sertifikat & Import CSV (<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=data_sertifikat&page=lsp-cert-import' ) ); ?>">Buka Import CSV &rarr;</a>)</h3>
                    <ul style="line-height: 1.8; list-style: disc; padding-left: 22px;">
                        <li>Anda dapat mengunggah ratusan data pemegang sertifikat sekaligus menggunakan file <code>.csv</code> dengan 5 kolom: <code>Nama Pemegang, Nomor Sertifikat, Nomor Registrasi, Skema Kompetensi, Tanggal Terbit (YYYY-MM-DD)</code>.</li>
                        <li>Masa berlaku sertifikat dihitung otomatis <strong>3 tahun</strong> sejak tanggal terbit.</li>
                        <li>Untuk mengatur Nomor WhatsApp Admin & Link Pendaftaran Asesi, buka menu <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=skema&page=lsp-core-settings' ) ); ?>"><strong>Skema Sertifikasi &rarr; Pengaturan LSP</strong></a>.</li>
                    </ul>
                    <hr style="margin: 20px 0;">
                    <h3>2. Portal Download Berkas (<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lsp_download' ) ); ?>">Buka Download Berkas &rarr;</a>)</h3>
                    <ul style="line-height: 1.8; list-style: disc; padding-left: 22px;">
                        <li>Dapat mengunggah file dari komputer atau menempelkan link <strong>Google Drive</strong> (otomatis diubah menjadi <em>direct download link</em>).</li>
                        <li>Setiap klik unduhan dicatat secara <em>realtime</em> dan laporannya dapat dilihat/diekspor ke CSV melalui menu <strong>Download Berkas &rarr; Laporan Statistik</strong>.</li>
                    </ul>
                </div>
            </div>
        </div>

        <style>
            .lsp-doc-tab {
                background: #fff;
                border: 1px solid #cbd5e1;
                padding: 10px 18px;
                border-radius: 8px;
                font-weight: 700;
                font-size: 13.5px;
                color: #334155;
                cursor: pointer;
                transition: all 0.2s ease;
            }
            .lsp-doc-tab:hover {
                border-color: #429f46;
                color: #429f46;
            }
            .lsp-doc-tab.active {
                background: #429f46;
                border-color: #429f46;
                color: #fff;
                box-shadow: 0 4px 10px rgba(66,159,70,0.2);
            }
            .lsp-doc-panel {
                display: none;
            }
            .lsp-doc-panel.active {
                display: block;
            }
            .lsp-doc-card {
                background: #fff;
                border: 1px solid #cbd5e1;
                border-radius: 12px;
                padding: 24px 28px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            }
            @media print {
                #adminmenumain, #wpadminbar, .lsp-doc-tabs { display: none !important; }
                #wpcontent { margin-left: 0 !important; }
                .lsp-doc-panel { display: block !important; margin-bottom: 20px; }
            }
        </style>
        <script>
        jQuery(document).ready(function($){
            $('.lsp-doc-tab').on('click', function(){
                $('.lsp-doc-tab').removeClass('active');
                $(this).addClass('active');
                var target = $(this).data('target');
                $('.lsp-doc-panel').removeClass('active');
                $('#' + target).addClass('active');
            });
            $('.lsp-copy-sc-btn').on('click', function(){
                var btn = $(this);
                var code = btn.attr('data-code');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(code);
                } else {
                    var temp = $('<input>');
                    $('body').append(temp);
                    temp.val(code).select();
                    document.execCommand('copy');
                    temp.remove();
                }
                var orig = btn.text();
                btn.text('✅ Tersalin!').css({'background':'#dcfce7','color':'#166534','border-color':'#86efac'});
                setTimeout(function(){
                    btn.text(orig).attr('style', '');
                }, 1800);
            });
        });
        </script>
        <?php
    }

    /**
     * Halaman Admin: Manajemen & Daftar User Asesor LSP (Beserta Migrasi 1-Klik dari CPT Lama jika ada)
     */
    public function render_asesor_admin_page() {
        if ( ! current_user_can( 'list_users' ) ) return;

        $notice = '';
        // Handler migrasi otomatis dari CPT 'asesor' lama ke WP User Role 'asesor'
        if ( isset( $_POST['lsp_migrate_old_asesor'] ) && check_admin_referer( 'lsp_migrate_asesor_action', 'lsp_migrate_asesor_nonce' ) ) {
            $old_posts = get_posts( array(
                'post_type'      => 'asesor',
                'posts_per_page' => -1,
                'post_status'    => 'any',
            ) );
            $migrated = 0;
            foreach ( $old_posts as $op ) {
                $name      = trim( $op->post_title );
                $nomor_met = get_post_meta( $op->ID, 'nomor_met', true );
                $thumb_url = get_the_post_thumbnail_url( $op->ID, 'full' );
                $username  = sanitize_user( strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $name ) ) );
                if ( empty( $username ) ) {
                    $username = 'asesor' . $op->ID;
                }
                if ( username_exists( $username ) ) {
                    $username .= '_' . $op->ID;
                }
                $email = $username . '@' . parse_url( home_url(), PHP_URL_HOST );
                if ( ! is_email( $email ) || email_exists( $email ) ) {
                    $email = $username . '@lspkomunikasi.local';
                }

                $new_user_id = wp_insert_user( array(
                    'user_login'   => $username,
                    'user_pass'    => wp_generate_password( 12, true ),
                    'user_email'   => $email,
                    'display_name' => $name,
                    'first_name'   => $name,
                    'role'         => 'asesor',
                ) );

                if ( ! is_wp_error( $new_user_id ) ) {
                    if ( $nomor_met ) update_user_meta( $new_user_id, 'nomor_met', $nomor_met );
                    if ( $thumb_url ) update_user_meta( $new_user_id, 'foto_asesor', $thumb_url );
                    if ( ! empty( $op->post_content ) ) update_user_meta( $new_user_id, 'deskripsi_asesor', wp_kses_post( $op->post_content ) );
                    wp_delete_post( $op->ID, true );
                    $migrated++;
                }
            }
            $notice = '<div class="updated notice is-dismissible"><p>Berhasil memigrasikan <strong>' . intval( $migrated ) . '</strong> data Asesor lama menjadi akun User dengan Role <strong>Asesor LSP</strong>!</p></div>';
        }

        $asesor_users = get_users( array(
            'role'    => 'asesor',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ) );

        // Cek apakah masih ada data di post_type 'asesor' lama
        global $wpdb;
        $old_cpt_count = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'asesor' AND post_status = 'publish'" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e( 'Direktori & Manajemen Asesor LSP (User Role)', 'lsp-core-management' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" class="page-title-action"><?php _e( '+ Tambah User Asesor Baru', 'lsp-core-management' ); ?></a>
            <a href="<?php echo esc_url( admin_url( 'users.php?role=asesor' ) ); ?>" class="page-title-action"><?php _e( 'Buka Tabel Users WordPress', 'lsp-core-management' ); ?></a>
            <hr class="wp-header-end">

            <?php echo $notice; ?>

            <div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #0284c7; padding:15px 20px; border-radius:4px; margin:15px 0;">
                <strong>ℹ️ Informasi Hak Akses Role Asesor LSP:</strong>
                <p style="margin:6px 0 0; color:#444;">
                    Setiap pengguna dengan role <strong>Asesor LSP (<code>asesor</code>)</strong> dapat login ke dashboard, mengunggah gambar, dan menulis artikel/berita. Namun setiap tulisan yang mereka kirimkan akan berstatus <strong>Pending Review (Menunggu Persetujuan)</strong> dan harus disetujui (Publish) oleh <strong>Administrator</strong> sebelum tampil di website.
                </p>
            </div>

            <?php if ( $old_cpt_count > 0 ) : ?>
                <div style="background:#fffbe6; border:1px solid #ffe58f; padding:15px 20px; border-radius:6px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong style="color:#d46b08;">🔄 Ditemukan <?php echo intval( $old_cpt_count ); ?> Data Asesor Lama (Custom Post Type):</strong>
                        <p style="margin:4px 0 0; font-size:13px;">Klik tombol di samping untuk mengonversi data Asesor lama menjadi akun <strong>User WordPress (Role: Asesor LSP)</strong> secara otomatis beserta foto dan nomor MET-nya.</p>
                    </div>
                    <form method="POST">
                        <?php wp_nonce_field( 'lsp_migrate_asesor_action', 'lsp_migrate_asesor_nonce' ); ?>
                        <button type="submit" name="lsp_migrate_old_asesor" class="button button-primary">Migrasikan ke User Role Asesor Sekarang</button>
                    </form>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped users" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 70px;">Foto</th>
                        <th>Nama Asesor &amp; Email</th>
                        <th>Nomor MET</th>
                        <th>Tgl Terbit &amp; Masa Berlaku (3 Thn)</th>
                        <th>Bidang Keahlian</th>
                        <th>Sosmed &amp; Artikel</th>
                        <th style="width: 110px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $asesor_users ) ) : ?>
                        <?php foreach ( $asesor_users as $u ) :
                            $foto     = get_user_meta( $u->ID, 'foto_asesor', true ) ?: get_avatar_url( $u->ID, array( 'size' => 96 ) );
                            $met      = get_user_meta( $u->ID, 'nomor_met', true );
                            $tgl_met  = get_user_meta( $u->ID, 'tanggal_terbit_met', true );
                            $bidang   = $this->get_asesor_all_bidang( $u->ID );
                            $post_cnt = count_user_posts( $u->ID, 'post', false );
                            $edit_url = get_edit_user_link( $u->ID );
                        ?>
                            <tr>
                                <td>
                                    <img src="<?php echo esc_url( $foto ); ?>" style="width:48px; height:48px; border-radius:50%; object-fit:cover; border:1px solid #ddd;" />
                                </td>
                                <td>
                                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $u->display_name ); ?></a></strong><br>
                                    <small style="color:#666;"><?php echo esc_html( $u->user_email ); ?></small>
                                </td>
                                <td>
                                    <?php echo $met ? '<code><strong>' . esc_html( $met ) . '</strong></code>' : '<span style="color:#aaa;">Belum diisi</span>'; ?>
                                </td>
                                <td>
                                    <?php
                                    if ( ! empty( $tgl_met ) ) {
                                        $t_terbit = strtotime( $tgl_met );
                                        $t_exp    = strtotime( '+3 years', $t_terbit );
                                        $expired  = time() > $t_exp;
                                        if ( $expired ) {
                                            echo '<span style="display:inline-block; padding:2px 8px; background:#ff4d4f; color:#fff; border-radius:10px; font-weight:700; font-size:11px;">⚠️ KADALUARSA</span><br>';
                                        } else {
                                            echo '<span style="display:inline-block; padding:2px 8px; background:#52c41a; color:#fff; border-radius:10px; font-weight:700; font-size:11px;">🟢 AKTIF &amp; VALID</span><br>';
                                        }
                                        echo '<small>Terbit: ' . esc_html( $this->format_indo_date( $t_terbit ) ) . '<br>Berakhir: <strong>' . esc_html( $this->format_indo_date( $t_exp ) ) . '</strong></small>';
                                    } else {
                                        echo '<span style="color:#999;">Tanggal belum diisi</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ( ! empty( $bidang ) ) {
                                        foreach ( $bidang as $b ) {
                                            echo '<span style="display:inline-block; background:#fff8f0; color:#72161a; border:1px solid #f89b29; padding:2px 8px; border-radius:10px; font-size:11px; margin:2px;">' . esc_html( $b ) . '</span>';
                                        }
                                    } else {
                                        echo '<span style="color:#aaa;">—</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <small>Artikel Ditulis: <strong><?php echo intval( $post_cnt ); ?></strong></small>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>#lsp-asesor-profile-section" class="button button-small">Edit Data Asesor</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:25px;">Belum ada akun pengguna dengan Role <strong>Asesor LSP</strong>. Silakan klik tombol <strong>+ Tambah User Asesor Baru</strong> di atas dan pilih Role <em>Asesor LSP</em>.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Halaman Pengaturan Core LSP (Nomor WhatsApp & URL Pendaftaran)
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $msg = '';
        if ( isset( $_POST['lsp_core_save_settings'] ) && check_admin_referer( 'lsp_core_settings_action', 'lsp_core_settings_nonce' ) ) {
            update_option( 'lsp_core_wa_number', sanitize_text_field( $_POST['lsp_core_wa_number'] ) );
            update_option( 'lsp_core_register_url', esc_url_raw( $_POST['lsp_core_register_url'] ) );
            $msg = '<div class="updated notice is-dismissible"><p>Pengaturan LSP berhasil disimpan.</p></div>';
        }

        $wa_number = get_option( 'lsp_core_wa_number', '6281328430600' );
        $reg_url   = get_option( 'lsp_core_register_url', '#' );
        ?>
        <div class="wrap">
            <h1><?php _e( 'Pengaturan Core LSP', 'lsp-core-management' ); ?></h1>
            <?php echo $msg; ?>
            <form method="POST" style="background:#fff; padding:25px; border:1px solid #ccd0d4; border-radius:8px; max-width:650px; margin-top:20px;">
                <?php wp_nonce_field( 'lsp_core_settings_action', 'lsp_core_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="lsp_core_wa_number">Nomor WhatsApp Admin (Format 628...)</label></th>
                        <td>
                            <input type="text" id="lsp_core_wa_number" name="lsp_core_wa_number" value="<?php echo esc_attr( $wa_number ); ?>" class="regular-text" placeholder="6281328430600" />
                            <p class="description">Digunakan pada tombol konsultasi WhatsApp di halaman Katalog &amp; Rincian Skema Sertifikasi.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="lsp_core_register_url">URL Tautan Tombol "Daftar Sekarang"</label></th>
                        <td>
                            <input type="url" id="lsp_core_register_url" name="lsp_core_register_url" value="<?php echo esc_attr( $reg_url ); ?>" class="regular-text" placeholder="https://..." />
                            <p class="description">Tautan tujuan saat pengunjung mengklik tombol "Daftar Sekarang" pada halaman rincian Skema Sertifikasi.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="lsp_core_save_settings" class="button button-primary" value="Simpan Pengaturan" />
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Halaman Import CSV Data Sertifikat
     */
    public function handle_csv_template_download() {
        if ( isset( $_GET['lsp_cert_download_csv_template'] ) && current_user_can( 'manage_options' ) ) {
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=template_import_sertifikat_lsp.csv' );
            $output = fopen( 'php://output', 'w' );
            fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
            fputcsv( $output, array( 'kode_blanko', 'nama_pemegang', 'no_registrasi', 'skema_sertifikasi', 'tanggal_terbit' ) );
            fputcsv( $output, array( 'K24.01.0001', 'Ahmad Fauzi, S.Kom.', 'KOM.010.00001 2024', 'Desainer Grafis Muda', '2024-05-15' ) );
            fputcsv( $output, array( 'K24.01.0002', 'Siti Rahmawati, M.M.', 'KOM.010.00002 2024', 'Digital Marketing', '2023-11-20' ) );
            fclose( $output );
            exit;
        }
    }

    /**
     * Helper sentral untuk mencari ID sertifikat berdasarkan meta key dan value (exact match).
     * Digunakan bersama oleh save_cert_meta, ajax_verify_cert, dan CSV Import.
     *
     * @param string $meta_key 'kode_blanko' atau 'no_registrasi'
     * @param string $value Nilai nomor sertifikat yang telah dinormalisasi
     * @param int    $exclude_id ID post yang dikecualikan (opsional)
     * @return int[] Daftar ID post yang cocok
     */
    public static function find_certs_by_meta( $meta_key, $value, $exclude_id = 0 ) {
        if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
            return array();
        }

        $query_args = array(
            'post_type'              => 'data_sertifikat',
            'post_status'            => 'any',
            'posts_per_page'         => 3, // Ambil hingga 3 record untuk deteksi duplikasi & ambiguitas
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'     => $meta_key,
                    'value'   => $value,
                    'compare' => '=',
                ),
            ),
        );

        if ( $exclude_id > 0 ) {
            $query_args['post__not_in'] = array( (int) $exclude_id );
        }

        $q = new WP_Query( $query_args );
        return ! empty( $q->posts ) ? array_map( 'intval', $q->posts ) : array();
    }

    /**
     * Validasi Keamanan File Upload CSV
     *
     * @param array $file_info Elemen dari $_FILES
     * @return array array( 'valid' => bool, 'error' => string )
     */
    public static function validate_csv_upload_file( $file_info ) {
        if ( ! is_array( $file_info ) || ! isset( $file_info['error'] ) ) {
            return array( 'valid' => false, 'error' => __( 'File upload tidak ditemukan atau berkas rusak.', 'lsp-core-management' ) );
        }

        if ( UPLOAD_ERR_OK !== $file_info['error'] ) {
            switch ( $file_info['error'] ) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return array( 'valid' => false, 'error' => __( 'Ukuran file melebihi batas maksimal konfigurasi server.', 'lsp-core-management' ) );
                case UPLOAD_ERR_NO_FILE:
                    return array( 'valid' => false, 'error' => __( 'Silakan pilih file CSV terlebih dahulu.', 'lsp-core-management' ) );
                default:
                    return array( 'valid' => false, 'error' => sprintf( __( 'Gagal mengunggah file (Kode error upload: %d).', 'lsp-core-management' ), intval( $file_info['error'] ) ) );
            }
        }

        $tmp_path = isset( $file_info['tmp_name'] ) ? (string) $file_info['tmp_name'] : '';
        if ( '' === $tmp_path || ! is_readable( $tmp_path ) ) {
            return array( 'valid' => false, 'error' => __( 'File sementara tidak dapat dibaca oleh server.', 'lsp-core-management' ) );
        }

        // Batas ukuran 5 MB (5 * 1024 * 1024 bytes) - Cukup untuk >50.000 baris sertifikat
        $max_size = 5 * 1024 * 1024;
        $size = isset( $file_info['size'] ) ? intval( $file_info['size'] ) : filesize( $tmp_path );
        if ( $size > $max_size ) {
            return array( 'valid' => false, 'error' => __( 'Ukuran file melebihi batas maksimal 5 MB.', 'lsp-core-management' ) );
        }

        // Validasi ekstensi nama file
        $name = isset( $file_info['name'] ) ? (string) $file_info['name'] : '';
        $ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
            return array( 'valid' => false, 'error' => __( 'Format file tidak diizinkan. Hanya file berekstensi .csv atau .txt yang diperbolehkan.', 'lsp-core-management' ) );
        }

        // Validasi keamanan isi file: larang executable, PHP tags, inline scripts
        $handle = fopen( $tmp_path, 'r' );
        if ( $handle ) {
            $sample = fread( $handle, 4096 );
            fclose( $handle );
            if ( false !== $sample ) {
                if ( preg_match( '/(<\?php|<\?=|<script\b|<\%|\bdata:text\/html\b)/i', $sample ) ) {
                    return array( 'valid' => false, 'error' => __( 'Peringatan Keamanan: File terdeteksi mengandung tag PHP atau skrip berbahaya.', 'lsp-core-management' ) );
                }
            }
        }

        return array( 'valid' => true, 'error' => '' );
    }

    /**
     * Deteksi Otomatis Pemisah Kolom CSV (Koma vs Titik Koma)
     *
     * @param string $file_path Path file CSV
     * @return string ',' atau ';'
     */
    public static function detect_csv_delimiter( $file_path ) {
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return ',';
        }
        $line = fgets( $handle );
        fclose( $handle );

        if ( false === $line ) {
            return ',';
        }

        // Hapus UTF-8 BOM jika ada
        if ( substr( $line, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $line = substr( $line, 3 );
        }

        $semicolons = substr_count( $line, ';' );
        $commas     = substr_count( $line, ',' );

        return ( $semicolons > $commas ) ? ';' : ',';
    }

    /**
     * Parsing dan Validasi Preflight Berkas CSV (Tanpa Menulis ke Database)
     *
     * @param string $file_path Path berkas CSV
     * @return array Hasil parsing dan validasi per baris
     */
    public static function parse_and_validate_csv_data( $file_path ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return array(
                'success' => false,
                'error'   => __( 'Berkas CSV tidak ditemukan atau tidak dapat dibaca.', 'lsp-core-management' ),
            );
        }

        $delimiter = self::detect_csv_delimiter( $file_path );
        $handle    = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return array(
                'success' => false,
                'error'   => __( 'Gagal membuka berkas CSV.', 'lsp-core-management' ),
            );
        }

        // Baca header CSV
        $raw_header = fgetcsv( $handle, 2048, $delimiter );
        if ( false === $raw_header || empty( $raw_header ) ) {
            fclose( $handle );
            return array(
                'success' => false,
                'error'   => __( 'Berkas CSV kosong atau tidak memiliki baris header.', 'lsp-core-management' ),
            );
        }

        // Hapus BOM pada nama kolom pertama jika ada
        if ( isset( $raw_header[0] ) && substr( $raw_header[0], 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_header[0] = substr( $raw_header[0], 3 );
        }

        if ( count( $raw_header ) < 5 ) {
            fclose( $handle );
            return array(
                'success' => false,
                'error'   => __( 'Header CSV tidak valid. Berkas harus memiliki minimal 5 kolom: kode_blanko, nama_pemegang, no_registrasi, skema_sertifikasi, tanggal_terbit.', 'lsp-core-management' ),
            );
        }

        // Normalisasi nama-nama kolom header
        $header_map = array();
        foreach ( $raw_header as $idx => $col_name ) {
            $norm = strtolower( trim( preg_replace( '/[^a-zA-Z0-9_]/', '_', (string) $col_name ) ) );
            $header_map[ $norm ] = $idx;
        }

        $find_idx = function( $aliases, $default_idx ) use ( $header_map ) {
            foreach ( $aliases as $alias ) {
                if ( isset( $header_map[ $alias ] ) ) {
                    return $header_map[ $alias ];
                }
            }
            return $default_idx;
        };

        $col_blanko = $find_idx( array( 'kode_blanko', 'nomor_blanko', 'no_blanko', 'blanko' ), 0 );
        $col_nama   = $find_idx( array( 'nama_pemegang', 'nama', 'nama_lengkap', 'asesi' ), 1 );
        $col_reg    = $find_idx( array( 'no_registrasi', 'nomor_registrasi', 'registrasi', 'no_reg' ), 2 );
        $col_skema  = $find_idx( array( 'skema_sertifikasi', 'skema', 'skema_kompetensi', 'judul_skema' ), 3 );
        $col_tgl    = $find_idx( array( 'tanggal_terbit', 'tgl_terbit', 'tanggal', 'tgl' ), 4 );

        $rows_data     = array();
        $seen_blanko   = array(); // blanko => row_num
        $seen_reg      = array(); // reg => row_num
        $row_number    = 1;

        $valid_inserts = 0;
        $valid_updates = 0;
        $skipped_count = 0;

        while ( false !== ( $raw_row = fgetcsv( $handle, 4096, $delimiter ) ) ) {
            $row_number++;

            // Lewati baris kosong
            if ( empty( $raw_row ) || ( 1 === count( $raw_row ) && null === $raw_row[0] ) ) {
                continue;
            }
            $all_empty = true;
            foreach ( $raw_row as $cell ) {
                if ( '' !== trim( (string) $cell ) ) {
                    $all_empty = false;
                    break;
                }
            }
            if ( $all_empty ) {
                continue;
            }

            $row_errors = array();

            // Pengecekan jumlah kolom data pada baris ini
            if ( count( $raw_row ) < 5 ) {
                $row_errors[] = __( 'Jumlah kolom pada baris ini tidak lengkap (kurang dari 5 kolom).', 'lsp-core-management' );
            }

            $raw_b = isset( $raw_row[ $col_blanko ] ) ? $raw_row[ $col_blanko ] : '';
            $raw_n = isset( $raw_row[ $col_nama ] ) ? $raw_row[ $col_nama ] : '';
            $raw_r = isset( $raw_row[ $col_reg ] ) ? $raw_row[ $col_reg ] : '';
            $raw_s = isset( $raw_row[ $col_skema ] ) ? $raw_row[ $col_skema ] : '';
            $raw_t = isset( $raw_row[ $col_tgl ] ) ? $raw_row[ $col_tgl ] : '';

            // 1. Normalisasi Terpusat
            $blanko = self::normalize_cert_number( $raw_b );
            $reg    = self::normalize_cert_number( $raw_r );
            $nama   = sanitize_text_field( trim( (string) $raw_n ) );
            $skema  = sanitize_text_field( trim( (string) $raw_s ) );
            $tgl    = sanitize_text_field( trim( (string) $raw_t ) );

            // 2. Validasi Kehadiran & Panjang Field
            if ( '' === $blanko ) {
                $row_errors[] = __( 'Kode / Nomor Blanko kosong.', 'lsp-core-management' );
            } elseif ( mb_strlen( $blanko, 'UTF-8' ) < 3 || mb_strlen( $blanko, 'UTF-8' ) > 60 ) {
                $row_errors[] = __( 'Panjang Kode Blanko harus antara 3 hingga 60 karakter.', 'lsp-core-management' );
            }

            if ( '' === $reg ) {
                $row_errors[] = __( 'Nomor Registrasi kosong.', 'lsp-core-management' );
            } elseif ( mb_strlen( $reg, 'UTF-8' ) < 3 || mb_strlen( $reg, 'UTF-8' ) > 60 ) {
                $row_errors[] = __( 'Panjang Nomor Registrasi harus antara 3 hingga 60 karakter.', 'lsp-core-management' );
            }

            if ( '' === $nama ) {
                $row_errors[] = __( 'Nama Pemegang Sertifikat (Asesi) kosong.', 'lsp-core-management' );
            } elseif ( mb_strlen( $nama, 'UTF-8' ) < 3 || mb_strlen( $nama, 'UTF-8' ) > 150 ) {
                $row_errors[] = __( 'Panjang Nama Pemegang harus antara 3 hingga 150 karakter.', 'lsp-core-management' );
            }

            if ( '' === $skema ) {
                $row_errors[] = __( 'Skema Sertifikasi kosong.', 'lsp-core-management' );
            } elseif ( mb_strlen( $skema, 'UTF-8' ) < 3 || mb_strlen( $skema, 'UTF-8' ) > 255 ) {
                $row_errors[] = __( 'Panjang Skema Sertifikasi harus antara 3 hingga 255 karakter.', 'lsp-core-management' );
            }

            // 3. Validasi Format & Keabsahan Tanggal Kalender (YYYY-MM-DD)
            if ( '' === $tgl ) {
                $row_errors[] = __( 'Tanggal Terbit kosong.', 'lsp-core-management' );
            } else {
                $d = DateTime::createFromFormat( 'Y-m-d', $tgl );
                if ( ! $d || $d->format( 'Y-m-d' ) !== $tgl ) {
                    $row_errors[] = sprintf( __( 'Format Tanggal Terbit "%s" tidak valid. Gunakan format YYYY-MM-DD (contoh: 2024-05-15).', 'lsp-core-management' ), $tgl );
                }
            }

            // 4. Deteksi Duplikasi Antarbaris di Dalam File CSV yang Sama (Intra-CSV Duplicates)
            if ( '' !== $blanko ) {
                if ( isset( $seen_blanko[ $blanko ] ) ) {
                    $row_errors[] = sprintf( __( 'Duplikasi intra-file: Nomor Blanko "%s" kembar dengan Baris %d.', 'lsp-core-management' ), $blanko, $seen_blanko[ $blanko ] );
                }
                if ( isset( $seen_reg[ $blanko ] ) ) {
                    $row_errors[] = sprintf( __( 'Tabrakan intra-file: Nomor Blanko "%s" sama dengan Nomor Registrasi pada Baris %d.', 'lsp-core-management' ), $blanko, $seen_reg[ $blanko ] );
                }
            }

            if ( '' !== $reg ) {
                if ( isset( $seen_reg[ $reg ] ) ) {
                    $row_errors[] = sprintf( __( 'Duplikasi intra-file: Nomor Registrasi "%s" kembar dengan Baris %d.', 'lsp-core-management' ), $reg, $seen_reg[ $reg ] );
                }
                if ( isset( $seen_blanko[ $reg ] ) ) {
                    $row_errors[] = sprintf( __( 'Tabrakan intra-file: Nomor Registrasi "%s" sama dengan Nomor Blanko pada Baris %d.', 'lsp-core-management' ), $reg, $seen_blanko[ $reg ] );
                }
            }

            $action                 = 'INSERT';
            $target_id              = 0;
            $matched_pendaftaran_id = 0;

            // 5. Validasi Pengamanan: Peserta Wajib Terdaftar di Sistem Pendaftaran LSP
            if ( empty( $row_errors ) ) {
                $p_match = self::find_registered_pendaftaran( $nama, $skema );
                if ( ! $p_match ) {
                    $row_errors[] = sprintf(
                        __( 'Peserta "%s" tidak ditemukan dalam sistem pendaftaran LSP. Sertifikat ditolak untuk mencegah penerbitan tanpa berkas pendaftaran.', 'lsp-core-management' ),
                        $nama
                    );
                } else {
                    $matched_pendaftaran_id = intval( $p_match['id'] );
                }
            }

            // 6. Pemeriksaan terhadap Database & Resolusi Target Update
            if ( empty( $row_errors ) ) {
                $db_blanko = self::find_certs_by_meta( 'kode_blanko', $blanko );
                $db_reg    = self::find_certs_by_meta( 'no_registrasi', $reg );
                $x_blanko  = self::find_certs_by_meta( 'no_registrasi', $blanko );
                $x_reg     = self::find_certs_by_meta( 'kode_blanko', $reg );

                // Deteksi ambiguitas di database
                if ( count( $db_blanko ) > 1 || count( $db_reg ) > 1 ) {
                    $row_errors[] = __( 'Ambiguitas di database: Nomor terdaftar pada lebih dari satu sertifikat di database.', 'lsp-core-management' );
                }

                // Resolusi aksi
                if ( empty( $db_blanko ) && empty( $db_reg ) ) {
                    // Record baru -> Pastikan tidak ada tabrakan cross-field
                    if ( ! empty( $x_blanko ) ) {
                        $row_errors[] = sprintf( __( 'Tabrakan lintas kolom: Nomor Blanko "%s" bertabrakan dengan Nomor Registrasi pada sertifikat ID %d di database.', 'lsp-core-management' ), $blanko, $x_blanko[0] );
                    }
                    if ( ! empty( $x_reg ) ) {
                        $row_errors[] = sprintf( __( 'Tabrakan lintas kolom: Nomor Registrasi "%s" bertabrakan dengan Nomor Blanko pada sertifikat ID %d di database.', 'lsp-core-management' ), $reg, $x_reg[0] );
                    }
                    $action = 'INSERT';
                } elseif ( ! empty( $db_blanko ) && ! empty( $db_reg ) ) {
                    // Kedua nomor ada di database
                    if ( $db_blanko[0] === $db_reg[0] ) {
                        $action    = 'UPDATE';
                        $target_id = $db_blanko[0];
                    } else {
                        $row_errors[] = sprintf(
                            __( 'Konflik identitas: Nomor Blanko terdaftar pada sertifikat ID %d, sedangkan Nomor Registrasi terdaftar pada sertifikat ID %d.', 'lsp-core-management' ),
                            $db_blanko[0],
                            $db_reg[0]
                        );
                    }
                } elseif ( ! empty( $db_blanko ) && empty( $db_reg ) ) {
                    $target_id = $db_blanko[0];
                    $cur_reg   = get_post_meta( $target_id, 'no_registrasi', true );
                    if ( '' !== $cur_reg && $cur_reg !== $reg ) {
                        // Tolak perubahan identitas nomor registrasi otomatis jika record sudah memiliki nomor registrasi berbeda
                        $row_errors[] = sprintf(
                            __( 'Perubahan identitas ditolak: Sertifikat ID %d sudah memiliki Nomor Registrasi "%s" yang berbeda di database. Perubahan identitas nomor sertifikat harus dilakukan manual.', 'lsp-core-management' ),
                            $target_id,
                            esc_html( $cur_reg )
                        );
                    }
                    if ( ! empty( $x_reg ) && ! in_array( $target_id, $x_reg, true ) ) {
                        $row_errors[] = sprintf( __( 'Tabrakan lintas kolom: Nomor Registrasi "%s" bertabrakan dengan Nomor Blanko pada sertifikat ID %d di database.', 'lsp-core-management' ), $reg, $x_reg[0] );
                    }
                    $action = 'UPDATE';
                } elseif ( empty( $db_blanko ) && ! empty( $db_reg ) ) {
                    $target_id = $db_reg[0];
                    $cur_blanko = get_post_meta( $target_id, 'kode_blanko', true );
                    if ( '' !== $cur_blanko && $cur_blanko !== $blanko ) {
                        // Tolak perubahan identitas kode blanko otomatis jika record sudah memiliki nomor blanko berbeda
                        $row_errors[] = sprintf(
                            __( 'Perubahan identitas ditolak: Sertifikat ID %d sudah memiliki Nomor Blanko "%s" yang berbeda di database. Perubahan identitas nomor sertifikat harus dilakukan manual.', 'lsp-core-management' ),
                            $target_id,
                            esc_html( $cur_blanko )
                        );
                    }
                    if ( ! empty( $x_blanko ) && ! in_array( $target_id, $x_blanko, true ) ) {
                        $row_errors[] = sprintf( __( 'Tabrakan lintas kolom: Nomor Blanko "%s" bertabrakan dengan Nomor Registrasi pada sertifikat ID %d di database.', 'lsp-core-management' ), $blanko, $x_blanko[0] );
                    }
                    $action = 'UPDATE';
                }
            }

            if ( ! empty( $row_errors ) ) {
                $status = 'SKIPPED';
                $skipped_count++;
            } else {
                $status = 'VALID';
                if ( 'INSERT' === $action ) {
                    $valid_inserts++;
                } else {
                    $valid_updates++;
                }
                $seen_blanko[ $blanko ] = $row_number;
                $seen_reg[ $reg ]       = $row_number;
            }

            $rows_data[] = array(
                'row_num'           => $row_number,
                'kode_blanko'       => $blanko,
                'no_registrasi'     => $reg,
                'nama_pemegang'     => $nama,
                'skema_sertifikasi' => $skema,
                'tanggal_terbit'    => $tgl,
                'action'            => $action,
                'target_id'         => $target_id,
                'pendaftaran_id'    => $matched_pendaftaran_id,
                'status'            => $status,
                'errors'            => $row_errors,
            );
        }

        fclose( $handle );

        return array(
            'success'       => true,
            'total_rows'    => count( $rows_data ),
            'valid_inserts' => $valid_inserts,
            'valid_updates' => $valid_updates,
            'skipped_count' => $skipped_count,
            'rows'          => $rows_data,
        );
    }

    /**
     * Klaim Batch CSV secara Atomik untuk Mencegah Race Condition / Concurrency Replay
     *
     * Menggunakan mekanisme atomic lock berbasis WordPress options (MySQL UNIQUE constraint)
     * sehingga dari beberapa request paralel, HANYA TEPAT SATU request yang dapat mengklaim batch.
     *
     * @param string $trans_key Kunci transient batch
     * @return array|false Data baris batch jika klaim berhasil, false jika gagal / sudah diklaim
     */
    public static function claim_batch_transient( $trans_key ) {
        if ( empty( $trans_key ) || ! is_string( $trans_key ) ) {
            return false;
        }

        $lock_key = 'lsp_lock_' . md5( $trans_key );

        // 1. Dapatkan atomic lock via add_option() (MySQL UNIQUE constraint pada wp_options.option_name)
        // Jika request paralel lain sudah melakukan insert, add_option() akan mengembalikan false.
        $acquired = add_option( $lock_key, time(), '', 'no' );
        if ( ! $acquired ) {
            return false;
        }

        // 2. Ambil data batch dari transient
        $batch_rows = get_transient( $trans_key );

        // 3. Langsung hapus transient seketika agar tidak dapat dibaca lagi
        delete_transient( $trans_key );

        // 4. Lepaskan lock setelah transient dipastikan terhapus
        delete_option( $lock_key );

        return ( false !== $batch_rows && is_array( $batch_rows ) ) ? $batch_rows : false;
    }

    /**
     * Eksekusi Penyimpanan Batch Sertifikat ke Database
     *
     * @param array $rows Array baris yang telah divalidasi
     * @return array Rekapitulasi hasil eksekusi
     */
    public static function execute_csv_import_batch( $rows ) {
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array(
                'imported'       => 0,
                'updated'        => 0,
                'skipped'        => 0,
                'failed'         => 0,
                'failed_details' => array(),
            );
        }

        $imported       = 0;
        $updated        = 0;
        $skipped        = 0;
        $failed         = 0;
        $failed_details = array();

        foreach ( $rows as $row ) {
            if ( ! isset( $row['status'] ) || 'VALID' !== $row['status'] ) {
                $skipped++;
                continue;
            }

            $blanko     = $row['kode_blanko'];
            $reg        = $row['no_registrasi'];
            $nama       = $row['nama_pemegang'];
            $skema      = $row['skema_sertifikasi'];
            $tgl        = $row['tanggal_terbit'];
            $action     = isset( $row['action'] ) ? $row['action'] : 'INSERT';
            $auto_title = $blanko . ' - ' . $nama;

            if ( 'INSERT' === $action ) {
                // Validasi ulang terhadap database secara realtime tepat sebelum insert
                // Mencegah race condition jika ada sertifikat baru yang ditambahkan di DB setelah preview
                $recheck_b  = self::find_certs_by_meta( 'kode_blanko', $blanko );
                $recheck_r  = self::find_certs_by_meta( 'no_registrasi', $reg );
                $recheck_x1 = self::find_certs_by_meta( 'no_registrasi', $blanko );
                $recheck_x2 = self::find_certs_by_meta( 'kode_blanko', $reg );

                if ( ! empty( $recheck_b ) || ! empty( $recheck_r ) || ! empty( $recheck_x1 ) || ! empty( $recheck_x2 ) ) {
                    $failed++;
                    $failed_details[] = array(
                        'row'   => $row['row_num'],
                        'field' => 'conflict_post_preview',
                        'error' => __( 'Konflik database pasca-pratinjau: Nomor sertifikat telah didaftarkan di pangkalan data oleh proses lain sebelum konfirmasi eksekusi.', 'lsp-core-management' ),
                    );
                    continue;
                }

                $post_id = wp_insert_post( array(
                    'post_type'   => 'data_sertifikat',
                    'post_title'  => $auto_title,
                    'post_status' => 'publish',
                ), true );

                if ( is_wp_error( $post_id ) || empty( $post_id ) ) {
                    $failed++;
                    $failed_details[] = array(
                        'row'   => $row['row_num'],
                        'field' => 'database_insert',
                        'error' => is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'Gagal menambahkan post sertifikat baru.', 'lsp-core-management' ),
                    );
                    continue;
                }

                update_post_meta( $post_id, 'kode_blanko', $blanko );
                update_post_meta( $post_id, 'nama_pemegang', $nama );
                update_post_meta( $post_id, 'no_registrasi', $reg );
                update_post_meta( $post_id, 'skema_sertifikasi', $skema );
                update_post_meta( $post_id, 'tanggal_terbit', $tgl );
                if ( ! empty( $row['pendaftaran_id'] ) ) {
                    update_post_meta( $post_id, 'pendaftaran_id', intval( $row['pendaftaran_id'] ) );
                }

                $imported++;
            } elseif ( 'UPDATE' === $action ) {
                $target_id = isset( $row['target_id'] ) ? intval( $row['target_id'] ) : 0;
                if ( $target_id <= 0 || 'data_sertifikat' !== get_post_type( $target_id ) ) {
                    $failed++;
                    $failed_details[] = array(
                        'row'   => $row['row_num'],
                        'field' => 'database_update',
                        'error' => __( 'Target ID sertifikat untuk pembaruan tidak valid atau telah dihapus.', 'lsp-core-management' ),
                    );
                    continue;
                }

                $up_res = wp_update_post( array(
                    'ID'         => $target_id,
                    'post_title' => $auto_title,
                ), true );

                if ( is_wp_error( $up_res ) || empty( $up_res ) ) {
                    $failed++;
                    $failed_details[] = array(
                        'row'   => $row['row_num'],
                        'field' => 'database_update',
                        'error' => is_wp_error( $up_res ) ? $up_res->get_error_message() : __( 'Gagal memperbarui judul post sertifikat.', 'lsp-core-management' ),
                    );
                    continue;
                }

                update_post_meta( $target_id, 'kode_blanko', $blanko );
                update_post_meta( $target_id, 'nama_pemegang', $nama );
                update_post_meta( $target_id, 'no_registrasi', $reg );
                update_post_meta( $target_id, 'skema_sertifikasi', $skema );
                update_post_meta( $target_id, 'tanggal_terbit', $tgl );
                if ( ! empty( $row['pendaftaran_id'] ) ) {
                    update_post_meta( $target_id, 'pendaftaran_id', intval( $row['pendaftaran_id'] ) );
                }

                $updated++;
            }
        }

        return array(
            'imported'       => $imported,
            'updated'        => $updated,
            'skipped'        => $skipped,
            'failed'         => $failed,
            'failed_details' => $failed_details,
        );
    }

    /**
     * Tampilan Halaman Admin: Import CSV dengan Preflight Preview & Final Reporting
     */
    public function render_import_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Anda tidak memiliki hak akses yang cukup untuk mengelola data sertifikat.', 'lsp-core-management' ) );
        }

        $page_state    = 'UPLOAD_FORM'; // UPLOAD_FORM | PREVIEW_TABLE | FINAL_REPORT
        $error_message = '';
        $preflight     = null;
        $batch_token   = '';
        $final_report  = null;

        // TAHAP 2: Konfirmasi Eksekusi Import
        if ( isset( $_POST['lsp_cert_import_confirm_submit'] ) && check_admin_referer( 'lsp_cert_import_confirm_action', 'lsp_cert_import_confirm_nonce' ) ) {
            $token = isset( $_POST['lsp_batch_token'] ) ? sanitize_text_field( $_POST['lsp_batch_token'] ) : '';
            $trans_key = 'lsp_csv_batch_' . get_current_user_id() . '_' . $token;
            $batch_rows = self::claim_batch_transient( $trans_key );

            if ( false !== $batch_rows && is_array( $batch_rows ) ) {
                $final_report = self::execute_csv_import_batch( $batch_rows );
                $page_state   = 'FINAL_REPORT';
            } else {
                $error_message = __( 'Sesi validasi preview telah kedaluwarsa, sedang diproses di request lain, atau tidak valid. Silakan unggah kembali berkas CSV Anda.', 'lsp-core-management' );
                $page_state    = 'UPLOAD_FORM';
            }
        }
        // TAHAP 1: Unggah & Validasi Preflight
        elseif ( isset( $_POST['lsp_cert_import_submit'] ) && check_admin_referer( 'lsp_cert_import_action', 'lsp_cert_import_nonce' ) ) {
            if ( isset( $_FILES['csv_file'] ) ) {
                $val_upload = self::validate_csv_upload_file( $_FILES['csv_file'] );
                if ( ! $val_upload['valid'] ) {
                    $error_message = $val_upload['error'];
                } else {
                    $parsed = self::parse_and_validate_csv_data( $_FILES['csv_file']['tmp_name'] );
                    if ( ! $parsed['success'] ) {
                        $error_message = $parsed['error'];
                    } else {
                        $preflight   = $parsed;
                        $batch_token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( (string) mt_rand(), true ) );
                        set_transient( 'lsp_csv_batch_' . get_current_user_id() . '_' . $batch_token, $parsed['rows'], 900 );
                        $page_state  = 'PREVIEW_TABLE';
                    }
                }
            } else {
                $error_message = __( 'Silakan pilih berkas CSV terlebih dahulu.', 'lsp-core-management' );
            }
        }
        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:10px;">
                <span class="dashicons dashicons-database-import" style="font-size:32px; width:32px; height:32px; color:#72161a;"></span>
                <?php _e( 'Import Data Pemegang Sertifikat (CSV)', 'lsp-core-management' ); ?>
            </h1>
            <p><?php _e( 'Sistem import aman dengan validasi preflight, pendeteksi delimiter otomatis, normalisasi identitas, dan pencegahan duplikasi data.', 'lsp-core-management' ); ?></p>

            <?php if ( ! empty( $error_message ) ) : ?>
                <div class="notice notice-error is-dismissible" style="border-left-color:#dc2626;">
                    <p><strong>⚠️ <?php _e( 'Gagal Memproses Berkas:', 'lsp-core-management' ); ?></strong> <?php echo esc_html( $error_message ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( 'PREVIEW_TABLE' === $page_state && $preflight ) : ?>
                <!-- TAMPILAN PREFLIGHT PREVIEW -->
                <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">
                        <?php _e( 'Hasil Validasi Preflight (Pratinjau Data)', 'lsp-core-management' ); ?>
                    </h2>
                    <p style="color:#555;">
                        <?php _e( 'Periksa ringkasan dan status setiap baris di bawah ini. Data <strong>belum disimpan</strong> ke pangkalan data hingga Anda menekan tombol konfirmasi.', 'lsp-core-management' ); ?>
                    </p>

                    <!-- METRIC CARDS -->
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:15px; margin:20px 0;">
                        <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:24px; font-weight:800; color:#1e293b;"><?php echo intval( $preflight['total_rows'] ); ?></div>
                            <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase; margin-top:4px;">Total Baris Data</div>
                        </div>
                        <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:24px; font-weight:800; color:#16a34a;"><?php echo intval( $preflight['valid_inserts'] ); ?></div>
                            <div style="font-size:12px; color:#15803d; font-weight:600; text-transform:uppercase; margin-top:4px;">Siap Tambah Baru</div>
                        </div>
                        <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:24px; font-weight:800; color:#2563eb;"><?php echo intval( $preflight['valid_updates'] ); ?></div>
                            <div style="font-size:12px; color:#1d4ed8; font-weight:600; text-transform:uppercase; margin-top:4px;">Siap Diperbarui</div>
                        </div>
                        <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:24px; font-weight:800; color:#dc2626;"><?php echo intval( $preflight['skipped_count'] ); ?></div>
                            <div style="font-size:12px; color:#b91c1c; font-weight:600; text-transform:uppercase; margin-top:4px;">Ditolak / Dilewati</div>
                        </div>
                    </div>

                    <!-- TABEL DETAIL PREVIEW -->
                    <div style="max-height:420px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:20px;">
                        <table class="wp-list-table widefat striped" style="border:none;">
                            <thead>
                                <tr>
                                    <th style="width:60px; text-align:center;">Baris</th>
                                    <th>Nomor Blanko</th>
                                    <th>Nomor Registrasi</th>
                                    <th>Nama Pemegang</th>
                                    <th>Skema Sertifikasi</th>
                                    <th>Tanggal Terbit</th>
                                    <th style="width:130px; text-align:center;">Rencana Aksi</th>
                                    <th>Keterangan / Alasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $preflight['rows'] as $r ) : ?>
                                    <tr>
                                        <td style="text-align:center; font-weight:bold;"><?php echo intval( $r['row_num'] ); ?></td>
                                        <td><code><?php echo esc_html( $r['kode_blanko'] ?: '—' ); ?></code></td>
                                        <td><code><?php echo esc_html( $r['no_registrasi'] ?: '—' ); ?></code></td>
                                        <td><strong><?php echo esc_html( $r['nama_pemegang'] ?: '—' ); ?></strong></td>
                                        <td><?php echo esc_html( $r['skema_sertifikasi'] ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $r['tanggal_terbit'] ?: '—' ); ?></td>
                                        <td style="text-align:center;">
                                            <?php if ( 'VALID' === $r['status'] ) : ?>
                                                <?php if ( 'INSERT' === $r['action'] ) : ?>
                                                    <span style="background:#dcfce7; color:#15803d; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700;">TAMBAH BARU</span>
                                                <?php else : ?>
                                                    <span style="background:#dbeafe; color:#1d4ed8; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700;">UPDATE ID #<?php echo intval( $r['target_id'] ); ?></span>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span style="background:#fee2e2; color:#b91c1c; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700;">DILEWATI</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $r['errors'] ) ) : ?>
                                                <span style="color:#dc2626; font-size:12px;"><?php echo esc_html( implode( ' | ', $r['errors'] ) ); ?></span>
                                            <?php else : ?>
                                                <span style="color:#16a34a; font-size:12px;">✓ Data valid &bull; Asesi Terdaftar Resmi (ID Pendaftaran: #<?php echo intval( $r['pendaftaran_id'] ?? 0 ); ?>).</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- TOMBOL AKSI KONFIRMASI -->
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-top:15px; border-top:1px solid #eee;">
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=data_sertifikat&page=lsp-cert-import' ) ); ?>" class="button button-secondary">
                            &larr; <?php _e( 'Batalkan &amp; Pilih File Lain', 'lsp-core-management' ); ?>
                        </a>

                        <?php $total_valid = $preflight['valid_inserts'] + $preflight['valid_updates']; ?>
                        <?php if ( $total_valid > 0 ) : ?>
                            <form method="POST">
                                <?php wp_nonce_field( 'lsp_cert_import_confirm_action', 'lsp_cert_import_confirm_nonce' ); ?>
                                <input type="hidden" name="lsp_batch_token" value="<?php echo esc_attr( $batch_token ); ?>" />
                                <button type="submit" name="lsp_cert_import_confirm_submit" class="button button-primary button-hero" style="background:#16a34a; border-color:#15803d;">
                                    <span class="dashicons dashicons-yes-alt" style="margin-top:5px;"></span>
                                    <?php printf( __( 'Konfirmasi &amp; Proses Import (%d Data)', 'lsp-core-management' ), $total_valid ); ?>
                                </button>
                            </form>
                        <?php else : ?>
                            <div style="color:#dc2626; font-weight:bold;">
                                ⚠️ <?php _e( 'Tidak ada baris data valid yang dapat disimpan.', 'lsp-core-management' ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ( 'FINAL_REPORT' === $page_state && $final_report ) : ?>
                <!-- TAMPILAN LAPORAN HASIL IMPORT (FINAL REPORT) -->
                <div style="background:#fff; border:1px solid #ccd0d4; padding:25px; border-radius:8px; margin-top:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <h2 style="margin-top:0; color:#15803d; display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-saved" style="font-size:28px; width:28px; height:28px;"></span>
                        <?php _e( 'Proses Import Selesai Dijalankan!', 'lsp-core-management' ); ?>
                    </h2>
                    <p style="color:#555;">
                        <?php _e( 'Berikut rekapitulasi data yang berhasil disimpan atau diperbarui pada pangkalan data WordPress:', 'lsp-core-management' ); ?>
                    </p>

                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:15px; margin:20px 0;">
                        <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:28px; font-weight:800; color:#16a34a;"><?php echo intval( $final_report['imported'] ); ?></div>
                            <div style="font-size:12px; color:#15803d; font-weight:600; text-transform:uppercase; margin-top:4px;">Data Baru (Imported)</div>
                        </div>
                        <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:28px; font-weight:800; color:#2563eb;"><?php echo intval( $final_report['updated'] ); ?></div>
                            <div style="font-size:12px; color:#1d4ed8; font-weight:600; text-transform:uppercase; margin-top:4px;">Diperbarui (Updated)</div>
                        </div>
                        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:28px; font-weight:800; color:#d97706;"><?php echo intval( $final_report['skipped'] ); ?></div>
                            <div style="font-size:12px; color:#b45309; font-weight:600; text-transform:uppercase; margin-top:4px;">Dilewati (Skipped)</div>
                        </div>
                        <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:15px; text-align:center;">
                            <div style="font-size:28px; font-weight:800; color:#dc2626;"><?php echo intval( $final_report['failed'] ); ?></div>
                            <div style="font-size:12px; color:#b91c1c; font-weight:600; text-transform:uppercase; margin-top:4px;">Gagal DB (Failed)</div>
                        </div>
                    </div>

                    <?php if ( ! empty( $final_report['failed_details'] ) ) : ?>
                        <div style="margin-top:20px;">
                            <h3 style="color:#b91c1c;"><?php _e( 'Rincian Kegagalan Database:', 'lsp-core-management' ); ?></h3>
                            <table class="wp-list-table widefat striped">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">Baris</th>
                                        <th>Operasi</th>
                                        <th>Pesan Kesalahan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $final_report['failed_details'] as $fd ) : ?>
                                        <tr>
                                            <td><?php echo intval( $fd['row'] ); ?></td>
                                            <td><code><?php echo esc_html( $fd['field'] ); ?></code></td>
                                            <td style="color:#dc2626;"><?php echo esc_html( $fd['error'] ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:25px; display:flex; gap:10px;">
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=data_sertifikat' ) ); ?>" class="button button-primary">
                            <?php _e( 'Lihat Daftar Sertifikat', 'lsp-core-management' ); ?>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=data_sertifikat&page=lsp-cert-import' ) ); ?>" class="button button-secondary">
                            <?php _e( 'Import Berkas CSV Baru', 'lsp-core-management' ); ?>
                        </a>
                    </div>
                </div>

            <?php else : ?>
                <!-- TAMPILAN FORMULIR UNGGAH AWAL -->
                <div style="background:#fff; border:1px solid #ccd0d4; padding:25px; border-radius:8px; max-width:750px; margin-top:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <h3 style="margin-top:0;"><?php _e( 'Panduan Import Sertifikat:', 'lsp-core-management' ); ?></h3>
                    <ol style="line-height:1.8; margin-bottom:20px;">
                        <li>Unduh template file CSV resmi: <br>
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=data_sertifikat&page=lsp-cert-import&lsp_cert_download_csv_template=1' ) ); ?>" class="button button-secondary" style="margin-top:5px;">
                                <span class="dashicons dashicons-download" style="margin-top:4px;"></span> <?php _e( 'Unduh Template CSV Resmi', 'lsp-core-management' ); ?>
                            </a>
                        </li>
                        <li>Isi data asesi pada spreadsheet. Format tanggal wajib <code>YYYY-MM-DD</code> (contoh: <code>2024-05-20</code>).</li>
                        <li><strong>Sistem Pengamanan:</strong> Nama pemegang sertifikat harus sesuai dengan peserta yang telah terdaftar pada modul <em>Pendaftaran Sertifikasi</em>. Baris dengan peserta yang belum terdaftar otomatis ditolak untuk mencegah penerbitan tanpa berkas asesi.</li>
                        <li>Simpan berkas dalam format <strong>CSV (Comma Delimited)</strong> atau dipisahkan titik koma (<code>;</code>).</li>
                        <li>Unggah berkas di bawah. Sistem akan menjalankan <strong>pratinjau &amp; validasi preflight</strong> sebelum data dimasukkan ke pangkalan data.</li>
                    </ol>

                    <hr style="margin:20px 0; border:0; border-top:1px solid #eee;">

                    <form method="POST" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'lsp_cert_import_action', 'lsp_cert_import_nonce' ); ?>
                        <div style="margin-bottom:20px;">
                            <label for="csv_file" style="font-weight:bold; display:block; margin-bottom:8px; font-size:14px;">
                                <?php _e( 'Pilih Berkas CSV:', 'lsp-core-management' ); ?>
                            </label>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv,text/plain" required style="display:block;" />
                            <p class="description" style="margin-top:6px; color:#666;">
                                <?php _e( 'Format didukung: .csv (pemisah koma atau titik koma). Ukuran berkas maksimal 5 MB.', 'lsp-core-management' ); ?>
                            </p>
                        </div>

                        <p style="margin:0;">
                            <input type="submit" name="lsp_cert_import_submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Unggah & Validasi (Pratinjau)', 'lsp-core-management' ); ?>" />
                        </p>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ====================================================================
     * 7. SHORTCODES: [lsp_skema], [lsp_tuk], [lsp_asesor], [cek_sertifikat]
     * ====================================================================
     */

    /**
     * Normalisasi sintaks shortcode di dalam konten (mengatasi kurung siku ganda seperti
     * [lsp_skema jumlah="3" [ acak="ya"]] atau [lsp_skema][ acak="ya"] serta tanda kutip lengkung " ")
     */
    public function normalize_lsp_shortcodes_in_content( $content ) {
        if ( empty( $content ) || strpos( $content, '[' ) === false ) {
            return $content;
        }

        // 1. Gabungkan potongan atribut terpisah seperti: [lsp_skema jumlah="3"] [ acak="ya"] -> [lsp_skema jumlah="3" acak="ya"]
        $content = preg_replace(
            '/\[(lsp_skema|lsp_tuk|lsp_asesor|lsp_berita|lsp_download)([^\]]*)\]\s*\[\s*(acak|jumlah|orderby|order|kemasan|kategori|hanya_aktif)\s*=\s*([^\]]+)\]/i',
            '[$1$2 $3=$4]',
            $content
        );

        // 2. Bersihkan kurung siku bersarang di dalam shortcode: [lsp_skema jumlah="3" [ acak="ya"]] -> [lsp_skema jumlah="3" acak="ya"]
        $content = preg_replace_callback(
            '/\[(lsp_skema|lsp_tuk|lsp_asesor|lsp_berita|lsp_download)\b((?:[^\[\]]|\[[^\[\]]*\])*)\]/i',
            function( $m ) {
                $inner = str_replace( array( '[', ']' ), ' ', $m[2] );
                $curly = array( '“', '”', '„', '‟', '‘', '’', '&#8220;', '&#8221;', '&#8216;', '&#8217;', '&#8243;', '&ldquo;', '&rdquo;' );
                $inner = str_replace( $curly, '"', $inner );
                return '[' . $m[1] . $inner . ']';
            },
            $content
        );

        return $content;
    }

    /**
     * Helper pembersih atribut shortcode (tahan terhadap tanda kutip lengkung / spasi / kurung siku)
     */
    public static function parse_clean_shortcode_atts( $raw_atts, $defaults ) {
        $cleaned_raw = array();
        $force_acak  = false;

        if ( is_array( $raw_atts ) ) {
            $strip_chars = array( '"', "'", '“', '”', '„', '‟', '‘', '’', '[', ']', '&#8220;', '&#8221;', '&#8243;', '&ldquo;', '&rdquo;' );
            foreach ( $raw_atts as $k => $v ) {
                $clean_k = strtolower( trim( str_replace( $strip_chars, '', (string) $k ) ) );
                $clean_v = trim( str_replace( $strip_chars, '', (string) $v ) );

                // Jika atribut posisional (tanpa key, misal: [lsp_skema acak="ya"] yang terpecah atau [lsp_skema acak])
                if ( is_numeric( $k ) ) {
                    if ( preg_match( '/(acak|jumlah|orderby|order|kemasan|hanya_aktif)\s*=\s*(.+)/i', $clean_v, $matches ) ) {
                        $clean_k = strtolower( trim( $matches[1] ) );
                        $clean_v = trim( str_replace( $strip_chars, '', $matches[2] ) );
                        $cleaned_raw[ $clean_k ] = $clean_v;
                    } elseif ( in_array( strtolower( $clean_v ), array( 'acak', 'rand', 'random' ), true ) ) {
                        $force_acak = true;
                    }
                } else {
                    $cleaned_raw[ $clean_k ] = $clean_v;
                }
            }
        }

        $a = shortcode_atts( $defaults, $cleaned_raw );
        if ( $force_acak ) {
            $a['acak'] = 'ya';
        }
        return $a;
    }

    /**
     * Cek apakah opsi acak aktif dari atribut shortcode
     */
    public static function is_shortcode_random( $a ) {
        $acak_val    = isset( $a['acak'] ) ? strtolower( trim( (string) $a['acak'] ) ) : '';
        $orderby_val = isset( $a['orderby'] ) ? strtolower( trim( (string) $a['orderby'] ) ) : '';
        return (
            in_array( $acak_val, array( 'ya', 'yes', 'true', '1', 'acak', 'rand', 'random' ), true ) ||
            in_array( $orderby_val, array( 'rand', 'random', 'acak' ), true )
        );
    }

    /**
     * Cari URL Halaman Asesor Kompetensi untuk tombol "Lihat Semua Asesor ->"
     */
    public function get_asesor_page_url() {
        $slugs = array( 'asesor-kompetensi', 'asesor', 'daftar-asesor', 'tim-asesor' );
        foreach ( $slugs as $slug ) {
            $p = get_page_by_path( $slug, OBJECT, 'page' );
            if ( $p && 'publish' === $p->post_status ) {
                return get_permalink( $p->ID );
            }
        }
        // Cari halaman yang memuat shortcode [lsp_asesor
        global $wpdb;
        $page_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[lsp_asesor%' ORDER BY ID DESC LIMIT 1"
        );
        if ( $page_id && (int) $page_id !== (int) get_option( 'page_on_front' ) ) {
            return get_permalink( $page_id );
        }
        return add_query_arg( 'semua_asesor', '1', get_permalink() ?: home_url( '/' ) );
    }

    // 7A. Shortcode [lsp_skema jumlah="6" orderby="date|rand|title" acak="ya|tidak" kemasan="KKNI|Okupasi|Klaster"]
    public function shortcode_skema( $atts ) {
        $a = self::parse_clean_shortcode_atts( $atts, array(
            'jumlah'  => 6,
            'orderby' => 'date',
            'order'   => 'DESC',
            'acak'    => '',
            'kemasan' => '',
        ) );

        ob_start();

        $is_random = self::is_shortcode_random( $a );
        $orderby   = $is_random ? 'date' : sanitize_text_field( $a['orderby'] );
        $raw_limit = preg_replace( '/[^0-9-]/', '', (string) $a['jumlah'] );
        $limit     = ( '' !== $raw_limit && intval( $raw_limit ) !== 0 ) ? intval( $raw_limit ) : 6;

        $query_args = array(
            'post_type'      => 'skema',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'order'          => ( 'ASC' === strtoupper( $a['order'] ) ) ? 'ASC' : 'DESC',
        );

        if ( ! empty( $a['kemasan'] ) ) {
            $query_args['meta_query'] = array(
                array(
                    'key'     => 'jenis_kemasan',
                    'value'   => sanitize_text_field( $a['kemasan'] ),
                    'compare' => '=',
                ),
            );
        }

        $all_posts   = get_posts( $query_args );
        $total_found = count( $all_posts );

        if ( $is_random && $total_found > 1 ) {
            shuffle( $all_posts );
        }

        $display_posts = ( $limit > 0 && $total_found > $limit ) ? array_slice( $all_posts, 0, $limit ) : $all_posts;

        if ( ! empty( $display_posts ) ) {
            echo '<div class="skema-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; font-style: normal;">';
            foreach ( $display_posts as $post_obj ) {
                $post_id    = $post_obj->ID;
                $title      = get_the_title( $post_id );
                $permalink  = get_permalink( $post_id );
                $kode       = get_post_meta( $post_id, 'kode_skema', true );
                $biaya      = get_post_meta( $post_id, 'biaya_sertifikasi', true );
                $kemasan    = get_post_meta( $post_id, 'jenis_kemasan', true );
                $level      = get_post_meta( $post_id, 'level_skema', true );
                if ( empty( $level ) ) {
                    $level = get_post_meta( $post_id, 'level', true );
                }
                $units_raw  = get_post_meta( $post_id, 'unit_kompetensi', true );
                $unit_lines = ! empty( $units_raw ) ? array_filter( array_map( 'trim', explode( "\n", $units_raw ) ) ) : array();
                $unit_count = count( $unit_lines );

                $badge_parts = array();
                if ( ! empty( $level ) ) {
                    $badge_parts[] = 'LEVEL ' . $level;
                }
                if ( ! empty( $kemasan ) ) {
                    $badge_parts[] = strtoupper( $kemasan );
                }
                $auto_badge = ! empty( $badge_parts ) ? implode( ' • ', $badge_parts ) : 'SKEMA SERTIFIKASI';
                $auto_sub   = ! empty( $kode ) ? 'Kode: ' . $kode : 'Standar Kompetensi Kerja Nasional (SKKNI)';

                echo '<article class="skema-card" style="border: 1px solid #eee; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); background: #fff; display: flex; flex-direction: column; font-style: normal;">';
                echo '<a href="' . esc_url( $permalink ) . '" style="display: block; position: relative; height: 200px; overflow: hidden; text-decoration: none;">';
                if ( has_post_thumbnail( $post_id ) ) {
                    echo get_the_post_thumbnail( $post_id, 'medium_large', array( 'style' => 'width:100%; height:100%; object-fit:cover; display:block;' ) );
                } else {
                    echo self::render_dynamic_cover_html( $post_id, $auto_badge, $title, $auto_sub );
                }
                if ( $kemasan ) {
                    echo '<span style="position: absolute; top: 12px; right: 12px; background: var(--secondary-color, #f89b29); color: #fff; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); text-transform: uppercase;">' . esc_html( $kemasan ) . '</span>';
                }
                echo '</a>';

                echo '<div style="padding: 20px; border-top: 4px solid var(--secondary-color, #f89b29); flex: 1; display: flex; flex-direction: column;">';
                echo '<h3 style="margin: 0 0 10px 0; font-size: 18px; line-height: 1.35; font-style: normal;"><a href="' . esc_url( $permalink ) . '" style="text-decoration:none; color:var(--primary-color, #72161a);">' . esc_html( $title ) . '</a></h3>';

                // Baris Meta Post Skema: Level + Jumlah Unit + Kode Skema
                echo '<div class="skema-card-meta" style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 12px;">';
                if ( ! empty( $level ) ) {
                    echo '<span style="display: inline-block; background: #fff8f0; color: #72161a; border: 1px solid #f89b29; padding: 2px 10px; border-radius: 4px; font-size: 12.5px; font-weight: 700;">Level ' . esc_html( $level ) . '</span>';
                }
                if ( $unit_count > 0 ) {
                    echo '<span style="display: inline-block; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 2px 9px; border-radius: 4px; font-size: 12px; font-weight: 600;"><i class="fas fa-layer-group" style="margin-right: 4px; font-size: 11px;"></i>' . intval( $unit_count ) . ' Unit</span>';
                }
                if ( ! empty( $kode ) ) {
                    echo '<span style="display: inline-block; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 4px; font-size: 11.5px; font-family: monospace; font-weight: 600;"><i class="fas fa-barcode" style="margin-right: 4px;"></i>' . esc_html( $kode ) . '</span>';
                }
                echo '</div>';

                if ( $biaya ) {
                    echo '<div class="skema-fee" style="margin-bottom: 15px; font-weight:bold; color:#334155; font-size: 15px;">Biaya: <span style="color:var(--primary-color, #72161a);">Rp ' . number_format( (float) $biaya, 0, ',', '.' ) . '</span></div>';
                }

                echo '<div style="margin-top: auto;">';
                echo '<a href="' . esc_url( $permalink ) . '" style="display: inline-block; padding: 8px 16px; background: var(--secondary-color, #f89b29); color: #fff; text-decoration: none; border-radius: 5px; font-size: 13.5px; font-weight: 700; transition: background 0.3s;">Lihat Detail &rarr;</a>';
                echo '</div>';
                echo '</div>';
                echo '</article>';
            }
            echo '</div>';

            // Jika total data skema lebih banyak dari jumlah yang ditampilkan, munculkan tombol "Lihat Semua Skema ->"
            if ( $total_found > count( $display_posts ) ) {
                $skema_archive_url = get_post_type_archive_link( 'skema' ) ?: home_url( '/skema/' );
                if ( ! empty( $a['kemasan'] ) ) {
                    $skema_archive_url = add_query_arg( 'kemasan', sanitize_text_field( $a['kemasan'] ), $skema_archive_url );
                }
                echo '<div class="lsp-see-all-wrap" style="text-align: center; margin-top: 28px; font-style: normal;">';
                echo '<a class="btn lsp-btn-see-all" href="' . esc_url( $skema_archive_url ) . '" style="display: inline-block; padding: 11px 28px; background: var(--primary-color, #72161a); color: #fff; border-radius: 30px; font-weight: 700; font-size: 14px; text-decoration: none; box-shadow: 0 4px 12px rgba(114,22,26,0.18); transition: all 0.25s ease;">Lihat Semua Skema &rarr;</a>';
                echo '</div>';
            }
        } else {
            echo '<p>Belum ada data skema.</p>';
        }

        return ob_get_clean();
    }

    // 7B. Shortcode [lsp_tuk jumlah="-1" acak="ya|tidak"] (Lengkap dengan Jenis TUK, Kontak, & Peta Google Maps)
    public function shortcode_tuk( $atts = array() ) {
        $a = self::parse_clean_shortcode_atts( $atts, array(
            'jumlah'  => -1,
            'orderby' => 'date',
            'order'   => 'ASC',
            'acak'    => '',
        ) );

        ob_start();

        $is_random = self::is_shortcode_random( $a );
        $raw_limit = preg_replace( '/[^0-9-]/', '', (string) $a['jumlah'] );
        $limit     = ( '' !== $raw_limit ) ? intval( $raw_limit ) : -1;

        $all_tuks = get_posts( array(
            'post_type'      => 'tuk',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => $is_random ? 'date' : sanitize_text_field( $a['orderby'] ),
            'order'          => ( 'DESC' === strtoupper( $a['order'] ) ) ? 'DESC' : 'ASC',
        ) );

        $total_found = count( $all_tuks );
        if ( $is_random && $total_found > 1 ) {
            shuffle( $all_tuks );
        }

        $display_tuks = ( $limit > 0 && $total_found > $limit ) ? array_slice( $all_tuks, 0, $limit ) : $all_tuks;

        if ( ! empty( $display_tuks ) ) {
            echo '<div class="tuk-list" style="font-style: normal;">';
            foreach ( $display_tuks as $tuk_obj ) {
                $post_id = $tuk_obj->ID;
                $kode    = get_post_meta( $post_id, 'kode_tuk', true );
                $jenis   = get_post_meta( $post_id, 'jenis_tuk', true );
                $alamat  = get_post_meta( $post_id, 'alamat_tuk', true );
                $kontak  = get_post_meta( $post_id, 'kontak_tuk', true );
                $gmaps   = get_post_meta( $post_id, 'gmaps_tuk', true );

                echo '<details style="margin-bottom: 14px; background: #ffffff; padding: 18px 20px; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.03); font-style: normal;">';
                echo '<summary style="font-weight: 700; cursor: pointer; color: #1e293b; font-size: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">';
                echo '<span>' . esc_html( get_the_title( $post_id ) );
                if ( $kode ) {
                    echo ' <code style="font-size:12px; background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; margin-left:6px;">' . esc_html( $kode ) . '</code>';
                }
                echo '</span>';
                if ( $jenis ) {
                    echo '<span style="font-size: 11px; background: var(--secondary-color, #f89b29); color: #fff; padding: 3px 10px; border-radius: 12px; text-transform: uppercase;">' . esc_html( $jenis ) . '</span>';
                }
                echo '</summary>';

                echo '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f1f5f9;">';
                if ( $alamat ) {
                    echo '<p style="margin: 0 0 8px 0;"><strong>📍 Alamat Lengkap:</strong> ' . esc_html( $alamat ) . '</p>';
                }
                if ( $kontak ) {
                    echo '<p style="margin: 0 0 12px 0;"><strong>📞 Kontak / Telepon:</strong> ' . esc_html( $kontak ) . '</p>';
                }

                $content = $tuk_obj->post_content;
                if ( ! empty( $content ) ) {
                    echo '<div style="margin-bottom: 15px;">' . apply_filters( 'the_content', $content ) . '</div>';
                }

                if ( ! empty( $gmaps ) ) {
                    echo '<div style="margin-top: 12px;">';
                    echo '<strong style="display:block; margin-bottom:8px; font-size:13.5px; color:#334155;">🗺️ Lokasi Peta Google Maps:</strong>';
                    echo $this->render_gmaps_output( $gmaps, 280 );
                    echo '</div>';
                }

                echo '<div style="margin-top: 12px; text-align: right;">';
                echo '<a href="' . esc_url( get_permalink( $post_id ) ) . '" style="font-size: 13px; font-weight: 600; color: var(--primary-color, #72161a); text-decoration: none;">Buka Halaman Detail TUK &rarr;</a>';
                echo '</div>';
                echo '</div>';
                echo '</details>';
            }
            echo '</div>';

            // Jika total TUK lebih banyak dari jumlah yang ditampilkan, tampilkan tombol "Lihat Semua TUK ->"
            if ( $total_found > count( $display_tuks ) ) {
                $tuk_archive_url = get_post_type_archive_link( 'tuk' ) ?: home_url( '/tuk/' );
                echo '<div class="lsp-see-all-wrap" style="text-align: center; margin-top: 28px; font-style: normal;">';
                echo '<a class="btn lsp-btn-see-all" href="' . esc_url( $tuk_archive_url ) . '" style="display: inline-block; padding: 11px 28px; background: var(--primary-color, #72161a); color: #fff; border-radius: 30px; font-weight: 700; font-size: 14px; text-decoration: none; box-shadow: 0 4px 12px rgba(114,22,26,0.18); transition: all 0.25s ease;">Lihat Semua TUK &rarr;</a>';
                echo '</div>';
            }
        } else {
            echo '<p>Belum ada data Tempat Uji Kompetensi (TUK).</p>';
        }

        return ob_get_clean();
    }

    // 7C. Shortcode [lsp_asesor jumlah="8" orderby="display_name|rand" acak="ya|tidak" hanya_aktif="tidak"]
    // Mengambil dari WordPress Users dengan Role 'asesor'
    public function shortcode_asesor( $atts ) {
        $a = self::parse_clean_shortcode_atts( $atts, array(
            'jumlah'      => 8,
            'orderby'     => 'display_name',
            'order'       => 'ASC',
            'acak'        => '',
            'hanya_aktif' => '',
        ) );

        ob_start();

        $user_args = array(
            'role'    => 'asesor',
            'orderby' => 'display_name',
            'order'   => ( 'DESC' === strtoupper( $a['order'] ) ) ? 'DESC' : 'ASC',
        );

        $users = get_users( $user_args );

        // Filter hanya asesor yang masa berlaku MET-nya masih aktif (jika atribut hanya_aktif="ya")
        if ( in_array( strtolower( $a['hanya_aktif'] ), array( 'ya', 'yes', 'true', '1' ), true ) ) {
            $users = array_filter( $users, function( $u ) {
                $tgl = get_user_meta( $u->ID, 'tanggal_terbit_met', true );
                if ( empty( $tgl ) ) return true;
                return time() <= strtotime( '+3 years', strtotime( $tgl ) );
            } );
        }

        $total_found = count( $users );

        // Acak urutan jika acak="ya" atau orderby="rand"
        if ( self::is_shortcode_random( $a ) && $total_found > 1 ) {
            shuffle( $users );
        }

        $raw_limit = preg_replace( '/[^0-9-]/', '', (string) $a['jumlah'] );
        $limit     = ( '' !== $raw_limit && intval( $raw_limit ) !== 0 ) ? intval( $raw_limit ) : 8;

        // Jika diakses dengan parameter ?semua_asesor=1, tampilkan semua asesor
        if ( isset( $_GET['semua_asesor'] ) && '1' === $_GET['semua_asesor'] ) {
            $limit = -1;
        }

        if ( $limit > 0 && $total_found > $limit ) {
            $users = array_slice( $users, 0, $limit );
        }

        if ( ! empty( $users ) ) {
            echo '<div class="asesor-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; font-style: normal;">';
            foreach ( $users as $user ) {
                $uid        = $user->ID;
                $foto       = get_user_meta( $uid, 'foto_asesor', true ) ?: get_avatar_url( $uid, array( 'size' => 200 ) );
                $nomor_met  = get_user_meta( $uid, 'nomor_met', true );
                $tgl_met    = get_user_meta( $uid, 'tanggal_terbit_met', true );
                $bidang_all = $this->get_asesor_all_bidang( $uid );
                $deskripsi  = get_user_meta( $uid, 'deskripsi_asesor', true );
                if ( empty( $deskripsi ) ) {
                    $deskripsi = get_user_meta( $uid, 'description', true );
                }
                $linkedin   = get_user_meta( $uid, 'asesor_linkedin', true );
                $instagram  = get_user_meta( $uid, 'asesor_instagram', true );
                $facebook   = get_user_meta( $uid, 'asesor_facebook', true );
                $website    = get_user_meta( $uid, 'asesor_website', true );
                $post_count = count_user_posts( $uid, 'post', true );

                // Hitung status masa berlaku 3 tahun sertifikat MET
                $badge_met_status = '';
                if ( ! empty( $tgl_met ) ) {
                    $t_exp      = strtotime( '+3 years', strtotime( $tgl_met ) );
                    $is_expired = time() > $t_exp;
                    if ( $is_expired ) {
                        $badge_met_status = '<div style="margin-top:6px;"><span style="display:inline-block; background:#fff2f0; color:#cf1322; border:1px solid #ffa39e; font-size:11px; font-weight:700; padding:2px 8px; border-radius:12px;" title="Berakhir pada ' . esc_attr( $this->format_indo_date( $t_exp ) ) . '">⚠️ Lisensi Kadaluarsa</span></div>';
                    } else {
                        $badge_met_status = '<div style="margin-top:6px;"><span style="display:inline-block; background:#f6ffed; color:#389e0d; border:1px solid #b7eb8f; font-size:11px; font-weight:700; padding:2px 8px; border-radius:12px;" title="Berlaku s/d ' . esc_attr( $this->format_indo_date( $t_exp ) ) . '">🟢 Lisensi Aktif (s/d ' . esc_html( date( 'Y', $t_exp ) ) . ')</span></div>';
                    }
                }

                echo '<article class="asesor-card" style="text-align: center; padding: 24px 20px; border: 1px solid #eee; background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: flex; flex-direction: column; align-items: center; font-style: normal;">';
                echo '<div class="asesor-foto" style="margin-bottom: 14px;">';
                echo '<img src="' . esc_url( $foto ) . '" alt="' . esc_attr( $user->display_name ) . '" style="border-radius: 50%; width: 105px; height: 105px; object-fit: cover; border: 3px solid var(--secondary-color, #f89b29); padding: 2px;" />';
                echo '</div>';

                echo '<h4 style="margin: 0 0 4px 0; font-size: 17px; color: #1e293b; font-style: normal;">' . esc_html( $user->display_name ) . '</h4>';

                if ( $nomor_met ) {
                    echo '<p style="font-size: 13px; color: #475569; margin: 0; font-family: monospace; font-weight: 600;">No. MET: ' . esc_html( $nomor_met ) . '</p>';
                }
                echo $badge_met_status;

                // Daftar Multi-Bidang Keahlian
                if ( ! empty( $bidang_all ) ) {
                    echo '<div class="asesor-bidang-tags" style="margin-top: 12px; display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;">';
                    foreach ( $bidang_all as $bdg ) {
                        echo '<span style="background: #fff8f0; color: var(--primary-color, #72161a); border: 1px solid #fed7aa; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;">' . esc_html( $bdg ) . '</span>';
                    }
                    echo '</div>';
                }

                // Deskripsi / Biografi Singkat Asesor
                if ( ! empty( $deskripsi ) ) {
                    echo '<div class="asesor-bio" style="margin-top: 12px; font-size: 13px; color: #64748b; line-height: 1.5;">';
                    echo wp_kses_post( wpautop( wp_trim_words( $deskripsi, 22, '...' ) ) );
                    echo '</div>';
                }

                // Tautan Sosial Media & Artikel Asesor
                $has_socials = ( $linkedin || $instagram || $facebook || $website || $post_count > 0 );
                if ( $has_socials ) {
                    echo '<div class="asesor-socials" style="margin-top: auto; padding-top: 14px; display: flex; gap: 10px; align-items: center; justify-content: center; flex-wrap: wrap;">';
                    if ( $linkedin ) {
                        echo '<a href="' . esc_url( $linkedin ) . '" target="_blank" rel="noopener noreferrer" title="LinkedIn" style="color: #0a66c2; font-size: 17px;"><i class="fab fa-linkedin"></i></a>';
                    }
                    if ( $instagram ) {
                        echo '<a href="' . esc_url( $instagram ) . '" target="_blank" rel="noopener noreferrer" title="Instagram" style="color: #e1306c; font-size: 17px;"><i class="fab fa-instagram"></i></a>';
                    }
                    if ( $facebook ) {
                        echo '<a href="' . esc_url( $facebook ) . '" target="_blank" rel="noopener noreferrer" title="Facebook" style="color: #1877f2; font-size: 17px;"><i class="fab fa-facebook"></i></a>';
                    }
                    if ( $website ) {
                        echo '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer" title="Website / Portofolio" style="color: #475569; font-size: 16px;"><i class="fas fa-globe"></i></a>';
                    }
                    if ( $post_count > 0 ) {
                        echo '<a href="' . esc_url( get_author_posts_url( $uid ) ) . '" style="font-size: 11.5px; background: #f1f5f9; color: #334155; padding: 3px 9px; border-radius: 12px; text-decoration: none; font-weight: 600;">📄 ' . intval( $post_count ) . ' Artikel</a>';
                    }
                    echo '</div>';
                }

                echo '</article>';
            }
            echo '</div>';

            // Jika total Asesor lebih banyak dari yang ditampilkan, tampilkan tombol "Lihat Semua Asesor ->"
            if ( $total_found > count( $users ) ) {
                $asesor_url = $this->get_asesor_page_url();
                echo '<div class="lsp-see-all-wrap" style="text-align: center; margin-top: 28px; font-style: normal;">';
                echo '<a class="btn lsp-btn-see-all" href="' . esc_url( $asesor_url ) . '" style="display: inline-block; padding: 11px 28px; background: var(--primary-color, #72161a); color: #fff; border-radius: 30px; font-weight: 700; font-size: 14px; text-decoration: none; box-shadow: 0 4px 12px rgba(114,22,26,0.18); transition: all 0.25s ease;">Lihat Semua Asesor &rarr;</a>';
                echo '</div>';
            }
        } else {
            echo '<p>Belum ada data Asesor Kompetensi.</p>';
        }

        return ob_get_clean();
    }

    // 7D. Shortcode [cek_sertifikat]
    public function shortcode_cek_sertifikat() {
        ob_start();
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'lsp_verify_cert_nonce' );
        ?>
        <div class="lsp-cert-verifier-wrapper" id="lsp-cert-verifier-wrapper">
            <div class="lsp-cert-card-container">
                <div class="lsp-cert-header-icon">
                    <i class="fas fa-certificate"></i>
                </div>
                <h2 class="lsp-cert-title">Verifikasi Keabsahan Sertifikat</h2>
                <p class="lsp-cert-subtitle"><?php bloginfo( 'name' ); ?> &bull; Terlisensi Badan Nasional Sertifikasi Profesi (BNSP)</p>
                <p class="lsp-cert-instruction">Masukkan <strong>Nomor / Kode Blanko Sertifikat</strong> yang tertera pada bagian fisik sertifikat kompetensi Anda untuk memeriksa keabsahan dan masa berlaku.</p>

                <form id="lsp-cert-verify-form" class="lsp-cert-form" onsubmit="lspSubmitCertCheck(event)">
                    <div class="lsp-cert-input-group">
                        <i class="fas fa-barcode lsp-cert-input-icon"></i>
                        <input type="text" id="lsp_input_kode_blanko" name="kode_blanko" placeholder="Contoh: K24.01.000123 atau nomor seri blanko..." required autocomplete="off" />
                        <button type="submit" id="lsp-btn-verify" class="lsp-cert-btn">
                            <span class="btn-text"><i class="fas fa-search me-1"></i> Cek Sertifikat</span>
                            <span class="btn-loading" style="display:none;"><i class="fas fa-spinner fa-spin"></i> Memeriksa...</span>
                        </button>
                    </div>
                </form>

                <div id="lsp-cert-result-area" class="lsp-cert-result" style="display:none;"></div>
            </div>
        </div>

        <style>
            .lsp-cert-verifier-wrapper { max-width: 800px; margin: 40px auto; font-family: inherit; }
            .lsp-cert-card-container { background: #ffffff; border-radius: 16px; padding: 40px 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #eee; text-align: center; }
            .lsp-cert-header-icon { width: 70px; height: 70px; margin: 0 auto 15px; background: rgba(114, 22, 26, 0.08); color: var(--primary-color, #72161a); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; }
            .lsp-cert-title { font-size: 26px; font-weight: 800; color: #1a1919; margin-bottom: 6px; }
            .lsp-cert-subtitle { font-size: 14px; color: var(--secondary-color, #f89b29); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 15px; }
            .lsp-cert-instruction { font-size: 14px; color: #666; max-width: 600px; margin: 0 auto 25px; line-height: 1.6; }
            .lsp-cert-input-group { display: flex; max-width: 650px; margin: 0 auto; position: relative; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border-radius: 30px; overflow: hidden; border: 2px solid #ddd; transition: border-color 0.3s ease; }
            .lsp-cert-input-group:focus-within { border-color: var(--secondary-color, #f89b29); }
            .lsp-cert-input-icon { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #888; font-size: 18px; }
            .lsp-cert-input-group input { flex: 1; padding: 14px 20px 14px 50px; border: none; font-size: 15px; outline: none; }
            .lsp-cert-btn { background: var(--primary-color, #72161a); color: #ffffff; border: none; padding: 14px 28px; font-weight: 700; font-size: 15px; cursor: pointer; transition: background 0.3s ease; }
            .lsp-cert-btn:hover { background: #501013; }
            .cert-result-card { margin-top: 35px; text-align: left; border-radius: 12px; overflow: hidden; border: 2px solid #eee; background: #fafafa; animation: lspFadeIn 0.4s ease-in-out; }
            @keyframes lspFadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
            .cert-result-badge-bar { padding: 15px 20px; color: #fff; font-weight: 800; font-size: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
            .badge-valid { background: #16a34a; }
            .badge-expired { background: #d97706; }
            .badge-notfound { background: #dc2626; }
            .cert-result-body { padding: 25px 25px; background: #ffffff; }
            .cert-data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .cert-data-table tr { border-bottom: 1px solid #f0f0f0; }
            .cert-data-table td { padding: 12px 8px; font-size: 14px; }
            .cert-data-label { width: 35%; font-weight: 600; color: #555; }
            .cert-data-value { width: 65%; color: #1a1a1a; font-weight: 700; }
            .cert-action-bar { padding: 15px 25px; background: #f8f9fa; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
            @media print { body * { visibility: hidden; } .cert-result-card, .cert-result-card * { visibility: visible; } .cert-result-card { position: absolute; left: 0; top: 0; width: 100%; border: 1px solid #000; box-shadow: none; } .cert-action-bar { display: none !important; } }
            @media (max-width: 600px) { .lsp-cert-input-group { flex-direction: column; border-radius: 12px; } .lsp-cert-input-icon { display: none; } .lsp-cert-input-group input { padding: 14px 15px; } .lsp-cert-btn { width: 100%; padding: 14px; border-radius: 0; } .cert-data-table td { display: block; width: 100%; padding: 6px 0; } }
        </style>

        <script>
        function lspSubmitCertCheck(e) {
            if (e && e.preventDefault) e.preventDefault();
            var inputEl = document.getElementById('lsp_input_kode_blanko');
            if (!inputEl) return false;
            var inputVal = inputEl.value.trim();
            if (!inputVal) {
                alert('Silakan masukkan nomor atau kode blanko sertifikat terlebih dahulu.');
                inputEl.focus();
                return false;
            }

            var btn = document.getElementById('lsp-btn-verify');
            var btnText = btn.querySelector('.btn-text');
            var btnLoading = btn.querySelector('.btn-loading');
            var resultArea = document.getElementById('lsp-cert-result-area');

            btn.disabled = true;
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline-block';

            var formData = new FormData();
            formData.append('action', 'lsp_verify_cert');
            formData.append('nonce', '<?php echo esc_js( $nonce ); ?>');
            formData.append('kode_blanko', inputVal);

            fetch('<?php echo esc_url( $ajax_url ); ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(res) {
                return res.json().catch(function() {
                    return { success: false, data: { html: '<div class="cert-result-card" style="padding:20px; text-align:center; color:#cf1322;">Terjadi kesalahan komunikasi dengan server (Status: ' + res.status + ').</div>' } };
                });
            })
            .then(function(response) {
                btn.disabled = false;
                btnText.style.display = 'inline-block';
                btnLoading.style.display = 'none';

                var htmlContent = '';
                if (response && response.data && typeof response.data.html !== 'undefined') {
                    htmlContent = response.data.html;
                } else if (response && typeof response.data === 'string') {
                    htmlContent = response.data;
                } else {
                    htmlContent = '<div class="cert-result-card" style="padding:25px; text-align:center; color:#c00;">Terjadi kesalahan saat memproses respon verifikasi.</div>';
                }

                resultArea.style.display = 'block';
                resultArea.innerHTML = htmlContent;
                resultArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function(err) {
                btn.disabled = false;
                btnText.style.display = 'inline-block';
                btnLoading.style.display = 'none';
                alert('Gagal menghubungi server. Silakan coba sesaat lagi.');
            });

            return false;
        }
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * ====================================================================
     * 8. AJAX VERIFIKASI SERTIFIKAT
     * ====================================================================
     */
    /**
     * Helper: Dapatkan Alamat IP Client secara Aman untuk Rate Limiting
     */
    private function get_client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }
        return '127.0.0.1';
    }

    public function ajax_verify_cert() {
        // 1. Verifikasi Token Keamanan Nonce
        if ( ! check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce', false ) ) {
            wp_send_json_error( array(
                'html' => '<div class="cert-result-card" style="padding:25px; text-align:center; color:#cf1322;">
                    <i class="fas fa-shield-alt" style="font-size:36px; margin-bottom:12px; display:block;"></i>
                    <h3 style="color:#b91c1c; margin-bottom:8px;">Sesi Verifikasi Tidak Valid</h3>
                    <p style="color:#666; font-size:14px; margin:0;">Token keamanan telah kedaluwarsa atau tidak valid. Silakan muat ulang (refresh) halaman ini dan coba kembali.</p>
                </div>'
            ), 403 );
        }

        // 2. Rate Limiting Aman (Maksimal 20 request per menit per hash IP)
        $client_ip = $this->get_client_ip();
        $rate_key  = 'lsp_rl_' . md5( $client_ip );
        $attempts  = (int) get_transient( $rate_key );

        if ( $attempts >= 20 ) {
            wp_send_json_error( array(
                'html' => '<div class="cert-result-card" style="padding:25px; text-align:center; color:#cf1322;">
                    <i class="fas fa-hourglass-half" style="font-size:36px; margin-bottom:12px; display:block; color:#d97706;"></i>
                    <h3 style="color:#b45309; margin-bottom:8px;">Batas Permintaan Terlampaui</h3>
                    <p style="color:#666; font-size:14px; margin:0;">Terlalu banyak permintaan verifikasi dari jaringan Anda dalam waktu singkat. Demi perlindungan sistem, silakan tunggu 1 menit sebelum mencoba kembali.</p>
                </div>'
            ), 429 );
        }
        set_transient( $rate_key, $attempts + 1, 60 );

        // 3. Sanitasi & Normalisasi Input Menggunakan Fungsi Sentral
        $raw_input = isset( $_POST['kode_blanko'] ) ? wp_unslash( $_POST['kode_blanko'] ) : '';
        $kode_cert = self::normalize_cert_number( $raw_input );

        // Validasi input kosong
        if ( '' === $kode_cert ) {
            wp_send_json_error( array(
                'html' => '<div class="cert-result-card" style="padding:20px; text-align:center; color:#cf1322;">
                    Nomor atau kode blanko sertifikat tidak boleh kosong.
                </div>'
            ), 400 );
        }

        // Validasi panjang input
        $len = mb_strlen( $kode_cert, 'UTF-8' );
        if ( $len < 3 ) {
            wp_send_json_error( array(
                'html' => '<div class="cert-result-card" style="padding:20px; text-align:center; color:#cf1322;">
                    Nomor sertifikat terlalu pendek (minimal 3 karakter). Masukkan nomor blanko atau nomor registrasi lengkap.
                </div>'
            ), 400 );
        }
        if ( $len > 60 ) {
            wp_send_json_error( array(
                'html' => '<div class="cert-result-card" style="padding:20px; text-align:center; color:#cf1322;">
                    Nomor sertifikat melebihi batas panjang maksimum yang diizinkan (maksimal 60 karakter).
                </div>'
            ), 400 );
        }

        // 4. Exact Matching Query (TIDAK MENGGUNAKAN LIKE)
        $query = new WP_Query( array(
            'post_type'              => 'data_sertifikat',
            'post_status'            => 'publish',
            'posts_per_page'         => 2, // Ambil 2 untuk mendeteksi ambiguitas data duplikat
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'OR',
                array(
                    'key'     => 'kode_blanko',
                    'value'   => $kode_cert,
                    'compare' => '=',
                ),
                array(
                    'key'     => 'no_registrasi',
                    'value'   => $kode_cert,
                    'compare' => '=',
                ),
            ),
        ) );

        ob_start();

        // 5. Penanganan Hasil: Ambiguitas Duplikat vs Tepat Satu vs Tidak Ditemukan
        if ( $query->post_count > 1 ) {
            // Data duplikat ditemukan: Jangan memilih salah satu secara sembarangan
            ?>
            <div class="cert-result-card">
                <div class="cert-result-badge-bar" style="background:#d97706; color:#fff;">
                    <span><i class="fas fa-exclamation-triangle me-2"></i> STATUS: PERLU KLARIFIKASI SEKRETARIAT</span>
                    <span style="font-size:13px; font-weight:600; opacity:0.9;">No. Input: <?php echo esc_html( $kode_cert ); ?></span>
                </div>
                <div class="cert-result-body" style="text-align:center; padding:35px 20px;">
                    <div style="font-size:45px; color:#d97706; margin-bottom:15px;"><i class="fas fa-copy"></i></div>
                    <h3 style="color:#b45309; margin-bottom:10px;">Ditemukan Lebih dari Satu Catatan</h3>
                    <p style="color:#666; max-width:550px; margin:0 auto 15px; font-size:14px; line-height:1.7;">
                        Nomor identitas ini tercatat pada lebih dari satu berkas sertifikat di pangkalan data. Demi kepastian hukum dan menghindari kesalahan identifikasi, silakan hubungi <strong>Sekretariat LSP Komunikasi Indonesia</strong> untuk verifikasi dokumen fisik secara langsung.
                    </p>
                </div>
            </div>
            <?php
        } elseif ( 1 === $query->post_count ) {
            $post_obj       = $query->posts[0];
            $post_id        = $post_obj->ID;
            $db_blanko      = get_post_meta( $post_id, 'kode_blanko', true );
            if ( empty( $db_blanko ) ) {
                $db_blanko = get_the_title( $post_id );
            }
            $nama_pemegang  = get_post_meta( $post_id, 'nama_pemegang', true );
            $skema          = get_post_meta( $post_id, 'skema_sertifikasi', true );
            $no_reg         = get_post_meta( $post_id, 'no_registrasi', true );
            $tanggal_terbit = get_post_meta( $post_id, 'tanggal_terbit', true );

            // Perhitungan masa berlaku yang aman terhadap tanggal tidak valid
            $time_terbit    = ! empty( $tanggal_terbit ) ? strtotime( $tanggal_terbit ) : false;
            $has_valid_date = ( false !== $time_terbit && $time_terbit > 0 );
            $time_expired   = $has_valid_date ? strtotime( '+3 years', $time_terbit ) : 0;
            $is_expired     = $has_valid_date ? ( time() > $time_expired ) : false;

            $badge_class = ( ! $has_valid_date ) ? 'badge-expired' : ( $is_expired ? 'badge-expired' : 'badge-valid' );
            $badge_icon  = ( ! $has_valid_date ) ? 'fa-info-circle' : ( $is_expired ? 'fa-exclamation-triangle' : 'fa-check-circle' );
            $badge_text  = ( ! $has_valid_date ) ? 'TANGGAL BELUM TERDATA' : ( $is_expired ? 'KADALUARSA (EXPIRED)' : 'VALID &amp; AKTIF' );
            ?>
            <div class="cert-result-card">
                <div class="cert-result-badge-bar <?php echo esc_attr( $badge_class ); ?>">
                    <span><i class="fas <?php echo esc_attr( $badge_icon ); ?> me-2"></i> STATUS: <?php echo $badge_text; ?></span>
                    <span style="font-size:13px; font-weight:600; opacity:0.9;">No. Blanko: <?php echo esc_html( $db_blanko ); ?></span>
                </div>
                <div class="cert-result-body">
                    <?php if ( ! $has_valid_date ) : ?>
                        <div style="background:#fffbe6; border:1px solid #ffe58f; padding:12px 16px; border-radius:6px; color:#d46b08; font-size:13.5px; margin-bottom:15px; line-height:1.6;">
                            <strong>Keterangan:</strong> Sertifikat ini terdaftar resmi dalam pangkalan data LSP, namun informasi tanggal terbit belum lengkap. Silakan hubungi Sekretariat LSP untuk konfirmasi masa berlaku.
                        </div>
                    <?php elseif ( $is_expired ) : ?>
                        <div style="background:#fffbe6; border:1px solid #ffe58f; padding:12px 16px; border-radius:6px; color:#d46b08; font-size:13.5px; margin-bottom:15px; line-height:1.6;">
                            <strong>Perhatian:</strong> Sertifikat ini terdaftar resmi dalam pangkalan data LSP, namun <strong>masa berlakunya telah habis</strong> (melebihi batas 3 tahun masa berlaku sertifikasi BNSP).
                        </div>
                    <?php else : ?>
                        <div style="background:#f6ffed; border:1px solid #b7eb8f; padding:12px 16px; border-radius:6px; color:#389e0d; font-size:13.5px; margin-bottom:15px; line-height:1.6;">
                            <strong>Keterangan Resmi:</strong> Sertifikat ini <strong>sah, terdaftar resmi</strong>, dan <strong>masih berlaku aktif</strong> di bawah lisensi Badan Nasional Sertifikasi Profesi (BNSP).
                        </div>
                    <?php endif; ?>
                    <table class="cert-data-table">
                        <tr>
                            <td class="cert-data-label"><i class="fas fa-barcode me-2" style="color:var(--secondary-color, #f89b29);"></i> Nomor / Kode Blanko</td>
                            <td class="cert-data-value"><code><?php echo esc_html( $db_blanko ); ?></code></td>
                        </tr>
                        <tr>
                            <td class="cert-data-label"><i class="fas fa-user-check me-2" style="color:var(--secondary-color, #f89b29);"></i> Nama Pemegang (Asesi)</td>
                            <td class="cert-data-value" style="font-size:16px; color:var(--primary-color, #72161a);"><?php echo esc_html( $nama_pemegang ?: '—' ); ?></td>
                        </tr>
                        <tr>
                            <td class="cert-data-label"><i class="fas fa-id-card me-2" style="color:var(--secondary-color, #f89b29);"></i> Nomor Registrasi</td>
                            <td class="cert-data-value"><code><?php echo esc_html( $no_reg ?: '—' ); ?></code></td>
                        </tr>
                        <tr>
                            <td class="cert-data-label"><i class="fas fa-award me-2" style="color:var(--secondary-color, #f89b29);"></i> Skema Sertifikasi</td>
                            <td class="cert-data-value"><?php echo esc_html( $skema ?: '—' ); ?></td>
                        </tr>
                        <tr>
                            <td class="cert-data-label"><i class="fas fa-calendar-alt me-2" style="color:var(--secondary-color, #f89b29);"></i> Tanggal Terbit</td>
                            <td class="cert-data-value"><?php echo $has_valid_date ? esc_html( $this->format_indo_date( $time_terbit ) ) : '—'; ?></td>
                        </tr>
                        <tr>
                            <td class="cert-data-label"><i class="fas fa-calendar-check me-2" style="color:var(--secondary-color, #f89b29);"></i> Masa Berlaku (3 Tahun)</td>
                            <td class="cert-data-value">
                                <?php if ( $has_valid_date ) : ?>
                                    <?php echo esc_html( $this->format_indo_date( $time_expired ) ); ?>
                                    <?php if ( ! $is_expired ) : ?>
                                        <span style="font-size:12px; color:#16a34a; margin-left:8px; font-weight:600;">(Masih Berlaku)</span>
                                    <?php else : ?>
                                        <span style="font-size:12px; color:#dc2626; margin-left:8px; font-weight:600;">(Telah Berakhir)</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span>—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                        $p_id_cert = get_post_meta( $post_id, 'pendaftaran_id', true );
                        $p_cert_match = null;
                        if ( ! empty( $p_id_cert ) ) {
                            $p_cert_match = self::find_registered_pendaftaran( '', '', intval( $p_id_cert ) );
                        }
                        if ( ! $p_cert_match && ! empty( $nama_pemegang ) ) {
                            $p_cert_match = self::find_registered_pendaftaran( $nama_pemegang, $skema );
                        }
                        ?>
                        <tr>
                            <td class="cert-data-label"><i class="fas fa-shield-alt me-2" style="color:var(--secondary-color, #f89b29);"></i> Validasi Berkas Pendaftaran</td>
                            <td class="cert-data-value">
                                <?php if ( $p_cert_match ) : ?>
                                    <span style="color:#16a34a; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fas fa-check-circle"></i> Terdaftar Resmi (ID Arsip Asesi: #<?php echo intval( $p_cert_match['id'] ); ?>)
                                    </span>
                                <?php else : ?>
                                    <span style="color:#64748b; font-weight:600;">
                                        Data Historis
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="cert-action-bar">
                    <span style="font-size:12px; color:#777;">
                        Diverifikasi secara realtime pada <?php echo esc_html( $this->format_indo_date( time() ) ); ?>.
                    </span>
                    <button type="button" onclick="window.print()" style="background:#fff; border:1px solid #ccc; padding:8px 18px; border-radius:20px; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-print"></i> Cetak Bukti Verifikasi
                    </button>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="cert-result-card">
                <div class="cert-result-badge-bar badge-notfound">
                    <span><i class="fas fa-times-circle me-2"></i> STATUS: TIDAK DITEMUKAN / TIDAK VALID</span>
                    <span style="font-size:13px; font-weight:600; opacity:0.9;">Pencarian: <?php echo esc_html( $kode_cert ); ?></span>
                </div>
                <div class="cert-result-body" style="text-align:center; padding:35px 20px;">
                    <div style="font-size:45px; color:#dc2626; margin-bottom:15px;"><i class="fas fa-file-excel"></i></div>
                    <h3 style="color:#b91c1c; margin-bottom:10px;">Nomor Sertifikat Tidak Terdaftar!</h3>
                    <p style="color:#666; max-width:550px; margin:0 auto 15px; font-size:14px; line-height:1.7;">
                        Nomor blanko atau nomor registrasi <strong>"<?php echo esc_html( $kode_cert ); ?>"</strong> tidak tercatat dalam pangkalan data resmi kami. Pastikan nomor yang Anda masukkan sesuai persis dengan yang tertera pada sertifikat fisik BNSP Anda.
                    </p>
                </div>
            </div>
            <?php
        }

        $output = ob_get_clean();
        wp_send_json_success( array( 'html' => $output ) );
    }

    /**
     * ====================================================================
     * 9. TEMPLATE LOADER & RENDERERS (KATALOG SKEMA, DETAIL SKEMA, DETAIL TUK)
     * ====================================================================
     */
    public function template_loader( $template ) {
        if ( is_post_type_archive( 'skema' ) ) {
            $theme_file = locate_template( array( 'archive-skema.php' ) );
            if ( $theme_file ) return $theme_file;
            $plugin_file = plugin_dir_path( __FILE__ ) . 'templates/archive-skema.php';
            if ( file_exists( $plugin_file ) ) return $plugin_file;
        }

        if ( is_singular( 'skema' ) ) {
            $theme_file = locate_template( array( 'single-skema.php' ) );
            if ( $theme_file ) return $theme_file;
            $plugin_file = plugin_dir_path( __FILE__ ) . 'templates/single-skema.php';
            if ( file_exists( $plugin_file ) ) return $plugin_file;
        }

        if ( is_post_type_archive( 'tuk' ) ) {
            $theme_file = locate_template( array( 'archive-tuk.php' ) );
            if ( $theme_file ) return $theme_file;
            $plugin_file = plugin_dir_path( __FILE__ ) . 'templates/archive-tuk.php';
            if ( file_exists( $plugin_file ) ) return $plugin_file;
        }

        if ( is_singular( 'tuk' ) ) {
            $theme_file = locate_template( array( 'single-tuk.php' ) );
            if ( $theme_file ) return $theme_file;
            $plugin_file = plugin_dir_path( __FILE__ ) . 'templates/single-tuk.php';
            if ( file_exists( $plugin_file ) ) return $plugin_file;
        }

        return $template;
    }

    public function get_skema_count_by_kemasan( $kemasan_name ) {
        $q = new WP_Query( array(
            'post_type'      => 'skema',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'jenis_kemasan',
                    'value'   => $kemasan_name,
                    'compare' => '=',
                ),
            ),
        ) );
        return $q->found_posts;
    }

    /**
     * Render Halaman Katalog Arsip Skema (/skema/)
     */
    public function render_skema_catalog_archive() {
        global $wp_query;
        ob_start();

        $current_kemasan = isset( $_GET['kemasan'] ) ? sanitize_text_field( wp_unslash( $_GET['kemasan'] ) ) : '';
        $current_search  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $current_urutkan = isset( $_GET['urutkan'] ) ? sanitize_text_field( wp_unslash( $_GET['urutkan'] ) ) : 'terbaru';

        $count_posts   = wp_count_posts( 'skema' );
        $count_all     = isset( $count_posts->publish ) ? $count_posts->publish : 0;
        $count_kkni    = $this->get_skema_count_by_kemasan( 'KKNI' );
        $count_okupasi = $this->get_skema_count_by_kemasan( 'Okupasi' );
        $count_klaster = $this->get_skema_count_by_kemasan( 'Klaster' );

        $archive_url = get_post_type_archive_link( 'skema' );
        $wa_number   = get_option( 'lsp_core_wa_number', '6281328430600' );
        ?>
        <!-- FILTER & SEARCH PANEL -->
        <div class="skema-filter-panel" style="background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); margin-top: -60px; position: relative; z-index: 5; margin-bottom: 40px; border: 1px solid #eee;">
            <form id="skema-filter-form" method="GET" action="<?php echo esc_url( $archive_url ); ?>">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div style="flex: 1 1 300px; position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #888;"></i>
                        <input type="text" id="skema-search-input" name="q" value="<?php echo esc_attr( $current_search ); ?>" placeholder="Cari skema atau kode skema..." style="width: 100%; padding: 12px 15px 12px 42px; border: 1px solid #ddd; border-radius: 30px; font-size: 14px; outline: none; transition: border-color 0.3s;">
                    </div>
                    <div style="flex: 0 1 240px; position: relative;">
                        <select name="urutkan" id="skema-sort-select" onchange="document.getElementById('skema-filter-form').submit();" style="width: 100%; padding: 12px 15px; border: 1px solid #ddd; border-radius: 30px; font-size: 14px; background: #fff; cursor: pointer; outline: none;">
                            <option value="terbaru" <?php selected( $current_urutkan, 'terbaru' ); ?>>Urutkan: Terbaru</option>
                            <option value="nama_asc" <?php selected( $current_urutkan, 'nama_asc' ); ?>>Nama: A &rarr; Z</option>
                            <option value="nama_desc" <?php selected( $current_urutkan, 'nama_desc' ); ?>>Nama: Z &rarr; A</option>
                            <option value="biaya_asc" <?php selected( $current_urutkan, 'biaya_asc' ); ?>>Biaya: Terendah</option>
                            <option value="biaya_desc" <?php selected( $current_urutkan, 'biaya_desc' ); ?>>Biaya: Tertinggi</option>
                            <option value="acak" <?php selected( $current_urutkan, 'acak' ); ?>>Acak (Random)</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-filter-submit" style="background: var(--primary-color, #72161a); color: #fff; border: none; padding: 12px 24px; border-radius: 30px; font-weight: 600; font-size: 14px; cursor: pointer;">
                            <i class="fas fa-filter" style="margin-right: 6px;"></i> Filter
                        </button>
                    </div>
                </div>

                <input type="hidden" name="kemasan" id="skema-kemasan-hidden" value="<?php echo esc_attr( $current_kemasan ); ?>">

                <div class="kemasan-tabs-wrapper" style="border-top: 1px solid #eee; padding-top: 15px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <span style="font-size: 14px; font-weight: 700; color: #555; margin-right: 5px;">Jenis Kemasan:</span>
                    <?php
                    $tab_items = array(
                        ''        => array( 'label' => 'Semua Skema', 'count' => $count_all ),
                        'KKNI'    => array( 'label' => 'KKNI', 'count' => $count_kkni ),
                        'Okupasi' => array( 'label' => 'Okupasi', 'count' => $count_okupasi ),
                        'Klaster' => array( 'label' => 'Klaster', 'count' => $count_klaster ),
                    );

                    foreach ( $tab_items as $k_val => $k_data ) :
                        $is_active   = ( $current_kemasan === $k_val );
                        $link_params = array();
                        if ( ! empty( $k_val ) ) $link_params['kemasan'] = $k_val;
                        if ( ! empty( $current_search ) ) $link_params['q'] = $current_search;
                        if ( ! empty( $current_urutkan ) && 'terbaru' !== $current_urutkan ) $link_params['urutkan'] = $current_urutkan;
                        $tab_url  = add_query_arg( $link_params, $archive_url );
                        $bg_style = $is_active ? 'background: var(--secondary-color, #f89b29); color: #fff; font-weight: 700; box-shadow: 0 2px 6px rgba(248,155,41,0.3);' : 'background: #f1f3f5; color: #555; font-weight: 500;';
                        ?>
                        <a href="<?php echo esc_url( $tab_url ); ?>" class="kemasan-tab-btn" style="padding: 6px 16px; border-radius: 20px; text-decoration: none; font-size: 13px; transition: all 0.2s ease; <?php echo $bg_style; ?>">
                            <?php echo esc_html( $k_data['label'] ); ?>
                            <span style="font-size: 11px; opacity: 0.85; margin-left: 4px; padding: 1px 6px; border-radius: 10px; background: rgba(0,0,0,0.1);"><?php echo intval( $k_data['count'] ); ?></span>
                        </a>
                    <?php endforeach; ?>

                    <?php if ( ! empty( $current_kemasan ) || ! empty( $current_search ) || 'terbaru' !== $current_urutkan ) : ?>
                        <a href="<?php echo esc_url( $archive_url ); ?>" style="margin-left: auto; color: #dc2626; font-size: 13px; text-decoration: none; font-weight: 600;">
                            <i class="fas fa-times-circle"></i> Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div style="margin-bottom: 25px;">
            <p style="margin: 0; font-size: 15px; color: #666;">
                Menampilkan <strong><?php echo intval( $wp_query->found_posts ); ?></strong> Skema Sertifikasi
                <?php if ( ! empty( $current_kemasan ) ) : ?>
                    dengan kemasan <span style="background: #e0f2fe; color: #0284c7; padding: 2px 8px; border-radius: 4px; font-weight: 600;"><?php echo esc_html( $current_kemasan ); ?></span>
                <?php endif; ?>
                <?php if ( ! empty( $current_search ) ) : ?>
                    untuk kata kunci "<em><?php echo esc_html( $current_search ); ?></em>"
                <?php endif; ?>
            </p>
        </div>

        <?php if ( have_posts() ) : ?>
            <div class="skema-catalog-grid" id="skema-catalog-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px;">
                <?php while ( have_posts() ) : the_post();
                    $post_id = get_the_ID();
                    $kode    = get_post_meta( $post_id, 'kode_skema', true );
                    $kemasan = get_post_meta( $post_id, 'jenis_kemasan', true );
                    $level   = get_post_meta( $post_id, 'level_skema', true );
                    if ( empty( $level ) ) {
                        $level = get_post_meta( $post_id, 'level', true );
                    }
                    $biaya      = get_post_meta( $post_id, 'biaya_sertifikasi', true );
                    $units_raw  = get_post_meta( $post_id, 'unit_kompetensi', true );
                    $unit_lines = ! empty( $units_raw ) ? array_filter( array_map( 'trim', explode( "\n", $units_raw ) ) ) : array();
                    $unit_count = count( $unit_lines );

                    $badge_parts = array();
                    if ( ! empty( $level ) ) {
                        $badge_parts[] = 'LEVEL ' . $level;
                    }
                    if ( ! empty( $kemasan ) ) {
                        $badge_parts[] = strtoupper( $kemasan );
                    }
                    $auto_badge = ! empty( $badge_parts ) ? implode( ' • ', $badge_parts ) : 'SKEMA SERTIFIKASI';
                    $auto_sub   = ! empty( $kode ) ? 'Kode: ' . $kode : 'Standar Kompetensi Kerja Nasional (SKKNI)';

                    $badge_bg = 'var(--secondary-color, #f89b29)';
                    if ( 'KKNI' === $kemasan ) $badge_bg = '#0284c7';
                    elseif ( 'Okupasi' === $kemasan ) $badge_bg = 'var(--primary-color, #72161a)';
                    elseif ( 'Klaster' === $kemasan ) $badge_bg = '#16a34a';
                ?>
                    <article class="skema-catalog-card" style="background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #eee; display: flex; flex-direction: column;">
                        <div style="position: relative; overflow: hidden; height: 200px; background: #f0f0f0;">
                            <a href="<?php the_permalink(); ?>" style="display: block; width: 100%; height: 100%; text-decoration: none;">
                                <?php if ( has_post_thumbnail() ) : ?>
                                    <?php the_post_thumbnail( 'medium_large', array( 'style' => 'width: 100%; height: 100%; object-fit: cover; display: block;' ) ); ?>
                                <?php else : ?>
                                    <?php echo self::render_dynamic_cover_html( $post_id, $auto_badge, get_the_title(), $auto_sub ); ?>
                                <?php endif; ?>
                            </a>
                            <?php if ( ! empty( $kemasan ) ) : ?>
                                <span style="position: absolute; top: 12px; right: 12px; background: <?php echo esc_attr( $badge_bg ); ?>; color: #ffffff; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase;">
                                    <?php echo esc_html( $kemasan ); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="padding: 22px; border-top: 4px solid var(--secondary-color, #f89b29); flex: 1; display: flex; flex-direction: column;">
                            <h3 style="font-size: 18px; margin: 0 0 10px 0; line-height: 1.4;">
                                <a href="<?php the_permalink(); ?>" style="color: var(--primary-color, #72161a); text-decoration: none;"><?php the_title(); ?></a>
                            </h3>

                            <div class="skema-card-meta" style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 14px;">
                                <?php if ( ! empty( $level ) ) : ?>
                                    <span style="display: inline-block; background: #fff8f0; color: #72161a; border: 1px solid #f89b29; padding: 2px 10px; border-radius: 4px; font-size: 12.5px; font-weight: 700;">Level <?php echo esc_html( $level ); ?></span>
                                <?php endif; ?>
                                <?php if ( $unit_count > 0 ) : ?>
                                    <span style="display: inline-block; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 2px 9px; border-radius: 4px; font-size: 12px; font-weight: 600;"><i class="fas fa-layer-group" style="margin-right: 4px; font-size: 11px;"></i><?php echo intval( $unit_count ); ?> Unit</span>
                                <?php endif; ?>
                                <?php if ( ! empty( $kode ) ) : ?>
                                    <span style="font-family: monospace; font-size: 11.5px; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                                        <i class="fas fa-barcode" style="margin-right: 4px;"></i><?php echo esc_html( $kode ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div style="margin-top: auto; padding-top: 15px; border-top: 1px solid #f0f0f0;">
                                <?php if ( ! empty( $biaya ) ) : ?>
                                    <div style="margin-bottom: 15px;">
                                        <small style="color: #666; font-size: 12px; display: block;">Biaya Sertifikasi:</small>
                                        <span style="color: var(--primary-color, #72161a); font-weight: 800; font-size: 17px;">
                                            Rp <?php echo number_format( (float) $biaya, 0, ',', '.' ); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <div style="display: flex; gap: 8px;">
                                    <a href="<?php the_permalink(); ?>" style="flex: 1; text-align: center; background: var(--secondary-color, #f89b29); color: #fff; padding: 10px 15px; border-radius: 6px; font-weight: 600; font-size: 13px; text-decoration: none;">
                                        Lihat Rincian &rarr;
                                    </a>
                                    <?php $wa_msg = urlencode( 'Halo Admin, saya mau bertanya mengenai sertifikasi ' . get_the_title() ); ?>
                                    <a href="https://wa.me/<?php echo esc_attr( $wa_number ); ?>?text=<?php echo esc_attr( $wa_msg ); ?>" target="_blank" rel="noopener noreferrer" title="Tanya via WA" style="background: #25D366; color: #fff; width: 40px; display: flex; align-items: center; justify-content: center; border-radius: 6px; text-decoration: none; font-size: 16px;">
                                        <i class="fab fa-whatsapp"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>

            <div style="margin-top: 50px; text-align: center;">
                <?php
                the_posts_pagination( array(
                    'mid_size'           => 2,
                    'prev_text'          => '<i class="fas fa-chevron-left"></i> Sebelumnya',
                    'next_text'          => 'Selanjutnya <i class="fas fa-chevron-right"></i>',
                    'screen_reader_text' => ' ',
                ) );
                ?>
            </div>
        <?php else : ?>
            <div style="background: #ffffff; padding: 60px 20px; border-radius: 12px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin: 30px 0;">
                <h3 style="color: #1f2937; margin-bottom: 10px;">Skema Sertifikasi Tidak Ditemukan</h3>
                <p style="color: #6b7280; max-width: 500px; margin: 0 auto 25px;">Tidak ada skema yang sesuai dengan kriteria pencarian atau filter yang Anda pilih.</p>
                <a href="<?php echo esc_url( $archive_url ); ?>" style="display: inline-block; background: var(--primary-color, #72161a); color: #ffffff; padding: 10px 25px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 14px;">
                    Tampilkan Semua Skema
                </a>
            </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render Konten Detail Single Skema Sertifikasi (Facade to LSP_Core_Skema)
     */
    public function render_single_skema_content( $raw_content = '' ) {
        return LSP_Core_Skema::get_instance()->render_single_skema_content( $raw_content );
    }

    /**
     * Render Konten Detail Single TUK (+ Peta Google Maps)
     */
    public function render_single_tuk_content( $raw_content = '' ) {
        $post_id = get_the_ID();
        $kode    = get_post_meta( $post_id, 'kode_tuk', true );
        $jenis   = get_post_meta( $post_id, 'jenis_tuk', true );
        $alamat  = get_post_meta( $post_id, 'alamat_tuk', true );
        $kontak  = get_post_meta( $post_id, 'kontak_tuk', true );
        $gmaps   = get_post_meta( $post_id, 'gmaps_tuk', true );

        ob_start();
        echo '<div style="background: #f8fafc; padding: 20px; border-left: 4px solid var(--secondary-color, #f89b29); margin-bottom: 25px; border-radius: 6px;">';
        if ( $kode ) {
            echo '<p style="margin: 0 0 8px 0;"><strong>🏷️ Kode TUK:</strong> <code>' . esc_html( $kode ) . '</code></p>';
        }
        if ( $jenis ) {
            echo '<p style="margin: 0 0 8px 0;"><strong>🏢 Jenis TUK:</strong> ' . esc_html( $jenis ) . '</p>';
        }
        if ( $alamat ) {
            echo '<p style="margin: 0 0 8px 0;"><strong>📍 Alamat Lengkap:</strong> ' . esc_html( $alamat ) . '</p>';
        }
        if ( $kontak ) {
            echo '<p style="margin: 0;"><strong>📞 Kontak / Telepon:</strong> ' . esc_html( $kontak ) . '</p>';
        }
        echo '</div>';

        echo apply_filters( 'the_content', $raw_content );

        if ( ! empty( $gmaps ) ) {
            echo '<div style="margin-top: 30px;">';
            echo '<h3 style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">🗺️ Peta Lokasi TUK</h3>';
            echo $this->render_gmaps_output( $gmaps, 380 );
            echo '</div>';
        }

        return ob_get_clean();
    }

    /**
     * ====================================================================
     * 10. HELPER & AKTIVASI PLUGIN
     * ====================================================================
     */
    public function enqueue_frontend_scripts() {
        if ( ! wp_style_is( 'font-awesome', 'enqueued' ) ) {
            wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );
        }
    }

    public function format_indo_date( $timestamp ) {
        if ( ! $timestamp ) return '—';
        $bulan = array(
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        );
        $d = date( 'j', $timestamp );
        $m = (int) date( 'n', $timestamp );
        $y = date( 'Y', $timestamp );
        return $d . ' ' . ( isset( $bulan[ $m ] ) ? $bulan[ $m ] : '' ) . ' ' . $y;
    }

    public static function activate() {
        $instance = self::get_instance();
        $instance->register_cpts_and_roles();

        $check_page = get_page_by_path( 'cek-sertifikat' );
        if ( ! $check_page ) {
            wp_insert_post( array(
                'post_title'     => 'Cek Sertifikat',
                'post_name'      => 'cek-sertifikat',
                'post_content'   => '<!-- wp:paragraph --><p>Silakan gunakan formulir di bawah ini untuk memverifikasi keabsahan sertifikat kompetensi yang diterbitkan oleh LSP.</p><!-- /wp:paragraph --> [cek_sertifikat]',
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed',
            ) );
        }

        flush_rewrite_rules();
    }

    /**
     * ====================================================================
     * 7. TOOLS: KELAYAKAN & MIGRASI DATA SKKNI
     * ====================================================================
     */

    /**
     * Ringkasan status kelengkapan Elemen/KUK per skema.
     *
     * @return array[]
     */
    public function collect_skkni_status_rows() {
        global $wpdb;

        $kegiatan_table = $wpdb->prefix . 'lsp_kegiatan';
        $has_kegiatan   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kegiatan_table ) ) === $kegiatan_table );

        $posts = get_posts( array(
            'post_type'      => 'skema',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $rows = array();
        foreach ( $posts as $post ) {
            $units       = self::get_skema_skkni_data( $post->ID );
            $n_unit      = count( $units );
            $n_elemen    = 0;
            $n_kuk       = 0;
            $n_tanpa     = 0;

            foreach ( $units as $u ) {
                $elemen = isset( $u['elemen'] ) && is_array( $u['elemen'] ) ? $u['elemen'] : array();
                $kuk_u  = 0;
                foreach ( $elemen as $e ) {
                    $n_elemen++;
                    if ( ! empty( $e['kuk'] ) ) {
                        $kuk_u = count( (array) $e['kuk'] );
                        $n_kuk += $kuk_u;
                    }
                }
                if ( empty( $elemen ) || 0 === $kuk_u ) {
                    $n_tanpa++;
                }
            }

            if ( $n_unit > 0 && 0 === $n_tanpa ) {
                $status = 'lengkap';
            } elseif ( $n_unit > 0 ) {
                $status = 'sebagian';
            } else {
                $status = 'kosong';
            }

            $n_kegiatan = 0;
            if ( $has_kegiatan ) {
                $n_kegiatan = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$kegiatan_table} WHERE skema_id = %d", $post->ID ) );
            }

            $rows[] = array(
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'kode'       => get_post_meta( $post->ID, 'kode_skema', true ),
                'unit'       => $n_unit,
                'elemen'     => $n_elemen,
                'kuk'        => $n_kuk,
                'tanpa'      => $n_tanpa,
                'status'     => $status,
                'kegiatan'   => $n_kegiatan,
                'punya_meta' => ( count( get_post_meta( $post->ID, 'skkni_data', true ) ) > 0 ),
            );
        }

        return $rows;
    }

    /**
     * Parsing teks import menjadi struktur skkni_data.
     *
     * Format baris:
     *   UNIT: KODE | JUDUL UNIT
     *   ELEMEN: 1 | Nama Elemen
     *   KUK: 1.1 Teks kriteria unjuk kerja
     *
     * Format JSON juga diterima: array unit, atau { "units": [...] }.
     *
     * @param string $raw
     * @return array{units:array,errors:string[]}
     */
    public function parse_skkni_import_text( $raw ) {
        $errors = array();
        $units  = array();
        $raw    = trim( (string) $raw );

        if ( '' === $raw ) {
            return array( 'units' => array(), 'errors' => array( 'Teks import masih kosong.' ) );
        }

        $first = substr( ltrim( $raw ), 0, 1 );

        if ( '{' === $first || '[' === $first ) {
            $decoded = json_decode( $raw, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                return array( 'units' => array(), 'errors' => array( 'JSON tidak valid: ' . json_last_error_msg() ) );
            }
            if ( isset( $decoded['units'] ) ) {
                $decoded = $decoded['units'];
            } elseif ( isset( $decoded['unit_kompetensi'] ) && is_array( $decoded['unit_kompetensi'] ) ) {
                $decoded = $decoded['unit_kompetensi'];
            } elseif ( isset( $decoded['kode_unit'] ) ) {
                // Satu unit saja (bentuk keluaran unit_ke_lsp_dict dari SKKNI Extractor).
                $decoded = array( $decoded );
            }
            if ( ! is_array( $decoded ) ) {
                return array( 'units' => array(), 'errors' => array( 'Struktur JSON harus berupa array unit.' ) );
            }
            foreach ( $decoded as $i => $u ) {
                // Alias: keluaran SKKNI Extractor memakai elemen_kompetensi / nomor_elemen / nama_elemen,
                // dan KUK berupa objek { nomor_kuk, uraian_kuk }. Semua bentuk itu diterima.
                $kode  = trim( (string) ( $u['kode_unit'] ?? $u['kode'] ?? '' ) );
                $judul = trim( (string) ( $u['judul_unit'] ?? $u['judul'] ?? '' ) );
                if ( '' === $kode || '' === $judul ) {
                    $errors[] = 'JSON unit #' . ( $i + 1 ) . ': kode_unit dan judul_unit wajib diisi.';
                    continue;
                }
                $raw_elemen = $u['elemen'] ?? $u['elemen_kompetensi'] ?? array();
                if ( ! is_array( $raw_elemen ) ) {
                    $raw_elemen = array();
                }

                $elemen_out = array();
                foreach ( $raw_elemen as $j => $e ) {
                    if ( ! is_array( $e ) ) {
                        continue;
                    }
                    $nama = trim( (string) ( $e['nama'] ?? $e['nama_elemen'] ?? $e['elemen'] ?? '' ) );
                    if ( '' === $nama ) {
                        $errors[] = 'JSON ' . $kode . ' elemen #' . ( $j + 1 ) . ': nama elemen kosong.';
                        continue;
                    }
                    $nomor_e = isset( $e['nomor'] ) ? (int) $e['nomor'] : ( isset( $e['nomor_elemen'] ) ? (int) $e['nomor_elemen'] : ( $j + 1 ) );

                    $kuk_out = array();
                    $raw_kuk = isset( $e['kuk'] ) ? $e['kuk'] : array();
                    if ( is_string( $raw_kuk ) ) {
                        $raw_kuk = preg_split( '/\R/', $raw_kuk );
                    }
                    foreach ( (array) $raw_kuk as $k ) {
                        if ( is_array( $k ) ) {
                            $k_no = trim( (string) ( $k['nomor_kuk'] ?? '' ) );
                            $k_ur = trim( (string) ( $k['uraian_kuk'] ?? $k['teks'] ?? $k['text'] ?? '' ) );
                            $k    = trim( $k_no . ' ' . $k_ur );
                        }
                        $k = trim( (string) $k );
                        if ( '' !== $k ) {
                            $kuk_out[] = $k;
                        }
                    }
                    if ( empty( $kuk_out ) ) {
                        $errors[] = 'JSON ' . $kode . ' elemen "' . $nama . '": KUK kosong.';
                    }
                    $elemen_out[] = array(
                        'nomor' => $nomor_e,
                        'nama'  => $nama,
                        'kuk'   => $kuk_out,
                    );
                }
                if ( empty( $elemen_out ) ) {
                    $errors[] = 'JSON ' . $kode . ': tidak ada elemen.';
                }

                $unit_out = array( 'kode_unit' => $kode, 'judul_unit' => $judul, 'elemen' => $elemen_out );
                $deskripsi = trim( (string) ( $u['deskripsi_unit'] ?? $u['deskripsi'] ?? '' ) );
                if ( '' !== $deskripsi ) {
                    $unit_out['deskripsi_unit'] = $deskripsi;
                }
                $units[] = $unit_out;
            }

            return array( 'units' => $units, 'errors' => $errors );
        }

        $lines     = preg_split( '/\R/', $raw );
        $cur_unit  = null;
        $cur_elemen = null;

        foreach ( $lines as $idx => $line ) {
            $no   = $idx + 1;
            $line = trim( $line );
            if ( '' === $line || 0 === strpos( $line, '#' ) ) {
                continue;
            }

            if ( preg_match( '/^UNIT\s*:\s*(.+)$/iu', $line, $m ) ) {
                $parts = explode( '|', $m[1], 2 );
                $kode  = trim( $parts[0] );
                $judul = isset( $parts[1] ) ? trim( $parts[1] ) : '';
                if ( '' === $kode || '' === $judul ) {
                    $errors[] = "Baris {$no}: UNIT harus berformat \"KODE | JUDUL UNIT\".";
                    $cur_unit = null;
                    $cur_elemen = null;
                    continue;
                }
                $units[]    = array( 'kode_unit' => $kode, 'judul_unit' => $judul, 'elemen' => array() );
                $cur_unit   = count( $units ) - 1;
                $cur_elemen = null;
                continue;
            }

            if ( preg_match( '/^ELEMEN\s*:\s*(.+)$/iu', $line, $m ) ) {
                if ( null === $cur_unit ) {
                    $errors[] = "Baris {$no}: ELEMEN ditemukan sebelum UNIT.";
                    continue;
                }
                $parts = explode( '|', $m[1], 2 );
                if ( isset( $parts[1] ) && ctype_digit( trim( $parts[0] ) ) ) {
                    $nomor = (int) trim( $parts[0] );
                    $nama  = trim( $parts[1] );
                } else {
                    $nomor = count( $units[ $cur_unit ]['elemen'] ) + 1;
                    $nama  = trim( $m[1] );
                }
                if ( '' === $nama ) {
                    $errors[] = "Baris {$no}: nama ELEMEN kosong.";
                    continue;
                }
                $units[ $cur_unit ]['elemen'][] = array( 'nomor' => $nomor, 'nama' => $nama, 'kuk' => array() );
                $cur_elemen = count( $units[ $cur_unit ]['elemen'] ) - 1;
                continue;
            }

            if ( preg_match( '/^KUK\s*:\s*(.+)$/iu', $line, $m ) ) {
                if ( null === $cur_elemen ) {
                    $errors[] = "Baris {$no}: KUK ditemukan sebelum ELEMEN.";
                    continue;
                }
                $teks = trim( $m[1] );
                if ( '' === $teks ) {
                    $errors[] = "Baris {$no}: teks KUK kosong.";
                    continue;
                }
                $units[ $cur_unit ]['elemen'][ $cur_elemen ]['kuk'][] = $teks;
                continue;
            }

            $errors[] = "Baris {$no}: format tidak dikenal — \"" . mb_substr( $line, 0, 60 ) . '".';
        }

        if ( empty( $units ) && empty( $errors ) ) {
            $errors[] = 'Tidak ada unit kompetensi yang terbaca.';
        }

        foreach ( $units as $u ) {
            if ( empty( $u['elemen'] ) ) {
                $errors[] = 'Unit ' . $u['kode_unit'] . ': belum punya elemen.';
                continue;
            }
            foreach ( $u['elemen'] as $e ) {
                if ( empty( $e['kuk'] ) ) {
                    $errors[] = 'Unit ' . $u['kode_unit'] . ' elemen "' . $e['nama'] . '": belum punya KUK.';
                }
            }
        }

        return array( 'units' => $units, 'errors' => $errors );
    }

    /**
     * Proses form import SKKNI.
     *
     * @return array{type:string,html:string}
     */
    public function handle_skkni_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return array( 'type' => 'error', 'html' => 'Akses ditolak.' );
        }
        if ( ! isset( $_POST['lsp_skkni_import_nonce'] ) || ! wp_verify_nonce( $_POST['lsp_skkni_import_nonce'], 'lsp_skkni_import' ) ) {
            return array( 'type' => 'error', 'html' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.' );
        }

        $skema_id = isset( $_POST['skema_id'] ) ? (int) $_POST['skema_id'] : 0;
        $dry_run  = ! empty( $_POST['dry_run'] );
        $raw      = isset( $_POST['skkni_text'] ) ? wp_unslash( $_POST['skkni_text'] ) : '';

        // Berkas JSON/TXT dari SKKNI Extractor diprioritaskan bila diunggah.
        if ( ! empty( $_FILES['skkni_file']['tmp_name'] ) && isset( $_FILES['skkni_file']['error'] ) && UPLOAD_ERR_OK === (int) $_FILES['skkni_file']['error'] ) {
            $nama_berkas = sanitize_file_name( $_FILES['skkni_file']['name'] );
            $ekstensi    = strtolower( pathinfo( $nama_berkas, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ekstensi, array( 'json', 'txt' ), true ) ) {
                return array( 'type' => 'error', 'html' => 'Berkas harus berekstensi .json atau .txt.' );
            }
            if ( (int) $_FILES['skkni_file']['size'] > 5 * 1024 * 1024 ) {
                return array( 'type' => 'error', 'html' => 'Berkas terlalu besar (maksimal 5 MB).' );
            }
            if ( ! is_uploaded_file( $_FILES['skkni_file']['tmp_name'] ) ) {
                return array( 'type' => 'error', 'html' => 'Unggahan berkas tidak valid.' );
            }
            $isi = file_get_contents( $_FILES['skkni_file']['tmp_name'] );
            if ( false === $isi || '' === trim( $isi ) ) {
                return array( 'type' => 'error', 'html' => 'Berkas kosong atau tidak dapat dibaca.' );
            }
            $raw = $isi;
        }

        if ( ! $skema_id || 'skema' !== get_post_type( $skema_id ) ) {
            return array( 'type' => 'error', 'html' => 'Skema tujuan tidak valid.' );
        }

        $parsed = $this->parse_skkni_import_text( $raw );

        if ( ! empty( $parsed['errors'] ) ) {
            $list = '';
            foreach ( array_slice( $parsed['errors'], 0, 25 ) as $err ) {
                $list .= '<li>' . esc_html( $err ) . '</li>';
            }
            $more = count( $parsed['errors'] ) > 25 ? '<p>… dan ' . ( count( $parsed['errors'] ) - 25 ) . ' kesalahan lain.</p>' : '';
            return array(
                'type' => 'error',
                'html' => '<strong>Import dibatalkan — data tidak disimpan.</strong><ul style="margin-left:18px;list-style:disc;">' . $list . '</ul>' . $more,
            );
        }

        $units     = $parsed['units'];
        $n_elemen  = 0;
        $n_kuk     = 0;
        $unit_lines = array();
        foreach ( $units as $u ) {
            $unit_lines[] = $u['kode_unit'] . ' | ' . $u['judul_unit'];
            foreach ( $u['elemen'] as $e ) {
                $n_elemen++;
                $n_kuk += count( $e['kuk'] );
            }
        }

        $ringkas = count( $units ) . ' unit, ' . $n_elemen . ' elemen, ' . $n_kuk . ' KUK';

        if ( $dry_run ) {
            return array(
                'type' => 'updated',
                'html' => '<strong>Validasi lolos (uji coba, belum disimpan):</strong> ' . esc_html( $ringkas ) . ' untuk skema <em>' . esc_html( get_the_title( $skema_id ) ) . '</em>.',
            );
        }

        update_post_meta( $skema_id, 'skkni_data', $units );
        update_post_meta( $skema_id, 'unit_kompetensi', implode( "\n", $unit_lines ) );

        return array(
            'type' => 'updated',
            'html' => '<strong>Tersimpan:</strong> ' . esc_html( $ringkas ) . ' untuk skema <em>' . esc_html( get_the_title( $skema_id ) ) . '</em>. '
                . 'Pencetakan FR.APL.02 / FR.IA.01 untuk skema ini sudah terbuka kembali.',
        );
    }

    /**
     * Halaman admin: kelayakan & migrasi data SKKNI.
     */
    public function render_skkni_migration_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Akses ditolak.' );
        }

        $notice = array();
        if ( isset( $_POST['lsp_skkni_import_submit'] ) ) {
            $notice = $this->handle_skkni_import();
        }

        $rows    = $this->collect_skkni_status_rows();
        $summary = array( 'lengkap' => 0, 'sebagian' => 0, 'kosong' => 0 );
        foreach ( $rows as $r ) {
            $summary[ $r['status'] ]++;
        }

        $badge = array(
            'lengkap'  => array( 'Lengkap', '#16a34a', '#dcfce7' ),
            'sebagian' => array( 'Belum lengkap', '#b45309', '#fef3c7' ),
            'kosong'   => array( 'Tanpa data SKKNI', '#b91c1c', '#fee2e2' ),
        );
        ?>
        <div class="wrap">
            <h1>Kelayakan &amp; Migrasi Data SKKNI</h1>

            <?php if ( ! empty( $notice ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo wp_kses_post( $notice['html'] ); ?></p></div>
            <?php endif; ?>

            <p style="max-width:900px;">
                Halaman ini memeriksa apakah setiap Master Skema sudah memiliki <strong>Unit, Elemen, dan KUK</strong>
                yang berasal dari SKKNI resmi. Skema yang belum lengkap tetap tampil di website, tetapi
                <strong>pencetakan FR.APL.02 dan FR.IA.01 ditahan</strong> sampai datanya dilengkapi.
            </p>

            <p>
                <strong><?php echo count( $rows ); ?></strong> skema &middot;
                <span style="color:#16a34a;"><?php echo (int) $summary['lengkap']; ?> lengkap</span> &middot;
                <span style="color:#b45309;"><?php echo (int) $summary['sebagian']; ?> belum lengkap</span> &middot;
                <span style="color:#b91c1c;"><?php echo (int) $summary['kosong']; ?> tanpa data</span>
            </p>

            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th style="width:40px;">No</th>
                        <th>Skema</th>
                        <th style="width:140px;">Kode</th>
                        <th style="width:70px;text-align:center;">Unit</th>
                        <th style="width:80px;text-align:center;">Elemen</th>
                        <th style="width:70px;text-align:center;">KUK</th>
                        <th style="width:140px;">Status</th>
                        <th style="width:110px;text-align:center;">Kegiatan</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="8">Belum ada Master Skema.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $i => $r ) :
                        $b = $badge[ $r['status'] ];
                    ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $r['id'] . '&action=edit' ) ); ?>"><strong><?php echo esc_html( $r['title'] ); ?></strong></a></td>
                            <td><code><?php echo esc_html( $r['kode'] ? $r['kode'] : '—' ); ?></code></td>
                            <td style="text-align:center;"><?php echo (int) $r['unit']; ?></td>
                            <td style="text-align:center;"><?php echo (int) $r['elemen']; ?></td>
                            <td style="text-align:center;"><?php echo (int) $r['kuk']; ?></td>
                            <td><span style="background:<?php echo esc_attr( $b[2] ); ?>;color:<?php echo esc_attr( $b[1] ); ?>;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;"><?php echo esc_html( $b[0] ); ?></span></td>
                            <td style="text-align:center;"><?php echo $r['kegiatan'] > 0 ? (int) $r['kegiatan'] : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <hr style="margin:32px 0;">

            <h2>Import Elemen &amp; KUK</h2>
            <p style="max-width:900px;">
                Tempel data SKKNI untuk satu skema. Satu skema satu kali import; import ulang akan
                <strong>menimpa</strong> data skema tersebut. Gunakan <em>uji coba</em> dulu untuk memeriksa hasil parsing.
            </p>

            <form method="post" enctype="multipart/form-data" style="max-width:900px;">
                <?php wp_nonce_field( 'lsp_skkni_import', 'lsp_skkni_import_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="skema_id">Skema tujuan</label></th>
                        <td>
                            <select name="skema_id" id="skema_id" required style="min-width:380px;">
                                <option value="">— pilih skema —</option>
                                <?php foreach ( $rows as $r ) : ?>
                                    <option value="<?php echo (int) $r['id']; ?>">
                                        <?php echo esc_html( $r['title'] ); ?><?php echo $r['kode'] ? ' (' . esc_html( $r['kode'] ) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="skkni_file">Berkas hasil ekstraksi</label></th>
                        <td>
                            <input type="file" name="skkni_file" id="skkni_file" accept=".json,.txt">
                            <p class="description">
                                Unggah berkas <code>.json</code> hasil <strong>SKKNI Extractor</strong> (satu unit atau array unit),
                                atau <code>.txt</code> berformat baris. Bila berkas diunggah, isian teks di bawah diabaikan.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="skkni_text">atau tempel data SKKNI</label></th>
                        <td>
                            <textarea name="skkni_text" id="skkni_text" rows="16" style="width:100%;font-family:monospace;font-size:13px;" placeholder="UNIT: J.63OPR00.004.2 | Menggunakan Perangkat Lunak Pengolah Kata Tingkat Dasar&#10;ELEMEN: 1 | Membuat dokumen&#10;KUK: 1.1 Aplikasi pengolah kata dibuka.&#10;KUK: 1.2 Dokumen baru dibuat sesuai kebutuhan.&#10;ELEMEN: 2 | Menyimpan dokumen&#10;KUK: 2.1 Dokumen disimpan pada folder yang ditentukan."></textarea>
                            <p class="description">
                                Format baris: <code>UNIT: KODE | JUDUL</code>, <code>ELEMEN: nomor | nama</code>, <code>KUK: teks</code>.
                                Baris diawali <code>#</code> diabaikan. JSON juga diterima.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Mode</th>
                        <td>
                            <label><input type="checkbox" name="dry_run" value="1" checked> Uji coba (validasi saja, jangan simpan)</label>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="lsp_skkni_import_submit" value="1" class="button button-primary">Proses</button>
                </p>
            </form>
        </div>
        <?php
    }
}

endif;

// Inisialisasi Plugin
LSP_Core_Management::get_instance();
register_activation_hook( __FILE__, array( 'LSP_Core_Management', 'activate' ) );
