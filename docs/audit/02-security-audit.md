# AUDIT LAPORAN 02: SECURITY AUDIT
**Plugin:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Lingkup:** Input Validation, Output Escaping, CSRF, Authorization, Endpoint Publik, Verifikasi Sertifikat, CSV Import  
**Standar Evaluasi:** WordPress Plugin Security Standards, OWASP Top 10, CWE / ASVS  
**Status:** Audit Selesai (Temuan Teknis Diverifikasi Berdasarkan Kode Aktual)  

---

## 1. DAFTAR TEMUAN KEAMANAN (FINDINGS)

### FINDING ID: SEC-01
- **SEVERITY:** HIGH
- **CATEGORY:** CSRF / Unprotected Public AJAX Handler
- **FILE:** `lsp-core-management.php`
- **LINE:** 2658 - 2663
- **AFFECTED FUNCTION:** `LSP_Core_Management::ajax_verify_cert()`
- **DESCRIPTION:**  
  Endpoint AJAX publik `lsp_verify_cert` yang didaftarkan untuk pengguna anonim (`nopriv`) dan pengguna login (`wp_ajax`) tidak memvalidasi token nonce sama sekali di sisi server. Walaupun skrip frontend JavaScript pada shortcode `[cek_sertifikat]` mengirimkan parameter `formData.append('nonce', '...')`, server handler tidak memanggil `check_ajax_referer()` atau `wp_verify_nonce()`.
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:2658
  public function ajax_verify_cert() {
      $kode_blanko = isset( $_POST['kode_blanko'] ) ? sanitize_text_field( trim( $_POST['kode_blanko'] ) ) : '';
      if ( empty( $kode_blanko ) ) {
          wp_send_json_error( array( 'html' => '...' ) );
      }
      // Langsung mengeksekusi WP_Query tanpa pemeriksaan nonce
  ```
- **ROOT CAUSE:** Pengembang mengabaikan pemanggilan `check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce' )` di awal fungsi AJAX.
- **IMPACT:** Endpoint dapat diserang via cross-origin scripted POST requests atau automated bot scraping tanpa batasan session handshake.
- **EXPLOITABILITY:** Mudah (Trivial). Cukup kirim HTTP POST ke `/wp-admin/admin-ajax.php` dengan action `lsp_verify_cert`.
- **RECOMMENDED FIX:**  
  Tambahkan validasi nonce di baris pertama fungsi `ajax_verify_cert()`:
  ```php
  if ( ! check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce', false ) ) {
      wp_send_json_error( array(
          'html' => '<div class="cert-result-card" style="padding:20px; text-align:center; color:#cf1322;">Sesi verifikasi tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.</div>'
      ) );
  }
  ```
- **COMPATIBILITY RISK:** Sangat Rendah. Form frontend resmi sudah mengirimkan parameter `nonce`.
- **TEST PLAN:**  
  1. Kirim request POST tanpa parameter `nonce` -> Respon harus error 403 atau JSON error.
  2. Kirim request POST dengan nonce salah -> Respon harus ditolak.
  3. Kirim request melalui form web resmi dengan nonce valid -> Berhasil diproses.

---

### FINDING ID: SEC-02
- **SEVERITY:** CRITICAL
- **CATEGORY:** Business Logic Flaw / Data Exposure / Broken Certificate Integrity
- **FILE:** `lsp-core-management.php`
- **LINE:** 2675 - 2686
- **AFFECTED FUNCTION:** `LSP_Core_Management::ajax_verify_cert()`
- **DESCRIPTION:**  
  Fungsi verifikasi keabsahan sertifikat mengimplementasikan fallback pencarian menggunakan operator `LIKE` (`%input%`) apabila pencarian eksak tidak membuahkan hasil. Akibatnya, jika pengunjung publik mengetikkan satu digit atau karakter umum (misalnya: "0", "1", "K", "KOM", atau "-"), sistem mencocokkan sebagian string terhadap record sertifikat mana saja yang mengandung karakter tersebut, lalu mengembalikan data sertifikat orang lain pertama yang cocok sebagai sertifikat yang **"VALID & AKTIF"** atas nama nomor yang dimasukkan pengguna.
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:2675-2686
  if ( ! $query->have_posts() ) {
      $query = new WP_Query( array(
          'post_type'      => 'data_sertifikat',
          'post_status'    => 'publish',
          'posts_per_page' => 1,
          'meta_query'     => array(
              'relation' => 'OR',
              array( 'key' => 'kode_blanko', 'value' => $kode_blanko, 'compare' => 'LIKE' ),
              array( 'key' => 'no_registrasi', 'value' => $kode_blanko, 'compare' => 'LIKE' ),
          ),
      ) );
  }
  ```
- **ROOT CAUSE:** Kesalahan konsepsi bahwa pencarian verifikasi publik disamakan dengan fitur mesin pencari teks (search engine) yang fleksibel, alih-alih validasi identitas keabsahan dokumen hukum negara.
- **IMPACT:**  
  1. **Integritas Dokumen Hancur:** Seseorang yang memiliki nomor sertifikat palsu yang hanya menyerupai sebagian nomor asli dapat mengklaim sertifikatnya terverifikasi resmi oleh sistem.
  2. **Data Leakage:** Siapapun dapat mencuri/melakukan enumerasi identitas asesi pemegang sertifikat, nomor registrasi, dan skema sertifikasi dengan memasukkan karakter acak.
- **EXPLOITABILITY:** Sangat Mudah (Trivial). Terjadi langsung melalui antarmuka web publik.
- **RECOMMENDED FIX:**  
  Hapus seluruh blok fallback query `LIKE`. Verifikasi wajib menggunakan pencarian eksak (`=`) terhadap `kode_blanko` atau `no_registrasi` yang telah dinormalisasi (menghilangkan spasi berlebih tanpa merusak format tanda baca resmi).
- **COMPATIBILITY RISK:** Rendah. Pengguna yang memiliki nomor sertifikat sah akan tetap terverifikasi 100% akurat.
- **TEST PLAN:**  
  1. Input `K` atau `1` -> Verifier wajib menyatakan: "TIDAK DITEMUKAN / TIDAK VALID".
  2. Input nomor sertifikat sebagian (contoh: `K24.01`) -> Wajib ditolak.
  3. Input nomor sertifikat lengkap persis (`K24.01.0001`) -> Sukses terverifikasi sebagai sertifikat pemilik asli.

---

### FINDING ID: SEC-03
- **SEVERITY:** HIGH
- **CATEGORY:** DoS / Resource Exhaustion / Automated Enumeration
- **FILE:** `lsp-core-management.php`
- **LINE:** 2658 - 2674
- **AFFECTED FUNCTION:** `LSP_Core_Management::ajax_verify_cert()`
- **DESCRIPTION:**  
  Endpoint publik verifikasi sertifikat tidak memiliki mekanisme pembatasan frekuensi request (Rate Limiting) dan tidak membatasi panjang karakter string input. Penyerang dapat membuat bot untuk mengirimkan ribuan request per menit guna membebani database server (exhausting DB connection pool) atau menyalin seluruh database asesi secara otomatis.
- **TECHNICAL EVIDENCE:**  
  Tidak ada pemeriksaan Transient API, Object Cache, maupun pengecekan `strlen( $kode_blanko )` di dalam `ajax_verify_cert()`.
- **ROOT CAUSE:** Ketiadaan rate limiting layer di tingkat aplikasi WordPress.
- **IMPACT:** Potensi downtime hosting, CPU throttling dari shared hosting, dan database scraping.
- **EXPLOITABILITY:** Mudah melalui automated Python / cURL script.
- **RECOMMENDED FIX:**  
  1. Tambahkan sanitasi panjang input: jika panjang string > 60 karakter atau < 3 karakter, tolak request secara aman.
  2. Implementasikan transient rate limiter berbasis IP aman (misal: maksimum 20 request per menit per alamat IP).
  ```php
  $client_ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
  $rate_key  = 'lsp_cert_rate_' . md5( $client_ip );
  $attempts  = (int) get_transient( $rate_key );
  if ( $attempts > 20 ) {
      wp_send_json_error( array(
          'html' => '<div class="cert-result-card" style="padding:20px; text-align:center; color:#cf1322;">Terlalu banyak permintaan verifikasi. Silakan tunggu 1 menit sebelum mencoba kembali.</div>'
      ) );
  }
  set_transient( $rate_key, $attempts + 1, 60 );
  ```
- **COMPATIBILITY RISK:** Nol. Penggunaan normal manusia (1-3 pencarian) tidak akan pernah menyentuh limit 20 request/menit.
- **TEST PLAN:** Jalankan loop script 25 request dalam 10 detik; request ke-21 ke atas wajib menerima pesan limitasi.

---

### FINDING ID: SEC-04
- **SEVERITY:** HIGH
- **CATEGORY:** Insecure File Upload / File Handling
- **FILE:** `lsp-core-management.php`
- **LINE:** 1963 - 1967
- **AFFECTED FUNCTION:** `LSP_Core_Management::render_import_page()`
- **DESCRIPTION:**  
  Proses import CSV pada halaman admin membaca `$_FILES['csv_file']['tmp_name']` tanpa melakukan:
  1. Validasi error status file upload (`$_FILES['csv_file']['error'] === UPLOAD_ERR_OK`).
  2. Pengecekan ekstensi file (`.csv`).
  3. Pengecekan MIME type (`text/csv`, `text/plain`, `application/vnd.ms-excel`).
  4. Batas maksimal ukuran file (Max File Size).
  5. Validasi integritas berkas sementara sebelum dibuka menggunakan `fopen()`.
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:1964
  if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
      $file   = $_FILES['csv_file']['tmp_name'];
      $handle = fopen( $file, 'r' );
  ```
- **ROOT CAUSE:** Mengasumsikan atribut HTML `<input type="file" accept=".csv">` sudah memadai untuk keamanan sisi server.
- **IMPACT:** Administrator atau user terkompromi dapat mengunggah file non-CSV berukuran sangat besar yang memicu crash pada parser `fgetcsv` atau file binary tak terduga.
- **EXPLOITABILITY:** Membutuhkan hak akses `manage_options`, namun merupakan pelanggaran standar keamanan WordPress Secure Uploading.
- **RECOMMENDED FIX:**  
  Gunakan validasi berlapis:
  ```php
  if ( ! isset( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
      // Tolak dengan pesan error spesifik
  }
  if ( $_FILES['csv_file']['size'] > 5 * 1024 * 1024 ) { // Maks 5 MB
      // Tolak berkas terlalu besar
  }
  $check = wp_check_filetype_and_ext( $_FILES['csv_file']['tmp_name'], $_FILES['csv_file']['name'], array( 'csv' => 'text/csv' ) );
  if ( ! $check['ext'] ) {
      // Tolak berkas yang bukan CSV murni
  }
  ```
- **COMPATIBILITY RISK:** Nol.
- **TEST PLAN:** Unggah file berekstensi non-csv atau file melebihi 5MB; verifikasi bahwa sistem menolak dengan pesan informatif.

---

### FINDING ID: SEC-05
- **SEVERITY:** MEDIUM
- **CATEGORY:** Input Sanitization Hygiene / Double-Escaping Slashes
- **FILE:** `lsp-core-management.php`
- **LINE:** 327 - 348, 633 - 642, 786 - 790, 1198 - 1232
- **AFFECTED FUNCTION:** `save_skema_meta()`, `save_tuk_meta()`, `save_cert_meta()`, `save_asesor_user_profile_fields()`
- **DESCRIPTION:**  
  WordPress secara global mengaplikasikan `magic quotes` (`add_magic_quotes`) pada seluruh superglobal `$_POST`. Pada saat menyimpan metadata, fungsi-fungsi di atas memanggil `sanitize_text_field( $_POST[...] )` atau `wp_kses_post( $_POST[...] )` tanpa memanggil `wp_unslash()` terlebih dahulu. Akibatnya, setiap karakter kutip (petik satu `'`, petik dua `"`, dan garis miring terbalik `\`) akan bertambah garis miringnya secara berulang (misal: `O\'Connor` menjadi `O\\\'Connor`) setiap kali disimpan ulang.
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:786-790
  $kode_blanko    = isset( $_POST['kode_blanko'] ) ? sanitize_text_field( trim( $_POST['kode_blanko'] ) ) : '';
  $nama_pemegang  = isset( $_POST['nama_pemegang'] ) ? sanitize_text_field( trim( $_POST['nama_pemegang'] ) ) : '';
  ```
- **ROOT CAUSE:** Kelalaian membersihkan slashes default WordPress sebelum sanitasi.
- **IMPACT:** Kerusakan string nama asesi, judul unit kompetensi, dan alamat TUK yang mengandung tanda petik atau simbol.
- **EXPLOITABILITY:** Dampak integritas data (non-exploit).
- **RECOMMENDED FIX:**  
  Gunakan pola standar WordPress:
  `sanitize_text_field( wp_unslash( $_POST['field_name'] ) )`
- **COMPATIBILITY RISK:** Nol. Justru mencegah kerusakan data teks.
- **TEST PLAN:** Simpan nama pemegang: `D'Angelo "Master" & O'Neil`; pastikan tersimpan rapi tanpa backslash tambahan.

---

### FINDING ID: SEC-06
- **SEVERITY:** MEDIUM
- **CATEGORY:** Data Protection / Public Response Minimization
- **FILE:** `lsp-core-management.php`
- **LINE:** 2720 - 2753
- **AFFECTED FUNCTION:** `ajax_verify_cert()`
- **DESCRIPTION:**  
  Tabel respon verifikasi publik menampilkan: Nomor Blanko, Nama Pemegang, Nomor Registrasi, Skema Sertifikasi, Tanggal Terbit, dan Tanggal Kadaluarsa. Pada baris status header (line 2708), sistem menampilkan `$kode_blanko` yang diketik oleh pengguna, bukan nomor blanko yang sebenarnya tersimpan di database post.
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:2708
  <span style="font-size:13px; font-weight:600; opacity:0.9;">No. Blanko: <?php echo esc_html( $kode_blanko ); ?></span>
  ```
- **ROOT CAUSE:** Menampilkan variabel input `$_POST['kode_blanko']` alih-alih nilai metadata resmi `$db_blanko`.
- **IMPACT:** Menimbulkan distorsi tampilan jika input pencarian adalah nomor registrasi atau string dengan spasi berbeda.
- **RECOMMENDED FIX:**  
  Gunakan nilai metadata resmi yang diambil dari database `$db_blanko = get_post_meta( $post_id, 'kode_blanko', true );` pada seluruh blok hasil verifikasi.
- **COMPATIBILITY RISK:** Nol.
- **TEST PLAN:** Cari menggunakan nomor registrasi; verifikasi bahwa nomor blanko yang muncul di badge hasil adalah nomor blanko yang sesuai dari basis data.

