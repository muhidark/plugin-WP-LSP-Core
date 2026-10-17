# AUDIT LAPORAN 06: REMEDIATION PLAN & ACTION ROADMAP (VALIDATED)
**Plugin:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Target:** https://lspkomunikasi.id  
**Auditor:** Tim Senior Software Engineering  
**Tanggal:** 17 Oktober 2026  

---

## 1. MATRIKS STATISTIK TEMUAN RESMI (SETELAH VALIDASI AKHIR)

| Severity | Jumlah | Kode Temuan |
|---|---|---|
| **CRITICAL** | **1** | `SEC-02` (Fallback LIKE pada verifikasi sertifikat) |
| **HIGH** | **3** | `SEC-03` (Ketiadaan Rate Limiting & Input Constraint), `SEC-04` (Insecure File Upload CSV), `ARCH-01` (Mutasi Role Capabilities pada `init`) |
| **MEDIUM** | **7** | `SEC-01` (Ketiadaan Nonce AJAX Verifikasi Publik), `BUG-01` (Kerapuhan Parser CSV), `PERF-01` (Inefficient Query pada CSV Loop), `PERF-02` (Redundant Query Kemasan Skema), `PERF-04` (Memory-intensive Shortcodes), `SEC-05` (Missing `wp_unslash`), `DATA-01` (Pengecekan Duplikasi Blanko Manual), `SEC-06` (Integritas Output Nomor Sertifikat) |
| **LOW** | **4** | `PERF-03` (Pending Posts Admin Polling), `PERF-05` (Unindexed Scan Halaman Asesor), `QUAL-01` (Hardcoded Font Path Auto Cover), `ARCH-02` (Ketiadaan Deactivation Hook) |
| **TOTAL TEMUAN** | **15** | — |

---

## 2. EVALUASI RISIKO REGRESI & KOMPATIBILITAS

- **Kompatibilitas Tema (`lspkomunikasi`):**  
  *Analisis Kode:* Seluruh pemanggilan fungsi tema (`render_skema_catalog_archive()`, `render_single_skema_content()`), shortcodes, dan variabel CSS tema dipertahankan utuh.  
  *Status:* Risiko regresi dinilai **Sangat Rendah**, menunggu konfirmasi pengujian regresi end-to-end.
- **Kompatibilitas Data Database:**  
  *Analisis Kode:* Seluruh post meta key historis (`kode_blanko`, `nama_pemegang`, `no_registrasi`, `skema_sertifikasi`, `tanggal_terbit`) tidak mengalami perubahan skema atau nama kolom.  
  *Status:* Risiko regresi dinilai **Sangat Rendah**, tidak ada manipulasi data historis secara massal.

---

## 3. RENCANA KERJA TAHAP 1 (DISETUJUI UNTUK DIEKSEKUSI)

Tahap 1 berfokus penuh dan terisolasi pada **Keamanan & Akurasi Verifikasi Sertifikat**:

1. **Exact Match Only:**
   - Menghapus seluruh blok query `compare => 'LIKE'` pada `ajax_verify_cert()`.
   - Melakukan pencarian eksak (`=`) terhadap `kode_blanko` dan `no_registrasi`.
   - Normalisasi input: menghapus leading/trailing whitespace, membersihkan karakter kontrol tersembunyi, tanpa mengubah format karakter tanda baca resmi.
2. **Penanganan Ambiguitas Duplikat:**
   - Jika ditemukan lebih dari 1 sertifikat dengan nomor yang sama persis di database, sistem tidak boleh memilih salah satu secara acak; kembalikan pesan bahwa data memerlukan verifikasi langsung ke sekretariat LSP untuk mencegah klaim keliru.
3. **Validasi Input Nomor Sertifikat:**
   - Menolak input kosong atau hanya spasi.
   - Menolak input dengan panjang di luar batas wajar (misal: `< 3` karakter atau `> 60` karakter).
4. **Verifikasi Nonce Sisi Server:**
   - Menambahkan verifikasi `check_ajax_referer( 'lsp_verify_cert_nonce', 'nonce', false )` dengan penanganan error ramah (HTTP 403 / JSON error terstruktur).
5. **Rate Limiting Aman:**
   - Menggunakan WordPress Transients dengan batas awal 20 request per menit per alamat IP.
   - Resolusi IP aman: menggunakan `REMOTE_ADDR` yang tidak dapat dipalsukan secara sembarangan oleh header HTTP dari klien.
   - IP di-hash menggunakan algoritma satu arah (`md5( 'lsp_rate_' . $ip )`) untuk melindungi privasi.
   - Mengembalikan pesan penjelasan sementara saat limit terlampaui tanpa memblokir permanen.
6. **Integritas Output Data:**
   - Menampilkan nomor blanko resmi yang diambil dari database (`get_post_meta`), bukan string mentah kiriman formulir.
   - Memastikan tidak ada data pribadi sensitif yang bocor di luar data sertifikasi resmi.
