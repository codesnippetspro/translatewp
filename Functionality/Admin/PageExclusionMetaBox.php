<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\PageExclusion;

/**
 * The per-page "exclude from translation" setting, as a side meta box in the
 * editor of every public post type. A meta box shows in the block editor and
 * the classic editor alike.
 */
class PageExclusionMetaBox {

	public const FIELD = 'translatewp_exclude';
	public const NONCE = 'translatewp_exclude_nonce';

	private const NONCE_ACTION = 'translatewp_exclude_save';

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'save_post', [ $this, 'save' ] );
	}

	public function register( $post_type ): void {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		unset( $post_types['attachment'] );

		if ( ! in_array( (string) $post_type, array_map( 'strval', $post_types ), true ) ) {
			return;
		}

		add_meta_box(
			'translatewp-exclude',
			__( 'TranslateWP', 'translate' ),
			[ $this, 'render' ],
			(string) $post_type,
			'side'
		);
	}

	public function render( $post ): void {
		$post_id  = (int) ( $post->ID ?? 0 );
		$excluded = ! empty( get_post_meta( $post_id, PageExclusion::META_KEY, true ) );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE );
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::FIELD ); ?>" value="1" <?php echo $excluded ? 'checked' : ''; ?> />
				<?php esc_html_e( 'Exclude from translation', 'translate' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Visitors see this page in its original language, and its text is never sent to the translation service.', 'translate' ); ?>
		</p>
		<?php
		if ( ! $excluded && PageExclusion::is_excluded( $post_id ) ) {
			?>
			<p class="description">
				<?php esc_html_e( 'Password-protected and private pages are not translated. To translate them, switch that on in TranslateWP Settings, on the Translation tab.', 'translate' ); ?>
			</p>
			<?php
		}
	}

	public function save( $post_id ): void {
		$post_id = (int) $post_id;
		$nonce   = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST[ self::FIELD ] ) ) {
			update_post_meta( $post_id, PageExclusion::META_KEY, 1 );
			return;
		}

		delete_post_meta( $post_id, PageExclusion::META_KEY );
	}
}
