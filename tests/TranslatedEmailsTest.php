<?php

declare(strict_types=1);

// A customer's shop emails go out in the language they shopped in. The order
// remembers that language; the email waits, briefly, for any text the service
// has not translated yet, and is sent in the source language if the wait runs
// out. An email is never lost and never sent half translated.

use TranslateWP\Components\LanguageUrl;
use TranslateWP\Functionality\TranslatedEmails;

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-test-stubs.php';

$failures = 0;

function assert_true( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failures++;
	}
}

class Fake_Order {
	public array $meta    = [];
	public int $saves     = 0;
	public string $first  = 'Ana';
	public string $number = '4987';
	public array $items   = [];
	public function get_billing_first_name(): string {
		return $this->first;
	}
	public function get_order_number(): string {
		return $this->number;
	}
	public function get_shipping_state(): string {
		return 'OR';
	}
	public function get_shipping_country(): string {
		return 'US';
	}
	public function get_formatted_shipping_address(): string {
		return "Ana Lee<br/>12 Market Street<br/>Portland, OR 97201<br/>United States (US)";
	}
	public function get_items(): array {
		return $this->items;
	}
	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}
	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}
	public function save(): void {
		$this->saves++;
	}
}

class Fake_Item {
	private string $name;
	public function __construct( string $name ) {
		$this->name = $name;
	}
	public function get_name(): string {
		return $this->name;
	}
}

class Fake_Email {
	public $object;
	public bool $customer = true;
	public string $type   = 'text/html';
	public function __construct( $object = null ) {
		$this->object = $object;
	}
	public function is_customer_email(): bool {
		return $this->customer;
	}
	public function get_content_type(): string {
		return $this->type;
	}
	public function get_from_address(): string {
		return 'shop@example.com';
	}
	public function get_from_name(): string {
		return 'Market Street Bakery';
	}
}

const HTML = '<html lang="en-US"><head><title>Market Street Bakery</title><style>p{color:#333}</style></head><body><div id="wrapper" dir="ltr"><h1>Thank you for your order</h1><p>Hi Ana,</p><td>Subtotal:</td><td>£4.50</td></div></body></html>';
const GERMAN = [
	'Your order has been received!' => 'Ihre Bestellung ist eingegangen!',
	'Market Street Bakery'          => 'Market Street Bäckerei',
	'Thank you for your order'      => 'Danke für Ihre Bestellung',
	'Hi {{1}},'                     => 'Hallo {{1}},',
	'Subtotal:'                     => 'Zwischensumme:',
];

function seed( array $known = GERMAN ): array {
	wp_test_reset(
		[
			'site'      => [ 'id' => '00000000-0000-4000-8000-000000000001', 'token' => 'site-token' ],
			'languages' => [
				'list' => [
					'en' => [ 'locale' => 'en', 'slug' => 'en', 'active' => true, 'default' => true ],
					'de' => [ 'locale' => 'de', 'slug' => 'de', 'active' => true, 'default' => false ],
					'he' => [ 'locale' => 'he', 'slug' => 'he', 'active' => true, 'default' => false ],
					'fr' => [ 'locale' => 'fr', 'slug' => 'fr', 'active' => false, 'default' => false ],
				],
			],
		]
	);
	$GLOBALS['wp_test_mails']         = [];
	$GLOBALS['wp_test_scheduled_log'] = [];
	$GLOBALS['wp_test_is_admin']      = false;
	$_COOKIE                          = [];
	LanguageUrl::set_requested_locale( '' );

	$calls = (object) [ 'known' => $known, 'requested' => [], 'lookup_fails' => false, 'request_fails' => false ];
	TranslatedEmails::set_services(
		static function ( array $texts, string $locale ) use ( $calls ) {
			if ( $calls->lookup_fails ) {
				return new WP_Error( 'down', 'down' );
			}
			$map = array_intersect_key( $calls->known, array_flip( $texts ) );
			return [ 'map' => $map, 'missing' => array_values( array_diff( $texts, array_keys( $map ) ) ) ];
		},
		static function ( array $texts, string $locale ) use ( $calls ) {
			$calls->requested[] = [ $locale, $texts ];
			return $calls->request_fails ? new WP_Error( 'refused', 'refused' ) : true;
		}
	);

	return [ new TranslatedEmails( 'translatewp', '1.0.0' ), $calls ];
}

function order_in( string $locale ): Fake_Order {
	$order       = new Fake_Order();
	$order->meta = '' === $locale ? [] : [ TranslatedEmails::ORDER_META => $locale ];
	return $order;
}

function send_through( TranslatedEmails $emails, Fake_Email $email, string $subject = 'Your order has been received!', string $message = HTML ) {
	$callback = $emails->mail_callback( 'wp_mail', $email );
	return call_user_func( $callback, 'ana@example.com', $subject, $message, "Content-Type: text/html\r\n", [] );
}

// --- the order remembers the language the visitor is shopping in ---
[ $emails ] = seed();
LanguageUrl::set_requested_locale( 'de' );
$order = new Fake_Order();
$emails->remember_order_language( $order );
assert_true( 'de' === $order->get_meta( TranslatedEmails::ORDER_META ) && 1 === $order->saves, 'an order placed at a language folder address remembers that language' );

[ $emails ] = seed();
$_COOKIE['translatewp_last_choice'] = 'de';
$order = new Fake_Order();
$emails->remember_order_language( $order );
assert_true( 'de' === $order->get_meta( TranslatedEmails::ORDER_META ), 'a request with no language folder (the checkout call) takes the language the visitor last chose' );

foreach ( [ 'en' => 'the default language', 'fr' => 'a language that is not active', 'xx' => 'an unknown language' ] as $choice => $what ) {
	[ $emails ] = seed();
	$_COOKIE['translatewp_last_choice'] = $choice;
	$order = order_in( 'de' );
	$emails->remember_order_language( $order );
	assert_true( '' === $order->get_meta( TranslatedEmails::ORDER_META ), "{$what} leaves the order in the site's language" );
}

[ $emails ] = seed();
$GLOBALS['wp_test_is_admin']        = true;
$_COOKIE['translatewp_last_choice'] = 'de';
$order = new Fake_Order();
$emails->remember_order_language( $order );
assert_true( [] === $order->meta && 0 === $order->saves, 'an order made in the admin does not take the administrator\'s own language' );

// --- an email the service has every text for is sent translated, at once ---
[ $emails, $calls ] = seed();
$sent = send_through( $emails, new Fake_Email( order_in( 'de' ) ) );
$mail = $GLOBALS['wp_test_mails'][0] ?? [];
assert_true( true === $sent && 1 === count( $GLOBALS['wp_test_mails'] ), 'a fully translated email is sent at once' );
assert_true( 'Ihre Bestellung ist eingegangen!' === ( $mail['subject'] ?? '' ), 'the subject is translated, got ' . ( $mail['subject'] ?? '' ) );
assert_true( false !== strpos( $mail['message'] ?? '', '<h1>Danke für Ihre Bestellung</h1><p>Hallo Ana,</p><td>Zwischensumme:</td><td>£4.50</td>' ), 'the body text is translated in place and amounts are left as they are, got ' . ( $mail['message'] ?? '' ) );
assert_true( false !== strpos( $mail['message'] ?? '', '<html lang="de">' ) && false !== strpos( $mail['message'] ?? '', 'p{color:#333}' ), 'the document names its language and its styles are untouched' );
assert_true( 'ana@example.com' === ( $mail['to'] ?? '' ) && false !== strpos( (string) ( $mail['headers'] ?? '' ), 'text/html' ), 'the recipient and headers are kept' );
assert_true( [] === $calls->requested && [] === $GLOBALS['wp_test_scheduled_log'], 'nothing is requested or scheduled for a complete email' );

// --- a bracket left open at the edge of a text stays where it was, whatever the translation did with it ---
[ $emails, $calls ] = seed( [ 'Order' => 'Bestellung', '[Order #{{1}}]' => '[Bestellung #{{1}}]', 'October {{1}}' => '{{1}}. Oktober' ] );
send_through( $emails, new Fake_Email( order_in( 'de' ) ), 'Order', '<html><body><p>[Order #7] (<time>October 6</time>)</p></body></html>' );
assert_true( false !== strpos( $GLOBALS['wp_test_mails'][0]['message'] ?? '', '<p>[Bestellung #7] (<time>6. Oktober</time>)</p>' ), 'an open bracket before a nested element is kept, got ' . ( $GLOBALS['wp_test_mails'][0]['message'] ?? '' ) );

// --- the wording is the same for every order: a second customer needs nothing new ---
[ $emails, $calls ] = seed();
$second         = order_in( 'de' );
$second->first  = 'Bob';
$second->number = '5120';
send_through( $emails, new Fake_Email( $second ), 'Your order has been received!', str_replace( 'Hi Ana,', 'Hi Bob,', HTML ) );
assert_true( false !== strpos( $GLOBALS['wp_test_mails'][0]['message'] ?? '', '<p>Hallo Bob,</p>' ) && [] === $calls->requested, 'another customer\'s email is translated from the same wording, with nothing requested, got ' . ( $GLOBALS['wp_test_mails'][0]['message'] ?? '' ) );

// --- values reach the customer as they were entered; nothing asks for them to be translated ---
[ $emails, $calls ] = seed( [] );
send_through( $emails, new Fake_Email( order_in( 'de' ) ), 'Order #4987', '<html><body><p>Ana</p><p>4987</p><p>Hi Ana, order #4987</p></body></html>' );
assert_true( [ [ 'de', [ 'Order #{{1}}', 'Hi {{1}}, order #{{2}}' ] ] ] === $calls->requested, 'only wording is requested, never a name or a number, got ' . wp_json_encode( $calls->requested ) );

// --- an address's state and country codes and its formatted block are values too ---
[ $emails, $calls ] = seed( [] );
send_through( $emails, new Fake_Email( order_in( 'de' ) ), 'Order #4987', '<html><body><address>Ana Lee<br/>12 Market Street<br/>Portland, OR 97201<br/>United States (US)</address><p>Ships to OR, US soon</p></body></html>' );
assert_true( [ [ 'de', [ 'Order #{{1}}', 'Ships to {{1}}, {{2}} soon' ] ] ] === $calls->requested, 'a state code, a country code and the formatted address are never requested, got ' . wp_json_encode( $calls->requested ) );

// --- a product name uses the translation the site already has, and is never requested ---
[ $emails, $calls ] = seed( [ 'Order' => 'Bestellung', 'Sourdough loaf' => 'Sauerteigbrot', 'Product' => 'Produkt' ] );
$order        = order_in( 'de' );
$order->items = [ new Fake_Item( 'Sourdough loaf' ), new Fake_Item( 'Walnut bread' ) ];
send_through( $emails, new Fake_Email( $order ), 'Order', '<html><body><th>Product</th><td>Sourdough loaf</td><td>Walnut bread</td></body></html>' );
assert_true( false !== strpos( $GLOBALS['wp_test_mails'][0]['message'] ?? '', '<th>Produkt</th><td>Sauerteigbrot</td><td>Walnut bread</td>' ), 'a product name the site has translated is used; one it has not stays as it is, got ' . ( $GLOBALS['wp_test_mails'][0]['message'] ?? '' ) );
assert_true( [] === $calls->requested && 1 === count( $GLOBALS['wp_test_mails'] ), 'an untranslated product name neither holds the email nor starts a translation' );

// --- saving the site's languages sends the wording of the shop's emails ahead, once per language ---
[ $emails, $calls ] = seed( [ 'Subtotal:' => 'Zwischensumme:' ] );
TranslatedEmails::set_services(
	static function ( array $texts, string $locale ) use ( $calls ) {
		$map = 'de' === $locale ? array_intersect_key( $calls->known, array_flip( $texts ) ) : [];
		return [ 'map' => $map, 'missing' => array_values( array_diff( $texts, array_keys( $map ) ) ) ];
	},
	static function ( array $texts, string $locale ) use ( $calls ) {
		$calls->requested[] = [ $locale, $texts ];
		return true;
	},
	static fn() => [
		[ 'Your order #1 has been received!', '<html><body><p>Hi Jo,</p><td>Subtotal:</td><td>Dummy Product</td></body></html>', true, [ 'Jo' ], [ 'Dummy Product' ] ],
		[ 'Your order #1 is complete', "Hi Jo,\nThanks for shopping with us.", false, [ 'Jo' ], [] ],
	]
);
$emails->schedule_prewarm();
assert_true( TranslatedEmails::PREWARM_HOOK === ( $GLOBALS['wp_test_scheduled_log'][0]['hook'] ?? '' ), 'saving the languages schedules the wording to be sent ahead' );
$emails->prewarm();
$wording = [ 'Your order #{{1}} has been received!', 'Hi {{1}},', 'Your order #{{1}} is complete', 'Thanks for shopping with us.' ];
assert_true(
	[ [ 'de', $wording ], [ 'he', array_merge( array_slice( $wording, 0, 2 ), [ 'Subtotal:' ], array_slice( $wording, 2 ) ) ] ] === $calls->requested,
	'each language is sent the wording it lacks: no values, no product names, nothing it already holds, got ' . wp_json_encode( $calls->requested )
);

// --- a right-to-left language turns the document ---
[ $emails ] = seed( array_merge( GERMAN, [] ) );
send_through( $emails, new Fake_Email( order_in( 'he' ) ) );
$message = $GLOBALS['wp_test_mails'][0]['message'] ?? '';
assert_true( false !== strpos( $message, '<html lang="he" dir="rtl">' ) && false !== strpos( $message, 'id="wrapper" dir="rtl"' ), 'a right-to-left language sets the direction, got ' . $message );

// --- a plain-text email is translated line by line ---
[ $emails ] = seed();
$email       = new Fake_Email( order_in( 'de' ) );
$email->type = 'text/plain';
send_through( $emails, $email, 'Your order has been received!', "Thank you for your order\n\n  Hi Ana,\nSubtotal:\n" );
assert_true( "Danke für Ihre Bestellung\n\n  Hallo Ana,\nZwischensumme:\n" === ( $GLOBALS['wp_test_mails'][0]['message'] ?? '' ), 'plain text keeps its lines and spacing, got ' . wp_json_encode( $GLOBALS['wp_test_mails'][0]['message'] ?? '' ) );

// --- emails that are not the customer's, or whose order is in the site's language, are left alone ---
[ $emails ] = seed();
$admin_email           = new Fake_Email( order_in( 'de' ) );
$admin_email->customer = false;
assert_true( 'wp_mail' === $emails->mail_callback( 'wp_mail', $admin_email ), 'the shop\'s own notification stays in the site\'s language' );
assert_true( 'wp_mail' === $emails->mail_callback( 'wp_mail', new Fake_Email( order_in( '' ) ) ), 'an order in the site\'s language is left alone' );
assert_true( 'wp_mail' === $emails->mail_callback( 'wp_mail', new Fake_Email( order_in( 'fr' ) ) ), 'a language that is no longer active is left alone' );

// --- an email with no order (a password reset) goes in the language of the visitor who caused it ---
[ $emails ] = seed();
$_COOKIE['translatewp_last_choice'] = 'de';
send_through( $emails, new Fake_Email( (object) [ 'ID' => 4, 'first_name' => 'Ana' ] ) );
assert_true( 'Ihre Bestellung ist eingegangen!' === ( $GLOBALS['wp_test_mails'][0]['subject'] ?? '' ), 'an account email follows the visitor\'s language' );

// --- text the service does not hold yet: the email waits, the text is requested, delivery is scheduled ---
[ $emails, $calls ] = seed( array_diff_key( GERMAN, [ 'Hi {{1}},' => 1, 'Subtotal:' => 1 ] ) );
$sent = send_through( $emails, new Fake_Email( order_in( 'de' ) ) );
assert_true( true === $sent && [] === $GLOBALS['wp_test_mails'], 'an email with untranslated text is held, and reported as handled' );
assert_true( [ [ 'de', [ 'Hi {{1}},', 'Subtotal:' ] ] ] === $calls->requested, 'only the missing wording is requested, with the customer\'s name taken out, got ' . wp_json_encode( $calls->requested ) );
$scheduled = $GLOBALS['wp_test_scheduled_log'][0] ?? [];
assert_true( TranslatedEmails::DELIVER_HOOK === ( $scheduled['hook'] ?? '' ) && 1 === count( $scheduled['args'] ?? [] ), 'delivery is scheduled with the held email\'s id' );
$id = (string) ( $scheduled['args'][0] ?? '' );

// Still missing: it waits again, and asks for nothing twice.
$emails->deliver( $id );
assert_true( [] === $GLOBALS['wp_test_mails'] && 2 === count( $GLOBALS['wp_test_scheduled_log'] ) && 1 === count( $calls->requested ), 'a held email waits again while its text is still missing' );

// The text arrived: it goes out translated, once, with the shop as sender.
$calls->known = GERMAN;
$emails->deliver( $id );
$mail = $GLOBALS['wp_test_mails'][0] ?? [];
assert_true( 1 === count( $GLOBALS['wp_test_mails'] ) && false !== strpos( $mail['message'] ?? '', '<p>Hallo Ana,</p>' ), 'the held email is sent translated once its text arrives' );
assert_true( 'shop@example.com' === ( $GLOBALS['wp_test_mail_from'] ?? '' ) && 'Market Street Bakery' === ( $GLOBALS['wp_test_mail_from_name'] ?? '' ), 'a held email keeps the shop as its sender' );
$emails->deliver( $id );
assert_true( 1 === count( $GLOBALS['wp_test_mails'] ), 'a delivered email is not sent twice' );

// --- the wait runs out: the email is sent whole in the source language ---
[ $emails, $calls ] = seed( [] );
send_through( $emails, new Fake_Email( order_in( 'de' ) ) );
$id   = (string) $GLOBALS['wp_test_scheduled_log'][0]['args'][0];
$held = get_option( TranslatedEmails::OPTION_PREFIX . $id );
$held['held_at'] = time() - TranslatedEmails::MAX_WAIT_SECONDS - 1;
update_option( TranslatedEmails::OPTION_PREFIX . $id, $held );
$emails->deliver( $id );
$mail = $GLOBALS['wp_test_mails'][0] ?? [];
assert_true( 'Your order has been received!' === ( $mail['subject'] ?? '' ) && HTML === ( $mail['message'] ?? '' ), 'when the wait runs out the original email is sent, unchanged' );
assert_true( false === get_option( TranslatedEmails::OPTION_PREFIX . $id ), 'a sent email is no longer held' );

// --- the service cannot be reached or refuses: the original is sent at once ---
[ $emails, $calls ] = seed();
$calls->lookup_fails = true;
send_through( $emails, new Fake_Email( order_in( 'de' ) ) );
assert_true( HTML === ( $GLOBALS['wp_test_mails'][0]['message'] ?? '' ) && [] === $GLOBALS['wp_test_scheduled_log'], 'a failed lookup sends the original at once' );

[ $emails, $calls ] = seed( [] );
$calls->request_fails = true;
send_through( $emails, new Fake_Email( order_in( 'de' ) ) );
assert_true( HTML === ( $GLOBALS['wp_test_mails'][0]['message'] ?? '' ) && [] === $GLOBALS['wp_test_scheduled_log'], 'a refused translation sends the original at once' );

// --- a held email is sent once, whichever of delivery and the release claims it ---
function hold_expired_email(): array {
	[ $emails ] = seed( [] );
	send_through( $emails, new Fake_Email( order_in( 'de' ) ) );
	$id              = (string) $GLOBALS['wp_test_scheduled_log'][0]['args'][0];
	$name            = TranslatedEmails::OPTION_PREFIX . $id;
	$held            = get_option( $name );
	$held['held_at'] = time() - TranslatedEmails::MAX_WAIT_SECONDS - 1;
	update_option( $name, $held );
	return [ $emails, $id, $name ];
}
$release = [
	'now'         => static fn (): float => 0.0,
	'deadline'    => 10.0,
	'keep_unsent' => true,
];

// Another request takes the email between the read and the claim: neither path sends it.
[ $emails, $id, $name ] = hold_expired_email();
$GLOBALS['wp_test_after_option_read'][ $name ] = static function () use ( $name ): void {
	delete_option( $name );
};
$emails->deliver( $id );
assert_true( [] === $GLOBALS['wp_test_mails'], 'delivery must not send an email another request has already taken' );
[ $emails, $id, $name ] = hold_expired_email();
$GLOBALS['wp_test_after_option_read'][ $name ] = static function () use ( $name ): void {
	delete_option( $name );
};
TranslatedEmails::release_held( $release );
assert_true( [] === $GLOBALS['wp_test_mails'], 'the release must not send an email another request has already taken' );

// Delivery reads the email, the release runs meanwhile: one send.
[ $emails, $id, $name ] = hold_expired_email();
$GLOBALS['wp_test_after_option_read'][ $name ] = static function () use ( $release ): void {
	TranslatedEmails::release_held( $release );
};
$emails->deliver( $id );
assert_true( 1 === count( $GLOBALS['wp_test_mails'] ), 'delivery racing the release must send the email once, sent ' . count( $GLOBALS['wp_test_mails'] ) );

// The release reads the email, delivery runs meanwhile: one send.
[ $emails, $id, $name ] = hold_expired_email();
$GLOBALS['wp_test_after_option_read'][ $name ] = static function () use ( $emails, $id ): void {
	$emails->deliver( $id );
};
TranslatedEmails::release_held( $release );
assert_true( 1 === count( $GLOBALS['wp_test_mails'] ), 'the release racing delivery must send the email once, sent ' . count( $GLOBALS['wp_test_mails'] ) );
$GLOBALS['wp_test_after_option_read'] = [];

TranslatedEmails::set_services( null, null );

if ( $failures > 0 ) {
	fwrite( STDERR, "TranslatedEmailsTest: {$failures} assertion(s) failed\n" );
	exit( 1 );
}

echo "TranslatedEmailsTest: all assertions passed\n";
