<?php

namespace TranslateWP\Functionality;

/**
 * Classic widget printing the same inert switcher placeholder.
 */
class SwitcherWidget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'translatewp_language_switcher',
			__( 'TranslateWP', 'translate' ),
			[ 'description' => __( 'TranslateWP language switcher.', 'translate' ) ]
		);

		add_action( 'admin_head-widgets.php', [ $this, 'render_admin_icon' ] );
	}

	public function render_admin_icon(): void {
		?>
		<style>
			.widgets-php .widget[id^="widget-translatewp_language_switcher"] .widget-title::before {
				content: "\\f326";
				font-family: dashicons;
				margin-right: 0.4em;
			}
		</style>
		<?php
	}

	public function widget( $args, $instance ) {
		unset( $instance );
		echo $args['before_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo SwitcherSurfaces::PLACEHOLDER; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ) {
		unset( $instance );
		echo '<p>' . esc_html__( 'The language switcher renders on the front end.', 'translate' ) . '</p>';
	}

	public function update( $new_instance, $old_instance ) {
		unset( $new_instance );
		return $old_instance;
	}
}
