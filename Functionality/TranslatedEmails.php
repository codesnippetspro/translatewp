<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\EmailTemplate;
use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\PageTextTranslator;
use TranslateWP\Components\TextDirection;
use TranslateWP\Components\TextTranslation;
use TranslateWP\Components\TranslationProxySettings;

/**
 * A WooCommerce customer's emails in the language they shopped in.
 *
 * A page is translated in the visitor's browser; an email never passes
 * through one, so the server translates it. The order remembers the language
 * of the visitor who placed it.
 *
 * An email is fixed wording plus the values of one order. The values (order
 * number, names, addresses, dates, amounts) are taken out of each text and the
 * wording that is left is the same for every order, so it is translated once
 * and found in the service's cache from then on. The wording of WooCommerce's
 * customer emails is sent for translation when the site's languages are
 * saved, so the first order usually finds it there already. No order starts a
 * translation of its own values.
 *
 * When a customer email is sent:
 *
 * - all its wording is in the cache: it goes out translated, at once;
 * - some is missing: that wording is sent for translation and the email is
 *   held, checked again shortly, and sent translated when it arrives;
 * - the wait runs out, or the service cannot be reached: the email goes out
 *   as WooCommerce wrote it.
 *
 * Product names are the site's content: the translation the site already has
 * for one is used, and a name without one stays as it is. An email is never
 * lost. The shop's own notifications stay in the site's language.
 */
class TranslatedEmails {

	public const ORDER_META       = '_translatewp_language';
	public const DELIVER_HOOK     = 'translatewp_deliver_translated_email';
	public const PREWARM_HOOK     = 'translatewp_prewarm_email_wording';
	public const OPTION_PREFIX    = 'translatewp_held_email_';
	public const RETRY_SECONDS    = 15;
	public const MAX_WAIT_SECONDS = 300;
	public const RELEASE_LIMIT    = 200;
	public const RELEASE_SECONDS  = 5;

	private const CHOICE_COOKIE = 'translatewp_last_choice';

	/**
	 * Looks texts up: fn( string[] $texts, string $locale ): array|\WP_Error.
	 *
	 * @var callable|null
	 */
	private static $lookup = null;

	/**
	 * Sends texts for translation: fn( string[] $texts, string $locale ): true|\WP_Error.
	 *
	 * @var callable|null
	 */
	private static $request = null;

	/**
	 * Renders the emails whose wording is sent ahead: fn(): array of
	 * [ subject, message, html, values, names ].
	 *
	 * @var callable|null
	 */
	private static $render = null;

	/**
	 * The email being sent: its language, kind and sender.
	 *
	 * @var array
	 */
	private array $current = [];

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'woocommerce_new_order', [ $this, 'remember_order_language' ], 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'remember_order_language' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'remember_order_language' ] );
		add_filter( 'woocommerce_mail_callback', [ $this, 'mail_callback' ], 10, 2 );
		add_action( self::DELIVER_HOOK, [ $this, 'deliver' ] );
		add_action( 'translatewp_languages_saved', [ $this, 'schedule_prewarm' ] );
		add_action( self::PREWARM_HOOK, [ $this, 'prewarm' ] );
	}

	/** Replaces the lookup and the request for translation. For tests; null restores the service. */
	public static function set_services( ?callable $lookup, ?callable $request, ?callable $render = null ): void {
		self::$lookup  = $lookup;
		self::$request = $request;
		self::$render  = $render;
	}

	/**
	 * Records on the order the language its visitor is shopping in. The hooks
	 * pass the order, or its id first and the order after.
	 *
	 * @param mixed ...$args The hook's arguments.
	 */
	public function remember_order_language( ...$args ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$order = null;
		foreach ( $args as $arg ) {
			if ( is_object( $arg ) && method_exists( $arg, 'update_meta_data' ) ) {
				$order = $arg;
				break;
			}
		}
		if ( null === $order && function_exists( 'wc_get_order' ) && is_numeric( $args[0] ?? null ) ) {
			$order = wc_get_order( (int) $args[0] );
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$locale = $this->visitor_locale();
		if ( $locale === (string) $order->get_meta( self::ORDER_META ) ) {
			return;
		}

		if ( '' === $locale ) {
			$order->delete_meta_data( self::ORDER_META );
		} else {
			$order->update_meta_data( self::ORDER_META, $locale );
		}
		$order->save();
	}

	/**
	 * The translation language of the visitor behind this request, '' for the
	 * site's language: the language folder of the address, or else the
	 * language they last chose (the checkout's own calls carry no folder).
	 */
	public function visitor_locale(): string {
		$locale = LanguageUrl::requested_locale();
		if ( '' === $locale && isset( $_COOKIE[ self::CHOICE_COOKIE ] ) ) {
			$locale = sanitize_text_field( wp_unslash( $_COOKIE[ self::CHOICE_COOKIE ] ) );
		}

		return $this->translation_locale( $locale );
	}

	/**
	 * Hands WooCommerce the sender for this email: this class for a customer
	 * email in a translation language, WooCommerce's own for any other.
	 *
	 * @param callable $callback The function WooCommerce sends with.
	 * @param object   $email    The email being sent.
	 * @return callable
	 */
	public function mail_callback( $callback, $email = null ) {
		if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) || ! $email->is_customer_email() ) {
			return $callback;
		}

		$object = $email->object ?? null;
		$locale = is_object( $object ) && method_exists( $object, 'get_meta' )
			? $this->translation_locale( (string) $object->get_meta( self::ORDER_META ) )
			: ( is_admin() && ! wp_doing_ajax() ? '' : $this->visitor_locale() );
		if ( '' === $locale ) {
			return $callback;
		}

		$this->current = [
			'locale'    => $locale,
			'values'    => self::values_of( $object ),
			'names'     => self::product_names_of( $object ),
			'html'      => 'text/plain' !== ( method_exists( $email, 'get_content_type' ) ? (string) $email->get_content_type() : 'text/html' ),
			'from'      => method_exists( $email, 'get_from_address' ) ? (string) $email->get_from_address() : '',
			'from_name' => method_exists( $email, 'get_from_name' ) ? (string) $email->get_from_name() : '',
		];

		return [ $this, 'send' ];
	}

	/**
	 * Sends the email translated, or holds it until its text is translated.
	 * Takes what wp_mail takes and answers as it does.
	 */
	public function send( $to, $subject, $message, $headers = '', $attachments = [] ): bool {
		$mail          = array_merge(
			$this->current,
			[
				'to'          => $to,
				'subject'     => (string) $subject,
				'message'     => (string) $message,
				'headers'     => $headers,
				'attachments' => $attachments,
				'held_at'     => time(),
			]
		);
		$this->current = [];
		if ( '' === ( $mail['locale'] ?? '' ) ) {
			return (bool) wp_mail( $to, $subject, $message, $headers, $attachments );
		}

		$translated = $this->translated( $mail );
		if ( isset( $translated['message'] ) ) {
			return (bool) wp_mail( $to, $translated['subject'], $translated['message'], $headers, $attachments );
		}

		// Not ready: ask for what is missing and hold the email. A service
		// that cannot be reached or refuses must not delay the email.
		if ( is_wp_error( $translated['error'] ?? null )
			|| is_wp_error( call_user_func( self::$request ?? [ TextTranslation::class, 'request' ], $translated['missing'], $mail['locale'] ) ) ) {
			return (bool) wp_mail( $to, $subject, $message, $headers, $attachments );
		}

		$id = wp_generate_uuid4();
		update_option( self::OPTION_PREFIX . $id, $mail, false );
		$this->schedule( $id );

		return true;
	}

	/** Sends a held email if its text has arrived or its wait has run out; otherwise waits again. */
	public function deliver( $id ): void {
		$id   = sanitize_key( (string) $id );
		$mail = get_option( self::OPTION_PREFIX . $id );
		if ( ! is_array( $mail ) ) {
			return;
		}

		$translated = $this->translated( $mail );
		$ready      = isset( $translated['message'] );
		$expired    = time() - (int) ( $mail['held_at'] ?? 0 ) >= self::MAX_WAIT_SECONDS;
		if ( ! $ready && ! $expired ) {
			$this->schedule( $id );
			return;
		}

		// The delete is the claim: the request whose delete removed the row sends it.
		if ( ! delete_option( self::OPTION_PREFIX . $id ) ) {
			return;
		}

		self::mail_held(
			$mail,
			$ready ? $translated['subject'] : (string) $mail['subject'],
			$ready ? $translated['message'] : (string) $mail['message']
		);
	}

	/**
	 * Sends every email held on the current site as it was written, as when
	 * its wait runs out. Deactivation and uninstall remove the schedule that
	 * would send them. Stops at $run['deadline'] (on the $run['now'] clock),
	 * which the caller shares across the sites of a network. Unsent emails
	 * stay held when $run['keep_unsent'] is true; the log carries counts only.
	 *
	 * @param array{now: callable, deadline: float, keep_unsent: bool} $run Clock, deadline, keep flag.
	 */
	public static function release_held( array $run ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				self::RELEASE_LIMIT
			)
		);
		// phpcs:enable

		$keep    = ! empty( $run['keep_unsent'] );
		$refused = 0;
		$left    = 0;
		foreach ( array_values( $names ) as $index => $name ) {
			if ( (float) call_user_func( $run['now'] ) >= (float) $run['deadline'] ) {
				$left = count( $names ) - $index;
				break;
			}

			$name = (string) $name;
			$mail = get_option( $name );
			// The delete is the claim, as in deliver(): only one request sends a held email.
			if ( ! is_array( $mail ) || ! delete_option( $name ) ) {
				continue;
			}
			if ( self::mail_held( $mail, (string) ( $mail['subject'] ?? '' ), (string) ( $mail['message'] ?? '' ) ) ) {
				continue;
			}

			++$refused;
			if ( $keep ) {
				add_option( $name, $mail, '', false );
			}
		}

		$fate = $keep ? 'stay held and unsent' : 'were not sent';
		if ( $refused > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'TranslateWP: the mailer refused %d held emails; they %s.', $refused, $fate ) );
			\TranslateWP\Components\ActionLog::record( sprintf( 'The mailer refused %d held emails', $refused ), 'error' );
		}
		if ( $left > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'TranslateWP: time limit reached, %d held emails %s.', $left, $fate ) );
		}
		if ( 0 === $left && count( $names ) >= self::RELEASE_LIMIT ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'TranslateWP: more than %d held emails; the rest %s.', self::RELEASE_LIMIT, $fate ) );
		}
	}

	private static function mail_held( array $mail, string $subject, string $message ): bool {
		$from      = static fn( $address ) => '' !== (string) ( $mail['from'] ?? '' ) ? (string) $mail['from'] : $address;
		$from_name = static fn( $name ) => '' !== (string) ( $mail['from_name'] ?? '' ) ? (string) $mail['from_name'] : $name;
		add_filter( 'wp_mail_from', $from );
		add_filter( 'wp_mail_from_name', $from_name );
		$sent = (bool) wp_mail(
			$mail['to'] ?? '',
			$subject,
			$message,
			$mail['headers'] ?? '',
			$mail['attachments'] ?? []
		);
		remove_filter( 'wp_mail_from', $from );
		remove_filter( 'wp_mail_from_name', $from_name );

		return $sent;
	}

	/**
	 * The email's subject and message in its language, when all its wording
	 * has a translation; otherwise the wording that is missing, or the error
	 * of the lookup.
	 *
	 * @return array{subject: string, message: string}|array{missing: string[]}|array{error: \WP_Error}
	 */
	private function translated( array $mail ) {
		$html   = ! empty( $mail['html'] );
		$parsed = self::wording( (string) $mail['subject'], (string) $mail['message'], $html, (array) ( $mail['values'] ?? [] ), (array) ( $mail['names'] ?? [] ) );
		$names  = array_values( array_unique( array_merge( ...array_values( array_column( $parsed['texts'], 'names' ) ), ...[ [] ] ) ) );
		$found  = call_user_func( self::$lookup ?? [ TextTranslation::class, 'lookup' ], array_merge( $parsed['templates'], $names ), $mail['locale'] );
		if ( is_wp_error( $found ) ) {
			return [ 'error' => $found ];
		}

		$known   = (array) ( $found['map'] ?? [] );
		$missing = array_values( array_diff( $parsed['templates'], array_keys( $known ) ) );
		if ( $missing ) {
			return [ 'missing' => $missing ];
		}

		$map = [];
		foreach ( $parsed['texts'] as $text => $part ) {
			if ( $part['names'] ) {
				// A product name: the site's own translation of it, when it has one.
				$map[ $text ] = strtr( (string) $text, array_intersect_key( $known, array_flip( $part['names'] ) ) );
				continue;
			}
			$filled       = null === $part['template'] ? null : EmailTemplate::fill( (string) ( $known[ $part['template'] ] ?? '' ), $part['values'] );
			$map[ $text ] = null === $filled ? (string) $text : $part['before'] . $filled . $part['after'];
		}

		if ( $html ) {
			$message = $this->in_language( PageTextTranslator::translate( $mail['message'], $map )['html'], $mail['locale'] );
		} else {
			$message = (string) preg_replace_callback(
				'/[^\r\n]+/',
				static function ( array $line ) use ( $map ): string {
					$text = trim( $line[0] );
					return '' === $text || ! isset( $map[ $text ] ) ? $line[0] : str_replace( $text, $map[ $text ], $line[0] );
				},
				$mail['message']
			);
		}

		return [
			'subject' => (string) ( $map[ trim( $mail['subject'] ) ] ?? $mail['subject'] ),
			'message' => $message,
		];
	}

	/**
	 * The texts of an email, each split into its values and its wording.
	 *
	 * @param string   $subject The subject line.
	 * @param string   $message The body.
	 * @param bool     $html    Whether the body is HTML.
	 * @param string[] $values  Values of this message: names, address lines.
	 * @param string[] $names   Product names in this message.
	 * @return array{texts: array<string, array{before: string, after: string, template: ?string, values: string[], names: string[]}>, templates: string[]}
	 *               `template` is null for a text with nothing to translate; `names` lists the product names a text holds.
	 */
	private static function wording( string $subject, string $message, bool $html, array $values, array $names ): array {
		$texts = array_merge( [ $subject ], $html ? PageTextTranslator::translate( $message, [] )['missing_texts'] : (array) preg_split( '/\R/', $message ) );
		$texts = array_values( array_unique( array_filter( array_map( 'trim', $texts ), 'strlen' ) ) );
		$names = array_values( array_filter( array_unique( array_map( 'trim', $names ) ), 'strlen' ) );

		$parsed    = [];
		$templates = [];
		foreach ( $texts as $text ) {
			[ $before, $core, $after ] = self::edges( $text );
			$held = array_values( array_filter( $names, static fn( string $name ): bool => false !== strpos( $core, $name ) ) );
			$mask = EmailTemplate::mask( $core, $values );
			$word = ! $held && EmailTemplate::has_wording( $mask['template'] );
			if ( $word ) {
				$templates[] = $mask['template'];
			}
			$parsed[ $text ] = [
				'before'   => $before,
				'after'    => $after,
				'template' => $word ? $mask['template'] : null,
				'values'   => $mask['values'],
				'names'    => $held,
			];
		}

		return [
			'texts'     => $parsed,
			'templates' => array_values( array_unique( $templates ) ),
		];
	}

	/** Asks for the wording of the shop's customer emails once the site's languages are saved. */
	public function schedule_prewarm(): void {
		if ( ! class_exists( 'WooCommerce' ) && null === self::$render ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::PREWARM_HOOK, [], 'translatewp' );
			return;
		}

		wp_schedule_single_event( time(), self::PREWARM_HOOK );
	}

	/**
	 * Sends the wording of the shop's customer emails for translation in
	 * every translation language, leaving out what the cache already holds.
	 */
	public function prewarm(): void {
		$templates = [];
		foreach ( (array) call_user_func( self::$render ?? [ self::class, 'preview_emails' ] ) as $mail ) {
			[ $subject, $message, $html, $values, $names ] = array_pad( (array) $mail, 5, [] );
			$templates = array_merge( $templates, self::wording( (string) $subject, (string) $message, (bool) $html, (array) $values, (array) $names )['templates'] );
		}
		$templates = array_values( array_unique( $templates ) );
		if ( ! $templates ) {
			return;
		}

		$default = TranslationProxySettings::default_locale();
		foreach ( TranslationProxySettings::active_lookup_languages() as $locale ) {
			if ( $locale === $default ) {
				continue;
			}
			$found = call_user_func( self::$lookup ?? [ TextTranslation::class, 'lookup' ], $templates, $locale );
			if ( is_wp_error( $found ) || empty( $found['missing'] ) ) {
				continue;
			}
			call_user_func( self::$request ?? [ TextTranslation::class, 'request' ], array_values( (array) $found['missing'] ), $locale );
		}
	}

	/**
	 * WooCommerce's customer emails as it previews them, with its sample order.
	 *
	 * @return array<int, array{0: string, 1: string, 2: bool, 3: string[], 4: string[]}>
	 */
	public static function preview_emails(): array {
		$preview_class = '\\Automattic\\WooCommerce\\Internal\\Admin\\EmailPreview\\EmailPreview';
		if ( ! function_exists( 'WC' ) || ! class_exists( $preview_class ) ) {
			return [];
		}

		$mails = [];
		foreach ( (array) WC()->mailer()->get_emails() as $class => $email ) {
			if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) || ! $email->is_customer_email() ) {
				continue;
			}
			try {
				$preview = $preview_class::instance();
				$preview->set_email_type( (string) $class );
				$message = (string) $preview->render();
				$subject = (string) $preview->get_subject();
				$object  = $preview->get_email()->object ?? null;
				$mails[] = [ $subject, $message, 'text/plain' !== (string) $preview->get_email()->get_content_type(), self::values_of( $object ), self::product_names_of( $object ) ];
			} catch ( \Throwable $error ) {
				// An email WooCommerce cannot preview is translated when it is first sent.
				continue;
			}
		}

		return $mails;
	}

	/**
	 * The values of the order or account an email is about: what must reach
	 * the customer exactly as it was entered.
	 *
	 * @param mixed $object The email's order, user or other object.
	 * @return string[]
	 */
	private static function values_of( $object ): array {
		if ( ! is_object( $object ) ) {
			return [];
		}

		$values = [];
		foreach ( [ 'billing', 'shipping' ] as $kind ) {
			// The whole formatted block first, so a line such as "Portland, OR 97201"
			// is one value and its state code never reads as a word.
			$formatted = "get_formatted_{$kind}_address";
			if ( method_exists( $object, $formatted ) ) {
				$lines = preg_split( '/<br\s*\/?>|\n/i', (string) $object->$formatted() );
				foreach ( is_array( $lines ) ? $lines : [] as $line ) {
					$values[] = wp_strip_all_tags( $line );
				}
			}
			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ] as $field ) {
				$getter = "get_{$kind}_{$field}";
				if ( method_exists( $object, $getter ) ) {
					$values[] = (string) $object->$getter();
				}
			}
			$full = "get_formatted_{$kind}_full_name";
			if ( method_exists( $object, $full ) ) {
				$values[] = (string) $object->$full();
			}
		}
		foreach ( [ 'get_order_number', 'get_customer_note' ] as $getter ) {
			if ( method_exists( $object, $getter ) ) {
				$values[] = (string) $object->$getter();
			}
		}
		foreach ( [ 'display_name', 'user_login', 'first_name', 'last_name', 'user_email' ] as $property ) {
			if ( isset( $object->$property ) && is_scalar( $object->$property ) ) {
				$values[] = (string) $object->$property;
			}
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $values ), 'strlen' ) ) );
	}

	/**
	 * The names of the products an order holds.
	 *
	 * @param mixed $object The email's order.
	 * @return string[]
	 */
	private static function product_names_of( $object ): array {
		if ( ! is_object( $object ) || ! method_exists( $object, 'get_items' ) ) {
			return [];
		}

		$names = [];
		foreach ( (array) $object->get_items() as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			if ( method_exists( $item, 'get_name' ) ) {
				$names[] = (string) $item->get_name();
			}
			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			if ( is_object( $product ) && method_exists( $product, 'get_title' ) ) {
				$names[] = (string) $product->get_title();
			}
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $names ), 'strlen' ) ) );
	}

	/** The document with its language named, and turned for a right-to-left language. */
	private function in_language( string $html, string $locale ): string {
		$rtl  = TextDirection::is_rtl( $locale );
		$html = (string) preg_replace_callback(
			'/<html\b[^>]*>/i',
			static function ( array $tag ) use ( $locale, $rtl ): string {
				$attributes = (string) preg_replace( '/\s+(?:lang|dir)\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', substr( $tag[0], 5, -1 ) );
				return '<html lang="' . esc_attr( $locale ) . '"' . ( $rtl ? ' dir="rtl"' : '' ) . $attributes . '>';
			},
			$html,
			1
		);

		return $rtl ? (string) preg_replace( '/\bdir\s*=\s*(["\'])ltr\1/i', 'dir="rtl"', $html ) : $html;
	}

	/**
	 * Splits a text into the brackets left open or closed at its edges and the
	 * text between them. Only the middle is translated: "[Order #7] (" before
	 * a date keeps its bracket whatever the translation does.
	 *
	 * @return array{0: string, 1: string, 2: string} Before, text, after.
	 */
	private static function edges( string $text ): array {
		if ( ! preg_match( '/^([)\]}]+\s*)?(.*?)(\s*[(\[{]+)?$/us', $text, $parts ) ) {
			return [ '', $text, '' ];
		}

		return [ (string) ( $parts[1] ?? '' ), (string) ( $parts[2] ?? '' ), (string) ( $parts[3] ?? '' ) ];
	}

	/** The locale when it is an active translation language, '' otherwise. */
	private function translation_locale( string $locale ): string {
		$locale = trim( $locale );

		return '' !== $locale
			&& TranslationProxySettings::default_locale() !== $locale
			&& TranslationProxySettings::is_configured_language( $locale )
			? $locale
			: '';
	}

	private function schedule( string $id ): void {
		$at = time() + self::RETRY_SECONDS;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $at, self::DELIVER_HOOK, [ $id ], 'translatewp' );
			return;
		}

		wp_schedule_single_event( $at, self::DELIVER_HOOK, [ $id ] );
	}
}
