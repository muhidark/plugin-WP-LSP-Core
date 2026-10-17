# LAPORAN RESMI AUDIT & REMEDIASI: PHASE 2 — CERTIFICATE DATA INTEGRITY
**Proyek:** LSP Core Management  
**Website Produksi:** https://lspkomunikasi.id  
**Tema Baseline:** `lspkomunikasi` (Versi Stabil Terbaru)  
**Cabang Git:** `phase-2-data-integrity`  
**Commit Baseline:** `720b2bb` (Remediasi Tahap 1)  
**Auditor:** Senior WordPress Plugin Developer, Database & Security Auditor, QA Engineer  
**Tanggal:** 17 Oktober 2026  

---

## 1. EXECUTIVE SUMMARY

Pada **PHASE 2 (Certificate Data Integrity)**, tim engineering berfokus penuh untuk menutup celah integritas data sertifikat (**DATA-01**), mencegah terjadinya duplikasi nomor sertifikat baik intra-kolom maupun lintas-kolom (*cross-field collision*), memperketat mekanisme penyimpanan di admin WordPress (`save_post`), membangun fungsi normalisasi nomor sertifikat terpusat (`normalize_cert_number`), serta memastikan seluruh data historis dan backward compatibility dengan tema `lspkomunikasi` tetap 100% terjaga tanpa distorsi.

### Ringkasan Pencapaian Phase 2:
1. **Pencegahan Duplikasi Identitas (DATA-01 Resolved):** Implementasi pengecekan keunikan exact-match untuk `kode_blanko` dan `no_registrasi` dengan proteksi terhadap record diri sendiri (`post__not_in => array($post_id)`).
2. **Pencegahan Tabrakan Lintas Kolom (Cross-Field Collision):** Menjamin nilai `kode_blanko` suatu sertifikat tidak boleh sama dengan `no_registrasi` sertifikat lain, dan sebaliknya.
3. **Admin Save Hardening (`save_cert_meta`):** Dilengkapi validasi token CSRF nonce (`lsp_cert_meta_nonce_action`), pemeriksaan hak akses pengguna (`edit_post`), bypass otomatis pada autosave/revision, pembersihan input (`wp_unslash`), pembatasan panjang karakter (3–60 karakter), validasi tanggal kalender format ISO `YYYY-MM-DD` via `DateTime::createFromFormat`, serta penyimpanan aman jika terjadi kegagalan validasi.
4. **Preservasi Data Tanpa Overwrite:** Jika admin memasukkan data yang bentrok atau tidak valid, sistem **tidak menimpa**, **tidak menghapus**, dan **tidak mengosongkan** data lama di database. Error flash disimpan dalam transient pengguna selama 45 detik dan ditampilkan via `admin_notices`.
5. **Normalisasi Terpusat (`normalize_cert_number`):** Fungsi helper statis baru yang menghapus karakter kontrol tersembunyi (`[\x00-\x1F\x7F\xC2\xA0]`) dan merapikan spasi ganda, namun mempertahankan karakter pemisah resmi (`.`, `/`, `-`). Fungsi ini dipakai bersama oleh Admin Save dan Endpoint Verifikasi Publik (`ajax_verify_cert`).
6. **Verifikasi Pengujian Lengkap:** Seluruh 15 skenario pengujian otomatis (`TEST 01` s/d `TEST 15`) telah dieksekusi melalui `tests/test-phase2.php` dengan hasil **15 PASS / 15 TOTAL (100% LULUS)**.
7. **Zero Regression:** Pengujian Phase 1 (`tests/test-phase1.php`) diuji ulang dan tetap lulus **12 PASS / 12 TOTAL**. Tidak ada perubahan pada tema `lspkomunikasi` maupun modul CSV Import.

---

## 2. AUDIT TEMUAN DATA-01

### 2.1 Deskripsi Masalah
Pada kode baseline awal `lsp-core-management.php:save_cert_meta()`, penyimpanan metadata sertifikat dilakukan secara langsung tanpa ada validasi apakah nomor identitas (`kode_blanko` atau `no_registrasi`) sudah terdaftar pada sertifikat lain.

```php
// KODE LAMA SEBELUM PHASE 2 (lsp-core-management.php:826)
if ( isset( $_POST['kode_blanko'] ) ) {
    update_post_meta( $post_id, 'kode_blanko', sanitize_text_field( $_POST['kode_blanko'] ) );
}
if ( isset( $_POST['no_registrasi'] ) ) {
    update_post_meta( $post_id, 'no_registrasi', sanitize_text_field( $_POST['no_registrasi'] ) );
}
```

### 2.2 Akar Penyebab (Root Cause)
1. **Ketiadaan Validasi Keunikan:** Tidak ada query pengecekan ke database sebelum menjalankan `update_post_meta`.
2. **Ketiadaan Proteksi Cross-Field Collision:** Sistem tidak memeriksa apakah nilai yang dimasukkan sebagai kode blanko fisik ternyata identik dengan nomor registrasi BNSP dari sertifikat lain.
3. **Ketiadaan Validasi Format Tanggal:** Input `tanggal_terbit` disimpan apa adanya hanya dengan `sanitize_text_field`, tanpa validasi apakah formatnya benar `YYYY-MM-DD` atau tanggal kalender valid.
4. **Ketiadaan Feedback Error:** Admin tidak menerima umpan balik apabila terjadi kesalahan input atau kegagalan simpan.

### 2.3 Bukti Dampak Bisnis & Operasional
1. **Ambiguitas Verifikasi Publik:** Jika dua asesi berbeda memiliki kode blanko atau nomor registrasi yang sama akibat kelalaian input admin, endpoint verifikasi publik akan mengembalikan peringatan "Perlu Klarifikasi Sekretariat" dan sertifikat sah tidak dapat diverifikasi secara instan oleh pihak industri/pemberi kerja.
2. **Inkonsistensi Sertifikasi Profesi BNSP:** Nomor blanko sertifikat adalah dokumen berharga negara yang dicetak oleh Badan Nasional Sertifikasi Profesi (BNSP) dengan pengaman khusus (*watermark/hologram*). Duplikasi nomor blanko fisik merupakan pelanggaran kepatuhan operasional LSP.

---

## 3. ATURAN IDENTITAS SERTIFIKAT (BUSINESS RULES)

Berdasarkan audit regulasi BNSP dan sistem operasional LSP Komunikasi Indonesia, aturan identitas sertifikat ditetapkan sebagai berikut:

| Atribut | Sifat | Batasan Panjang | Karakter Diizinkan | Aturan Keunikan |
| :--- | :--- | :--- | :--- | :--- |
| **`kode_blanko`** | Wajib | 3 – 60 Karakter | Alfanumerik, titik, strip, spasi (misal: `K24.01.0001`) | **Unik global** pada CPT `data_sertifikat`. Tidak boleh dipakai oleh sertifikat lain. |
| **`no_registrasi`** | Wajib | 3 – 60 Karakter | Alfanumerik, titik, strip, spasi, tahun (misal: `KOM.010.00001 2024`) | **Unik global** pada CPT `data_sertifikat`. Tidak boleh dipakai oleh sertifikat lain. |
| **`nama_pemegang`** | Wajib | 3 – 150 Karakter | Teks nama lengkap beserta gelar asesi | Tidak harus unik (bisa ada kesamaan nama asesi). |
| **`skema_sertifikasi`** | Wajib | 3 – 255 Karakter | Judul Skema Sertifikasi | Mengacu pada skema yang disahkan BNSP. |
| **`tanggal_terbit`** | Wajib | Tepat 10 Karakter | Format ISO `YYYY-MM-DD` | Harus tanggal kalender yang valid (contoh: `2024-05-15`). |

### Aturan Lintas Kolom (Cross-Field Collision):
- Nilai `kode_blanko` dari Post A **TIDAK BOLEH** sama dengan `no_registrasi` dari Post B.
- Nilai `no_registrasi` dari Post A **TIDAK BOLEH** sama dengan `kode_blanko` dari Post B.
- Aturan ini mencegah kebingungan saat publik melakukan pengecekan di `/cek-sertifikat/`, karena form pencarian publik menerima salah satu dari kedua nomor tersebut dalam satu field input.

### Penanganan Duplikasi Historis:
- **Aturan Non-Destruktif:** Sistem tidak melakukan penghapusan atau perubahan massal pada database operasional yang sudah ada.
- Jika terdapat duplikasi pada data historis lama, sistem tetap membiarkan data tersebut utuh di database. Pada saat verifikasi publik, sistem Phase 1 secara aman menampilkan status "Perlu Klarifikasi Sekretariat" tanpa merusak integritas pangkalan data.
- Pada saat admin mengedit sertifikat tertentu, validasi Phase 2 akan memandu admin untuk mengoreksi nomor yang bermasalah.

---

## 4. PERUBAHAN IMPLEMENTASI

### 4.1 File yang Dimodifikasi
- `lsp-core-management.php`

### 4.2 Detail Perubahan Kode

#### A. Penambahan Helper Statis: `normalize_cert_number( $number )`
```php
public static function normalize_cert_number( $number ) {
    if ( ! is_string( $number ) && ! is_numeric( $number ) ) {
        return '';
    }
    // 1. Unslash dan hilangkan karakter kontrol serta spasi tak kasat mata
    $str = wp_unslash( (string) $number );
    $str = preg_replace( '/[\x00-\x1F\x7F\xC2\xA0]/u', ' ', $str );
    $str = sanitize_text_field( $str );
    // 2. Satukan spasi berulang menjadi spasi tunggal dan trim
    return trim( preg_replace( '/\s+/', ' ', $str ) );
}
```
*Manfaat:* Menghilangkan risiko kegagalan pencocokan akibat perbedaan spasi di awal/akhir, tabulasi, atau karakter non-breaking space (`\xC2\xA0`) hasil copy-paste dari berkas spreadsheet/PDF.

#### B. Penguatan Metode `save_cert_meta( $post_id )`
Metode `save_cert_meta` ditulis ulang secara menyeluruh dengan alur pengamanan 9 tahap:
1. **Verifikasi Nonce:** Memeriksa `$_POST['lsp_cert_meta_nonce']` terhadap action `'lsp_cert_meta_nonce_action'`.
2. **Proteksi Autosave & Revisi:** Mengecek `DOING_AUTOSAVE`, `wp_is_post_autosave( $post_id )`, dan `wp_is_post_revision( $post_id )`.
3. **Verifikasi Post Type:** Memastikan tipe post adalah `'data_sertifikat'`.
4. **Verifikasi Otorisasi:** Memastikan pengguna saat ini memiliki capability `current_user_can( 'edit_post', $post_id )`.
5. **Sanitasi & Normalisasi Input:** Menerapkan `normalize_cert_number()` pada `kode_blanko` dan `no_registrasi`.
6. **Validasi Field Wajib & Batasan Karakter:** Memeriksa kehadiran nilai dan panjang string (3–60 karakter).
7. **Validasi Format Tanggal ISO:** Menggunakan `DateTime::createFromFormat( 'Y-m-d', $tanggal_terbit )` untuk memastikan tanggal eksis secara kalender (mencegah nilai absurd seperti `2024-99-99` atau `2024-02-30`).
8. **Deteksi Duplikasi Cerdas (Excluding Current Post):**
   Menggunakan `WP_Query` dengan performa tinggi (`no_found_rows => true`, `fields => 'ids'`, `post__not_in => array($post_id)`):
   - Pengecekan keunikan `kode_blanko`.
   - Pengecekan keunikan `no_registrasi`.
   - Pengecekan tabrakan lintas kolom (*cross-field collision*).
9. **Penanganan Kesalahan Non-Destruktif:**
   Jika ditemukan error validasi atau duplikasi:
   - Metadata lama di database **tidak dimodifikasi**.
   - Judul post tidak diubah.
   - Daftar pesan error disimpan di transient `lsp_cert_save_errors_{user_id}` selama 45 detik.
   - Eksekusi keluar secara aman tanpa melempar fatal error atau infinite loop.

#### C. Hook Notifikasi Admin: `display_cert_admin_notices()`
Ditautkan pada hook WordPress `admin_notices` untuk merender kotak peringatan merah standar WordPress yang mencantumkan rincian field yang bermasalah beserta ID Post sertifikat lain yang berkonflik.

#### D. Penyelarasan Endpoint Publik: `ajax_verify_cert()`
Memperbarui pemrosesan input publik agar menggunakan fungsi sentral `self::normalize_cert_number( $raw_input )`.

---

## 5. INTEGRATION ANALYSIS

### 5.1 Verifikasi Publik (`[cek_sertifikat]`)
- Endpoint `ajax_verify_cert` kini menggunakan algoritma normalisasi yang sama persis dengan backend admin.
- Format spasi yang dinormalisasi pada saat admin menyimpan sertifikat akan cocok 100% dengan input pencarian pengunjung di frontend website, meskipun pengunjung memasukkan spasi di awal atau akhir nomor.

### 5.2 CSV Import (Status & Roadmap Phase Berikutnya)
- **Status Saat Ini:** Sesuai arahan batasan kerja, modul `csv-import-export-cert.php` **tidak diubah** pada Phase 2.
- **Kesiapan Integrasi:** Fungsi `LSP_Core_Management::normalize_cert_number()` telah dirancang sebagai `public static`, sehingga modul CSV import dapat langsung memanggil fungsi ini pada Phase mendatang tanpa perlu refactoring ulang.

### 5.3 Theme Integration (`lspkomunikasi`)
- Struktur metadata WordPress tetap identik:
  - Meta key: `kode_blanko`
  - Meta key: `no_registrasi`
  - Meta key: `nama_pemegang`
  - Meta key: `skema_sertifikasi`
  - Meta key: `tanggal_terbit`
- CPT name tetap: `data_sertifikat`
- Theme `lspkomunikasi` terbukti 100% kompatibel dan tidak mengalami perubahan baris kode apa pun.

---

## 6. HASIL PENGUJIAN OTOMATIS (TEST 01 – TEST 15)

Pengujian dieksekusi secara independen menggunakan test harness `tests/test-phase2.php` dengan hasil sebagai berikut:

| Test ID | Skenario Pengujian | Hasil Diharapkan | Status |
| :--- | :--- | :--- | :---: |
| **TEST 01** | Tambah sertifikat baru dengan nomor unik | Berhasil disimpan, judul dan metadata terupdate | **PASS** |
| **TEST 02** | Edit sertifikat lama tanpa ubah nomor | Berhasil disimpan (post__not_in bekerja, bukan self-duplicate) | **PASS** |
| **TEST 03** | Tambah / edit dengan `kode_blanko` sama dengan sertifikat lain | Ditolak, error notice tersimpan, data lama sertifikat tetap utuh | **PASS** |
| **TEST 04** | Tambah / edit dengan `no_registrasi` sama dengan sertifikat lain | Ditolak, error notice tersimpan, data lama sertifikat tetap utuh | **PASS** |
| **TEST 05** | Tabrakan nomor sertifikat lintas kolom (*cross-field collision*) | Ditolak pada kedua arah tabrakan, data lama tetap terlindungi | **PASS** |
| **TEST 06** | Input kosong pada seluruh field wajib | Ditolak dengan 5 pesan validasi lengkap, data lama utuh | **PASS** |
| **TEST 07** | Normalisasi input dengan spasi liar & karakter kontrol tersembunyi | Bersih dari spasi ganda & invisible char tanpa merusak tanda baca | **PASS** |
| **TEST 08** | Input tanggal tidak valid (misal `2024-99-99`) | Ditolak oleh validator kalender, data tanggal lama dipertahankan | **PASS** |
| **TEST 09** | Hak akses: User tanpa kemampuan `edit_post` | Ditolak / diabaikan, metadata database tidak dapat disentuh | **PASS** |
| **TEST 10** | Token CSRF Nonce salah atau tidak dikirim | Ditolak sebelum proses validasi atau penyimpanan | **PASS** |
| **TEST 11** | Proteksi Autosave dan Revision WordPress | Bypassed secara aman tanpa mengubah metadata live post | **PASS** |
| **TEST 12** | Verifikasi publik `ajax_verify_cert` setelah sertifikat diedit | Berhasil diverifikasi dan mengembalikan status Valid & Aktif | **PASS** |
| **TEST 13** | Keterbacaan data historis sertifikat lama | Terbaca sempurna via exact match kode_blanko maupun no_registrasi | **PASS** |
| **TEST 14** | Pemeriksaan runtime PHP Warning / Notice / Fatal Error | Zero errors (0 PHP Notice, 0 Warning, 0 Fatal Error) | **PASS** |
| **TEST 15** | Kompatibilitas theme `lspkomunikasi` | 100% Utuh (CPT, shortcodes, dan meta keys tidak bergeser) | **PASS** |

### Bukti Eksekusi Terminal:
```text
====================================================================
   LSP CORE MANAGEMENT - PHASE 2 TEST SUITE (DATA INTEGRITY)
====================================================================

[PASS] TEST 01: Tambah sertifikat baru dengan nomor unik berhasil disimpan.
[PASS] TEST 02: Edit sertifikat lama tanpa ubah nomor berhasil (post__not_in bekerja, bukan self-duplicate).
[PASS] TEST 03: Duplikat kode_blanko berhasil ditolak, data lama sertifikat tetap utuh.
[PASS] TEST 04: Duplikat no_registrasi berhasil ditolak, data lama sertifikat tetap utuh.
[PASS] TEST 05: Tabrakan nomor sertifikat lintas kolom (cross-field collision) berhasil dicegah.
[PASS] TEST 06: Input kosong pada seluruh field wajib berhasil ditolak dengan notice validasi.
[PASS] TEST 07: Normalisasi nomor sertifikat membersihkan spasi liar dan kontrol karakter tanpa merusak format.
[PASS] TEST 08: Tanggal tidak valid (2024-99-99) berhasil ditolak, data lama dipertahankan.
[PASS] TEST 09: User tanpa kemampuan 'edit_post' ditolak, metadata tidak berubah.
[PASS] TEST 10: Nonce CSRF invalid ditolak, metadata terlindungi.
[PASS] TEST 11: Autosave dan Revision dilewati dengan aman tanpa mengubah metadata.
[PASS] TEST 12: Verifikasi publik via ajax_verify_cert membaca data hasil normalisasi secara akurat.
[PASS] TEST 13: Data historis sertifikat tetap terbaca sempurna via exact match kode_blanko maupun no_registrasi.
[PASS] TEST 14: Pengujian berjalan mulus tanpa PHP Notice, Warning, atau Fatal Error.
[PASS] TEST 15: Kompatibilitas theme lspkomunikasi 100% utuh (struktur CPT, meta keys, dan shortcode tidak berubah).

--------------------------------------------------------------------
HASIL AKHIR: 15 / 15 PENGUJIAN LULUS (PASS)
--------------------------------------------------------------------
STATUS: SELURUH PENGUJIAN INTEGRITAS DATA PHASE 2 BERHASIL SEMPURNA!
```

---

## 7. REKOMENDASI TAHAP BERIKUTNYA (PHASE 3 PREVIEW)

Setelah keberhasilan stabilisasi Phase 2, tahapan berikutnya yang direkomendasikan adalah:
1. **Phase 3: Refactoring Role & Capabilities (ARCH-01):**
   - Memindahkan pemanggilan `add_cap()` dan `remove_cap()` dari hook `init` (yang dieksekusi pada setiap request) ke *plugin activation hook* (`register_activation_hook`), guna menghentikan penulisan serialized data `update_option()` pada tabel `wp_options`.
2. **Phase 4: CSV Import Hardening:**
   - Menghubungkan fungsi `normalize_cert_number()` ke parser CSV.
   - Menambahkan deteksi duplikasi pra-impor (*pre-flight validation*) sehingga baris CSV yang memiliki duplikasi nomor di database atau di dalam file CSV itu sendiri dilaporkan ke admin sebelum dimasukkan ke database.
3. **Phase 5: Status Siklus Hidup Sertifikat:**
   - Menyediakan status operasional resmi sertifikat (Tercetak, Diserahkan, Aktif, Kadaluarsa, Dicabut/Suspended).
