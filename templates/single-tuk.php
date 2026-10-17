<?php
/**
 * The template for displaying single TUK detail
 *
 * @package LSP_Core_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<main id="primary" class="site-main single-tuk-page">

    <?php
    while ( have_posts() ) :
        the_post();
        $jenis_tuk = get_post_meta( get_the_ID(), 'jenis_tuk', true );
        ?>
        <!-- Parallax Hero Banner -->
        <section class="parallax-banner" <?php if ( has_post_thumbnail() ) { echo 'style="background-image: url(' . esc_url( get_the_post_thumbnail_url( get_the_ID(), 'full' ) ) . ');"'; } ?>>
            <div class="parallax-content">
                <h1><?php the_title(); ?></h1>
                <?php if ( ! empty( $jenis_tuk ) ) : ?>
                    <div style="margin-top: 15px;">
                        <span style="display: inline-block; background-color: var(--secondary-color, #f89b29); color: #ffffff; padding: 6px 18px; border-radius: 20px; font-size: 14px; font-weight: 700; text-transform: uppercase;">
                            <?php echo esc_html( $jenis_tuk ); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="site-content" style="max-width: 1200px; margin: 0 auto; padding: 0 20px 60px;">
            <article id="post-<?php the_ID(); ?>" <?php post_class( 'single-post-article' ); ?> style="background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-top: -60px; position: relative; z-index: 2;">
                <div class="entry-content" style="font-size: 16px; line-height: 1.8; color: #444;">
                    <?php
                    if ( class_exists( 'LSP_Core_Management' ) ) {
                        echo LSP_Core_Management::get_instance()->render_single_tuk_content( get_the_content() );
                    } else {
                        the_content();
                    }
                    ?>
                </div>
            </article>
        </div>
        <?php
    endwhile;
    ?>

</main>

<?php
get_footer();
