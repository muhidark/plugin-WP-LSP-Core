# AUDIT LAPORAN 02: SECURITY AUDIT (VALIDATED)
**Plugin:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Lingkup:** Input Validation, Output Escaping, CSRF, Authorization, Endpoint Publik, Verifikasi Sertifikat, CSV Import  
**Standar Evaluasi:** WordPress Plugin Security Standards, OWASP Top 10, CWE / ASVS  
**Status:** Divalidasi Ulang & Terkalibrasi (17 Oktober 2026)  

---

## 1. REKAPITULASI TEMUAN KEAMANAN & DATA INTEGRITY

| ID | Severity | Kategori | File | Baris | Fungsi |
|---|---|---|---|---|---|
| **SEC-02** | **CRITICAL** | Business Logic / Data Exposure | `lsp-core-management.php` | 2675–2686 | `ajax_verify_cert()` |
| **SEC-03** | **HIGH** | DoS / Brute-force Enumeration | `lsp-core-management.php` | 2658–2674 | `ajax_verify_cert()` |
| **SEC-04** | **HIGH** | Insecure File Upload | `lsp-core-management.php` | 1963–1967 | `render_import_page()` |
| **SEC-01** | **MEDIUM** | Missing Nonce on Public AJAX | `lsp-core-management.php` | 2658–2663 | `ajax_verify_cert()` |
| **SEC-05** | **MEDIUM** | Input Sanitization Hygiene | `lsp-core-management.php` | 327–348, 633–642, 786–790, 1198–1232 | Handlers `save_*_meta()` |
| **SEC-06** | **MEDIUM** | Output Integrity & Data Exposure | `lsp-core-management.php` | 2708, 2723, 2770 | `ajax_verify_cert()` |
| **DATA-01**| **MEDIUM** | Data Integrity / Uniqueness | `lsp-core-management.php` | 780–807 | `save_cert_meta()` |

---

## 2. DETAIL TEKNIS SETIAP TEMUAN

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
- **ROOT CAUSE:** Penggunaan kueri fuzzy search `LIKE` pada sistem pembuktian keabsahan hukum sertifikat kompetensi.
- **IMPACT:**  
  1. Sertifikat palsu/tidak sah dapat dinyatakan valid secara resmi oleh website LSP hanya karena memiliki kesamaan beberapa karakter dengan sertifikat asesi lain.
  2. Kebocoran data pribadi pemegang sertifikat sah kepada pihak yang melakukan pencarian acak.
- **EXPLOITABILITY:** Sangat Mudah (Trivial). Cukup masukkan satu karakter umum di kotak pencarian publik.
- **RECOMMENDED FIX:**  
  Hapus seluruh blok fallback kueri `LIKE`. Gunakan pencarian eksak (`=`) terhadap `kode_blanko` atau `no_registrasi`. Normalisasikan spasi dan huruf besar tanpa mengubah karakter tanda baca nomor sertifikat. Jika terdapat hasil duplikat identik, tangani secara aman tanpa menyatakan salah satu secara ambigu.
- **REGRESSION RISK:** Rendah. Pengguna yang memiliki nomor sertifikat sah akan tetap terverifikasi dengan benar.
- **TEST CASE:**  
  - Input: `K`, `1`, `KOM` -> Expected: Ditolak / Tidak Ditemukan.
  - Input: `K24.01.0001` (lengkap) -> Expected: Ditemukan tepat dan valid.

---

### FINDING ID: SEC-03
- **SEVERITY:** HIGH
- **CATEGORY:** DoS / Resource Exhaustion / Automated Enumeration
- **FILE:** `lsp-core-management.php`
- **LINE:** 2658 - 2674
- **AFFECTED FUNCTION:** `LSP_Core_Management::ajax_verify_cert()`
- **DESCRIPTION:**  
  Endpoint publik `ajax_verify_cert()` tidak memiliki mekanisme pembatasan frekuensi request (Rate Limiting) dan tidak membatasi panjang input pengguna. Penyerang dapat mengirimkan puluhan request per detik untuk enumerasi data atau melumpuhkan koneksi database MySQL melalui kueri berulang.
- **TECHNICAL EVIDENCE:**  
  Tidak ada pemeriksaan pembatasan request (Transient/Object Cache) dan tidak ada pengecekan panjang string (`mb_strlen`) pada `$_POST['kode_blanko']`.
- **ROOT CAUSE:** Ketiadaan rate limiting layer di level handler endpoint AJAX publik.
- **IMPACT:** Penipisan resource server, database connection exhaustion, dan potensi scraping data pemegang sertifikat secara massal.
- **EXPLOITABILITY:** Mudah melalui automated cURL / script.
- **RECOMMENDED FIX:**  
  1. Batasi panjang input (minimal 3 karakter, maksimal 60 karakter).
  2. Implementasikan rate limiter berbasis WordPress Transients dengan batas awal 20 request/menit per hash IP client yang divalidasi dengan aman (tidak langsung mempercayai header spoofable seperti `X-Forwarded-For`).
  3. Kembalikan kode status HTTP 429 atau respons JSON terstruktur saat limit terlampaui.
- **REGRESSION RISK:** Rendah, pengguna sah manusia tidak melakukan lebih dari 3-5 pencarian per menit.
- **TEST CASE:** Jalankan script 25 request dalam 10 detik; request ke-21 wajib menerima respons rate limit terlampaui.

---

### FINDING ID: SEC-04
- **SEVERITY:** HIGH
- **CATEGORY:** Insecure File Upload / File Handling
- **FILE:** `lsp-core-management.php`
- **LINE:** 1963 - 1967
- **AFFECTED FUNCTION:** `LSP_Core_Management::render_import_page()`
- **DESCRIPTION:**  
  File import CSV diproses langsung dari `$_FILES['csv_file']['tmp_name']` tanpa memvalidasi:
  1. Error status upload (`$_FILES['csv_file']['error'] === UPLOAD_ERR_OK`).
  2. Ekstensi file (`.csv`).
  3. Validasi MIME type di server (`text/csv`, `text/plain`, `application/vnd.ms-excel`).
  4. Batas ukuran file (Max File Size).
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:1964
  if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
      $file   = $_FILES['csv_file']['tmp_name'];
      $handle = fopen( $file, 'r' );
  ```
- **ROOT CAUSE:** Mengandalkan atribut `<input accept=".csv">` di browser tanpa validasi server-side yang kuat.
- **IMPACT:** Risiko memory exhaustion dan server error jika diunggah file binary sangat besar atau file non-CSV.
- **EXPLOITABILITY:** Membutuhkan hak akses administrator (`manage_options`).
- **RECOMMENDED FIX:**  
  Validasi status upload error, ekstensi dengan `pathinfo()`, MIME type dengan `wp_check_filetype_and_ext()`, dan batasi ukuran file maksimal 5MB.
- **REGRESSION RISK:** Rendah. File CSV valid tetap diproses normal.
- **TEST CASE:** Unggah file non-CSV atau file berukuran 10MB; verifikasi pesan penolakan yang ramah dan aman.

---

### FINDING ID: SEC-01
- **SEVERITY:** MEDIUM *(Dikalibrasi dari temuan awal)*
- **CATEGORY:** Missing CSRF Nonce on Public Read-Only Endpoint
- **FILE:** `lsp-core-management.php`
- **LINE:** 2658 - 2663
- **AFFECTED FUNCTION:** `LSP_Core_Management::ajax_verify_cert()`
- **DESCRIPTION:**  
  Endpoint AJAX `lsp_verify_cert` tidak memverifikasi nonce yang dikirim dari klien. Karena endpoint ini bersifat publik dan hanya membaca data (read-only), tidak ada perubahan state atau data sensitif pengguna login yang dimanipulasi (tidak ada true CSRF privilege escalation). Namun, verifikasi nonce tetap dibutuhkan sebagai pertahanan lapis pertama untuk mencegah eksekusi form liar dari situs eksternal.
- **TECHNICAL EVIDENCE:**
  Form frontend shortcode mengirimkan parameter `nonce`, namun di PHP `ajax_verify_cert()` tidak terdapat pemanggilan `check_ajax_referer()` atau `wp_verify_nonce()`.
- **ROOT CAUSE:** Ketiadaan verifikasi token handshake di server handler.
- **IMPACT:** Endpoint dapat ditembak langsung oleh form pihak ketiga tanpa batasan origin.
- **RECOMMENDED FIX:**  
  Tambahkan verifikasi nonce:
  ```php
  if ( ! check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce', false ) ) {
      wp_send_json_error( array(
          'html' => '<div class="cert-result-card" style="padding:20px; text-align:center; color:#cf1322;">Sesi verifikasi telah kedaluwarsa atau tidak valid. Silakan muat ulang halaman.</div>'
      ), 403 );
  }
  ```
- **REGRESSION RISK:** Rendah, karena form resmi frontend sudah menyertakan nonce yang valid.
- **TEST CASE:** Kirim POST tanpa nonce atau nonce salah; verifikasi permintaan ditolak dengan respons yang jelas.

---

### FINDING ID: SEC-05
- **SEVERITY:** MEDIUM
- **CATEGORY:** Input Sanitization Hygiene / Accumulating Slashes
- **FILE:** `lsp-core-management.php`
- **LINE:** 327–348, 633–642, 786–790, 1198–1232
- **AFFECTED FUNCTION:** `save_skema_meta()`, `save_tuk_meta()`, `save_cert_meta()`, `save_asesor_user_profile_fields()`
- **DESCRIPTION:**  
  WordPress secara default menambahkan escape slashes ke superglobal `$_POST`. Pada saat menyimpan metadata, handler memanggil `sanitize_text_field( $_POST[...] )` tanpa memanggil `wp_unslash()` terlebih dahulu. Akibatnya, karakter tanda petik (`'`, `"`) atau backslash (`\`) akan terakumulasi menjadi ganda (`O\'Neil` menjadi `O\\\'Neil`) setiap kali disimpan ulang di admin.
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:786-787
  $kode_blanko   = isset( $_POST['kode_blanko'] ) ? sanitize_text_field( trim( $_POST['kode_blanko'] ) ) : '';
  $nama_pemegang = isset( $_POST['nama_pemegang'] ) ? sanitize_text_field( trim( $_POST['nama_pemegang'] ) ) : '';
  ```
- **ROOT CAUSE:** Mengabaikan konvensi WordPress `wp_unslash()` sebelum sanitasi.
- **IMPACT:** Kerusakan string data nama asesi, alamat TUK, dan persyaratan skema saat diperbarui berkali-kali.
- **RECOMMENDED FIX:**  
  Terapkan `wp_unslash()` sebelum sanitasi: `sanitize_text_field( wp_unslash( trim( $_POST['...'] ) ) )`.
- **REGRESSION RISK:** Nol.
- **TEST CASE:** Simpan data dengan tanda petik; pastikan tidak ada akumulasi backslash ganda di database.

---

### FINDING ID: SEC-06
- **SEVERITY:** MEDIUM
- **CATEGORY:** Output Integrity & Data Exposure
- **FILE:** `lsp-core-management.php`
- **LINE:** 2708, 2723, 2770
- **AFFECTED FUNCTION:** `LSP_Core_Management::ajax_verify_cert()`
- **DESCRIPTION:**  
  Pada tampilan kartu hasil verifikasi, sistem mencetak string input yang diketikkan pengguna (`$kode_blanko`), bukan nomor blanko resmi yang tersimpan di database post (`$db_blanko`). Jika pengguna mencari dengan nomor registrasi atau string dengan spasi berbeda, badge dan tabel menampilkan nomor yang diketik user alih-alih identitas resmi dari database.
- **TECHNICAL EVIDENCE:**
  ```php
  // lsp-core-management.php:2708
  <span style="font-size:13px; font-weight:600; opacity:0.9;">No. Blanko: <?php echo esc_html( $kode_blanko ); ?></span>
  // lsp-core-management.php:2724
  <td class="cert-data-value"><code><?php echo esc_html( $kode_blanko ); ?></code></td>
  ```
- **ROOT CAUSE:** Merender variabel input mentah alih-alih metadata yang diambil dari `$post_id`.
- **IMPACT:** Ketidaksesuaian data verifikasi resmi dan potensi distorsi informasi hasil cetak sertifikat.
- **RECOMMENDED FIX:**  
  Ambil nomor blanko resmi dari database:
  `$official_blanko = get_post_meta( $post_id, 'kode_blanko', true );`
  dan tampilkan variabel ini pada kartu hasil verifikasi.
- **REGRESSION RISK:** Nol.
- **TEST CASE:** Verifikasi sertifikat menggunakan nomor registrasi; kartu hasil wajib menampilkan nomor blanko resmi dari database.

---

### FINDING ID: DATA-01
- **SEVERITY:** MEDIUM
- **CATEGORY:** Data Integrity / Lack of Duplicate Validation
- **FILE:** `lsp-core-management.php`
- **LINE:** 780 - 807
- **AFFECTED FUNCTION:** `LSP_Core_Management::save_cert_meta()`
- **DESCRIPTION:**  
  Ketika administrator menambahkan atau mengedit data sertifikat secara manual melalui form admin post editor, tidak ada pemeriksaan apakah nomor blanko tersebut sudah dipakai oleh post sertifikat lain. Jika terjadi duplikasi, verifier publik berisiko mengalami ambiguitas (dua pemilik dengan nomor blanko sama).
- **TECHNICAL EVIDENCE:**  
  Fungsi `save_cert_meta()` langsung memanggil `update_post_meta( $post_id, 'kode_blanko', $kode_blanko )` tanpa verifikasi apakah ada post ID lain dengan meta key `kode_blanko` yang bernilai sama.
- **ROOT CAUSE:** Ketiadaan validasi keunikan nomor blanko pada event `save_post_data_sertifikat`.
- **IMPACT:** Inkonsistensi data manual di database WordPress.
- **RECOMMENDED FIX:**  
  Tambahkan pengecekan duplikasi pada `save_cert_meta()` dan berikan admin notice/transient error jika nomor blanko sudah digunakan oleh post lain.
- **REGRESSION RISK:** Rendah.
- **TEST CASE:** Coba buat dua sertifikat dengan nomor blanko identik; pastikan sistem memberikan peringatan duplikasi.
