# AUDIT LAPORAN 04: WORDPRESS ARCHITECTURE AUDIT
**Plugin:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Lingkup:** Bootstrap, Lifecycle Hooks, Role Architecture, Singleton, Separation of Concerns, Asset Management  
**Auditor:** Senior WordPress Plugin Developer & PHP Backend Engineer  
**Tanggal:** 17 Oktober 2026  

---

## 1. EVALUASI ARSITEKTUR & DESAIN

### 1.1 Bootstrap & Singleton Pattern
- Plugin menggunakan class monolitik `LSP_Core_Management` dengan pola Singleton klasik (`get_instance()`).
- File utama `lsp-core-management.php` memuat lebih dari 3.200 baris kode yang mencakup seluruh layer aplikasi (CPT registration, UI admin, shortcode, AJAX, template loading, dan image processing).
- **Penilaian:** Meskipun monolitik dalam 1 file mempermudah portabilitas (single file drop-in), pemisahan concern menjadi modular (misal: sub-class untuk admin, shortcode, dan AJAX) merupakan technical debt yang perlu dijaga agar modifikasi tetap terisolasi dan tidak merusak fungsi lain. Sesuai Rule 4 (No Unnecessary Rewrite), restrukturisasi file besar tidak disarankan di produksi jika perubahan terisolasi sudah cukup aman.

---

### 1.2 Lifecycle Hooks & Anti-Pattern Role Synchronization

#### FINDING ID: ARCH-01 (SEVERITY: HIGH)
- **FILE:** `lsp-core-management.php:187-201`
- **DESKRIPSI:**  
  Pemeriksaan dan mutasi hak akses (capabilities) untuk role `asesor` dijalankan pada setiap request WordPress melalui hook `init`:
  ```php
  // Dijalankan pada setiap request init!
  $role = get_role( 'asesor' );
  if ( $role ) {
      $role->add_cap( 'read' );
      $role->add_cap( 'edit_posts' );
      $role->add_cap( 'delete_posts' );
      $role->add_cap( 'upload_files' );
      $role->remove_cap( 'publish_posts' );
      $role->remove_cap( 'edit_published_posts' );
      $role->remove_cap( 'delete_published_posts' );
  }
  ```
- **ANALISIS TEKNIS:**  
  Dalam WordPress Core, objek `WP_Role::add_cap()` dan `remove_cap()` memanggil fungsi `update_option()` pada opsi `wp_user_roles` di database. Menjalankan operasi ini pada setiap request merupakan anti-pattern arsitektur WordPress yang berat.
- **SOLUSI:**  
  Pindahkan sinkronisasi role & capability ke dalam `register_activation_hook` atau jalankan hanya jika versi plugin diperbarui menggunakan migration flag versioning sederhana.

---

### 1.3 Lifecycle Hook Deaktivasi

#### FINDING ID: ARCH-02 (SEVERITY: LOW)
- **FILE:** `lsp-core-management.php:3228-3230`
- **DESKRIPSI:**  
  Plugin mendaftarkan `register_activation_hook( __FILE__, array( 'LSP_Core_Management', 'activate' ) )`, namun **TIDAK** mendaftarkan `register_deactivation_hook`.
- **DAMPAK:**  
  Ketika plugin dinonaktifkan oleh administrator, aturan rewrite URL untuk custom post type `skema` dan `tuk` tidak dibersihkan (`flush_rewrite_rules`), yang dapat meninggalkan rewrite rules usang di database.
- **SOLUSI:**  
  Tambahkan method `deactivate()` yang memanggil `flush_rewrite_rules()`. Sesuai Rule 2, jangan pernah menghapus tabel atau data sertifikat pada saat deaktivasi.

---

### 1.4 Template Loader & Fallback Mechanism
- Method `template_loader( $template )` di baris 2791 bekerja dengan urutan prioritas yang benar:
  1. Memeriksa keberadaan file template pada tema aktif menggunakan `locate_template()`.
  2. Jika tema tidak menyediakan template, plugin memuat fallback dari folder internal `templates/`.
- Ini menjamin bahwa jika tema aktif diganti ke tema default WordPress (misal: Twenty Twenty-Four) atau tema turunan, tampilan halaman arsip dan detail skema tetap render secara utuh tanpa error.

---

### 1.5 Media Library Filtering untuk Asesor
- Hook `ajax_query_attachments_args` pada baris 961 membatasi asesor agar hanya dapat melihat gambar unggahan mereka sendiri di Media Library modal:
  ```php
  public function restrict_asesor_media_library( $query ) {
      $user = wp_get_current_user();
      if ( $user && in_array( 'asesor', (array) $user->roles, true ) && ! current_user_can( 'edit_others_posts' ) ) {
          $query['author'] = $user->ID;
      }
      return $query;
  }
  ```
- **Penilaian:** Implementasi ini sangat baik dan sesuai dengan prinsip least-privilege untuk melindungi media/dokumen rahasia milik pengguna lain.

---

### 1.6 Asset Loading (CSS / JS)
- FontAwesome 6 dimuat dari CDN cdnjs pada hook `wp_enqueue_scripts` dengan pengecekan `! wp_style_is( 'font-awesome', 'enqueued' )`.
- Skrip CSS dan JS untuk admin panduan dimuat secara inline di dalam method render.
- **Rekomendasi Pemeliharaan:** Inline JS pada halaman form verifikasi (`lspSubmitCertCheck`) dan modal panduan aman, namun disarankan untuk membungkusnya dalam fungsi modular tanpa mengekspos variabel global ke window scope.

