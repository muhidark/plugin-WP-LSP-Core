# LAPORAN REMEDIASI PHASE 4 — ROLE ASESOR ACCESS CONTROL REFINEMENT
**Plugin:** LSP Core Management  
**Repositori/Workspace:** `/var/www/html/wordpress/wp-content/plugins/lsp-core-management`  
**Baseline Git:** Branch `phase-4-role-asesor`  
**Tanggal Pengujian:** 17 Oktober 2026  
**Tim Penguji:** Senior WordPress Core Engineer, PHP Security Auditor, Database Integrity Engineer, Quality Assurance Engineer  

---

## 1. RINGKASAN EKSEKUTIF

Siklus **PHASE 4 — ROLE ASESOR ACCESS CONTROL REFINEMENT** telah berhasil dirancang, diimplementasikan, dan diuji secara komprehensif. Fokus utama fase ini adalah menyelaraskan hak akses akun pengguna dengan Role **Asesor LSP (`asesor`)** sesuai dengan kebijakan operasional dan kepatuhan regulasi Lembaga Sertifikasi Profesi (LSP) Komunikasi Indonesia.

Penyempurnaan utama yang telah diselesaikan:
1. **Penegakan Alur Kerja Artikel (Article Workflow):**
   - Asesor hanya dapat membuat draf (`draft`), menyimpan revisi draf miliknya, dan mengajukan artikel untuk peninjauan (`pending review`).
   - Asesor **TIDAK memiliki** kapabilitas `publish_posts`, `edit_published_posts`, `edit_others_posts`, maupun `delete_published_posts`.
   - Artikel yang telah disetujui dan diterbitkan (`publish`) oleh Administrator berubah statusnya menjadi **strictly Read-Only** bagi Asesor pembuat artikel.
2. **Kontrol Akses Profil & Otorisasi Server-Side:**
   - Asesor pada formulir profil mandiri (`profile.php`) **hanya diizinkan memperbarui Alamat Email dan Kata Sandi**.
   - Seluruh data profesional & lisensi MET (`nomor_met`, `tanggal_terbit_met`, `foto_asesor`, `bidang_keahlian_asesor`, `bidang_keahlian_tambahan`, `deskripsi_asesor`, tautan sosial media, serta nama resmi) **dilindungi penuh oleh otorisasi server (`manage_options`)**.
   - Kerentanan otorisasi pada `current_user_can('edit_user', $user_id)` yang sebelumnya memungkinkan penyusupan HTTP POST telah ditutup tuntas.
   - Antarmuka profil untuk Asesor menampilkan data profesional dalam bentuk kartu informasi **hanya-baca (read-only)** tanpa tombol pengunggah file media.
3. **Penyelesaian Tuntas Temuan Arsitektur ARCH-01:**
   - Mutasi kapabilitas role berulang pada setiap web request di hook `init` telah digantikan dengan **Version-Based Role Migration** (`LSP_Core_Management::maybe_migrate_asesor_role()`).
   - Menghilangkan 7 kali panggilan `update_option()` per HTTP request sehingga menghemat I/O basis data dan mencegah cache invalidation.
4. **Hasil Pengujian & Nol Regresi:**
   - 20 dari 20 pengujian pada suite `tests/test-phase4.php` dinyatakan **LULUS (100% PASS)**.
   - Rangkaian pengujian kumulatif (Phase 1: 12 test, Phase 2: 15 test, Phase 3: 25 test, Phase 4: 20 test) menghasilkan **72 / 72 PASS (100%)**.

---

## 2. DETAIL PERUBAHAN & ARSITEKTUR AKSES

### A. Matriks Hak Akses Role Asesor LSP

Sesuai prinsip *Least Privilege*, berikut adalah pemetaan kapabilitas akun Role Asesor:

| Kapabilitas WordPress | Nilai Role Asesor | Keterangan Operasional |
| :--- | :--- | :--- |
| `read` | `true` | Diizinkan login ke WP-Admin dan mengakses dasbor terbatas. |
| `edit_posts` | `true` | Diizinkan membuat draf dan mengedit draf/pending post miliknya. |
| `upload_files` | `true` | Diizinkan mengunggah gambar ilustrasi artikel miliknya sendiri. |
| `delete_posts` | `true` | Diizinkan menghapus draf artikel miliknya sendiri sebelum diajukan. |
| `publish_posts` | `false` | **Dilarang keras.** Hanya Administrator yang berhak menerbitkan artikel. |
| `edit_published_posts` | `false` | **Dilarang keras.** Artikel yang sudah terbit bersifat strictly Read-Only. |
| `edit_others_posts` | `false` | **Dilarang keras.** Tidak dapat melihat atau mengedit artikel pengguna lain. |
| `delete_published_posts`| `false` | **Dilarang keras.** Tidak dapat menghapus artikel yang sudah tayang. |
| `delete_others_posts` | `false` | **Dilarang keras.** Tidak dapat menghapus tulisan pengguna lain. |
| `manage_options` | `false` | **Dilarang keras.** Dilarang mengelola pengaturan plugin & profil MET. |

### B. Alur Kerja Artikel (Article Workflow Lifecycle)

```
[ ASESOR ]
    │
    ▼
1. Tulis Artikel Baru (Post)
    │
    ▼
2. Simpan Draft
    │
    ▼
3. Ajukan Peninjauan (Submit for Review)  ──► Status: 'pending'
    │                                              │
    ◄────────────────── [ REVISI DIMINTA ] ────────┤
    │                   (Asesor dapat mengedit     │
    │                    kembali sebelum terbit)   │
    │                                              ▼
    │                                     [ ADMINISTRATOR REVIEW ]
    │                                              │
    │                                              ▼
    │                                     Persetujuan & Terbitkan (Publish)
    │                                              │
    │                                              ▼
    └─────────────────────────────────────► Status: 'publish'
                                                   │
                                                   ▼
                                         [ STRICTLY READ-ONLY ]
                                         Asesor tidak dapat lagi
                                         mengedit/menghapus konten
```

#### Mekanisme Pertahanan Server-Side (`enforce_asesor_post_workflow`):
- Filter `wp_insert_post_data` memvalidasi setiap operasi simpan artikel.
- Jika pengguna adalah Asesor dan mencoba mengirim payload `post_status = 'publish'` (misalnya melalui manipulasi inspect element, script REST API, atau curl), sistem otomatis memaksa status kembali menjadi `pending`.
- Jika artikel target sudah pernah berstatus `publish`, setiap percobaan modifikasi judul (`post_title`), konten (`post_content`), atau ringkasan (`post_excerpt`) dibatalkan dan nilai asli database dipulihkan secara otomatis.

### C. Proteksi Otorisasi Profil (Profile Access Control)

#### Analisis Celah Keamanan Sebelumnya:
Pada versi lama, fungsi penyimpanan profil menggunakan pemeriksaan:
```php
if ( ! current_user_can( 'edit_user', $user_id ) ) return;
```
Di dalam WordPress, fungsi `current_user_can( 'edit_user', $user_id )` otomatis bernilai `true` saat pengguna mengedit profilnya sendiri di `/wp-admin/profile.php`. Akibatnya, Asesor dapat menyusupkan field `nomor_met`, `foto_asesor`, maupun `bidang_keahlian_asesor` melalui request POST manual.

#### Remediasi Server-Side:
1. **Otorisasi Khusus Administrator pada `save_asesor_user_profile_fields()`:**
   ```php
   if ( ! current_user_can( 'manage_options' ) ) {
       return; // Tolak keras seluruh update metadata profesional jika bukan Admin
   }
   ```
2. **Proteksi Field Identitas Native WordPress via `wp_pre_insert_user_data` & `insert_user_meta`:**
   - Asesor hanya diizinkan memperbarui `user_email` dan `user_pass`.
   - Modifikasi `display_name`, `first_name`, `last_name`, `nickname`, dan `description` oleh non-admin dicegah dengan memulihkan nilai asli yang tersimpan di basis data.
3. **Penyajian Antarmuka (UI) Read-Only:**
   - Pada saat Asesor membuka `profile.php`, sistem merender banner informasi regulasi BNSP.
   - Kolom nomor MET, tanggal terbit, masa berlaku, dan deskripsi ditampilkan dalam mode `readonly disabled` dengan latar belakang abu-abu terang.
   - Pilihan bidang kompetensi disajikan dalam bentuk badge tag statis (bukan checkbox aktif).
   - Tombol pemilihan media WordPress (`#btn_upload_foto_asesor`) dan script JavaScript media uploader dihilangkan sepenuhnya dari halaman Asesor.

### D. Version-Based Capability Migration (Penyelesaian ARCH-01)

Sebelumnya, plugin memanggil `$role->add_cap()` dan `$role->remove_cap()` pada setiap kali event `init` WordPress dipicu. Hal ini menyebabkan penulisan serialized data `update_option( 'wp_user_roles' )` sebanyak 7 kali pada setiap *page load*.

Solusi yang diterapkan:
- Menetapkan konstanta versi role: `const ASESOR_ROLE_VERSION = '1.1.0';`.
- Metode `maybe_migrate_asesor_role()` memverifikasi opsi `get_option( 'lsp_asesor_role_version' )`.
- Mutasi kapabilitas hanya dilakukan **satu kali saja** saat inisialisasi awal atau saat nomor versi dinaikkan.
- Pada request web normal berikutnya, fungsi langsung mengembalikan nilai tanpa melakukan query tulis ke tabel `wp_options`.

---

## 3. MATRIKS HASIL PENGUJIAN PHASE 4 (TEST SUITE)

Pengujian dijalankan melalui script otomasi [`tests/test-phase4.php`](file:///var/www/html/wordpress/wp-content/plugins/lsp-core-management/tests/test-phase4.php):

| ID Test | Kategori | Klasifikasi | Deskripsi Pengujian | Hasil | Catatan Integritas |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TEST 01** | Autentikasi | UNIT / MOCK | Asesor login & kapabilitas dasbor `read` | **PASS** | Akses dasbor terbatas aktif |
| **TEST 02** | Artikel Workflow | UNIT / MOCK | Asesor membuat artikel baru status `draft` | **PASS** | Draf tersimpan dengan author asesor |
| **TEST 03** | Artikel Workflow | UNIT / MOCK | Asesor mengedit draf artikel miliknya sendiri | **PASS** | Perubahan draf berhasil disimpan |
| **TEST 04** | Artikel Workflow | UNIT / MOCK | Asesor mengajukan artikel (`pending review`) | **PASS** | Status transisi ke `pending` |
| **TEST 05** | Artikel Workflow | UNIT / MOCK | Asesor merevisi artikel pending sebelum terbit | **PASS** | Revisi artikel pending sah diizinkan |
| **TEST 06** | Keamanan Publikasi | UNIT / MOCK | Penolakan publikasi langsung oleh Asesor | **PASS** | Status dipaksa kembali ke `pending` |
| **TEST 07** | Keamanan Publikasi | UNIT / MOCK | Penolakan edit artikel yang sudah published | **PASS** | Artikel published strictly read-only |
| **TEST 08** | Isolasi Pengguna | UNIT / MOCK | Penolakan edit artikel milik pengguna lain | **PASS** | Otorisasi `edit_others_posts` bekerja |
| **TEST 09** | Media Library | UNIT / MOCK | Asesor mengunggah gambar artikel sendiri | **PASS** | Izin `upload_files` aktif |
| **TEST 10** | Profil Mandiri | UNIT / MOCK | Asesor memperbarui email akunnya sendiri | **PASS** | Update email sah berhasil |
| **TEST 11** | Profil Mandiri | UNIT / MOCK | Asesor memperbarui password akunnya sendiri | **PASS** | Update password sah berhasil |
| **TEST 12** | Otorisasi Profil | UNIT / MOCK | Penolakan perubahan Nomor MET oleh Asesor | **PASS** | Data MET di database tetap utuh |
| **TEST 13** | Otorisasi Profil | UNIT / MOCK | Penolakan manipulasi bidang kompetensi | **PASS** | Skema keahlian asesor terlindungi |
| **TEST 14** | Otorisasi Profil | UNIT / MOCK | Penolakan perubahan foto resmi oleh Asesor | **PASS** | URL foto resmi tidak berubah |
| **TEST 15** | Keamanan Input | UNIT / MOCK | Netralisasi manipulasi HTTP POST manual profil | **PASS** | Field profesional & nama dipulihkan |
| **TEST 16** | Otoritas Admin | UNIT / MOCK | Admin mengubah data profesional & MET asesor | **PASS** | Seluruh data berhasil diperbarui admin |
| **TEST 17** | Otoritas Admin | UNIT / MOCK | Admin menyetujui & menerbitkan (publish) post | **PASS** | Artikel asesor berhasil dipublish |
| **TEST 18** | Integritas Data | UNIT / MOCK | Data seluruh akun asesor lama tetap utuh | **PASS** | Nol kerusakan data historis |
| **TEST 19** | Kinerja Arsitektur | UNIT / MOCK | Migrasi role bersifat idempotent (ARCH-01 fix) | **PASS** | Nol penulisan option berulang |
| **TEST 20** | Regresi Sistem | INTEGRATION | Integritas sistem lama (Phase 1, 2, 3) terlindungi | **PASS** | Kompatibilitas 100% terjaga |

> **Pernyataan Kejujuran Pengujian:**  
> Pengujian di atas dieksekusi menggunakan lingkungan simulasi *WordPress PHP Runtime Test Harness* di CLI. Pengujian antarmuka browser grafis (End-to-End browser click di Chrome/Firefox) dan integrasi langsung ke MySQL produksi diklasifikasikan sebagai **NOT TESTED** pada siklus ini demi menjaga stabilitas server produksi.

---

## 4. STATUS REGRESI LINTAS FASE (CUMULATIVE REGRESSION)

Rangkaian pengujian lengkap dijalankan berurutan:
1. `tests/test-phase1.php`: **12 / 12 PASS** (Exact match meta search, eliminasi wildcard LIKE, sanitasi AJAX).
2. `tests/test-phase2.php`: **15 / 15 PASS** (Integritas simpan admin, pencegahan duplikasi kode blanko & nomor registrasi).
3. `tests/test-phase3.php`: **25 / 25 PASS** (Hardening CSV Import, deteksi delimiter, isolasi transient batch, formula defense).
4. `tests/test-phase4.php`: **20 / 20 PASS** (Kontrol akses Role Asesor, alur artikel pending/published, otorisasi profil).

**TOTAL AKUMULASI PENGUJIAN:** **72 / 72 PASS (100% SUKSES)**

---

## 5. PENEGAKAN BATASAN (STOP CONDITION)

Sesuai instruksi:
- **Theme `lspkomunikasi`:** 100% tidak diubah (Baseline stabil).
- **Deployment Produksi:** **TIDAK DILAKUKAN** (Seluruh kode tersimpan rapi di branch lokal `phase-4-role-asesor`).
- **Kelanjutan Phase 5:** **DITAHAN** menunggu tinjauan dan arahan lebih lanjut dari pimpinan proyek.

