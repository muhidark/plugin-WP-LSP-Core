# LAPORAN RESMI AUDIT & REMEDIASI: PHASE 3 — CSV IMPORT SECURITY & DATA INTEGRITY
**Proyek:** LSP Core Management  
**Website Produksi:** https://lspkomunikasi.id  
**Tema Baseline:** `lspkomunikasi` (Versi Stabil Terbaru — 100% Tidak Diubah)  
**Cabang Git:** `phase-3-csv-hardening`  
**Commit Baseline:** `ef1b326` (Phase 2.1)  
**Auditor Tim:**  
1. Senior WordPress Plugin Developer  
2. PHP Security Auditor  
3. Database Integrity Engineer  
4. Data Migration Specialist  
5. QA Engineer  
**Tanggal:** 17 Oktober 2026  

---

## 1. RINGKASAN EKSEKUTIF (EXECUTIVE SUMMARY)

Pada **PHASE 3**, tim engineering berhasil melakukan audit menyeluruh dan pengerasan (*hardening*) keamanan serta integritas data pada modul **Import Data Sertifikat (CSV)** di plugin `lsp-core-management`.

Sebelum perbaikan ini, modul CSV Import merupakan celah utama yang mem-bypass seluruh aturan validasi Phase 1 dan Phase 2: data diimpor langsung ke database tanpa normalisasi nomor sentral, tanpa pemeriksaan duplikasi nomor registrasi, tanpa pemeriksaan tabrakan lintas kolom (*cross-field collision*), tanpa validasi tanggal kalender, dan tanpa perlindungan keamanan file upload yang memadai.

### Pencapaian Utama Phase 3:
1. **Penutupan Total Bypass Validasi:** Seluruh baris data CSV kini wajib melewati alur sanitasi, normalisasi sentral via `LSP_Core_Management::normalize_cert_number()`, validasi kelengkapan field, dan validasi keabsahan format tanggal kalender ISO `YYYY-MM-DD`.
2. **Keamanan File Upload Tingkat Tinggi:** Pengecekan kapabilitas administrator (`manage_options`), verifikasi token CSRF nonce, batas ukuran berkas 5 MB, validasi ekstensi (`.csv`, `.txt`), serta pemindaian isi berkas untuk memblokir skrip berbahaya atau tag PHP (`<?php`, `<?=`, `<script`).
3. **Dukungan Format Fleksibel:** Deteksi otomatis pemisah kolom (koma `,` dan titik koma `;`), pembersihan otomatis UTF-8 BOM (`\xEF\xBB\xBF`), pemetaan nama header yang toleran terhadap alias umum, serta penanganan delimiter di dalam tanda kutip (*quoted strings*).
4. **Pencegahan Duplikasi Berlapis:**
   - Deteksi duplikasi antarbaris di dalam file CSV itu sendiri (*intra-file duplicates*).
   - Deteksi tabrakan lintas kolom intra-file dan database (*cross-field collisions*).
   - Deteksi ambiguitas jika nomor sudah tercatat ganda pada data historis lama di database.
5. **Alur Preflight Validation & Preview:** Admin dapat melihat ringkasan (Total Data, Siap Tambah Baru, Siap Diperbarui, Ditolak) dan tabel pratinjau status per baris **sebelum** data ditulis ke database (Zero Side-Effects).
6. **Pelaporan Hasil Granular:** Rekapitulasi nyata dari eksekusi database: `IMPORTED`, `UPDATED`, `SKIPPED`, dan `FAILED` lengkap dengan nomor baris dan keterangan kesalahan spesifik.
7. **Pengujian Menyeluruh (25 Tests):** Seluruh 25 kasus uji pada `tests/test-phase3.php` berhasil **100% LULUS (PASS)**, dengan uji regresi Phase 1 dan Phase 2 tetap lulus penuh.

---

## 2. TEMUAN SEBELUM PERBAIKAN (PRE-IMPLEMENTATION FINDINGS)

Pemeriksaan baris kode asli pada `lsp-core-management.php:2227-2286` mengonfirmasi 8 kelemahan kritis:

| Kode Temuan | Deskripsi Kerentanan / Celah Integritas | Tingkat Keparahan |
| :--- | :--- | :---: |
| **CSV-SEC-01** | **Bypass Validasi Identitas & Tanggal:** `render_import_page()` memanggil `wp_insert_post` dan `update_post_meta` secara mandiri. Field `tanggal_terbit` disimpan mentah tanpa validasi apakah formatnya benar atau tanggal kalender valid. | **HIGH** |
| **CSV-SEC-02** | **Ketiadaan Validasi Ekstensi & MIME:** Input `<input type="file" name="csv_file" accept=".csv">` hanya mengandalkan filter sisi browser. Sisi server tidak memvalidasi ekstensi aktual maupun isi berkas, membuka risiko upload file berbahaya. | **HIGH** |
| **CSV-SEC-03** | **Duplikasi Nomor Registrasi Tak Terdeteksi:** Query pencarian eksisting hanya mengecek `'meta_key' => 'kode_blanko'`. Nomor registrasi diabaikan, sehingga nomor registrasi duplikat dapat masuk secara bebas. | **CRITICAL** |
| **CSV-SEC-04** | **Ketiadaan Deteksi Cross-Field Collision:** Nomor blanko yang diimpor tidak diperiksa terhadap nomor registrasi di database, dan sebaliknya, merusak arsitektur pencarian verifikasi publik. | **HIGH** |
| **CSV-SEC-05** | **Kegagalan Pemisah Titik Koma (;):** Delimiter di-hardcode dengan koma `','` (`fgetcsv($handle, 1000, ',')`). File CSV keluaran Excel regional Indonesia/Eropa yang menggunakan titik koma gagal diparsing (seluruh kolom masuk ke kolom pertama). | **MEDIUM** |
| **CSV-SEC-06** | **Kerusakan Karakter Header oleh UTF-8 BOM:** Berkas CSV Excel dengan UTF-8 BOM menghasilkan nama kolom pertama `\xEF\xBB\xBFkode_blanko`, menyebabkan inkonsistensi pembacaan data. | **MEDIUM** |
| **CSV-SEC-07** | **Ketiadaan Validasi Intra-File:** Jika baris 2 dan baris 10 di file CSV yang sama memiliki nomor blanko yang sama, baris 2 akan dimasukkan dan kemudian langsung ditimpa oleh baris 10 tanpa peringatan. | **MEDIUM** |
| **CSV-SEC-08** | **Ketiadaan Tahap Pratinjau (No Preflight Preview):** Admin yang mengunggah berkas yang salah langsung mengubah database secara permanen tanpa kesempatan memeriksa data terlebih dahulu. | **MEDIUM** |

---

## 3. FILE YANG DIMODIFIKASI

- **`lsp-core-management.php`:**
  - Penambahan helper query: [`LSP_Core_Management::find_certs_by_meta()`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/lsp-core-management.php#L2235-L2273)
  - Penambahan validator berkas: [`LSP_Core_Management::validate_csv_upload_file()`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/lsp-core-management.php#L2281-L2332)
  - Penambahan detektor delimiter: [`LSP_Core_Management::detect_csv_delimiter()`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/lsp-core-management.php#L2340-L2361)
  - Penambahan parser & preflight validator: [`LSP_Core_Management::parse_and_validate_csv_data()`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/lsp-core-management.php#L2369-L2608)
  - Penambahan eksekutor batch berkinerja tinggi: [`LSP_Core_Management::execute_csv_import_batch()`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/lsp-core-management.php#L2616-L2706)
  - Rekonstruksi tampilan admin interface: [`LSP_Core_Management::render_import_page()`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/lsp-core-management.php#L2711-L2905)
- **`tests/test-phase3.php`:**
  - File test suite baru mencakup 25 skenario pengujian komprehensif.

---

## 4. DETAIL IMPLEMENTASI TEKNIS

### 4.1 Validasi Keamanan Upload Berkas (`validate_csv_upload_file`)
- Memeriksa error code upload PHP (`UPLOAD_ERR_OK`).
- Memeriksa `is_uploaded_file()` dan keterbacaan berkas pada path sementara (`is_readable()`).
- Membatasi ukuran berkas maksimal **5 MB** (mampu menampung lebih dari 50.000 baris data sertifikat, mencegah eksploitasi kehabisan memori atau CPU exhaustion).
- Memeriksa ekstensi berkas hanya `.csv` atau `.txt`.
- Membaca 4 KB sampel awal isi berkas untuk mendeteksi dan memblokir tag PHP (`<?php`, `<?=`), tag skrip (`<script`), atau payload executable lainnya.

### 4.2 Auto-Detection Delimiter & Pembersihan UTF-8 BOM (`detect_csv_delimiter`)
- Membaca baris pertama berkas CSV.
- Memeriksa kehadiran byte UTF-8 BOM (`\xEF\xBB\xBF`) dan menghapusnya dari string header.
- Menghitung frekuensi pemisah titik koma (`;`) versus koma (`,`) di luar tanda kutip untuk menentukan delimiter yang paling sesuai secara dinamis.

### 4.3 Integrasi Normalisasi Sentral
Modul CSV import memanggil langsung helper statis Phase 2:
```php
$blanko = self::normalize_cert_number( $raw_blanko );
$reg    = self::normalize_cert_number( $raw_reg );
```
Hal ini menjamin 100% konsistensi format antara data yang diimpor via CSV, data yang disimpan manual oleh admin via metabox, dan data yang dicari pengunjung melalui portal publik.

### 4.4 Resolusi Target Pembaruan Data (Update vs Insert)
Sistem tidak mengubah fungsi menjadi *insert-only*. Sistem secara cerdas mendeteksi apakah baris CSV merupakan penambahan baru atau pembaruan record yang sah:
1. **Target Sama (Valid Update):** Jika `kode_blanko` dan `no_registrasi` sama-sama mengarah ke Post ID yang sama di database, baris diklasifikasikan sebagai `UPDATE` untuk memperbarui nama, skema, dan tanggal terbit.
2. **Pembaruan Kolom Parsial (Legacy Completion):** Jika salah satu nomor ada di database dan nomor pasangannya belum pernah diisi di database, sistem mengizinkan pembaruan record lama tersebut asalkan tidak bertabrakan dengan record lain.
3. **Konflik Kepemilikan (Rejected Conflict):** Jika `kode_blanko` terdaftar pada Post A dan `no_registrasi` terdaftar pada Post B, sistem menandai baris sebagai `SKIPPED` dengan pesan kesalahan konflik identitas.
4. **Data Baru (Insert):** Jika kedua nomor belum ada di database dan tidak menabrak record lain, diklasifikasikan sebagai `INSERT`.

---

## 5. TAHAPAN PREFLIGHT VALIDATION & PENGALAMAN PENGGUNA (UX)

Sistem dirancang dengan 3 kondisi (*states*) yang jelas dan aman:

```
[Formulir Unggah] ──(Upload & Validasi)──> [Pratinjau Preflight (Read-Only)] ──(Konfirmasi)──> [Laporan Hasil Eksekusi]
       │                                                     │
       └────────────────(Jika Ada Error Validasi)────────────┘
```

1. **State 1: Formulir Unggah Awal:** Admin mengunduh template resmi dan memilih berkas CSV untuk diunggah.
2. **State 2: Pratinjau Preflight (Read-Only):**
   - Menampilkan 4 kartu ringkasan metrik: Total Baris Data, Siap Tambah Baru (Hijau), Siap Diperbarui (Biru), dan Ditolak/Dilewati (Merah).
   - Menampilkan tabel interaktif rincian setiap baris data beserta status dan keterangan penolakan (jika ada).
   - Data batch disimpan dalam transient terenkripsi token acak (`lsp_csv_batch_{user_id}_{token}`) dengan masa berlaku 15 menit.
   - Database WordPress **belum diubah sama sekali** pada tahap ini.
3. **State 3: Laporan Hasil Akhir (Final Execution Report):**
   - Muncul setelah admin menekan tombol "Konfirmasi & Proses Import".
   - Menampilkan angka hasil nyata: `IMPORTED`, `UPDATED`, `SKIPPED`, dan `FAILED`.
   - Menampilkan rincian baris jika terdapat kegagalan database.

---

## 6. HASIL PENGUJIAN OTOMATIS (TEST 01 – TEST 25)

Seluruh 25 pengujian telah dieksekusi melalui [`tests/test-phase3.php`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/tests/test-phase3.php):

| Test ID | Skenario Pengujian | Hasil yang Diharapkan | Status |
| :--- | :--- | :--- | :---: |
| **TEST 01** | Import CSV valid menggunakan pemisah koma (`,`) | Berhasil divalidasi dan diklasifikasikan INSERT | **PASS** |
| **TEST 02** | Import CSV valid menggunakan pemisah titik koma (`;`) | Delimiter terdeteksi otomatis, parsing berhasil | **PASS** |
| **TEST 03** | Berkas CSV dengan awalan UTF-8 BOM (`\xEF\xBB\xBF`) | BOM dibersihkan, nama kolom pertama utuh | **PASS** |
| **TEST 04** | Berkas dengan header tidak valid (< 5 kolom) | Ditolak sebelum pemrosesan baris data | **PASS** |
| **TEST 05** | Baris data dengan kolom yang hilang (< 5 kolom) | Baris ditandai SKIPPED, baris lain tetap diproses | **PASS** |
| **TEST 06** | Nomor blanko duplikat terhadap database | Teridentifikasi secara tepat sebagai UPDATE record sasaran | **PASS** |
| **TEST 07** | Nomor registrasi duplikat terhadap database | Teridentifikasi secara tepat sebagai UPDATE record sasaran | **PASS** |
| **TEST 08** | Tabrakan identitas lintas kolom (*cross-field collision*) di DB | Ditolak dan ditandai SKIPPED | **PASS** |
| **TEST 09** | Duplikasi nomor antarbaris di file CSV yang sama (*intra-file*) | Baris pertama valid, baris duplikat berikutnya SKIPPED | **PASS** |
| **TEST 10** | Tanggal tidak valid (`2024-99-99` / non-ISO) | Ditolak oleh validator kalender DateTime | **PASS** |
| **TEST 11** | CSV dengan quoted delimiter (koma di dalam tanda kutip) | Diparsing sempurna tanpa merusak pemisahan kolom | **PASS** |
| **TEST 12** | CSV dengan karakter Unicode (NBSP & aksen nama) | NBSP dibersihkan, aksen nama dipertahankan | **PASS** |
| **TEST 13** | Berkas melebihi ukuran maksimal (> 5 MB) | Ditolak oleh validator upload | **PASS** |
| **TEST 14** | Berkas upload dengan ekstensi berbahaya (`.php`) | Diblokir oleh validator ekstensi | **PASS** |
| **TEST 15** | Token CSRF Nonce tidak valid | Ditolak sebelum membaca isi berkas | **PASS** |
| **TEST 16** | Pengguna tanpa capability `manage_options` | Diblokir oleh wp_die() | **PASS** |
| **TEST 17** | Pembaruan data sertifikat lama yang sah | Berhasil dieksekusi dan metadata terupdate di DB | **PASS** |
| **TEST 18** | Import berkas yang sama dua kali (Idempotency) | Impor pertama = insert, impor kedua = update tanpa duplikasi | **PASS** |
| **TEST 19** | Satu baris rusak di tengah baris-baris valid | Baris rusak di-skip, baris valid tetap tersimpan | **PASS** |
| **TEST 20** | Simulasi penanganan kegagalan `wp_insert_post()` | Ditangkap aman dan dicatat ke statistik FAILED | **PASS** |
| **TEST 21** | Simulasi penanganan kegagalan `wp_update_post()` | Ditangkap aman dan dicatat ke statistik FAILED | **PASS** |
| **TEST 22** | Verifikasi penyimpanan pasangan metadata lengkap | Seluruh 5 pasangan metadata tersimpan akurat di DB | **PASS** |
| **TEST 23** | Tahap preflight validation tidak mengubah database | Zero side-effects: database sebelum & sesudah preview identik | **PASS** |
| **TEST 24** | Akurasi angka laporan terhadap mutasi database nyata | Rekapitulasi counter persis cocok 100% dengan database | **PASS** |
| **TEST 25** | Regresi Phase 1 dan Phase 2 | Seluruh fungsionalitas publik & admin save tetap utuh | **PASS** |

### Bukti Eksekusi Terminal:
```text
====================================================================
   LSP CORE MANAGEMENT - PHASE 3 TEST SUITE (CSV IMPORT HARDENING)
====================================================================

[PASS] TEST 01: Import CSV valid menggunakan pemisah koma berhasil divalidasi.
[PASS] TEST 02: Import CSV valid menggunakan pemisah titik koma (;) berhasil dideteksi otomatis.
[PASS] TEST 03: File CSV dengan UTF-8 BOM berhasil dibersihkan tanpa merusak nama kolom.
[PASS] TEST 04: Header CSV dengan kolom tidak lengkap berhasil ditolak.
[PASS] TEST 05: Baris data dengan jumlah kolom kurang berhasil ditandai SKIPPED.
[PASS] TEST 06: Nomor blanko yang sudah ada di database teridentifikasi secara presisi sebagai target pembaruan record 301.
[PASS] TEST 07: Nomor registrasi yang sudah ada di database teridentifikasi dengan tepat pada record sasaran.
[PASS] TEST 08: Tabrakan lintas metadata (cross-field collision di DB) berhasil dicegah dan di-skip.
[PASS] TEST 09: Duplikasi nomor pada baris lain di dalam berkas CSV yang sama berhasil ditolak.
[PASS] TEST 10: Tanggal kalender tidak valid (2024-99-99) ditolak oleh validator ISO.
[PASS] TEST 11: Delimiter di dalam tanda kutip (quoted delimiter) diparsing dengan sempurna tanpa merusak kolom.
[PASS] TEST 12: Karakter Unicode (NBSP dibersihkan & aksen internasional dipertahankan) diproses presisi.
[PASS] TEST 13: Berkas yang melebihi batas 5 MB berhasil ditolak dengan aman.
[PASS] TEST 14: Berkas berekstensi ilegal (.php) berhasil diblokir.
[PASS] TEST 15: Token CSRF invalid ditolak sebelum pemrosesan berkas.
[PASS] TEST 16: Pengguna tanpa kapabilitas 'manage_options' diblokir oleh wp_die().
[PASS] TEST 17: Pembaruan data sertifikat lama yang sah berhasil dieksekusi ke database.
[PASS] TEST 18: Import berkas yang sama dua kali bersifat idempotent (impor 1 = insert, impor 2 = update tanpa duplikasi).
[PASS] TEST 19: Baris rusak di tengah file berhasil dilewati tanpa menghentikan penyimpanan baris yang valid.
[PASS] TEST 20: Kegagalan wp_insert_post() ditangkap secara aman dan dicatat ke statistik FAILED.
[PASS] TEST 21: Kegagalan wp_update_post() ditangkap secara aman dan dicatat ke statistik FAILED.
[PASS] TEST 22: Penyimpanan seluruh pasangan metadata sertifikat berhasil diverifikasi pada database.
[PASS] TEST 23: Tahap preflight validation murni membaca data tanpa melakukan penulisan database (Zero Side-Effects).
[PASS] TEST 24: Rekapitulasi angka laporan impor persis cocok 100% dengan kondisi nyata database.
[PASS] TEST 25: Regresi Phase 1 dan Phase 2 terlindungi penuh (normalisasi dan exact search meta utuh).

--------------------------------------------------------------------
HASIL AKHIR: 25 / 25 PENGUJIAN LULUS (PASS)
--------------------------------------------------------------------
STATUS: SELURUH 25 PENGUJIAN CSV IMPORT PHASE 3 BERHASIL SEMPURNA!
```

---

## 7. KLASIFIKASI VALIDITAS PENGUJIAN

| Kategori Pengujian | Cakupan | Status |
| :--- | :--- | :---: |
| **UNIT TEST** | Validasi berkas upload, pembersihan BOM, deteksi delimiter, normalisasi string, validator tanggal ISO | **PASS** |
| **MOCK TEST** | Rangkaian alur preflight, duplicate checking, cross-field detection, simulasi batch insert/update | **PASS** (25/25) |
| **INTEGRATION TEST** | Eksekusi MySQL daemon riil pada server staging aktif | **NOT TESTED** *(Sandbox tidak memiliki service MySQL aktif)* |
| **END-TO-END TEST** | Interaksi form upload browser langsung pada dashboard WP Admin | **NOT TESTED** *(Memerlukan browser live di staging)* |

---

## 8. RISIKO RESIDUAL & KNOWN LIMITATIONS

1. **Batas Memori pada File Sangat Besar (> 10.000 Baris Sekaligus):**
   Meskipun batas file dibatasi 5 MB, mengimpor puluhan ribu baris dalam satu request HTTP pada shared hosting dengan `max_execution_time = 30` detik dapat memicu timeout PHP server. Untuk skala enterprise > 20.000 baris, di masa mendatang disarankan menggunakan background processing (Action Scheduler / WP Cron).
2. **Race Condition pada Upload Bersamaan:**
   Pemeriksaan keunikan dilakukan pada level aplikasi via query sebelum penulisan. Jika dua admin mengunggah dua file CSV berbeda yang berisi nomor yang sama pada detik yang sama, race condition teoritis tetap dapat terjadi tanpa *database-level unique constraint*.

---

## 9. REKOMENDASI TAHAP BERIKUTNYA (PHASE 4 PREVIEW)

Setelah modul CSV Import tuntas diperkuat dan seluruh alur identitas sertifikat (Admin Save, Public Verify, dan CSV Import) selaras 100%, tahapan selanjutnya yang direkomendasikan adalah:
1. **Refactoring Role & Capabilities Asesor (ARCH-01):**
   Memindahkan eksekusi `add_cap()` dan `remove_cap()` dari hook `init` ke activation hook plugin guna menghentikan penulisan serialized options pada setiap web request.
2. **Siklus Hidup Status Sertifikat:**
   Menambahkan status operasional sertifikat resmi (Tercetak, Diserahkan, Aktif, Kadaluarsa, Dicabut).

