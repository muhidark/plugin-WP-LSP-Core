# AUDIT LAPORAN 06: REMEDIATION PLAN & ACTION ROADMAP
**Plugin:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Target:** https://lspkomunikasi.id  
**Auditor:** Tim Senior Software Engineering  
**Tanggal:** 17 Oktober 2026  

---

## 1. MATRIKS STATISTIK TEMUAN AUDIT

Berdasarkan audit mendalam baris demi baris pada kode aktual plugin dan tema, berikut ringkasan kuantitatif temuan:

| Klasifikasi Severity | Jumlah Temuan | Kode Temuan |
|---|---|---|
| **CRITICAL** | **1** | `SEC-02` |
| **HIGH** | **4** | `SEC-01`, `SEC-03`, `SEC-04`, `ARCH-01` |
| **MEDIUM** | **6** | `BUG-01`, `PERF-01`, `PERF-02`, `PERF-04`, `SEC-05`, `FUNC-01` |
| **LOW** | **4** | `PERF-03`, `PERF-05`, `QUAL-01`, `ARCH-02` |
| **TOTAL TEMUAN** | **15** | — |

---

## 2. TOP 10 RISIKO KEAMANAN & INTEGRITAS DATA (SECURITY RISKS)

1. **`SEC-02` (CRITICAL) — Fallback Pencarian `LIKE` Menghasilkan Verifikasi Palsu & Kebocoran Data**  
   Penggunaan operator `LIKE` (`%input%`) pada saat input tidak cocok persis menyebabkan input satu huruf/angka sembarang (misal: "1", "0", "K") mengembalikan sertifikat asesi lain sebagai "VALID & AKTIF". Ini merusak fungsi utama keabsahan sertifikat BNSP.
2. **`SEC-01` (HIGH) — Ketiadaan Verifikasi Nonce pada AJAX Verifikasi Publik**  
   Server tidak memverifikasi token nonce pada `ajax_verify_cert()`, memungkinkan cross-site form execution atau automated scraping tanpa validasi request origin.
3. **`SEC-03` (HIGH) — Ketiadaan Rate Limiting & Input Length Constraint**  
   Endpoint publik dapat dibanjiri ratusan request per detik untuk enumerasi nomor sertifikat atau membebani database (Denial of Service).
4. **`SEC-04` (HIGH) — Insecure File Upload pada Modul Import CSV**  
   File upload diproses tanpa validasi status upload error, ekstensi MIME type yang ketat, dan batas ukuran file di sisi server.
5. **`BUG-01` (MEDIUM/HIGH) — Kerapuhan Parser CSV (Delimiter, BOM, Header, Format Tanggal)**  
   Hardcoded pemisah koma mengakibatkan silent failure pada Excel Indonesia yang memakai titik-koma (`;`), header tidak diverifikasi urutannya, dan format tanggal tidak divalidasi `YYYY-MM-DD`.
6. **`ARCH-01` (HIGH) — Mutasi Opsi Database Role Capabilities pada Setiap Request `init`**  
   Pemanggilan `add_cap` dan `remove_cap` pada hook `init` memicu operasi `update_option('wp_user_roles')` pada setiap page request seluruh pengunjung website.
7. **`SEC-05` (MEDIUM) — Kerusakan Karakter Teks Akibat Ketiadaan `wp_unslash()`**  
   Tanda petik nama asesi, judul unit, atau alamat tersimpan dengan akumulasi garis miring ganda (`\'` -> `\\\'`) setiap kali disimpan ulang di admin.
8. **`FUNC-01` (MEDIUM) — Status Sertifikat Tidak Dapat Dicabut (Revoked/Suspended)**  
   Perhitungan masa berlaku sertifikat di-hardcode otomatis 3 tahun tanpa dukungan status administratif pembekuan/pencabutan sertifikat.
9. **`DATA-01` (MEDIUM) — Tidak Ada Validasi Duplikasi Blanko saat Edit Manual di Admin**  
   Admin dapat memasukkan nomor blanko yang sama pada dua posting sertifikat berbeda tanpa ada peringatan atau pencegahan duplikasi.
10. **`SEC-06` (LOW/MEDIUM) — Respon Publik Menampilkan Nilai Input Bukan Data Database Resmi**  
    Header kartu verifikasi mencetak string yang diketik pengguna, bukan nomor blanko resmi yang tersimpan di basis data.

---

## 3. TOP 10 PENINGKATAN TEKNIS (TECHNICAL IMPROVEMENTS)

1. **Exact-Match & Normalized Certificate Lookup:** Menjamin hasil verifikasi hanya keluar jika nomor blanko atau nomor registrasi cocok 100% secara eksak setelah normalisasi spasi/huruf besar.
2. **Transient-Based IP Rate Limiter:** Membatasi frekuensi pengecekan sertifikat maksimal 20 request/menit per IP untuk menangkal bot scraper.
3. **Robust CSV Importer Engine:** Mendukung deteksi otomatis delimiter (koma `,` dan titik koma `;`), pembersihan UTF-8 BOM, pemetaan nama header dinamis, dan validasi tanggal `YYYY-MM-DD`.
4. **CSV Import Error & Summary Reporting:** Menyediakan statistik mendetail saat import: Jumlah Baru (`imported`), Jumlah Diperbarui (`updated`), Jumlah Dilewati (`skipped`), dan Rincian Baris Gagal (`errors`).
5. **Role Capability Lifecycle Optimization:** Memindahkan mutasi capabilities ke `register_activation_hook` sehingga request reguler tidak lagi menulis ke `wp_options`.
6. **Agregasi Cache untuk Katalog Skema:** Menggunakan WordPress Transients untuk menyimpan hitungan kemasan skema (`KKNI`, `Okupasi`, `Klaster`) dengan invalidasi otomatis saat post skema diubah.
7. **Eliminasi N+1 Query pada CSV Import:** Mengganti query `get_posts()` di dalam loop baris CSV dengan direct lookup `$wpdb` yang diindeks.
8. **Optimasi Memory Fetching pada Shortcodes:** Menghilangkan `posts_per_page => -1` yang tidak perlu pada shortcode skema dan TUK dengan batasan limit yang proporsional.
9. **Penambahan Status Administratif Sertifikat (Backward Compatible):** Mendukung status `active`, `expired`, `suspended`, dan `revoked` serta tanggal kadaluarsa kustom tanpa merusak data sertifikat lama.
10. **Lifecycle Deaktivasi Bersih:** Mendaftarkan hook deaktivasi untuk membersihkan transient cache dan me-flush rewrite rules secara aman.

---

## 4. ESTIMASI DAMPAK KOMPATIBILITAS (COMPATIBILITY IMPACT)

- **Kompatibilitas Tema (`lspkomunikasi`):** **100% AMAN (ZERO IMPACT)**  
  Tidak ada perubahan nama fungsi publik (`render_skema_catalog_archive()`, `render_single_skema_content()`), tidak ada perubahan nama shortcode (`[lsp_skema]`, `[lsp_tuk]`, `[lsp_asesor]`, `[cek_sertifikat]`), dan tidak ada perubahan slug post type atau taxonomy.
- **Kompatibilitas Data Lama (Database Postmeta):** **100% AMAN (ZERO IMPACT)**  
  Seluruh meta key lama (`kode_blanko`, `nama_pemegang`, `no_registrasi`, `skema_sertifikasi`, `tanggal_terbit`) tetap utuh tanpa perubahan struktur. Sertifikat lama yang belum memiliki metadata status baru otomatis diasumsikan aktif selama masa berlakunya valid.
- **Kompatibilitas User Asesor:** **100% AMAN**  
  Role `asesor` dan capabilities-nya tetap identik. User lama tidak terpengaruh.

---

## 5. URUTAN TAHAPAN PERBAIKAN (PROPOSED FIX ORDER - PHASE 9)

Perbaikan akan dilaksanakan dengan pendekatan **Isolated Changes** sesuai urutan prioritas:

### TAHAP 1: PERBAIKAN KEAMANAN KRITIS (CRITICAL HOTFIX)
1. Perbaiki `ajax_verify_cert()`:
   - Tambahkan verifikasi `check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce' )`.
   - Hapus total fallback kueri `LIKE`. Wajibkan pencarian eksak (`=`).
   - Implementasikan Transient Rate Limiter (20 req/menit per IP) dan batasan panjang input (3 - 60 karakter).
   - Tampilkan nomor blanko resmi dari database pada kartu hasil verifikasi.

### TAHAP 2: PERBAIKAN INTEGRITAS DATA & CSV IMPORTER (DATA & IMPORT FIXES)
2. Perbaiki fungsi `render_import_page()`:
   - Validasi upload: `UPLOAD_ERR_OK`, ekstensi `.csv`, mime type, dan batas ukuran file (5MB).
   - Deteksi delimiter otomatis (koma vs titik-koma) dan strip UTF-8 BOM.
   - Validasi header schema (`kode_blanko`, `nama_pemegang`, dsb).
   - Validasi format tanggal menggunakan `DateTime::createFromFormat('Y-m-d', ...)`.
   - Tingkatkan efisiensi pengecekan duplikasi blanko di dalam loop import.
   - Sediakan rincian laporan: Baru, Update, Dilewati, dan Baris Gagal.

### TAHAP 3: PERBAIKAN ARSITEKTUR & MUTASI ROLE (ARCHITECTURE CLEANUP)
3. Pindahkan mutasi role capability `asesor` dari `init` ke activation hook (`activate()`).
4. Tambahkan deactivation hook untuk flush rewrite rules dan membersihkan transient.

### TAHAP 4: SANITASI HYGIENE & PERBAIKAN FORM SAVE (SANITIZATION FIXES)
5. Tambahkan `wp_unslash()` pada seluruh handler simpan:
   - `save_skema_meta()`
   - `save_tuk_meta()`
   - `save_cert_meta()`
   - `save_asesor_user_profile_fields()`
6. Tambahkan pengecekan duplikasi blanko saat simpan manual di `save_cert_meta()`.

### TAHAP 5: OPTIMASI PERFORMA & QUERY (PERFORMANCE OPTIMIZATION)
7. Ganti `get_posts( posts_per_page => -1 )` pada notifikasi artikel pending dengan `wp_count_posts('post')->pending`.
8. Implementasikan caching transient untuk hitungan kemasan skema di katalog arsip.
9. Optimalkan query shortcode skema dan TUK agar tidak memuat seluruh database ke RAM jika parameter acak tidak digunakan.

### TAHAP 6: PENYEMPURNAAN BISNIS VERIFIKASI (BACKWARD COMPATIBLE FEATURE)
10. Tambahkan dukungan opsional field `status_sertifikat` (Aktif, Dibekukan, Dicabut) dan `tanggal_kadaluarsa` pada meta box dan verifier, dengan fallback otomatis ke aturan historis 3 tahun jika field tidak diisi.

