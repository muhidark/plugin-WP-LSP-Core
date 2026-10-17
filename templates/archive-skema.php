<?php
/**
 * The template for displaying Skema Sertifikasi catalog archive
 *
 * @package LSP_Core_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<main id="primary" class="site-main skema-archive-page">

    <!-- Parallax Hero Banner -->
    <section class="parallax-banner">
        <div class="parallax-content">
            <h1>Skema Sertifikasi</h1>
            <p>Daftar Lengkap Skema Sertifikasi Kompetensi <?php bloginfo( 'name' ); ?></p>
        </div>
    </section>

    <div class="site-content" style="max-width: 1200px; margin: 0 auto; padding: 0 20px 60px;">
        <?php
        if ( class_exists( 'LSP_Core_Management' ) ) {
            echo LSP_Core_Management::get_instance()->render_skema_catalog_archive();
        }
        ?>
    </div>

</main>

<?php
get_footer();
