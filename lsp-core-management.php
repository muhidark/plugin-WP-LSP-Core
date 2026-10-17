<?php
/**
 * Plugin Name: LSP Core Management & Sertifikat Checker
 * Plugin URI:  https://lspkomunikasi.id/
 * Description: Plugin inti (Core) untuk Website Lembaga Sertifikasi Profesi (LSP): Manajemen Skema Sertifikasi, Tempat Uji Kompetensi (TUK + Google Maps), Manajemen User Asesor (Multi-Bidang, Masa Berlaku MET 3 Tahun, & Approval Artikel), serta Sistem Verifikasi Keabsahan Sertifikat BNSP.
 * Version:     1.0.0
 * Author:      Muhadi Ganteng
 * Author URI:  https://instagram.com/yuk.sepedaan
 * Text Domain: lsp-core-management
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'LSP_Core_Management' ) ) :

class LSP_Core_Management {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
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

        // 4. User Profile Fields & Admin Columns untuk Role Asesor
        add_action( 'show_user_profile', array( $this, 'render_asesor_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_asesor_user_profile_fields' ) );
        add_action( 'user_new_form', array( $this, 'render_asesor_new_user_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_asesor_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_asesor_user_profile_fields' ) );
        add_action( 'user_register', array( $this, 'save_asesor_user_profile_fields' ) );

        add_filter( 'manage_users_columns', array( $this, 'asesor_user_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'asesor_user_column_content' ), 10, 3 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Batasi Asesor agar hanya melihat media miliknya sendiri saat upload gambar artikel
        add_filter( 'ajax_query_attachments_args', array( $this, 'restrict_asesor_media_library' ) );
        // Notifikasi di Admin jika ada artikel Asesor yang menunggu persetujuan (Pending Review)
        add_action( 'admin_notices', array( $this, 'pending_asesor_posts_notice' ) );
        // Notifikasi Validasi & Pencegahan Duplikasi Data Sertifikat
        add_action( 'admin_notices', array( $this, 'display_cert_admin_notices' ) );

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
        // A. Custom Post Type: Skema Sertifikasi
        register_post_type( 'skema', array(
            'labels'      => array(
                'name'               => __( 'Skema Sertifikasi', 'lsp-core-management' ),
                'singular_name'      => __( 'Skema', 'lsp-core-management' ),
                'menu_name'          => __( 'Skema Sertifikasi', 'lsp-core-management' ),
                'add_new'            => __( 'Tambah Skema', 'lsp-core-management' ),
                'add_new_item'       => __( 'Tambah Skema Baru', 'lsp-core-management' ),
                'edit_item'          => __( 'Edit Skema', 'lsp-core-management' ),
                'view_item'          => __( 'Lihat Skema', 'lsp-core-management' ),
                'all_items'          => __( 'Semua Skema', 'lsp-core-management' ),
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

        // B. Custom Post Type: Tempat Uji Kompetensi (TUK)
        register_post_type( 'tuk', array(
            'labels'      => array(
                'name'               => __( 'Tempat Uji Kompetensi', 'lsp-core-management' ),
                'singular_name'      => __( 'TUK', 'lsp-core-management' ),
                'menu_name'          => __( 'Tempat Uji (TUK)', 'lsp-core-management' ),
                'add_new'            => __( 'Tambah TUK', 'lsp-core-management' ),
                'add_new_item'       => __( 'Tambah TUK Baru', 'lsp-core-management' ),
                'edit_item'          => __( 'Edit TUK', 'lsp-core-management' ),
                'view_item'          => __( 'Lihat TUK', 'lsp-core-management' ),
                'all_items'          => __( 'Semua TUK', 'lsp-core-management' ),
                'search_items'       => __( 'Cari TUK', 'lsp-core-management' ),
                'not_found'          => __( 'Tidak ada TUK ditemukan.', 'lsp-core-management' ),
            ),
            'public'        => true,
            'has_archive'   => true,
            'menu_icon'     => 'dashicons-location-alt',
            'menu_position' => 25,
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

        // D. Custom User Role: Asesor LSP ('asesor')
        // Punya hak menulis posting & upload gambar, tetapi TIDAK punya publish_posts (harus di-approve Admin)
        $asesor_caps = array(
            'read'                   => true,
            'edit_posts'             => true,
            'delete_posts'           => true,
            'upload_files'           => true,
            'publish_posts'          => false,
            'edit_published_posts'   => false,
            'delete_published_posts' => false,
        );
        if ( ! get_role( 'asesor' ) ) {
            add_role( 'asesor', __( 'Asesor LSP', 'lsp-core-management' ), $asesor_caps );
        } else {
            // Pastikan capabilities selalu sinkron (bisa menulis & upload, tapi butuh approval admin untuk publish)
            $role = get_role( 'asesor' );
            if ( $role ) {
                $role->add_cap( 'read' );
                $role->add_cap( 'edit_posts' );
                $role->add_cap( 'delete_posts' );
                $role->add_cap( 'upload_files' );
                $role->remove_cap( 'publish_posts' );
                $role->remove_cap( 'edit_published_posts' );
                $role->remove_cap( 'delete_published_posts' );
            }
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
     * 2A. Render & Save Meta Box Skema
     */
    public function render_skema_meta_box( $post ) {
        wp_nonce_field( 'lsp_skema_save_meta', 'lsp_skema_meta_nonce' );

        $kode_skema      = get_post_meta( $post->ID, 'kode_skema', true );
        $level_skema     = get_post_meta( $post->ID, 'level_skema', true );
        if ( '' === $level_skema ) {
            $level_skema = get_post_meta( $post->ID, 'level', true );
        }
        $jenis_kemasan   = get_post_meta( $post->ID, 'jenis_kemasan', true );
        $biaya           = get_post_meta( $post->ID, 'biaya_sertifikasi', true );
        $persyaratan     = get_post_meta( $post->ID, 'persyaratan_skema', true );
        $persyaratan_adm = get_post_meta( $post->ID, 'persyaratan_admin', true );
        $unit_kompetensi = get_post_meta( $post->ID, 'unit_kompetensi', true );
        ?>
        <style>
            .lsp-meta-row { margin-bottom: 20px; }
            .lsp-meta-row label { font-weight: bold; display: block; margin-bottom: 8px; font-size: 14px; }
            .lsp-meta-row input[type="text"], .lsp-meta-row input[type="number"], .lsp-meta-row select, .lsp-meta-row textarea { width: 100%; max-width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            .lsp-meta-row p.description { color: #666; font-size: 12px; margin-top: 5px; font-style: italic; }
        </style>

        <div class="lsp-meta-row">
            <label for="kode_skema">Kode Skema</label>
            <input type="text" id="kode_skema" name="kode_skema" value="<?php echo esc_attr( $kode_skema ); ?>" placeholder="Contoh: SS.01.001.2023" />
        </div>

        <div class="lsp-meta-row">
            <label for="level_skema">Level Skema Sertifikasi (Angka 1 - 9)</label>
            <input type="number" id="level_skema" name="level_skema" min="1" max="9" step="1" value="<?php echo esc_attr( $level_skema ); ?>" placeholder="Contoh: 3" style="width: 100%; max-width: 220px;" />
            <p class="description">Masukkan nilai level skema (angka 1 s/d 9). Pada kartu skema akan tertulis: <strong>Level <?php echo $level_skema ? esc_html( $level_skema ) : '...'; ?></strong></p>
        </div>

        <div class="lsp-meta-row">
            <label for="jenis_kemasan">Jenis Kemasan</label>
            <select id="jenis_kemasan" name="jenis_kemasan">
                <option value="">-- Pilih Jenis Kemasan --</option>
                <?php
                $options_kemasan = array(
                    'KKNI'    => 'KKNI',
                    'Okupasi' => 'Okupasi',
                    'Klaster' => 'Klaster',
                );
                foreach ( $options_kemasan as $val => $lbl ) {
                    echo '<option value="' . esc_attr( $val ) . '" ' . selected( $jenis_kemasan, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
                }
                ?>
            </select>
            <p class="description">Pilih jenis kemasan: KKNI, Okupasi, atau Klaster.</p>
        </div>

        <div class="lsp-meta-row">
            <label for="biaya_sertifikasi">Biaya Sertifikasi (Rp)</label>
            <input type="text" id="biaya_sertifikasi" name="biaya_sertifikasi" value="<?php echo esc_attr( $biaya ); ?>" placeholder="Contoh: 1500000" />
            <p class="description">Tulis angkanya saja, tanpa titik atau koma.</p>
        </div>

        <div class="lsp-meta-row">
            <label for="persyaratan_skema">1. Persyaratan Dasar</label>
            <textarea id="persyaratan_skema" name="persyaratan_skema" rows="4" placeholder="Contoh: Pendidikan minimal SMA..."><?php echo esc_textarea( $persyaratan ); ?></textarea>
            <p class="description">Gunakan baris baru (Enter) untuk memisahkan setiap poin persyaratan.</p>
        </div>

        <div class="lsp-meta-row">
            <label for="persyaratan_admin">2. Persyaratan Administrasi</label>
            <textarea id="persyaratan_admin" name="persyaratan_admin" rows="4" placeholder="Contoh: Fotocopy KTP, Pas Foto 3x4..."><?php echo esc_textarea( $persyaratan_adm ); ?></textarea>
            <p class="description">Gunakan baris baru (Enter) untuk memisahkan setiap poin persyaratan administrasi.</p>
        </div>

        <div class="lsp-meta-row">
            <label for="unit_kompetensi">Daftar Unit Kompetensi</label>
            <textarea id="unit_kompetensi" name="unit_kompetensi" rows="8" placeholder="J.620100.001.01 | Mengumpulkan Kebutuhan Teknis&#10;J.620100.002.01 | Mendesain Jaringan..."><?php echo esc_textarea( $unit_kompetensi ); ?></textarea>
            <p class="description"><strong>Format Wajib:</strong> Kode Unit [spasi] | [spasi] Judul Unit. <br>Gunakan garis lurus (|) sebagai pemisah. Satu baris untuk satu unit.</p>
        </div>
        <?php
    }

    public function save_skema_meta( $post_id ) {
        if ( ! isset( $_POST['lsp_skema_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['lsp_skema_meta_nonce'], 'lsp_skema_save_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['kode_skema'] ) ) {
            update_post_meta( $post_id, 'kode_skema', sanitize_text_field( $_POST['kode_skema'] ) );
        }
        if ( isset( $_POST['level_skema'] ) ) {
            $lvl = sanitize_text_field( $_POST['level_skema'] );
            update_post_meta( $post_id, 'level_skema', $lvl );
            update_post_meta( $post_id, 'level', $lvl );
        }
        if ( isset( $_POST['jenis_kemasan'] ) ) {
            update_post_meta( $post_id, 'jenis_kemasan', sanitize_text_field( $_POST['jenis_kemasan'] ) );
        }
        if ( isset( $_POST['biaya_sertifikasi'] ) ) {
            update_post_meta( $post_id, 'biaya_sertifikasi', sanitize_text_field( $_POST['biaya_sertifikasi'] ) );
        }
        if ( isset( $_POST['persyaratan_skema'] ) ) {
            update_post_meta( $post_id, 'persyaratan_skema', wp_kses_post( $_POST['persyaratan_skema'] ) );
        }
        if ( isset( $_POST['persyaratan_admin'] ) ) {
            update_post_meta( $post_id, 'persyaratan_admin', wp_kses_post( $_POST['persyaratan_admin'] ) );
        }
        if ( isset( $_POST['unit_kompetensi'] ) ) {
            update_post_meta( $post_id, 'unit_kompetensi', wp_kses_post( $_POST['unit_kompetensi'] ) );
        }

        // Otomatis buatkan / perbarui gambar Featured Image (Auto-Cover UI) jika belum ada foto kustom
        $this->maybe_generate_skema_auto_thumbnail( $post_id );
    }

    /**
     * Otomatis membuat / memperbarui gambar sampul (Featured Image) Skema jika belum ada foto kustom
     */
    public function maybe_generate_skema_auto_thumbnail( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'skema' !== $post->post_type || 'auto-draft' === $post->post_status ) {
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

        $slug     = $post->post_name ?: 'post-' . $post_id;
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

        <div class="lsp-cert-field">
            <label for="kode_blanko"><?php _e( 'Nomor / Kode Blanko Sertifikat (Wajib & Unik)', 'lsp-core-management' ); ?> *</label>
            <input type="text" id="kode_blanko" name="kode_blanko" value="<?php echo esc_attr( $kode_blanko ); ?>" placeholder="Contoh: K24.01.000123 / BNSP-00123" required />
            <p class="description"><?php _e( 'Nomor seri / kode blanko unik yang tertera pada sertifikat fisik. Digunakan oleh publik untuk verifikasi.', 'lsp-core-management' ); ?></p>
        </div>

        <div class="lsp-cert-field">
            <label for="nama_pemegang"><?php _e( 'Nama Lengkap Pemegang Sertifikat (Asesi)', 'lsp-core-management' ); ?> *</label>
            <input type="text" id="nama_pemegang" name="nama_pemegang" value="<?php echo esc_attr( $nama_pemegang ); ?>" placeholder="Contoh: Ahmad Fauzi, S.Kom." required />
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
        <?php
    }

    /**
     * Normalisasi Nomor Identitas Sertifikat (Kode Blanko / No. Registrasi)
     * Membersihkan spasi tak sengaja dan karakter kontrol tanpa merusak tanda baca resmi.
     */
    public static function normalize_cert_number( $number ) {
        if ( ! is_scalar( $number ) ) {
            return '';
        }
        // Hapus karakter kontrol dan non-breaking space (U+00A0)
        $clean = preg_replace( '/[\x00-\x1F\x7F\xC2\xA0]/u', ' ', (string) $number );
        $clean = sanitize_text_field( $clean );
        // Satukan spasi berurutan dan hapus spasi di awal/akhir
        return trim( preg_replace( '/\s+/', ' ', $clean ) );
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

        // 9. Eksekusi Penyimpanan atau Pencegahan Konflik
        if ( ! empty( $errors ) ) {
            // TERJADI KONFLIK ATAU VALIDASI GAGAL:
            // - Jangan menimpa sertifikat lain.
            // - Jangan menghapus data lama.
            // - Pertahankan metadata lama.
            // - Simpan notifikasi error untuk ditampilkan ke admin.
            set_transient( 'lsp_cert_save_errors_' . get_current_user_id(), $errors, 45 );
            return;
        }

        // VALIDASI BERHASIL: Simpan metadata baru
        update_post_meta( $post_id, 'kode_blanko', $kode_blanko );
        update_post_meta( $post_id, 'nama_pemegang', $nama_pemegang );
        update_post_meta( $post_id, 'no_registrasi', $no_registrasi );
        update_post_meta( $post_id, 'skema_sertifikasi', $skema );
        update_post_meta( $post_id, 'tanggal_terbit', $tanggal_terbit );

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
        wp_nonce_field( 'lsp_save_asesor_profile', 'lsp_asesor_profile_nonce' );

        $user_id            = $user ? $user->ID : 0;
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
            <p class="description" style="margin-bottom: 15px;">
                <?php _e( 'Lengkapi informasi lisensi MET BNSP, masa berlaku sertifikat asesor, multi-bidang keahlian, deskripsi, dan tautan sosial media untuk ditampilkan di halaman Asesor.', 'lsp-core-management' ); ?>
            </p>

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
                            <div style="flex: 1; min-width: 260px;">
                                <input type="text" name="foto_asesor" id="foto_asesor" value="<?php echo esc_attr( $foto_asesor ); ?>" class="regular-text" placeholder="URL foto atau pilih dari Media Library..." />
                                <div style="margin-top: 8px; display: flex; gap: 8px;">
                                    <button type="button" class="button" id="btn_upload_foto_asesor"><?php _e( 'Pilih / Unggah Foto', 'lsp-core-management' ); ?></button>
                                    <button type="button" class="button" id="btn_remove_foto_asesor" <?php echo empty( $foto_asesor ) ? 'style="display:none;"' : ''; ?>><?php _e( 'Hapus Foto', 'lsp-core-management' ); ?></button>
                                </div>
                                <p class="description"><?php _e( 'Jika dikosongkan, sistem otomatis menggunakan Gravatar akun.', 'lsp-core-management' ); ?></p>
                            </div>
                        </div>
                    </td>
                </tr>

                <!-- 2. Nomor MET -->
                <tr>
                    <th><label for="nomor_met"><?php _e( 'Nomor Registrasi / Sertifikat MET', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <input type="text" name="nomor_met" id="nomor_met" value="<?php echo esc_attr( $nomor_met ); ?>" class="regular-text" placeholder="Contoh: MET.000.001234.2024" />
                        <p class="description"><?php _e( 'Nomor Registrasi Asesor Kompetensi yang diterbitkan oleh BNSP.', 'lsp-core-management' ); ?></p>
                    </td>
                </tr>

                <!-- 3. Tanggal Dikeluarkan Sertifikat MET (Expired 3 Tahun) -->
                <tr>
                    <th><label for="tanggal_terbit_met"><?php _e( 'Tanggal Dikeluarkan Sertifikat MET', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <input type="date" name="tanggal_terbit_met" id="tanggal_terbit_met" value="<?php echo esc_attr( $tanggal_terbit_met ); ?>" class="regular-text" style="max-width: 240px;" />
                        <p class="description"><?php _e( 'Sertifikat kompetensi Asesor berlaku selama 3 tahun sejak tanggal dikeluarkan.', 'lsp-core-management' ); ?></p>
                        <?php echo $status_met_html; ?>
                    </td>
                </tr>

                <!-- 4. Bidang Keahlian (Multi-Select / Bisa Lebih dari Satu) -->
                <tr>
                    <th><label><?php _e( 'Bidang / Skema Keahlian (Bisa Pilih Lebih dari Satu)', 'lsp-core-management' ); ?></label></th>
                    <td>
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
                    </td>
                </tr>

                <!-- 5. Deskripsi / Biografi / Catatan Bebas Asesor -->
                <tr>
                    <th><label for="deskripsi_asesor"><?php _e( 'Deskripsi / Biografi & Pengalaman Asesor', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <textarea name="deskripsi_asesor" id="deskripsi_asesor" rows="4" class="large-text" style="max-width: 550px;" placeholder="Tuliskan deskripsi singkat, latar belakang profesional, pengalaman industri, atau informasi tambahan lainnya..."><?php echo esc_textarea( $deskripsi_asesor ); ?></textarea>
                        <p class="description"><?php _e( 'Teks deskripsi ini akan ditampilkan pada kartu / profil Asesor di halaman depan.', 'lsp-core-management' ); ?></p>
                    </td>
                </tr>

                <!-- 6. Tautan Sosial Media & Profesional Asesor -->
                <tr>
                    <th><label><?php _e( 'Tautan Sosial Media & Portofolio', 'lsp-core-management' ); ?></label></th>
                    <td>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; max-width: 550px;">
                            <div>
                                <label for="asesor_linkedin" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">🔗 LinkedIn URL</label>
                                <input type="url" name="asesor_linkedin" id="asesor_linkedin" value="<?php echo esc_attr( $asesor_linkedin ); ?>" class="regular-text" style="width: 100%;" placeholder="https://linkedin.com/in/username" />
                            </div>
                            <div>
                                <label for="asesor_instagram" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">📸 Instagram URL</label>
                                <input type="url" name="asesor_instagram" id="asesor_instagram" value="<?php echo esc_attr( $asesor_instagram ); ?>" class="regular-text" style="width: 100%;" placeholder="https://instagram.com/username" />
                            </div>
                            <div>
                                <label for="asesor_facebook" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">📘 Facebook URL</label>
                                <input type="url" name="asesor_facebook" id="asesor_facebook" value="<?php echo esc_attr( $asesor_facebook ); ?>" class="regular-text" style="width: 100%;" placeholder="https://facebook.com/username" />
                            </div>
                            <div>
                                <label for="asesor_website" style="font-weight: 600; font-size: 12.5px; display: block; margin-bottom: 4px;">🌐 Website / Link Lainnya</label>
                                <input type="url" name="asesor_website" id="asesor_website" value="<?php echo esc_attr( $asesor_website ); ?>" class="regular-text" style="width: 100%;" placeholder="https://website-anda.com" />
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

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
        <?php
    }

    public function save_asesor_user_profile_fields( $user_id ) {
        if ( ! isset( $_POST['lsp_asesor_profile_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['lsp_asesor_profile_nonce'], 'lsp_save_asesor_profile' ) ) return;
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;

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

        // Submenu Pengaturan LSP di bawah Skema Sertifikasi
        add_submenu_page(
            'edit.php?post_type=skema',
            __( 'Pengaturan Core LSP', 'lsp-core-management' ),
            __( 'Pengaturan LSP', 'lsp-core-management' ),
            'manage_options',
            'lsp-core-settings',
            array( $this, 'render_settings_page' )
        );

        // Submenu Panduan Penggunaan di bawah Skema Sertifikasi
        add_submenu_page(
            'edit.php?post_type=skema',
            __( 'Panduan Penggunaan Tema & Plugin LSP', 'lsp-core-management' ),
            __( '📖 Panduan LSP', 'lsp-core-management' ),
            'edit_posts',
            'lsp-documentation-guide',
            array( $this, 'render_documentation_page' )
        );

        // Menu Top-Level Pintasan untuk Manajemen User Asesor LSP
        add_menu_page(
            __( 'Manajemen Asesor LSP', 'lsp-core-management' ),
            __( 'Asesor LSP', 'lsp-core-management' ),
            'list_users',
            'lsp-asesor-directory',
            array( $this, 'render_asesor_admin_page' ),
            'dashicons-businessman',
            25
        );

        // Menu Top-Level Pusat Panduan & Dokumentasi LSP (Agar admin masa depan mudah mempelajari sistem)
        add_menu_page(
            __( 'Pusat Panduan & Dokumentasi LSP', 'lsp-core-management' ),
            __( 'Panduan LSP', 'lsp-core-management' ),
            'edit_posts',
            'lsp-documentation-guide',
            array( $this, 'render_documentation_page' ),
            'dashicons-book-alt',
            29
        );
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

    public function render_import_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $message = '';
        if ( isset( $_POST['lsp_cert_import_submit'] ) && check_admin_referer( 'lsp_cert_import_action', 'lsp_cert_import_nonce' ) ) {
            if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
                $file   = $_FILES['csv_file']['tmp_name'];
                $handle = fopen( $file, 'r' );
                if ( false !== $handle ) {
                    $header   = fgetcsv( $handle, 1000, ',' );
                    $imported = 0;
                    $updated  = 0;

                    while ( false !== ( $row = fgetcsv( $handle, 1000, ',' ) ) ) {
                        if ( empty( $row[0] ) || empty( $row[1] ) ) continue;

                        $kode_blanko   = sanitize_text_field( trim( $row[0] ) );
                        $nama_pemegang = sanitize_text_field( trim( $row[1] ) );
                        $no_reg        = isset( $row[2] ) ? sanitize_text_field( trim( $row[2] ) ) : '';
                        $skema         = isset( $row[3] ) ? sanitize_text_field( trim( $row[3] ) ) : '';
                        $tgl_terbit    = isset( $row[4] ) ? sanitize_text_field( trim( $row[4] ) ) : '';

                        $existing = get_posts( array(
                            'post_type'  => 'data_sertifikat',
                            'meta_key'   => 'kode_blanko',
                            'meta_value' => $kode_blanko,
                            'fields'     => 'ids',
                        ) );

                        if ( ! empty( $existing ) ) {
                            $post_id = $existing[0];
                            wp_update_post( array(
                                'ID'         => $post_id,
                                'post_title' => $kode_blanko . ' - ' . $nama_pemegang,
                            ) );
                            $updated++;
                        } else {
                            $post_id = wp_insert_post( array(
                                'post_type'   => 'data_sertifikat',
                                'post_title'  => $kode_blanko . ' - ' . $nama_pemegang,
                                'post_status' => 'publish',
                            ) );
                            $imported++;
                        }

                        if ( $post_id ) {
                            update_post_meta( $post_id, 'kode_blanko', $kode_blanko );
                            update_post_meta( $post_id, 'nama_pemegang', $nama_pemegang );
                            update_post_meta( $post_id, 'no_registrasi', $no_reg );
                            update_post_meta( $post_id, 'skema_sertifikasi', $skema );
                            update_post_meta( $post_id, 'tanggal_terbit', $tgl_terbit );
                        }
                    }
                    fclose( $handle );
                    $message = '<div class="updated notice is-dismissible"><p><strong>Proses Import Selesai!</strong> Berhasil menambahkan <strong>' . $imported . '</strong> data baru dan memperbarui <strong>' . $updated . '</strong> data sertifikat.</p></div>';
                }
            } else {
                $message = '<div class="error notice is-dismissible"><p>Silakan pilih file CSV terlebih dahulu.</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e( 'Import Data Pemegang Sertifikat (CSV)', 'lsp-core-management' ); ?></h1>
            <p><?php _e( 'Gunakan fitur ini untuk mengunggah banyak data pemegang sertifikat asesi sekaligus menggunakan file CSV / Excel.', 'lsp-core-management' ); ?></p>
            <?php echo $message; ?>
            <div style="background:#fff; border:1px solid #ccd0d4; padding:25px; border-radius:8px; max-width:700px; margin-top:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <h3>Langkah-langkah Import:</h3>
                <ol style="line-height:1.8; margin-bottom:20px;">
                    <li>Unduh template file CSV terlebih dahulu: <br>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=data_sertifikat&page=lsp-cert-import&lsp_cert_download_csv_template=1' ) ); ?>" class="button button-secondary" style="margin-top:5px;">
                            <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Unduh Template CSV Contoh
                        </a>
                    </li>
                    <li>Buka template menggunakan Excel atau Spreadsheet, lalu isi kolom sesuai format (format tanggal: <code>YYYY-MM-DD</code> misal <code>2024-05-20</code>).</li>
                    <li>Simpan kembali file dalam format <strong>CSV (Comma Delimited)</strong>.</li>
                    <li>Pilih file di bawah ini dan klik <strong>Unggah &amp; Proses Import</strong>.</li>
                </ol>
                <hr style="margin:20px 0;">
                <form method="POST" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'lsp_cert_import_action', 'lsp_cert_import_nonce' ); ?>
                    <p>
                        <label for="csv_file" style="font-weight:bold; display:block; margin-bottom:8px;">Pilih File CSV:</label>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" required />
                    </p>
                    <p style="margin-top:20px;">
                        <input type="submit" name="lsp_cert_import_submit" class="button button-primary button-large" value="Unggah &amp; Proses Import" />
                    </p>
                </form>
            </div>
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
     * Render Konten Detail Single Skema Sertifikasi
     */
    public function render_single_skema_content( $raw_content = '' ) {
        $post_id       = get_the_ID();
        $kode          = get_post_meta( $post_id, 'kode_skema', true );
        $level         = get_post_meta( $post_id, 'level_skema', true );
        if ( empty( $level ) ) {
            $level = get_post_meta( $post_id, 'level', true );
        }
        $jenis_kemasan = get_post_meta( $post_id, 'jenis_kemasan', true );
        $biaya         = get_post_meta( $post_id, 'biaya_sertifikasi', true );
        $syarat        = get_post_meta( $post_id, 'persyaratan_skema', true );
        $syarat_admin  = get_post_meta( $post_id, 'persyaratan_admin', true );
        $unit          = get_post_meta( $post_id, 'unit_kompetensi', true );

        $wa_number = get_option( 'lsp_core_wa_number', '6281328430600' );
        $reg_url   = get_option( 'lsp_core_register_url', '#' );

        ob_start();

        // 1. Box Info (Kode, Level, Jenis Kemasan & Biaya)
        echo '<div style="background: #f0f9ff; padding: 20px; border-left: 4px solid var(--secondary-color, #f89b29); margin-bottom: 20px; border-radius: 4px;">';
        if ( $kode ) {
            echo '<div style="margin-bottom: 10px; font-size: 16px;"><strong>Kode Skema:</strong> ' . esc_html( $kode ) . '</div>';
        }
        if ( $level ) {
            echo '<div style="margin-bottom: 10px; font-size: 16px;"><strong>Level:</strong> <span style="display: inline-block; background: #fff8f0; color: #72161a; border: 1px solid #f89b29; padding: 2px 10px; border-radius: 4px; font-weight: 700;">Level ' . esc_html( $level ) . '</span></div>';
        }
        if ( $jenis_kemasan ) {
            echo '<div style="margin-bottom: 10px; font-size: 16px;"><strong>Jenis Kemasan:</strong> <span style="display: inline-block; background: #e0f2fe; color: #0284c7; padding: 3px 12px; border-radius: 4px; font-weight: 700;">' . esc_html( $jenis_kemasan ) . '</span></div>';
        }
        if ( $biaya ) {
            echo '<div><strong style="color: var(--primary-color, #72161a); font-size: 18px;">Biaya Sertifikasi: Rp ' . number_format( (float) $biaya, 0, ',', '.' ) . '</strong></div>';
        }
        echo '</div>';

        // 1b. Action Buttons (Daftar & Konsultasi)
        $judul_skema     = get_the_title( $post_id );
        $pesan_wa_encode = urlencode( "Halo Admin, saya mau konsultasi untuk sertifikasi '{$judul_skema}'" );
        $js_trigger      = "event.preventDefault(); var floatingBtn = document.querySelector('#wa-qc-toggle-btn, .wa-qc-toggle-btn, .wa-quick-connect-floating, .wa-floating-button, .wa-chat-button, #wa-widget'); if(floatingBtn) { floatingBtn.click(); var msgBox = document.querySelector('#wa-qc-floating-container textarea[name=\\'wa_message\\']'); if(msgBox && !msgBox.value) { msgBox.value = 'Halo Admin, saya mau konsultasi untuk sertifikasi \\'" . esc_js( $judul_skema ) . "\\''; } } else { window.open('https://wa.me/" . esc_js( $wa_number ) . "?text=" . $pesan_wa_encode . "', '_blank'); }";

        echo '<div style="margin-bottom: 30px; display: flex; gap: 10px; flex-wrap: wrap;">';
        echo '<a href="' . esc_url( $reg_url ?: '#' ) . '" style="background: #0ea5e9; color: #fff; padding: 12px 25px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">📝 Daftar Sekarang</a>';
        echo '<a href="#" onclick="' . esc_attr( $js_trigger ) . '" style="background: #25D366; color: #fff; padding: 12px 25px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">💬 Konsultasi via WA</a>';
        echo '</div>';

        // Teks utama artikel
        echo apply_filters( 'the_content', $raw_content );

        // 2a. Persyaratan Dasar
        if ( $syarat ) {
            echo '<h3 style="margin-top: 40px; border-bottom: 2px solid #eee; padding-bottom: 10px;">1. Persyaratan Dasar</h3>';
            echo '<div style="background: #fff5f2; padding: 20px; border-radius: 4px; border: 1px solid #f25a2b; margin-top: 15px; line-height: 1.8;">';
            echo nl2br( esc_html( $syarat ) );
            echo '</div>';
        }

        // 2b. Persyaratan Administrasi
        if ( $syarat_admin ) {
            echo '<h3 style="margin-top: 30px; border-bottom: 2px solid #eee; padding-bottom: 10px;">2. Persyaratan Administrasi</h3>';
            echo '<div style="background: #fff5f2; padding: 20px; border-radius: 4px; border: 1px solid #f25a2b; margin-top: 15px; line-height: 1.8;">';
            echo nl2br( esc_html( $syarat_admin ) );
            echo '</div>';
        }

        // 3. Tabel Unit Kompetensi
        if ( $unit ) {
            echo '<h3 style="margin-top: 40px; border-bottom: 2px solid #eee; padding-bottom: 10px;">Daftar Unit Kompetensi</h3>';
            echo '<div style="overflow-x: auto;">';
            echo '<table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 15px;">';
            echo '<thead><tr style="background: #f25a2b; color: #fff;">';
            echo '<th style="padding: 12px; border: 1px solid #ddd; width: 50px; text-align:center;">No</th>';
            echo '<th style="padding: 12px; border: 1px solid #ddd; width: 200px;">Kode Unit</th>';
            echo '<th style="padding: 12px; border: 1px solid #ddd;">Judul Unit</th>';
            echo '</tr></thead><tbody>';

            $lines = explode( "\n", $unit );
            $no    = 1;
            foreach ( $lines as $line ) {
                if ( '' === trim( $line ) ) continue;
                $parts      = explode( '|', $line );
                $kode_unit  = isset( $parts[0] ) ? trim( $parts[0] ) : '';
                $judul_unit = isset( $parts[1] ) ? trim( $parts[1] ) : '';

                echo '<tr>';
                echo '<td style="padding: 10px; border: 1px solid #ddd; text-align:center;">' . $no++ . '</td>';
                echo '<td style="padding: 10px; border: 1px solid #ddd; font-family: monospace;">' . esc_html( $kode_unit ) . '</td>';
                echo '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html( $judul_unit ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        return ob_get_clean();
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
}

endif;

// Inisialisasi Plugin
LSP_Core_Management::get_instance();
register_activation_hook( __FILE__, array( 'LSP_Core_Management', 'activate' ) );
