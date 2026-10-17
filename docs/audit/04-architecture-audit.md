# AUDIT LAPORAN 04: WORDPRESS ARCHITECTURE AUDIT (VALIDATED)
**Plugin:** LSP Core Management & Sertifikat Checker (`lsp-core-management`)  
**Lingkup:** Bootstrap, Lifecycle Hooks, Role Architecture, Singleton, Separation of Concerns, Asset Management  
**Auditor:** Senior WordPress Plugin Developer & PHP Backend Engineer  
**Tanggal:** 17 Oktober 2026  

---

## 1. EVALUASI ARSITEKTUR & DESAIN

### 1.1 Bootstrap & Singleton Pattern
- Plugin menggunakan class monolitik `LSP_Core_Management` dengan pola Singleton klasik (`get_instance()`).
- File utama `lsp-core-management.php` memuat 3.229 baris kode yang mencakup seluruh layer aplikasi.
- Sesuai Rule 4 (No Unnecessary Rewrite), restrukturisasi file besar tidak disarankan di produksi jika perubahan terisolasi sudah cukup aman.

---

### 1.2 Lifecycle Hooks & Pembuktian Mutasi Role Capabilities

#### FINDING ID: ARCH-01
- **SEVERITY:** HIGH
- **CATEGORY:** WordPress Lifecycle Anti-Pattern / Unnecessary Overhead
- **FILE:** `lsp-core-management.php`
- **LINE:** 187 - 201
- **AFFECTED FUNCTION:** `LSP_Core_Management::register_cpts_and_roles()`
- **DESCRIPTION:**  
  Pemeriksaan dan mutasi hak akses (capabilities) untuk role `asesor` dijalankan pada setiap request WordPress melalui hook `init`:
  ```php
  // lsp-core-management.php:189-201
  } else {
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
  }
  ```
- **VERIFIKASI & BUKTI TEKNIS PADA KODE WORDPRESS CORE:**  
  Pemeriksaan langsung pada source code WordPress Core (`/wp-includes/class-wp-role.php:59-75` dan `/wp-includes/class-wp-roles.php:232-260`):
  1. `$role->add_cap()` mendelegasikan ke `wp_roles()->add_cap( $this->name, $cap, $grant )`.
  2. Di dalam `WP_Roles::add_cap()` baris 238-240:
     ```php
     $this->roles[ $role ]['capabilities'][ $cap ] = $grant;
     if ( $this->use_db ) {
         update_option( $this->role_key, $this->roles );
     }
     ```
  3. Hal yang sama terjadi di `WP_Roles::remove_cap()` baris 257-259:
     ```php
     unset( $this->roles[ $role ]['capabilities'][ $cap ] );
     if ( $this->use_db ) {
         update_option( $this->role_key, $this->roles );
     }
     ```
  4. Meskipun `update_option()` memiliki pengecekan `$value === $old_value` untuk mencegah penulisan raw SQL fisik ke tabel `wp_options` jika array bernilai identik, operasi ini tetap memicu overhead serialisasi dan komparasi in-memory sebanyak **7 KALI** pada **SETIAP HALAMAN REQUEST** di seluruh situs.
- **ROOT CAUSE:** Menjalankan rutinitas setup role pada event runtime `init` alih-alih event instalasi/aktivasi.
- **IMPACT:** Menambah overhead pemrosesan opsi WordPress pada setiap request HTTP.
- **RECOMMENDED FIX:**  
  Pindahkan sinkronisasi role & capability ke dalam `register_activation_hook` atau gunakan migration flag versioning satu kali.
- **REGRESSION RISK:** Rendah. Hak akses user role `asesor` tetap identik.
- **TEST CASE:** Verifikasi hak akses role `asesor` tetap valid pada activation, dan tidak ada lagi pemanggilan `update_option()` berulang saat browsing.

---

### 1.3 Lifecycle Hook Deaktivasi

#### FINDING ID: ARCH-02
- **SEVERITY:** LOW
- **CATEGORY:** Lifecycle Management / Rewrite Rules Hygiene
- **FILE:** `lsp-core-management.php`
- **LINE:** 3228 - 3230
- **AFFECTED FUNCTION:** Global Plugin Setup
- **DESCRIPTION:**  
  Plugin mendaftarkan `register_activation_hook`, namun **TIDAK** mendaftarkan `register_deactivation_hook`.
- **ROOT CAUSE:** Ketiadaan callback hook deaktivasi.
- **IMPACT:** Aturan rewrite URL untuk custom post type `skema` dan `tuk` tidak dibersihkan saat plugin dinonaktifkan.
- **RECOMMENDED FIX:**  
  Daftarkan `register_deactivation_hook( __FILE__, array( 'LSP_Core_Management', 'deactivate' ) )` dengan memanggil `flush_rewrite_rules()`.
- **REGRESSION RISK:** Nol.
- **TEST CASE:** Nonaktifkan plugin; pastikan rewrite rules dibersihkan tanpa menghapus data.

---

### 1.4 Evaluasi Modul Lainnya
- **Template Loader & Fallback:** Bekerja dengan urutan prioritas yang benar (`locate_template()` lalu internal fallback).
- **Media Library Filtering Asesor:** Membatasi akses gambar dengan benar via `ajax_query_attachments_args`.
