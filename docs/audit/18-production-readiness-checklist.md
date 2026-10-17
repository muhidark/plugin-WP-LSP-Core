# CHECKLIST KESIAPAN PRODUKSI (PRODUCTION READINESS CHECKLIST)
**Plugin:** LSP Core Management  
**Target:** https://lspkomunikasi.id  
**Tanggal Audit Kesiapan:** 17 Oktober 2026  
**Klasifikasi Akhir:** **CONDITIONALLY READY FOR PRODUCTION**  
*(Sistem siap dideploy ke lingkungan Staging untuk verifikasi visual browser akhir sebelum deployment live produksi)*

---

## 1. REKAPITULASI EVALUASI KOMPONEN

| No | Modul / Fitur | Status Pengujian | Kesiapan Kode | Keterangan Evaluasi |
| :---: | :--- | :---: | :---: | :--- |
| **1** | **Exact Match Verifikasi AJAX** (Phase 1) | PASS (12/12) | **SIAP** | Menggunakan operator persis (`=`), anti wildcard LIKE, rate limiter aktif, nonce check ketat. |
| **2** | **Normalisasi Sertifikat & Anti-Duplikasi** (Phase 2 & 2.1) | PASS (15/15) | **SIAP** | Fungsi sentral `normalize_cert_number()`, perlindungan self-duplicate, penolakan cross-field collision. |
| **3** | **CSV Import Hardening & Preflight** (Phase 3 & 3.1) | PASS (40/40) | **SIAP** | Preflight zero-write side-effects, MIME check, formula injection safe, transient isolation per-user. |
| **4** | **Role Asesor Access Control** (Phase 4) | PASS (20/20) | **SIAP** | Least privilege, draft $\to$ pending $\to$ admin publish, published read-only, MET & bio protected. |
| **5** | **Integrasi Real WordPress & MySQL** (Phase 5) | PASS (32/32) | **SIAP** | Teruji pada runtime nyata WordPress 7.1.3 + MySQL 8.4.11 + theme `lspkomunikasi`. |
| **6** | **Integritas Theme lspkomunikasi** | 100% Utuh | **SIAP** | Nol modifikasi berkas tema. Seluruh shortcode render stabil. |

---

## 2. STATUS KLASIFIKASI KESIAPAN: CONDITIONALLY READY

Sistem diberikan predikat **CONDITIONALLY READY** dengan catatan pemenuhan syarat berikut:

> [!IMPORTANT]
> **Kondisi Prasyarat Deployment:**
> 1. **Zero Theme Modification:** Theme `lspkomunikasi` di server produksi tidak boleh disentuh atau diubah.
> 2. **Dry-Run Staging:** Lakukan uji coba impor CSV dan alur login Asesor pada salinan database staging sebelum diterapkan langsung pada database live `https://lspkomunikasi.id`.
> 3. **Manual Visual Browser Testing:** Lakukan pengecekan visual tampilan tombol print, form input, dan responsivitas mobile di browser riil (Chrome, Safari, Firefox).
> 4. **Backup Database Mutlak:** Buat salinan cadangan (*full database backup*) tabel `wp_posts`, `wp_postmeta`, `wp_users`, dan `wp_usermeta` sebelum proses rilis.

---

## 3. CHECKLIST PRA-DEPLOYMENT (PRE-DEPLOYMENT)

- [x] **Audit Keamanan & Kerentanan:** Selesai (Semua temuan SEC-01 s.d. SEC-06 dan DATA-01 teratasi).
- [x] **Unit & Integration Testing:** Selesai (Total 104 pengujian otomatis lulus 100%).
- [x] **Pemeriksaan Sintaks PHP:** Selesai (Bebas dari PHP fatal error, parse error, dan warning).
- [x] **Pembersihan Berkas Temporer & Uji:** Pastikan berkas `/tmp/test_import_*.csv` telah dihapus.
- [ ] **Full Database Backup:** Eksekusi dump database produksi sebelum aktivasi versi baru:
  ```bash
  mysqldump -u <user> -p <dbname> > backup_pre_lsp_phase5_$(date +%Y%m%d).sql
  ```
- [ ] **Pemeriksaan Direktif PHP Server Produksi:**
  - `upload_max_filesize` $\ge$ 5M
  - `post_max_size` $\ge$ 8M
  - `memory_limit` $\ge$ 256M

---

## 4. CHECKLIST SAAT DEPLOYMENT (DEPLOYMENT EXECUTION)

- [ ] **Aktifkan Maintenance Mode Sementara:**
  ```bash
  wp maintenance-mode activate
  ```
- [ ] **Deploy Berkas Plugin:** Unggah versi terbaru plugin `lsp-core-management` (Branch: `phase-5-integration-testing`).
- [ ] **Verifikasi File Permissions:**
  ```bash
  chown -R www-data:www-data /var/www/html/wordpress/wp-content/plugins/lsp-core-management
  find /var/www/html/wordpress/wp-content/plugins/lsp-core-management -type f -exec chmod 644 {} +
  find /var/www/html/wordpress/wp-content/plugins/lsp-core-management -type d -exec chmod 755 {} +
  ```
- [ ] **Picu Migration Role Asesor:** Akses wp-admin sebagai Administrator untuk memicu eksekusi `maybe_migrate_asesor_role()`.
- [ ] **Verifikasi Opsi Versi Role di Database:**
  ```sql
  SELECT option_value FROM wp_options WHERE option_name = 'lsp_asesor_role_version';
  -- Ekspektasi: '1.1.0'
  ```
- [ ] **Nonaktifkan Maintenance Mode:**
  ```bash
  wp maintenance-mode deactivate
  ```

---

## 5. CHECKLIST PASCA-DEPLOYMENT (POST-DEPLOYMENT SMOKE TESTS)

Lakukan pengujian cepat di browser secara manual:

1. **Verifikasi Publik AJAX:**
   - [ ] Buka halaman verifikasi sertifikat (`/verifikasi-sertifikat/`).
   - [ ] Masukkan nomor blanko resmi yang sudah terdaftar $\to$ Verifikasi kartu hijau muncul dengan data akurat.
   - [ ] Masukkan nomor acak $\to$ Verifikasi kartu merah `TIDAK DITEMUKAN` muncul tanpa PHP error.
   - [ ] Masukkan spasi kosong $\to$ Verifikasi pesan error input muncul.
2. **Admin Sertifikat:**
   - [ ] Buka menu `Sertifikat LSP` $\to$ Tambah Baru.
   - [ ] Coba masukkan nomor sertifikat yang sudah ada $\to$ Pastikan status dipaksa menjadi `draft` dan notice peringatan duplikat muncul.
3. **CSV Import:**
   - [ ] Buka menu `Import CSV`.
   - [ ] Unggah file contoh berformat 5 kolom.
   - [ ] Pastikan pratinjau tabel muncul tanpa langsung menyimpan data ke database.
   - [ ] Klik Konfirmasi Import $\to$ Pastikan data masuk dan laporan ringkasan akurat.
4. **Role Asesor:**
   - [ ] Login sebagai salah satu Asesor.
   - [ ] Pastikan menu Admin hanya menampilkan Dashboard, Pos (Artikel), Media, dan Profil.
   - [ ] Coba buat artikel baru dan klik "Kirim untuk Ditinjau".
   - [ ] Buka halaman profil Asesor $\to$ Pastikan field Nomor MET dalam keadaan *disabled* / read-only.

---

## 6. RENCANA ROLLBACK (ROLLBACK PROCEDURE)

Jika terjadi kendala tidak terduga pada server produksi saat proses rilis:

1. **Kembalikan Berkas Plugin ke Versi Sebelumnya:**
   ```bash
   git checkout tags/v1.0.0-pre-hardening -- lsp-core-management/
   ```
2. **Kembalikan Database dari Backup:**
   ```bash
   mysql -u <user> -p <dbname> < backup_pre_lsp_phase5_<timestamp>.sql
   ```
3. **Hapus Versi Migrasi Opsi:**
   ```sql
   DELETE FROM wp_options WHERE option_name = 'lsp_asesor_role_version';
   ```
4. **Bersihkan Cache WordPress & Server:**
   ```bash
   wp cache flush
   ```

