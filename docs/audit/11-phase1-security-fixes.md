# LAPORAN PELAKSANAAN REMEDIASI TAHAP 1
**Project:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Website:** https://lspkomunikasi.id  
**Lingkup:** Tahap 1 — Keamanan Endpoint & Logika Verifikasi Sertifikat Publik  
**Waktu Pelaksanaan:** 17 Oktober 2026  
**Auditor & Pelaksana:** Tim Senior Software Engineering  

---

## 1. MASALAH YANG DIPERBAIKI PADA TAHAP 1

Pada Tahap 1, perbaikan dilakukan secara terisolasi (*isolated fix*) hanya pada modul verifikasi sertifikat publik (`ajax_verify_cert` dan shortcode pendukungnya):

1. **`SEC-02` (CRITICAL):** Menghapus total query fallback `LIKE` (`%input%`) yang sebelumnya menyebabkan pencarian parsial atau 1 karakter sembarang ("0", "1", "K") mengembalikan sertifikat asesi lain sebagai "VALID & AKTIF".
2. **`SEC-01` (MEDIUM):** Menambahkan verifikasi CSRF nonce sisi server menggunakan `check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce', false )` dengan respons status HTTP 403 jika token tidak valid.
3. **`SEC-03` (HIGH):** Mengimplementasikan Transient IP Rate Limiter (maksimal 20 request per menit per hash client IP) untuk menahan automated scraping / brute-force, serta membatasi panjang input sertifikat (minimal 3, maksimal 60 karakter).
4. **`SEC-06` (MEDIUM):** Memastikan nomor blanko yang dicetak pada kartu verifikasi dan badge status diambil resmi dari database (`get_post_meta`), bukan string input mentah user.
5. **`SEC-05` (Sebagian):** Menerapkan `wp_unslash()` pada pembacaan `$_POST['kode_blanko']` sebelum sanitasi untuk mencegah akumulasi escape character.
6. **Penanganan Ambiguitas Data Duplikat:** Menambahkan logika penanganan aman jika di database ditemukan lebih dari 1 sertifikat dengan nomor identik; sistem menolak menyatakan valid secara sepihak dan menampilkan kartu status "PERLU KLARIFIKASI SEKRETARIAT".

---

## 2. DAFTAR FILE YANG MENGALAMI PERUBAHAN

| File Path | Jenis Perubahan | Deskripsi Perubahan |
|---|---|---|
| `lsp-core-management.php` | Modifikasi Source Code | Penguatan `ajax_verify_cert()`, penambahan `get_client_ip()`, dan penguatan handler `fetch()` di `shortcode_cek_sertifikat()` |
| `tests/test-phase1.php` | Penambahan File Baru | Test suite otomatis untuk mengevaluasi TEST 01 s/d TEST 12 |
| `docs/audit/02-security-audit.md` | Pembaruan Dokumen | Rekalibrasi severity SEC-01 & penegasan temuan resmi |
| `docs/audit/03-performance-audit.md` | Pembaruan Dokumen | Evaluasi objektif WP_Query vs raw SQL $wpdb |
| `docs/audit/04-architecture-audit.md` | Pembaruan Dokumen | Verifikasi teknis source code WP Core pada ARCH-01 |
| `docs/audit/06-remediation-plan.md` | Pembaruan Dokumen | Matriks temuan tervalidasi & roadmap Tahap 1 |

> **Catatan:** Tidak ada file tema `lspkomunikasi` yang diubah. Baseline tema tetap 100% utuh.

---

## 3. PENJELASAN PERUBAHAN KODE (TECHNICAL DIFF BREAKDOWN)

### A. Penguatan Handler `ajax_verify_cert()`
- **Verifikasi Nonce:**
  ```php
  if ( ! check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce', false ) ) {
      wp_send_json_error( array( 'html' => '...' ), 403 );
  }
  ```
- **Rate Limiting Aman (Transient API):**
  Menggunakan `$_SERVER['REMOTE_ADDR']` yang divalidasi via `FILTER_VALIDATE_IP`, kemudian di-hash menggunakan `md5()` (`lsp_rl_<hash>`) agar IP pengguna tidak disimpan mentah. Jika jumlah percobaan dalam 60 detik mencapai $\ge 20$, sistem mengembalikan HTTP 429 tanpa memblokir permanen.
- **Normalisasi Input Eksak:**
  Input dibersihkan dari karakter kontrol tersembunyi (`[\x00-\x1F\x7F\xC2\xA0]`), whitespace ganda diciutkan, dan di-trim. Panjang string dibatasi ketat antara 3 s/d 60 karakter (HTTP 400 jika melanggar).
- **Exact Match Query (Hapus LIKE):**
  Menggunakan `WP_Query` dengan `compare => '='` terhadap `kode_blanko` dan `no_registrasi`. Nilai `posts_per_page` diatur ke `2` untuk mendeteksi ambiguitas duplikasi data.
- **Penanganan Duplikat Identik:**
  Jika `$query->post_count > 1`, sistem tidak menebak salah satu data, melainkan merender kartu peringatan bernuansa oranye bertuliskan *"Ditemukan Lebih dari Satu Catatan — Silakan hubungi Sekretariat LSP Komunikasi Indonesia untuk verifikasi fisik"*.
- **Integritas Output:**
  Jika `$query->post_count === 1`, variabel `$db_blanko = get_post_meta( $post_id, 'kode_blanko', true )` digunakan di seluruh kartu output. Tanggal terbit diverifikasi (`$has_valid_date`) untuk mencegah kesalahan masa berlaku jika tanggal kosong.

### B. Ketahanan Fetch Frontend pada `shortcode_cek_sertifikat()`
Skrip JavaScript pada baris 2617 disempurnakan agar `res.json()` tetap memproses respons HTML error baik saat server mengembalikan kode status HTTP 200, 400, 403, maupun 429, sehingga pengguna disajikan kartu visual informatif di dalam area `#lsp-cert-result-area` tanpa memicu popup `alert()` browser.

---

## 4. DAFTAR PENGUJIAN DAN HASIL AKTUAL (TEST REPORT)

Pengujian dieksekusi menggunakan test harness otomatis (`tests/test-phase1.php`) dan verifikasi CLI linter:

| Test ID | Skenario Uji | Harapan (Expected) | Hasil Aktual | Status |
|---|---|---|---|:---:|
| **TEST 01** | Nomor sertifikat valid dan lengkap (`K24.01.0001`) | Sertifikat yang tepat ditemukan dan berstatus "VALID & AKTIF" | Berhasil menemukan Budi Santoso, nomor blanko tepat dari DB | **PASS** |
| **TEST 02** | Nomor sertifikat tidak terdaftar (`K24.99.9999`) | Menampilkan kartu "TIDAK DITEMUKAN / TIDAK VALID" | Merender kartu penolakan resmi tanpa membocorkan data | **PASS** |
| **TEST 03** | Nomor parsial (`K24.01`) | Tidak dinyatakan valid | Dinyatakan "TIDAK DITEMUKAN / TIDAK VALID" (LIKE dinonaktifkan) | **PASS** |
| **TEST 04** | Satu karakter umum (`K`) | Ditolak karena tidak memenuhi standar panjang minimal | Ditolak dengan HTTP 400 (minimal 3 karakter) | **PASS** |
| **TEST 05** | Input kosong atau hanya spasi | Ditolak | Ditolak dengan HTTP 400 ("tidak boleh kosong") | **PASS** |
| **TEST 06** | Input melebihi 60 karakter | Ditolak | Ditolak dengan HTTP 400 ("melebihi batas panjang") | **PASS** |
| **TEST 07** | Nonce tidak valid / kedaluwarsa | Ditolak dengan pesan sesi tidak valid | Ditolak dengan HTTP 403 ("Sesi Verifikasi Tidak Valid") | **PASS** |
| **TEST 08** | Permintaan melebihi 20 req/menit | Ditolak sementara dengan HTTP 429 | Request ke-21 terblokir sementara (Batas Permintaan Terlampaui) | **PASS** |
| **TEST 09** | Dua sertifikat dengan nomor identik | Menolak ambiguitas, meminta klarifikasi sekretariat | Merender kartu status "PERLU KLARIFIKASI SEKRETARIAT" | **PASS** |
| **TEST 10** | Kompatibilitas Tema `lspkomunikasi` | Tema tetap terhubung tanpa memodifikasi file tema | Hook shortcode & template loader tetap kompatibel | **PASS** |
| **TEST 11** | Perlindungan data pribadi | Tidak mengekspos data pribadi di luar kebutuhan verifikasi | Tidak ada NIK, telepon, atau email; nomor blanko dari DB | **PASS** |
| **TEST 12** | Integritas runtime PHP & JavaScript | Tidak menimbulkan PHP fatal error, warning, atau JS error | `php -l` lulus tanpa error, JS fetch lulus sintaks | **PASS** |

**Rangkuman Pengujian:** **12 PASS / 0 FAIL / 0 NOT TESTED**

---

## 5. KOMPATIBILITAS TEMA (`lspkomunikasi`)
- Tema `lspkomunikasi` tidak mengalami perubahan kode satu barispun.
- Shortcode `[cek_sertifikat]` mempertahankan struktur container visual yang sama persis: `.lsp-cert-verifier-wrapper`, `#lsp-cert-verify-form`, dan `#lsp-cert-result-area`.
- Seluruh variabel styling CSS (`--primary-color: #72161a`, `--secondary-color: #f89b29`) tetap diaplikasikan secara konsisten.

---

## 6. ANALISIS RISIKO YANG MASIH TERSISA (RESIDUAL RISKS)
1. **Modul Import CSV:** Belum tersentuh pada Tahap 1 (sesuai instruksi pembatasan). File upload CSV, delimiter titik-koma, dan kueri loop masih menunggu pengerjaan Tahap 2.
2. **Mutasi Role Capability:** Pemanggilan `add_cap()` pada hook `init` masih ada dan dijadwalkan dipindahkan pada Tahap 3.
3. **Save Post Handlers:** Handlers penyimpanan skema dan TUK belum dilengkapi `wp_unslash()`, dijadwalkan pada Tahap 4.
4. **Transient pada Environment Tanpa Persistent Cache:** Rate limiting saat ini mengandalkan transient WordPress. Pada server tanpa Redis/Memcached, transient disimpan di tabel `wp_options`. Hal ini bekerja baik untuk traffic moderat, namun jika traffic verifikasi sangat tinggi, penggunaan Redis sangat direkomendasikan.

---

## 7. REKOMENDASI TAHAP BERIKUTNYA (TAHAP 2)
Setelah Tahap 1 ini disetujui, langkah berikutnya adalah **TAHAP 2 (Integritas Data & Penguatan Modul CSV Import)**:
1. Menambahkan validasi upload file CSV (`UPLOAD_ERR_OK`, ekstensi `.csv`, MIME type, ukuran maksimal 5MB).
2. Mendukung deteksi otomatis pemisah koma (`,`) dan titik koma (`;`) untuk kompatibilitas Excel Indonesia.
3. Membersihkan karakter UTF-8 BOM pada baris header CSV.
4. Validasi format tanggal `YYYY-MM-DD` sebelum dimasukkan ke database.
5. Optimasi kueri lookup di dalam loop import menggunakan high-performance `WP_Query` arguments (`no_found_rows => true`, `fields => 'ids'`).
6. Menyajikan ringkasan hasil import (Baru, Diperbarui, Dilewati, Gagal).
