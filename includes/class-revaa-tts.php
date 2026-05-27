<?php
/**
 * Main class for Revaa TTS plugin.
 *
 * Handles hook registration, asset enqueueing, and widget HTML injection
 * into LifterLMS lesson pages.
 *
 * @package Revaa_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Revaa_TTS
 */
class Revaa_TTS {

	/**
	 * Register all hooks. Bails early if LifterLMS is not active.
	 *
	 * @return void
	 */
	public function init() {
		// Do nothing if LifterLMS is not active.
		if ( ! class_exists( 'LifterLMS' ) ) {
			add_action( 'admin_notices', array( $this, 'admin_notice_lifterlms_missing' ) );
			return;
		}

		// Inject the TTS widget HTML into lesson content.
		add_filter( 'the_content', array( $this, 'inject_widget' ) );

		// Enqueue CSS & JS only on lesson pages.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Display an admin notice when LifterLMS is not active.
	 *
	 * @return void
	 */
	public function admin_notice_lifterlms_missing() {
		echo '<div class="notice notice-warning is-dismissible">'
			. '<p><strong>Revaa TTS :</strong> '
			. esc_html__( 'Ce plugin nécessite LifterLMS pour fonctionner.', 'revaa-tts' )
			. '</p></div>';
	}

	/**
	 * Enqueue stylesheet and script only on LifterLMS lesson singular pages.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_singular( 'llms_lesson' ) ) {
			return;
		}

		// Enqueue the TTS widget stylesheet.
		wp_enqueue_style(
			'revaa-tts-style',
			REVAA_TTS_URL . 'assets/revaa-tts.css',
			array(),
			REVAA_TTS_VERSION
		);

		// Enqueue the TTS widget script (in footer).
		wp_enqueue_script(
			'revaa-tts-script',
			REVAA_TTS_URL . 'assets/revaa-tts.js',
			array(),
			REVAA_TTS_VERSION,
			true
		);

		// Pass translated UI strings to the JS.
		wp_localize_script(
			'revaa-tts-script',
			'revaa_tts_strings',
			array(
				'play'  => __( 'Lire', 'revaa-tts' ),
				'pause' => __( 'Pause', 'revaa-tts' ),
				'stop'  => __( 'Arrêter', 'revaa-tts' ),
				'speed' => __( 'Vitesse', 'revaa-tts' ),
				'voice' => __( 'Voix', 'revaa-tts' ),
			)
		);
	}

	/**
	 * Inject the TTS widget HTML into lesson page content.
	 *
	 * The widget is inserted after the first </h1> or </h2> tag found in the
	 * content. If no heading is found, it is prepended to the content.
	 *
	 * @param  string $content The post content.
	 * @return string Modified content with the TTS widget inserted.
	 */
	public function inject_widget( $content ) {
		// Only act on LifterLMS lesson singular pages.
		if ( ! is_singular( 'llms_lesson' ) ) {
			return $content;
		}

		// Prevent double injection (safety guard).
		if ( false !== strpos( $content, 'id="revaa-tts-widget"' ) ) {
			return $content;
		}

		$widget = $this->get_widget_html();

		// Try to insert after the first </h1> or </h2>.
		foreach ( array( '</h1>', '</h2>' ) as $tag ) {
			$pos = strpos( $content, $tag );
			if ( false !== $pos ) {
				$insert_at = $pos + strlen( $tag );
				return substr( $content, 0, $insert_at ) . $widget . substr( $content, $insert_at );
			}
		}

		// Fallback: prepend the widget at the top of the content.
		return $widget . $content;
	}

	/**
	 * Return the TTS widget HTML string.
	 *
	 * @return string
	 */
	private function get_widget_html() {
		ob_start();
		?>
<div id="revaa-tts-widget" role="region" aria-label="<?php esc_attr_e( 'Lecteur audio de la leçon', 'revaa-tts' ); ?>">
  <button id="revaa-tts-play" aria-label="<?php esc_attr_e( 'Lire', 'revaa-tts' ); ?>">&#9654; <?php esc_html_e( 'Lire', 'revaa-tts' ); ?></button>
  <button id="revaa-tts-stop" aria-label="<?php esc_attr_e( 'Arrêter', 'revaa-tts' ); ?>">&#9209;</button>
  <label for="revaa-tts-speed"><?php esc_html_e( 'Vitesse', 'revaa-tts' ); ?></label>
  <select id="revaa-tts-speed">
    <option value="0.75">0.75&times;</option>
    <option value="1" selected>1&times;</option>
    <option value="1.25">1.25&times;</option>
    <option value="1.5">1.5&times;</option>
    <option value="2">2&times;</option>
  </select>
  <label for="revaa-tts-voice"><?php esc_html_e( 'Voix', 'revaa-tts' ); ?></label>
  <select id="revaa-tts-voice"></select>
  <progress id="revaa-tts-progress" value="0" max="100" aria-label="<?php esc_attr_e( 'Progression de la lecture', 'revaa-tts' ); ?>"></progress>
</div>
		<?php
		return ob_get_clean();
	}
}
