<?php
/**
 * Audio player markup.
 *
 * Overridable by themes: copy to `wp-content/themes/<theme>/vaani/audio-player.php`.
 *
 * @package Vaani
 *
 * @var array{url:string,mime:string,label:string,title:string} $vaani_audio_player
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $vaani_audio_player['url'] ) ) {
	return;
}
?>
<figure class="vaani-audio-player">
	<figcaption class="vaani-audio-player__title">
		<?php echo esc_html( $vaani_audio_player['title'] ); ?>
		<span class="vaani-audio-player__lang">(<?php echo esc_html( $vaani_audio_player['label'] ); ?>)</span>
	</figcaption>
	<audio class="vaani-audio-player__audio" controls preload="none">
		<source src="<?php echo esc_url( $vaani_audio_player['url'] ); ?>" type="<?php echo esc_attr( $vaani_audio_player['mime'] ); ?>" />
	</audio>
</figure>
