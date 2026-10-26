<?php
/**
 * Modul Skema Sertifikasi & Visual SKKNI Builder
 *
 * @package LSP_Core_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LSP_Core_Skema {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // CPT & Hooks (hanya didaftarkan langsung jika kelas ini dijalankan standalone tanpa LSP_Core_Management)
        if ( ! class_exists( 'LSP_Core_Management' ) ) {
            add_action( 'init', array( $this, 'register_cpt' ) );
            add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
            add_action( 'save_post_skema', array( $this, 'save_skema_meta' ) );
            add_filter( 'manage_skema_posts_columns', array( $this, 'custom_columns' ) );
            add_action( 'manage_skema_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
            add_action( 'pre_get_posts', array( $this, 'archive_query' ) );
        }
    }

    /**
     * 1. Register Custom Post Type: Skema
     */
    public function register_cpt() {
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
    }

    /**
     * 2. Register Meta Box Skema
     */
    public function register_meta_boxes() {
        add_meta_box(
            'lsp_skema_meta',
            __( 'Data Detail Skema Sertifikasi & Dokumen SKKNI', 'lsp-core-management' ),
            array( $this, 'render_skema_meta_box' ),
            'skema',
            'normal',
            'high'
        );
    }

    /**
     * 3. Render Visual Meta Box Skema
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
        $acuan_skkni     = get_post_meta( $post->ID, 'acuan_skkni', true );

        // Ambil data unit SKKNI terstruktur
        $units_data = self::get_skema_skkni_data( $post->ID );
        ?>
        <style>
            .lsp-meta-row { margin-bottom: 22px; }
            .lsp-meta-row > label { font-weight: 700; display: block; margin-bottom: 6px; font-size: 14px; color: #1e293b; }
            .lsp-meta-row input[type="text"], .lsp-meta-row input[type="number"], .lsp-meta-row select, .lsp-meta-row textarea { width: 100%; max-width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
            .lsp-meta-row p.description { color: #64748b; font-size: 12.5px; margin-top: 5px; font-style: italic; }

            /* SKKNI Builder Styles */
            .skkni-builder-wrap { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 20px; margin-top: 15px; }
            .skkni-builder-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
            .skkni-builder-header h3 { margin: 0; font-size: 16px; color: #0f172a; display: flex; align-items: center; gap: 8px; }
            .skkni-unit-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.2s; }
            .skkni-unit-card:hover { border-color: #94a3b8; }
            .skkni-unit-header { background: #f1f5f9; padding: 12px 16px; border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; align-items: center; cursor: pointer; border-bottom: 1px solid #e2e8f0; }
            .skkni-unit-title-preview { font-weight: 600; color: #1e293b; font-size: 14px; display: flex; align-items: center; gap: 8px; }
            .skkni-unit-badge { background: #0284c7; color: #fff; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 12px; }
            .skkni-unit-body { padding: 18px; }
            .skkni-grid-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px; }
            @media (max-width: 782px) { .skkni-grid-2 { grid-template-columns: 1fr; } }
            
            /* Elemen Sub-repeater */
            .skkni-elemen-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px; margin-top: 15px; }
            .skkni-elemen-header { font-weight: 700; font-size: 13px; color: #334155; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
            .skkni-elemen-card { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 5px; padding: 12px; margin-bottom: 12px; }
            .skkni-elemen-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
            .skkni-elemen-badge { background: #475569; color: #fff; font-size: 11px; padding: 2px 6px; border-radius: 3px; font-weight: 600; }
            
            /* Smart Paste Modal */
            #skkni-paste-modal { display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.6); backdrop-filter: blur(2px); }
            .skkni-modal-content { background: #ffffff; width: 90%; max-width: 800px; margin: 40px auto; border-radius: 8px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; max-height: 85vh; }
            .skkni-modal-header { padding: 16px 20px; background: #0f172a; color: #ffffff; display: flex; justify-content: space-between; align-items: center; }
            .skkni-modal-header h4 { margin: 0; font-size: 16px; color: #ffffff; }
            .skkni-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
            .skkni-modal-footer { padding: 12px 20px; background: #f1f5f9; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; }
        </style>

        <!-- A. Field Dasar Skema -->
        <div class="lsp-meta-row">
            <label for="kode_skema">Kode Skema</label>
            <input type="text" id="kode_skema" name="kode_skema" value="<?php echo esc_attr( $kode_skema ); ?>" placeholder="Contoh: SKM/LSP-KOM/005/2026" />
        </div>

        <div class="lsp-meta-row">
            <label for="level_skema">Level Skema Sertifikasi (Angka 1 - 9)</label>
            <input type="number" id="level_skema" name="level_skema" min="1" max="9" step="1" value="<?php echo esc_attr( $level_skema ); ?>" placeholder="Contoh: 3" style="max-width: 200px;" />
            <p class="description">Masukkan nilai level KKNI (1 s/d 9). Pada kartu skema akan tertulis: <strong>Level <?php echo $level_skema ? esc_html( $level_skema ) : '...'; ?></strong></p>
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
        </div>

        <div class="lsp-meta-row">
            <label for="biaya_sertifikasi">Biaya Sertifikasi (Rp)</label>
            <input type="text" id="biaya_sertifikasi" name="biaya_sertifikasi" value="<?php echo esc_attr( $biaya ); ?>" placeholder="Contoh: 1500000" />
            <p class="description">Tulis angka saja tanpa titik atau koma.</p>
        </div>

        <div class="lsp-meta-row">
            <label for="persyaratan_skema">1. Persyaratan Dasar</label>
            <textarea id="persyaratan_skema" name="persyaratan_skema" rows="4" placeholder="Contoh: Pendidikan minimal Diploma SMA/SMK..."><?php echo esc_textarea( $persyaratan ); ?></textarea>
            <p class="description">Gunakan baris baru (Enter) untuk memisahkan setiap poin persyaratan.</p>
        </div>

        <div class="lsp-meta-row">
            <label for="persyaratan_admin">2. Persyaratan Administrasi</label>
            <textarea id="persyaratan_admin" name="persyaratan_admin" rows="3" placeholder="Contoh: Fotocopy KTP, Pas Foto 3x4..."><?php echo esc_textarea( $persyaratan_adm ); ?></textarea>
            <p class="description">Gunakan baris baru (Enter) untuk memisahkan setiap poin persyaratan administrasi.</p>
        </div>

        <div class="lsp-meta-row">
            <label for="acuan_skkni">3. Acuan Normatif Standar Kompetensi (SKKNI)</label>
            <textarea id="acuan_skkni" name="acuan_skkni" rows="3" placeholder="Contoh: Keputusan Menteri Ketenagakerjaan Republik Indonesia Nomor 124 Tahun 2022 tentang Penetapan SKKNI Kategori Aktivitas Konsultasi Manajemen Bidang Pemasaran..."><?php echo esc_textarea( $acuan_skkni ); ?></textarea>
            <p class="description">Tuliskan nomor Kepmenaker atau dasar hukum SKKNI rujukan penyusunan skema ini.</p>
        </div>

        <!-- B. Visual SKKNI Builder (Unit Kompetensi, Elemen & KUK) -->
        <div class="skkni-builder-wrap">
            <div class="skkni-builder-header">
                <h3>
                    <span class="dashicons dashicons-list-view" style="color: #0284c7;"></span>
                    Struktur Standar Kompetensi (Unit, Elemen & KUK)
                </h3>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="button button-secondary" id="btn-open-skkni-parser">
                        <span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span> ⚡ Quick Import / Paste Teks SKKNI
                    </button>
                    <button type="button" class="button button-primary" id="btn-add-skkni-unit">
                        <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span> Tambah Unit Kompetensi
                    </button>
                </div>
            </div>

            <div id="skkni-units-container">
                <!-- Unit cards rendered here by JS -->
            </div>

            <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                <span id="skkni-units-count-badge" style="font-size: 13px; color: #64748b; font-weight: 600;">Total: 0 Unit</span>
                <button type="button" class="button button-primary" id="btn-add-skkni-unit-bottom">
                    + Tambah Unit Kompetensi
                </button>
            </div>

            <!-- Hidden input for final payload -->
            <input type="hidden" name="skkni_data_json" id="skkni_data_json_input" value="">
        </div>

        <!-- C. Modal Smart Paste SKKNI -->
        <div id="skkni-paste-modal">
            <div class="skkni-modal-content">
                <div class="skkni-modal-header">
                    <h4>⚡ Smart Paste / Importer Dokumen SKKNI</h4>
                    <button type="button" class="button-link" id="btn-close-skkni-modal" style="color:#fff; font-size:18px; text-decoration:none; cursor:pointer;">&times;</button>
                </div>
                <div class="skkni-modal-body">
                    <p style="margin-top:0; color:#475569; font-size:13px;">
                        Salin (copy) teks SKKNI atau data JSON langsung dari <strong>SKKNI Extractor</strong>, lalu tempel (paste) di bawah ini. Sistem otomatis mendeteksi format teks maupun format JSON.
                    </p>
                    <textarea id="skkni-paste-textarea" rows="14" style="width:100%; font-family:monospace; font-size:12.5px;" placeholder="Contoh teks yang bisa ditempel:
KODE UNIT: M.70MKT00.009.2
Merencanakan Riset Terhadap Sebuah Produk dan/atau Merek
Unit kompetensi ini berhubungan dengan pengetahuan, keterampilan...
ELEMEN KOMPETENSI | KRITERIA UNJUK KERJA
1. Menentukan kebutuhan dan tujuan riset | 1.1 Masalah diidentifikasi... 1.2 Tujuan riset ditentukan...
2. Menentukan metode riset | 2.1 Jenis data yang diperlukan..."></textarea>
                    <label style="display:block; margin-top:10px; font-size:13px;">
                        <input type="checkbox" id="skkni-replace-existing" value="1"> Gantikan unit yang ada saat ini (jika tidak dicentang, akan ditambahkan di akhir).
                    </label>
                </div>
                <div class="skkni-modal-footer">
                    <button type="button" class="button button-secondary" id="btn-cancel-skkni-modal">Batal</button>
                    <button type="button" class="button button-primary" id="btn-process-skkni-paste">Proses & Masukkan ke Form</button>
                </div>
            </div>
        </div>

        <!-- Initial Data Script -->
        <script>
        var initialSkkniUnits = <?php echo wp_json_encode( $units_data ?: array() ); ?>;
        </script>

        <!-- Visual Builder & Smart Parser Logic -->
        <script>
        jQuery(document).ready(function($) {
            var units = Array.isArray(initialSkkniUnits) ? initialSkkniUnits : [];

            function renderAllUnits() {
                var $container = $('#skkni-units-container');
                $container.empty();

                if (units.length === 0) {
                    $container.html('<div style="background:#fff; border:1px dashed #cbd5e1; padding:30px; text-align:center; border-radius:6px; color:#64748b;">Belum ada unit kompetensi. Klik tombol <strong>+ Tambah Unit Kompetensi</strong> atau gunakan <strong>⚡ Quick Import</strong>.</div>');
                    $('#skkni-units-count-badge').text('Total: 0 Unit');
                    syncHiddenPayload();
                    return;
                }

                $('#skkni-units-count-badge').text('Total: ' + units.length + ' Unit Kompetensi terdaftar');

                $.each(units, function(uIdx, unit) {
                    var unitNo = uIdx + 1;
                    var kode = unit.kode_unit || unit.kode || '';
                    var judul = unit.judul_unit || unit.judul || '';
                    var deskripsi = unit.deskripsi_unit || unit.deskripsi || '';
                    var elemenList = Array.isArray(unit.elemen) ? unit.elemen : [];

                    var $card = $('<div class="skkni-unit-card" data-uidx="' + uIdx + '"></div>');
                    
                    // Card Header
                    var $head = $('<div class="skkni-unit-header"></div>');
                    var $titlePreview = $('<div class="skkni-unit-title-preview"><span class="skkni-unit-badge">Unit ' + unitNo + '</span> <span>' + (kode ? '<strong>' + escapeHtml(kode) + '</strong> — ' : '') + escapeHtml(judul || 'Unit Baru') + '</span></div>');
                    var $actions = $('<div><button type="button" class="button button-small btn-toggle-unit" style="margin-right:5px;">Sembunyikan/Tampilkan</button><button type="button" class="button button-small button-link-delete btn-remove-unit" style="color:#b91c1c;">Hapus Unit</button></div>');
                    $head.append($titlePreview).append($actions);

                    // Card Body
                    var $body = $('<div class="skkni-unit-body"></div>');
                    var $grid = $('<div class="skkni-grid-2"></div>');
                    
                    var $colKode = $('<div><label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Kode Unit (12 Digit)</label><input type="text" class="input-unit-kode" value="' + escapeAttr(kode) + '" placeholder="Contoh: M.70MKT00.009.2"></div>');
                    var $colJudul = $('<div><label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Judul Unit Kompetensi</label><input type="text" class="input-unit-judul" value="' + escapeAttr(judul) + '" placeholder="Contoh: Merencanakan Riset Terhadap Sebuah Produk..."></div>');
                    $grid.append($colKode).append($colJudul);

                    var $rowDeskripsi = $('<div style="margin-bottom:15px;"><label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Deskripsi Singkat Unit (Opsional)</label><textarea class="input-unit-deskripsi" rows="2" placeholder="Penjelasan isi, pengetahuan, keterampilan, dan sikap kerja...">' + escapeHtml(deskripsi) + '</textarea></div>');

                    // Elemen Section
                    var $elemSection = $('<div class="skkni-elemen-section"></div>');
                    var $elemHead = $('<div class="skkni-elemen-header"><span>Daftar Elemen Kompetensi & Kriteria Unjuk Kerja (KUK)</span> <button type="button" class="button button-small btn-add-elemen">+ Tambah Elemen</button></div>');
                    var $elemContainer = $('<div class="skkni-elemen-container"></div>');

                    if (elemenList.length === 0) {
                        $elemContainer.html('<p style="color:#94a3b8; font-size:12px; margin:5px 0;">Belum ada elemen. Klik + Tambah Elemen.</p>');
                    } else {
                        $.each(elemenList, function(eIdx, el) {
                            var eNo = el.nomor || (eIdx + 1);
                            var eNama = el.nama || '';
                            var kuks = Array.isArray(el.kuk) ? el.kuk.join("\n") : (el.kuk || '');

                            var $elCard = $('<div class="skkni-elemen-card" data-eidx="' + eIdx + '"></div>');
                            var $elCardHead = $('<div class="skkni-elemen-card-header"><span class="skkni-elemen-badge">Elemen ' + eNo + '</span> <button type="button" class="button button-small button-link-delete btn-remove-elemen" style="color:#b91c1c; font-size:11px;">Hapus Elemen</button></div>');
                            var $elNamaRow = $('<div style="margin-bottom:8px;"><label style="font-size:11.5px; font-weight:600; display:block; margin-bottom:3px;">Nama Elemen Kompetensi</label><input type="text" class="input-elemen-nama" value="' + escapeAttr(eNama) + '" placeholder="Contoh: Menentukan kebutuhan dan tujuan riset"></div>');
                            var $elKukRow = $('<div><label style="font-size:11.5px; font-weight:600; display:block; margin-bottom:3px;">Kriteria Unjuk Kerja (KUK) — <em>Satu baris per poin KUK</em></label><textarea class="input-elemen-kuk" rows="3" style="font-size:12.5px; font-family:sans-serif;" placeholder="1.1 Masalah diidentifikasi berdasarkan ketidaksesuaian...\n1.2 Tujuan riset ditentukan sesuai identifikasi masalah...">' + escapeHtml(kuks) + '</textarea></div>');

                            $elCard.append($elCardHead).append($elNamaRow).append($elKukRow);
                            $elemContainer.append($elCard);
                        });
                    }

                    $elemSection.append($elemHead).append($elemContainer);
                    $body.append($grid).append($rowDeskripsi).append($elemSection);
                    $card.append($head).append($body);
                    $container.append($card);
                });

                syncHiddenPayload();
            }

            // Sync from DOM to units array and hidden input
            function syncFromDOM() {
                var newUnits = [];
                $('#skkni-units-container .skkni-unit-card').each(function(uIdx) {
                    var $card = $(this);
                    var kode = $card.find('.input-unit-kode').val().trim();
                    var judul = $card.find('.input-unit-judul').val().trim();
                    var deskripsi = $card.find('.input-unit-deskripsi').val().trim();

                    var elemList = [];
                    $card.find('.skkni-elemen-card').each(function(eIdx) {
                        var $el = $(this);
                        var eNama = $el.find('.input-elemen-nama').val().trim();
                        var rawKuk = $el.find('.input-elemen-kuk').val();
                        var kuks = [];
                        if (rawKuk) {
                            var lines = rawKuk.split("\n");
                            $.each(lines, function(i, l) {
                                l = l.trim();
                                if (l) kuks.push(l);
                            });
                        }
                        if (eNama || kuks.length > 0) {
                            elemList.push({
                                nomor: eIdx + 1,
                                nama: eNama || ('Elemen ' + (eIdx + 1)),
                                kuk: kuks
                            });
                        }
                    });

                    if (kode || judul || elemList.length > 0) {
                        newUnits.push({
                            kode_unit: kode,
                            judul_unit: judul,
                            deskripsi_unit: deskripsi,
                            elemen: elemList
                        });
                    }
                });

                units = newUnits;
                syncHiddenPayload();
            }

            function syncHiddenPayload() {
                var payloadStr = JSON.stringify(units);
                $('#skkni_data_json_input').val(payloadStr).trigger('change');
            }

            function escapeHtml(str) {
                if (!str) return '';
                return $('<div>').text(str).html();
            }

            function escapeAttr(str) {
                if (!str) return '';
                return str.replace(/"/g, '&quot;');
            }

            // Event Listeners for DOM inputs
            $(document).on('input change', '.input-unit-kode, .input-unit-judul, .input-unit-deskripsi, .input-elemen-nama, .input-elemen-kuk', function() {
                syncFromDOM();
            });

            // Pastikan data tersinkronisasi sebelum publish atau save di Gutenberg maupun Classic
            $(document).on('click', '#publish, #save-post, .editor-post-publish-button, .editor-post-save-draft', function() {
                syncFromDOM();
            });
            $(document).on('submit', '#post, form.metabox-location-normal, .edit-post-layout__metaboxes form', function() {
                syncFromDOM();
            });

            // Update title preview on typing
            $(document).on('input', '.input-unit-kode, .input-unit-judul', function() {
                var $card = $(this).closest('.skkni-unit-card');
                var uIdx = parseInt($card.data('uidx'), 10) + 1;
                var kode = $card.find('.input-unit-kode').val().trim();
                var judul = $card.find('.input-unit-judul').val().trim();
                $card.find('.skkni-unit-title-preview').html('<span class="skkni-unit-badge">Unit ' + uIdx + '</span> ' + (kode ? '<strong>' + escapeHtml(kode) + '</strong> — ' : '') + escapeHtml(judul || 'Unit Baru'));
            });

            // Toggle Unit Body
            $(document).on('click', '.btn-toggle-unit, .skkni-unit-header', function(e) {
                if ($(e.target).hasClass('btn-remove-unit')) return;
                var $card = $(this).closest('.skkni-unit-card');
                $card.find('.skkni-unit-body').slideToggle(200);
            });

            // Add Unit
            $('#btn-add-skkni-unit, #btn-add-skkni-unit-bottom').on('click', function(e) {
                e.preventDefault();
                syncFromDOM();
                units.push({
                    kode_unit: '',
                    judul_unit: '',
                    deskripsi_unit: '',
                    elemen: [
                        { nomor: 1, nama: '', kuk: [] }
                    ]
                });
                renderAllUnits();
                // scroll to bottom
                $('html, body').animate({ scrollTop: $('#skkni-units-container .skkni-unit-card:last').offset().top - 80 }, 300);
            });

            // Remove Unit
            $(document).on('click', '.btn-remove-unit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (confirm('Yakin ingin menghapus unit kompetensi ini?')) {
                    var uIdx = $(this).closest('.skkni-unit-card').data('uidx');
                    syncFromDOM();
                    units.splice(uIdx, 1);
                    renderAllUnits();
                }
            });

            // Add Elemen
            $(document).on('click', '.btn-add-elemen', function(e) {
                e.preventDefault();
                var uIdx = $(this).closest('.skkni-unit-card').data('uidx');
                syncFromDOM();
                if (!units[uIdx].elemen) units[uIdx].elemen = [];
                var nextNo = units[uIdx].elemen.length + 1;
                units[uIdx].elemen.push({
                    nomor: nextNo,
                    nama: '',
                    kuk: []
                });
                renderAllUnits();
            });

            // Remove Elemen
            $(document).on('click', '.btn-remove-elemen', function(e) {
                e.preventDefault();
                var $card = $(this).closest('.skkni-unit-card');
                var uIdx = $card.data('uidx');
                var eIdx = $(this).closest('.skkni-elemen-card').data('eidx');
                syncFromDOM();
                if (units[uIdx] && units[uIdx].elemen) {
                    units[uIdx].elemen.splice(eIdx, 1);
                    renderAllUnits();
                }
            });

            // Modal Controls
            $('#btn-open-skkni-parser').on('click', function(e) {
                e.preventDefault();
                $('#skkni-paste-textarea').val('');
                $('#skkni-paste-modal').fadeIn(200);
            });
            $('#btn-close-skkni-modal, #btn-cancel-skkni-modal').on('click', function(e) {
                e.preventDefault();
                $('#skkni-paste-modal').fadeOut(200);
            });

            // Smart Parser Implementation
            $('#btn-process-skkni-paste').on('click', function(e) {
                e.preventDefault();
                var rawText = $('#skkni-paste-textarea').val();
                if (!rawText.trim()) {
                    alert('Silakan tempel teks SKKNI terlebih dahulu.');
                    return;
                }

                var parsedUnits = parseRawSkkniText(rawText);
                if (parsedUnits.length === 0) {
                    alert('Tidak dapat mendeteksi Unit Kompetensi. Pastikan teks memuat format "KODE UNIT: ...", tabel ELEMEN KOMPETENSI, atau Kriteria Unjuk Kerja.');
                    return;
                }

                syncFromDOM();
                var replaceExisting = $('#skkni-replace-existing').is(':checked');
                if (replaceExisting) {
                    units = parsedUnits;
                } else {
                    units = units.concat(parsedUnits);
                }

                $('#skkni-paste-modal').fadeOut(200);
                renderAllUnits();
                alert('Berhasil mengimpor ' + parsedUnits.length + ' Unit Kompetensi!');
            });

            // Parser Function (Mendukung Teks SKKNI atau JSON langsung)
            function parseRawSkkniText(text) {
                var trimmed = text.trim();

                // 1. Cek apakah input berupa format JSON (dari SKKNI Extractor atau backup)
                if (trimmed.startsWith('[') || trimmed.startsWith('{')) {
                    try {
                        var parsed = JSON.parse(trimmed);
                        var rawList = Array.isArray(parsed) ? parsed : (parsed.units || parsed.unit_kompetensi || [parsed]);
                        var jsonResults = [];

                        $.each(rawList, function(idx, u) {
                            var kode = u.kode_unit || u.kode || '';
                            var judul = u.judul_unit || u.judul || '';
                            var deskripsi = u.deskripsi_unit || u.deskripsi || '';
                            var rawElems = u.elemen || u.elemen_kompetensi || [];
                            var elemList = [];

                            $.each(rawElems, function(eIdx, el) {
                                var eNo = el.nomor || el.nomor_elemen || (eIdx + 1);
                                var eNama = el.nama || el.nama_elemen || ('Elemen ' + eNo);
                                var kuks = [];
                                var rawKuk = el.kuk || [];
                                if (Array.isArray(rawKuk)) {
                                    $.each(rawKuk, function(kIdx, k) {
                                        if (typeof k === 'string') {
                                            if (k.trim()) kuks.push(k.trim());
                                        } else if (k && typeof k === 'object') {
                                            var kNo = k.nomor_kuk || '';
                                            var kUr = k.uraian_kuk || '';
                                            var kText = (kNo ? kNo + ' ' : '') + kUr;
                                            if (kText.trim()) kuks.push(kText.trim());
                                        }
                                    });
                                } else if (typeof rawKuk === 'string') {
                                    $.each(rawKuk.split('\n'), function(i, l) {
                                        if (l.trim()) kuks.push(l.trim());
                                    });
                                }

                                elemList.push({
                                    nomor: eNo,
                                    nama: eNama,
                                    kuk: kuks
                                });
                            });

                            if (kode || judul || elemList.length > 0) {
                                jsonResults.push({
                                    kode_unit: kode,
                                    judul_unit: judul,
                                    deskripsi_unit: deskripsi,
                                    elemen: elemList
                                });
                            }
                        });

                        if (jsonResults.length > 0) {
                            return jsonResults;
                        }
                    } catch (e) {
                        console.warn('Gagal membaca sebagai JSON, beralih ke parser teks:', e);
                    }
                }

                var results = [];
                
                // Cek apakah ada multiple "KODE UNIT:"
                var unitBlocks = text.split(/(?=KODE\s*UNIT\s*[:\-])/i);

                $.each(unitBlocks, function(bIdx, block) {
                    block = block.trim();
                    if (!block) return;

                    var lines = block.split("\n");
                    var kode = '';
                    var judul = '';
                    var deskripsi = '';
                    var elemenList = [];
                    var currentEl = null;
                    var eNum = 1;
                    var inDesc = false;

                    // 1. Cek format tabel pipe (| Elemen | KUK |) jika ada
                    var tableRows = block.match(/\|([^|\n\r]+)\|([^|\n\r]+)\|/g);
                    if (tableRows && tableRows.length > 0) {
                        $.each(tableRows, function(rIdx, row) {
                            var cols = row.split('|');
                            if (cols.length >= 3) {
                                var colEl = cols[1].trim();
                                var colKuk = cols[2].trim();
                                if (colEl.match(/ELEMEN\s*KOMPETENSI/i)) return;
                                if (!colEl) return;

                                var rawKukItems = colKuk.split(/(?=\d+\.\d+\.?\s+)/);
                                var cleanKuks = [];
                                $.each(rawKukItems, function(kIdx, kItem) {
                                    kItem = kItem.trim();
                                    if (kItem) cleanKuks.push(kItem);
                                });

                                elemenList.push({
                                    nomor: eNum++,
                                    nama: colEl.replace(/^\d+\.\s*/, ''),
                                    kuk: cleanKuks
                                });
                            }
                        });
                    }

                    // 2. Parser baris per baris yang toleran & akurat
                    $.each(lines, function(lIdx, rawLine) {
                        var l = rawLine.trim();
                        if (!l) return;

                        // A. Deteksi KODE UNIT
                        var mKode = l.match(/^KODE\s*UNIT\s*[:\-]\s*([A-Za-z0-9\.\_\-]+)/i);
                        if (mKode && !kode) {
                            kode = mKode[1].trim();
                            inDesc = false;
                            return;
                        }

                        // B. Deteksi JUDUL UNIT (eksplisit label JUDUL UNIT)
                        var mJudul = l.match(/^JUDUL\s*UNIT\s*[:\-]\s*(.+)/i);
                        if (mJudul && !judul) {
                            judul = mJudul[1].trim();
                            inDesc = false;
                            return;
                        }

                        // C. Deteksi DESKRIPSI UNIT (eksplisit label DESKRIPSI UNIT)
                        var mDescLabel = l.match(/^DESKRIPSI\s*(?:SINGKAT\s*)?UNIT\s*[:\-]?\s*(.*)$/i);
                        if (mDescLabel) {
                            deskripsi = mDescLabel[1].trim();
                            inDesc = true;
                            return;
                        }

                        // D. Deteksi Elemen Kompetensi (contoh: "1. Mengidentifikasi...")
                        var mEl = l.match(/^(\d+)\.\s+([A-Za-z0-9\(].*)$/);
                        if (mEl && !l.match(/^\d+\.\d+/)) {
                            inDesc = false;
                            if (tableRows && tableRows.length > 0) return;
                            if (currentEl) elemenList.push(currentEl);
                            currentEl = {
                                nomor: parseInt(mEl[1], 10) || eNum++,
                                nama: mEl[2].trim().replace(/\.$/, ''),
                                kuk: []
                            };
                            return;
                        }

                        // E. Deteksi KUK (contoh: "1.1 Alternatif jaringan...")
                        var mKuk = l.match(/^(\d+\.\d+)\.?\s+(.+)$/);
                        if (mKuk && currentEl) {
                            inDesc = false;
                            if (tableRows && tableRows.length > 0) return;
                            currentEl.kuk.push(l);
                            return;
                        }

                        // F. Jika sedang dalam blok deskripsi dan belum ketemu elemen, lanjutkan deskripsi (multi-baris)
                        if (inDesc && !currentEl) {
                            if (l.match(/^(?:ELEMEN\s*KOMPETENSI|KRITERIA\s*UNJUK|BATASAN\s*VARIABEL|PANDUAN\s*PENILAIAN|NO\b)/i)) {
                                inDesc = false;
                                return;
                            }
                            deskripsi += (deskripsi ? ' ' : '') + l;
                            return;
                        }

                        // G. Jika belum ada judul, baris ini adalah judul (fallback baris setelah kode)
                        if (!judul && !l.startsWith('|') && !l.match(/^(?:ELEMEN|KRITERIA)/i)) {
                            judul = l.replace(/^JUDUL\s*UNIT\s*[:\-]\s*/i, '');
                            inDesc = false;
                            return;
                        }

                        // H. Fallback deskripsi tanpa label (pola kalimat pembuka SKKNI)
                        if (!deskripsi && !currentEl && l.match(/^(Unit\s+(?:kompetensi\s+)?ini\b.*)/i)) {
                            deskripsi = l;
                            inDesc = true;
                            return;
                        }
                    });

                    if (currentEl && (!tableRows || tableRows.length === 0)) {
                        elemenList.push(currentEl);
                    }

                    if (kode || judul || elemenList.length > 0) {
                        results.push({
                            kode_unit: kode,
                            judul_unit: judul,
                            deskripsi_unit: deskripsi.trim(),
                            elemen: elemenList
                        });
                    }
                });

                return results;
            }

            // Sync payload before post submission
            $('#post').on('submit', function() {
                syncFromDOM();
            });

            // Initial render
            renderAllUnits();
        });
        </script>
        <?php
    }

    /**
     * 4. Save Meta Box Skema
     */
    public function save_skema_meta( $post_id ) {
        if ( ! isset( $_POST['lsp_skema_meta_nonce'] ) ) {
            // Jika save dipicu oleh REST API (Gutenberg) atau update judul tanpa nonce metabox,
            // tetap buatkan auto-thumbnail jika post memiliki judul sah dan bukan auto-draft
            if ( ( ! defined( 'DOING_AUTOSAVE' ) || ! DOING_AUTOSAVE ) && current_user_can( 'edit_post', $post_id ) ) {
                $this->maybe_generate_skema_auto_thumbnail( $post_id );
            }
            return;
        }
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
        if ( isset( $_POST['acuan_skkni'] ) ) {
            update_post_meta( $post_id, 'acuan_skkni', wp_kses_post( $_POST['acuan_skkni'] ) );
        }

        // Simpan Data Struktur SKKNI (dari Visual Builder)
        if ( isset( $_POST['skkni_data_json'] ) ) {
            $raw_json = wp_unslash( $_POST['skkni_data_json'] );
            $decoded  = json_decode( $raw_json, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                update_post_meta( $post_id, 'skkni_data', $decoded );

                // Otomatis sinkronkan teks unit_kompetensi untuk kompatibilitas ke belakang
                $unit_lines = array();
                foreach ( $decoded as $u ) {
                    $k = trim( $u['kode_unit'] ?? '' );
                    $j = trim( $u['judul_unit'] ?? '' );
                    if ( $k && $j ) {
                        $unit_lines[] = "$k | $j";
                    }
                }
                if ( ! empty( $unit_lines ) ) {
                    update_post_meta( $post_id, 'unit_kompetensi', implode( "\n", $unit_lines ) );
                }
            } elseif ( empty( trim( $raw_json ) ) ) {
                delete_post_meta( $post_id, 'skkni_data' );
            }
        }

        // Otomatis buatkan / perbarui gambar Featured Image jika belum ada foto kustom
        $this->maybe_generate_skema_auto_thumbnail( $post_id );
    }

    /**
     * 5. Helper Statis: Mengambil data unit kompetensi lengkap beserta Elemen & KUK dari SKKNI
     *
     * @param int $post_id ID Skema
     * @return array
     */
    public static function get_skema_skkni_data( $post_id ) {
        $skkni_data = get_post_meta( $post_id, 'skkni_data', true );
        if ( ! empty( $skkni_data ) && is_array( $skkni_data ) ) {
            return $skkni_data;
        }

        // Cadangan: daftar unit dari meta unit_kompetensi (KODE | JUDUL).
        //
        // Elemen dan KUK TIDAK dikarang di sini. Sebelumnya fungsi ini membuat satu
        // elemen generik ("Melaksanakan unit kompetensi …") beserta satu KUK generik
        // untuk setiap unit yang belum punya data SKKNI terstruktur. Data karangan itu
        // ikut tercetak di dokumen resmi asesmen (FR.APL.02, FR.IA.01) dan tampil di
        // halaman publik skema.
        //
        // Sekarang: unit tetap dikembalikan (kode + judul adalah data master yang sah),
        // elemen dibiarkan kosong, dan sumber ditandai 'meta_skema' agar konsumen tahu
        // data kompetensi belum lengkap. Gunakan flag ini untuk menahan pencetakan
        // dokumen yang mensyaratkan Elemen/KUK.
        $unit_text = get_post_meta( $post_id, 'unit_kompetensi', true );
        if ( empty( $unit_text ) ) {
            return array();
        }

        $units = array();
        $lines = explode( "\n", str_replace( "\r", "", $unit_text ) );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) continue;
            $parts = explode( '|', $line );
            if ( count( $parts ) >= 2 ) {
                $units[] = array(
                    'kode_unit'  => trim( $parts[0] ),
                    'judul_unit' => trim( $parts[1] ),
                    'elemen'     => array(),
                    'sumber'     => 'meta_skema',
                );
            }
        }

        return $units;
    }

    /**
     * 6. Admin Columns
     */
    public function custom_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            $new_columns[ $key ] = $title;
            if ( 'title' === $key ) {
                $new_columns['kode_skema']   = __( 'Kode Skema', 'lsp-core-management' );
                $new_columns['jenis_kemasan'] = __( 'Jenis Kemasan', 'lsp-core-management' );
                $new_columns['level_skema']  = __( 'Level', 'lsp-core-management' );
                $new_columns['biaya']        = __( 'Biaya', 'lsp-core-management' );
                $new_columns['total_unit']   = __( 'Total Unit', 'lsp-core-management' );
            }
        }
        return $new_columns;
    }

    public function custom_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'kode_skema':
                echo esc_html( get_post_meta( $post_id, 'kode_skema', true ) ?: '-' );
                break;
            case 'jenis_kemasan':
                $kemasan = get_post_meta( $post_id, 'jenis_kemasan', true );
                echo $kemasan ? '<span class="badge" style="background:#e0f2fe; color:#0284c7; padding:2px 8px; border-radius:4px; font-weight:600;">' . esc_html( $kemasan ) . '</span>' : '-';
                break;
            case 'level_skema':
                $lvl = get_post_meta( $post_id, 'level_skema', true ) ?: get_post_meta( $post_id, 'level', true );
                echo $lvl ? 'Level ' . esc_html( $lvl ) : '-';
                break;
            case 'biaya':
                $biaya = get_post_meta( $post_id, 'biaya_sertifikasi', true );
                echo $biaya ? 'Rp ' . number_format( (float) $biaya, 0, ',', '.' ) : '-';
                break;
            case 'total_unit':
                $units = self::get_skema_skkni_data( $post_id );
                echo count( $units ) . ' Unit';
                break;
        }
    }

    public function archive_query( $query ) {
        if ( ! is_admin() && $query->is_main_query() && is_post_type_archive( 'skema' ) ) {
            $query->set( 'posts_per_page', 12 );
            $query->set( 'orderby', 'title' );
            $query->set( 'order', 'ASC' );
        }
    }

    /**
     * 7. Auto Thumbnail Generator (Delegasi ke Master Generator LSP_Core_Management)
     */
    public function maybe_generate_skema_auto_thumbnail( $post_id ) {
        if ( class_exists( 'LSP_Core_Management' ) ) {
            return LSP_Core_Management::get_instance()->maybe_generate_skema_auto_thumbnail( $post_id );
        }

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
            if ( ! $is_auto && strpos( (string) $t_title, 'skema-' ) !== 0 ) {
                return;
            }
        }

        $kode          = get_post_meta( $post_id, 'kode_skema', true ) ?: 'SKEMA RESMI';
        $jenis_kemasan = get_post_meta( $post_id, 'jenis_kemasan', true ) ?: 'Sertifikasi Profesi';
        $full_title    = $post->post_title;
        $slug          = $post->post_name ?: ( ! empty( $post->post_title ) ? sanitize_title( $post->post_title ) : 'post-' . $post_id );
        $filename      = 'skema-' . sanitize_file_name( $slug ) . '.jpg';

        $attach_id = self::generate_auto_cover_image( $filename, $kode, $full_title, $jenis_kemasan, 'maroon', $thumb_id );
        if ( $attach_id && ! is_wp_error( $attach_id ) ) {
            set_post_thumbnail( $post_id, $attach_id );
        }
    }

    /**
     * 8. Engine Generator Gambar Sampul (GD)
     */
    public static function generate_auto_cover_image( $filename, $badge_text, $full_title, $subtitle, $bg_scheme = 'maroon', $existing_attach_id = 0 ) {
        if ( class_exists( 'LSP_Core_Management' ) ) {
            return LSP_Core_Management::generate_auto_cover_image( $filename, $badge_text, $full_title, $subtitle, $bg_scheme, $existing_attach_id );
        }

        if ( ! extension_loaded( 'gd' ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['path'];
        $filepath   = $target_dir . '/' . sanitize_file_name( $filename );

        $width  = 1200;
        $height = 630;
        $img    = imagecreatetruecolor( $width, $height );

        $color_bg_top = imagecolorallocate( $img, 114, 22, 26 );
        $color_bg_bot = imagecolorallocate( $img, 65, 10, 13 );
        $color_white  = imagecolorallocate( $img, 255, 255, 255 );
        $color_accent = imagecolorallocate( $img, 248, 155, 41 );
        $color_gray   = imagecolorallocate( $img, 226, 232, 240 );

        // Gradien Background
        for ( $y = 0; $y < $height; $y++ ) {
            $ratio = $y / $height;
            $r = (int) ( ( 1 - $ratio ) * 114 + $ratio * 65 );
            $g = (int) ( ( 1 - $ratio ) * 22 + $ratio * 10 );
            $b = (int) ( ( 1 - $ratio ) * 26 + $ratio * 13 );
            $col = imagecolorallocate( $img, $r, $g, $b );
            imageline( $img, 0, $y, $width, $y, $col );
        }

        // Header Card / Badge
        $badge_clean = strtoupper( substr( sanitize_text_field( $badge_text ), 0, 40 ) );
        imagestring( $img, 5, 80, 80, "LSP KOMUNIKASI INDONESIA | " . $badge_clean, $color_accent );

        // Judul Skema (Wordwrap sederhana)
        $clean_title = sanitize_text_field( $full_title );
        $words = explode( ' ', $clean_title );
        $lines = array();
        $curr = '';
        foreach ( $words as $w ) {
            if ( strlen( $curr . ' ' . $w ) > 32 ) {
                $lines[] = trim( $curr );
                $curr = $w;
            } else {
                $curr .= ' ' . $w;
            }
        }
        if ( trim( $curr ) ) $lines[] = trim( $curr );

        $y_title = 180;
        foreach ( array_slice( $lines, 0, 3 ) as $l ) {
            imagestring( $img, 5, 80, $y_title, $l, $color_white );
            $y_title += 45;
        }

        // Subtitle / Kemasan
        $clean_sub = sanitize_text_field( $subtitle );
        imagestring( $img, 4, 80, $height - 120, "KEMASAN: " . strtoupper( $clean_sub ) . " | STANDAR BNSP", $color_gray );

        imagejpeg( $img, $filepath, 90 );
        imagedestroy( $img );

        // WordPress Attachment
        $filetype = wp_check_filetype( basename( $filepath ), null );
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . basename( $filepath ),
            'post_mime_type' => $filetype['type'],
            'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filepath ) ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        if ( $existing_attach_id && get_post( $existing_attach_id ) ) {
            $attach_id = $existing_attach_id;
            update_attached_file( $attach_id, $filepath );
            clean_post_cache( $attach_id );
        } else {
            $attach_id = wp_insert_attachment( $attachment, $filepath );
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
            wp_update_attachment_metadata( $attach_id, $attach_data );
            update_post_meta( $attach_id, '_lsp_auto_thumb', 1 );
        }

        return $attach_id;
    }

    /**
     * 9. Render Konten Detail Single Skema (Frontend)
     */
    public function render_single_skema_content( $raw_content = '' ) {
        $post_id       = get_the_ID();
        $kode          = get_post_meta( $post_id, 'kode_skema', true );
        $level         = get_post_meta( $post_id, 'level_skema', true ) ?: get_post_meta( $post_id, 'level', true );
        $jenis_kemasan = get_post_meta( $post_id, 'jenis_kemasan', true );
        $biaya         = get_post_meta( $post_id, 'biaya_sertifikasi', true );
        $syarat        = get_post_meta( $post_id, 'persyaratan_skema', true );
        $syarat_admin  = get_post_meta( $post_id, 'persyaratan_admin', true );
        $acuan         = get_post_meta( $post_id, 'acuan_skkni', true );
        $units         = self::get_skema_skkni_data( $post_id );

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

        // 2c. Acuan Standar Kompetensi (SKKNI)
        if ( $acuan ) {
            echo '<h3 style="margin-top: 30px; border-bottom: 2px solid #eee; padding-bottom: 10px;">3. Acuan Standar Kompetensi Kerja (SKKNI)</h3>';
            echo '<div style="background: #f8fafc; padding: 20px; border-radius: 4px; border: 1px solid #cbd5e1; margin-top: 15px; line-height: 1.8;">';
            echo nl2br( esc_html( $acuan ) );
            echo '</div>';
        }

        // 3. Tabel Unit Kompetensi Lengkap
        if ( ! empty( $units ) ) {
            echo '<h3 style="margin-top: 40px; border-bottom: 2px solid #eee; padding-bottom: 10px;">Daftar Unit Kompetensi Sesuai Kemasan</h3>';
            echo '<div style="overflow-x: auto;">';
            echo '<table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 15px;">';
            echo '<thead><tr style="background: #f25a2b; color: #fff;">';
            echo '<th style="padding: 12px; border: 1px solid #ddd; width: 50px; text-align:center;">No</th>';
            echo '<th style="padding: 12px; border: 1px solid #ddd; width: 220px;">Kode Unit</th>';
            echo '<th style="padding: 12px; border: 1px solid #ddd;">Judul Unit Kompetensi</th>';
            echo '<th style="padding: 12px; border: 1px solid #ddd; width: 120px; text-align:center;">Elemen</th>';
            echo '</tr></thead><tbody>';

            $no = 1;
            foreach ( $units as $u ) {
                $kode_unit  = $u['kode_unit'] ?? $u['kode'] ?? '';
                $judul_unit = $u['judul_unit'] ?? $u['judul'] ?? '';
                $elem_count = isset( $u['elemen'] ) && is_array( $u['elemen'] ) ? count( $u['elemen'] ) : 0;

                echo '<tr>';
                echo '<td style="padding: 12px; border: 1px solid #ddd; text-align:center;">' . $no++ . '</td>';
                echo '<td style="padding: 12px; border: 1px solid #ddd; font-family: monospace; font-weight:600;">' . esc_html( $kode_unit ) . '</td>';
                echo '<td style="padding: 12px; border: 1px solid #ddd;">';
                echo '<strong>' . esc_html( $judul_unit ) . '</strong>';
                $deskripsi_unit = $u['deskripsi_unit'] ?? $u['deskripsi'] ?? '';
                if ( ! empty( $deskripsi_unit ) ) {
                    echo '<div style="font-size:13px; color:#64748b; margin-top:4px;">' . esc_html( $deskripsi_unit ) . '</div>';
                }
                echo '</td>';
                // Badge elemen: tampilkan "—" bila data elemen belum tersedia, agar halaman
                // publik tidak terlihat rusak saat Master Skema belum dilengkapi.
                $elem_label = $elem_count > 0 ? $elem_count . ' Elemen' : '—';
                $elem_title = $elem_count > 0 ? $elem_count . ' elemen kompetensi' : 'Data elemen belum tersedia';
                echo '<td style="padding: 12px; border: 1px solid #ddd; text-align:center;"><span title="' . esc_attr( $elem_title ) . '" style="background:#f1f5f9; padding:4px 10px; border-radius:12px; font-size:13px; font-weight:600;">' . esc_html( $elem_label ) . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        return ob_get_clean();
    }
}
