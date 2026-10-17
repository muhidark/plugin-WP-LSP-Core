# AUDIT LAPORAN 01: CODEBASE INVENTORY
**Plugin:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Versi:** 1.0.0  
**Target Produksi:** https://lspkomunikasi.id  
**Auditor:** Tim Senior Software Engineering (WordPress Plugin, Security, PHP, Database, QA, Theme Integration)  
**Tanggal:** 17 Oktober 2026  

---

## 1. Ringkasan Eksekutif & Struktur Workspace

Komponen utama yang diaudit terdiri dari:
1. **Plugin Utama:** `/var/www/html/wordpress/wp-content/plugins/lsp-core-management`
2. **Tema Baseline:** `/var/www/html/wordpress/wp-content/themes/lspkomunikasi` (Baseline Stabil, Tidak Boleh Diubah Kecuali Mutlak Diperlukan)

Struktur direktori plugin:
```
lsp-core-management/
├── docs/
│   ├── Panduan-Tema-dan-Plugin-LSP-Komunikasi.pdf
│   └── audit/
│       ├── 01-codebase-inventory.md
│       ├── 02-security-audit.md
│       ├── 03-performance-audit.md
│       ├── 04-architecture-audit.md
│       ├── 05-theme-integration.md
│       └── 06-remediation-plan.md
├── templates/
│   ├── archive-skema.php
│   ├── archive-tuk.php
│   ├── single-skema.php
│   └── single-tuk.php
└── lsp-core-management.php (3.229 baris kode)
```

---

## 2. File & Modul Utama

| File | Baris | Ukuran | Tanggung Jawab / Fitur |
|---|---|---|---|
| `lsp-core-management.php` | 3.229 | 194.912 B | Bootstrap plugin, registrasi CPT & Role, Metaboxes, Admin Pages, CSV Import, Shortcodes, AJAX Verifikasi Sertifikat, Auto Thumbnail GD, Template Renderers |
| `templates/archive-skema.php` | 37 | 822 B | Template katalog arsip Skema Sertifikasi (fallback jika tema tidak menyediakan) |
| `templates/archive-tuk.php` | 35 | 893 B | Template direktori TUK (fallback jika tema tidak menyediakan) |
| `templates/single-skema.php` | 57 | 2.270 B | Template halaman rincian Skema Sertifikasi |
| `templates/single-tuk.php` | 57 | 2.109 B | Template halaman rincian Tempat Uji Kompetensi |
| `docs/Panduan-...pdf` | Binary | 52.853 B | Buku panduan manual pengguna tema & plugin |

---

## 3. Inventaris Komponen Sistem

### 3.1 Custom Post Types (CPT)
1. **`skema` (Skema Sertifikasi)**
   - `public`: `true`, `has_archive`: `true`, `rewrite`: `['slug' => 'skema']`, `show_in_rest`: `true`
   - `supports`: `['title', 'editor', 'thumbnail', 'excerpt']`
   - Menu Icon: `dashicons-awards`, Posisi: 24
2. **`tuk` (Tempat Uji Kompetensi)**
   - `public`: `true`, `has_archive`: `true`, `rewrite`: `['slug' => 'tuk']`, `show_in_rest`: `true`
   - `supports`: `['title', 'editor', 'thumbnail']`
   - Menu Icon: `dashicons-location-alt`, Posisi: 25
3. **`data_sertifikat` (Data Pemegang Sertifikat BNSP)**
   - `public`: `false`, `show_ui`: `true`, `show_in_menu`: `true`, `has_archive`: `false`, `query_var`: `false`, `rewrite`: `false`
   - `supports`: `['title']`
   - Menu Icon: `dashicons-id-alt`, Posisi: 26

### 3.2 Custom Roles & User Capabilities
- **Role:** `asesor` (Label: `Asesor LSP`)
- **Capabilities Asli:**
  - `read` => `true`
  - `edit_posts` => `true`
  - `delete_posts` => `true`
  - `upload_files` => `true`
  - `publish_posts` => `false` (Wajib persetujuan Admin/Editor)
  - `edit_published_posts` => `false`
  - `delete_published_posts` => `false`

### 3.3 Post Metadata Keys
- **Skema (`skema`):**
  - `kode_skema`: Kode unik skema (contoh: `SS.01.001.2023`)
  - `level_skema` / `level`: Angka level KKNI 1 - 9
  - `jenis_kemasan`: `KKNI`, `Okupasi`, `Klaster`
  - `biaya_sertifikasi`: Angka biaya nominal rupiah
  - `persyaratan_skema`: Teks persyaratan dasar
  - `persyaratan_admin`: Teks persyaratan administrasi
  - `unit_kompetensi`: Daftar baris unit dengan format `KODE_UNIT | Judul Unit`
  - `_lsp_auto_thumb`: Flag internal penanda thumbnail otomatis GD
- **TUK (`tuk`):**
  - `kode_tuk`: Kode pengenal TUK
  - `jenis_tuk`: `TUK Sewaktu`, `TUK Tempat Kerja`, `TUK Mandiri`
  - `alamat_tuk`: Alamat fisik lengkap
  - `kontak_tuk`: Nomor telepon / PIC
  - `gmaps_tuk`: Kode embed `<iframe>` Google Maps atau link URL tautan
- **Data Sertifikat (`data_sertifikat`):**
  - `kode_blanko`: Nomor seri fisik blanko BNSP (Identitas Pencarian Utama)
  - `nama_pemegang`: Nama lengkap asesi pemegang sertifikat
  - `no_registrasi`: Nomor registrasi sertifikasi (contoh: `KOM.010.00012 2024`)
  - `skema_sertifikasi`: Nama skema sertifikasi yang ditempuh
  - `tanggal_terbit`: Tanggal terbit sertifikat format `YYYY-MM-DD`

### 3.4 User Metadata Keys (Asesor)
- `nomor_met`: Nomor Registrasi MET Asesor BNSP
- `tanggal_terbit_met`: Tanggal penerbitan lisensi MET (`YYYY-MM-DD`)
- `foto_asesor`: URL gambar profil khusus asesor
- `bidang_keahlian_asesor`: Array nama skema / bidang keahlian
- `bidang_keahlian_tambahan`: Teks bidang tambahan pisah koma
- `deskripsi_asesor`: Biografi singkat asesor
- `asesor_linkedin`, `asesor_instagram`, `asesor_facebook`, `asesor_website`: Tautan media sosial

### 3.5 WordPress Options
- `lsp_core_wa_number`: Nomor kontak WhatsApp Admin (format `628...`)
- `lsp_core_register_url`: Tautan eksternal tombol "Daftar Sekarang"

### 3.6 Shortcodes Frontend
- `[lsp_skema jumlah="6" orderby="date|rand" acak="ya" kemasan="KKNI|Okupasi|Klaster"]`
- `[lsp_tuk jumlah="-1" acak="ya"]`
- `[lsp_asesor jumlah="8" acak="ya" hanya_aktif="ya"]`
- `[cek_sertifikat]`

### 3.7 Endpoint AJAX & Hooks
- `wp_ajax_lsp_verify_cert` & `wp_ajax_nopriv_lsp_verify_cert` -> `LSP_Core_Management::ajax_verify_cert()`
- Admin actions:
  - `init` -> `register_cpts_and_roles`
  - `add_meta_boxes` -> `register_meta_boxes`
  - `save_post_skema` -> `save_skema_meta`
  - `save_post_tuk` -> `save_tuk_meta`
  - `save_post_data_sertifikat` -> `save_cert_meta`
  - `admin_menu` -> `register_admin_menus`
  - `admin_init` -> `handle_csv_template_download`
  - `admin_enqueue_scripts` -> `enqueue_admin_scripts`
  - `wp_dashboard_setup` -> `register_dashboard_guide_widget`
  - `pre_get_posts` -> `skema_archive_query`
  - `template_include` -> `template_loader`
  - `the_content` / `widget_text_content` -> `normalize_lsp_shortcodes_in_content`

---

## 4. Ketergantungan Theme - Plugin

1. **Tema `lspkomunikasi`**:
   - `archive-skema.php`: Memanggil `LSP_Core_Management::get_instance()->render_skema_catalog_archive()`. Jika plugin nonaktif, menampilkan pesan fallback tanpa fatal error.
   - Variabel CSS Tema: Plugin mengadopsi `--primary-color` (#72161a) dan `--secondary-color` (#f89b29) yang didefinisikan tema.
   - Pustaka FontAwesome 6: Dimuat bersamaan secara idempotent.
2. **Backward Compatibility**:
   - Jika tema tidak memiliki `single-skema.php`, `archive-tuk.php`, atau `single-tuk.php`, plugin menyediakan template fallback otomatis di `templates/`.

---

## 5. Ringkasan Risiko Arsitektur Awal
1. Mutasi role capability pada setiap request `init`.
2. Verifikasi sertifikat menggunakan query `LIKE` yang berpotensi menghasilkan false positive.
3. Ketiadaan verifikasi nonce dan rate limiting pada endpoint publik `lsp_verify_cert`.
4. Kelemahan parsing CSV (asumsi koma, tidak ada validasi header, tidak ada sanitasi tanggal mendalam).
5. Kueri N+1 pada proses import CSV dan `posts_per_page => -1` pada beberapa query frontend.

