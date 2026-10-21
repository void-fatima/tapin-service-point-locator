<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?> class="tapin-price-public-page">
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php \Tapin\ServicePointLocator\PriceCheck\InterfaceScreen::public_head(); ?>
<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?><title><?php echo esc_html( wp_get_document_title() ); ?></title><?php endif; wp_head(); ?></head>
<body <?php body_class( 'tapin-price-page tapin-price-dedicated-page' ); ?>>
<?php wp_body_open(); while ( have_posts() ) : the_post(); the_content(); endwhile; wp_footer(); ?>
</body></html>
