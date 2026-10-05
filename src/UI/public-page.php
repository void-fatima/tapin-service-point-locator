<?php
/** Full-viewport shell for pages containing the public locator shortcode. */
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
		<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php endif; ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'tapin-locator-page' ); ?>>
	<?php wp_body_open(); ?>
	<div class="tapin-locator-page-content">
		<?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
