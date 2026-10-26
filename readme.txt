=== LSP Core Management & Sertifikat Checker ===
Contributors: muhadi
Tags: lsp, skkni, sertifikasi, kompetensi, bns
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later

Master data sertifikasi LSP Komunikasi Indonesia: skema, TUK, data sertifikat, dan data SKKNI
terstruktur (unit, elemen, KUK) yang dipakai plugin sertifikasi.

== Description ==

Plugin ini menjadi sumber data utama bagi proses sertifikasi:

* Master Skema Sertifikasi (termasuk data SKKNI terstruktur: unit, elemen, KUK)
* Master TUK (Tempat Uji Kompetensi)
* Data sertifikat & verifikasi publik
* Halaman Migrasi SKKNI: impor Elemen/KUK dari teks atau berkas JSON (uji coba sebelum simpan)
* Pengaturan global persyaratan administratif untuk semua skema

== Installation ==

1. Unggah folder `lsp-core-management` ke `wp-content/plugins/`, atau unggah berkas ZIP lewat
   Plugins → Add New → Upload Plugin.
2. Aktifkan plugin.
3. Isi Master Skema dan Master TUK lebih dulu — plugin sertifikasi bergantung pada data ini.
4. Buka LSP Core → Pengaturan untuk mengisi persyaratan administratif.
5. Buka LSP Core → Migrasi SKKNI untuk melengkapi Elemen/KUK tiap skema.

== Changelog ==

= 1.1.1 =
* Perbaikan: fallback Elemen/KUK karangan pada `get_skema_skkni_data()` dihentikan — kini hanya
  kode dan judul unit dari meta skema, dengan penanda sumber `meta_skema`
* Baru: halaman Migrasi SKKNI (status per skema, impor teks baris / JSON, mode uji coba,
  validasi bernomor baris, sinkron meta unit kompetensi)
* Baru: impor menerima berkas JSON hasil SKKNI Extractor (alias `elemen_kompetensi`,
  `nomor_elemen`, `nama_elemen`, KUK objek, dukungan satu unit)
* Baru: badge publik netral ("—") ketika jumlah elemen belum tersedia
* Baru: pengaturan global persyaratan administratif (`lsp_core_persyaratan_admin`) untuk semua skema

= 1.1.0 =
* Skema dipecah ke `includes/class-skema.php` dengan Visual SKKNI Builder & Smart Parser
* Dukungan meta `acuan_skkni` dan `skkni_data` (elemen & KUK)

= 1.0.x =
* Rilis awal: master skema, TUK, data sertifikat, verifikasi publik
