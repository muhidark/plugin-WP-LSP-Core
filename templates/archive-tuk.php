<?php
/**
 * The template for displaying Tempat Uji Kompetensi (TUK) archive
 *
 * @package LSP_Core_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<main id="primary" class="site-main tuk-archive-page">

    <!-- Parallax Hero Banner -->
    <section class="parallax-banner">
        <div class="parallax-content">
            <h1>Tempat Uji Kompetensi (TUK)</h1>
            <p>Daftar Tempat Uji Kompetensi Terverifikasi <?php bloginfo( 'name' ); ?></p>
        </div>
    </section>

    <div class="site-content" style="max-width: 1200px; margin: 0 auto; padding: 0 20px 60px;">
        <div style="background: #fff; padding: 35px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); margin-top: -60px; position: relative; z-index: 5;">
            <?php echo do_shortcode( '[lsp_tuk]' ); ?>
        </div>
    </div>

</main>

<?php
get_footer();
