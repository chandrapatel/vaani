<?php
/**
 * Language switcher markup.
 *
 * Overridable by themes: copy to `wp-content/themes/<theme>/vaani/switcher.php`.
 *
 * @package Vaani
 *
 * @var array{aria_label:string,items:array<int,array{label:string,url:string,is_current:bool}>} $vaani_switcher
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $vaani_switcher['items'] ) ) {
	return;
}
?>
<nav class="vaani-language-switcher" aria-label="<?php echo esc_attr( $vaani_switcher['aria_label'] ); ?>">
	<ul class="vaani-language-switcher__list">
		<?php foreach ( $vaani_switcher['items'] as $item ) : ?>
			<li class="vaani-language-switcher__item">
				<?php if ( $item['is_current'] ) : ?>
					<span class="vaani-language-switcher__current" aria-current="true"><?php echo esc_html( $item['label'] ); ?></span>
				<?php else : ?>
					<a class="vaani-language-switcher__link" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
