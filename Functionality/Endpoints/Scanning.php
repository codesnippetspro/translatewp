<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Functionality\Endpoints\BaseApiEndpoints;
use TranslateWP\Components\Options;
use TranslateWP\Components\PageExclusion;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\RemoteApi;
use TranslateWP\Components\SiteRequests;
use TranslateWP\Components\Sites;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Entities\CPTs\Lexicon;

use WP_Error;

class Scanning extends BaseApiEndpoints {

    private const SAMPLE_QUERY_SIZE = 12;
    // The front page plus the newest and the oldest SAMPLE_QUERY_SIZE items.
    public const SCAN_PAGE_CAP = 25;
    public const STYLE_PRESETS = [ 'marketing', 'neutral', 'medical', 'technical', 'legal' ];
    public const TONE_PRESETS  = [ 'neutral', 'friendly', 'casual', 'professional', 'formal' ];
    // A scan of a large site takes the model a while; the request waits for it.
    private const SCAN_TIMEOUT_SECONDS = 90;
    public const SCAN_HOOK = 'translatewp_context_scan_run';
    private const SCAN_STATUS_OPTION = 'translations.context.scan_status';
    private const SCAN_RESULT_OPTION = 'translations.context.scan_result';
    private const SCAN_RETRY_OPTION = 'translations.context.scan_retried';
    private const SCAN_PROGRESS_OPTION = 'translations.context.scan_progress';
    private const SCAN_LOCK_TRANSIENT  = 'translatewp_context_scan_lock';
    private const SCAN_LOCK_SECONDS    = 150;
    // Each sample is cut to 8 KB and the whole payload to 48 KB (about 12k
    // prompt tokens), so the model answers well inside the request timeout.
    public const SCAN_SAMPLE_MAX_BYTES  = 8192;
    public const SCAN_PAYLOAD_MAX_BYTES = 49152;
    private const SELF_CHECK_TIMEOUT_SECONDS = 5;

    private bool $retry_scheduled = false;

    public function __construct( $plugin_name, $plugin_version ) {
        parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/scanning' );

        add_action( self::SCAN_HOOK, [ $this, 'run_scan' ] );
    }

    public function define_endpoints() {
        $cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

        $context = new PostEndpoint(
            $this->namespace,
            'context',
            [ $this, 'scan_context' ],
            [ $this, 'admin_permissions_check' ]
        );
        $context->useMiddleware( $cors );

        $status = new GetEndpoint(
            $this->namespace,
            'context/status',
            [ $this, 'scan_status' ],
            [ $this, 'admin_permissions_check' ]
        );
        $status->useMiddleware( $cors );

        $apply_lexicon = new PostEndpoint(
            $this->namespace,
            'context/apply-lexicon',
            [ $this, 'apply_scan_lexicon' ],
            [ $this, 'admin_write_permissions_check' ]
        );
        $apply_lexicon->useMiddleware( $cors );

        return [
            $context,
            $status,
            $apply_lexicon,
        ];
    }

    /**
     * The lexicon terms the last scan proposed land only when a Pro owner
     * asks; a Free site cannot apply them.
     */
    public function apply_scan_lexicon( $request ) {
        unset( $request );

        if ( ! self::lexicon_proposals_allowed() ) {
            return new WP_Error(
                'pro_required',
                'Suggested lexicon terms are a Pro feature.',
                [ 'status' => 403 ]
            );
        }

        $result = Options::get( self::SCAN_RESULT_OPTION, null );
        $terms  = is_array( $result ) && is_array( $result['lexicon'] ?? null ) ? $result['lexicon'] : [];

        $added = $this->populate_lexicon( $terms );

        $result['lexicon_applied'] = true;
        Options::update( self::SCAN_RESULT_OPTION, $result );

        return [
            'added' => $added,
        ];
    }

    public function scan_context( $request ) {
        unset( $request );

        $samples = $this->collect_samples();

        if ( empty( $samples ) ) {
            return new WP_Error(
                'no_scannable_content',
                'No published site content was found to scan.',
                [ 'status' => self::HTTP_UNPROCESSABLE_ENTITY ]
            );
        }

        Options::update( self::SCAN_STATUS_OPTION, 'pending' );
        Options::update( self::SCAN_RESULT_OPTION, null );
        Options::update( self::SCAN_RETRY_OPTION, false );
        Options::update( 'translations.context.scan_reason', '' );
        Options::update(
            self::SCAN_PROGRESS_OPTION,
            [
				'phase' => 'collecting',
				'sampled' => 0,
				'total' => 0,
				'started' => time(),
			]
        );

        if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
            wp_schedule_single_event( time(), self::SCAN_HOOK );
        }
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }

        return [ 'status' => 'pending' ];
    }

    /**
     * The scan's term proposals reach only a Pro site.
     */
    private static function lexicon_proposals_allowed(): bool {
        return (bool) ProEligibility::cached()['entitled'];
    }

    /** The scan's lexicon candidates reduced to what the review shows. */
    private function suggested_terms( $lexicon ): array {
        $terms = [];
        foreach ( is_array( $lexicon ) ? $lexicon : [] as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $term = sanitize_text_field( (string) ( $entry['term'] ?? '' ) );
            if ( '' === $term ) {
                continue;
            }
            $terms[] = [
                'term'        => $term,
                'description' => sanitize_textarea_field( (string) ( $entry['description'] ?? '' ) ),
                'category'    => sanitize_text_field( (string) ( $entry['category'] ?? '' ) ),
            ];
        }

        return $terms;
    }

    public function scan_status( $request ) {
        unset( $request );

        $status = Options::get( self::SCAN_STATUS_OPTION, 'pending' );

        if ( 'pending' === $status ) {
            \TranslateWP\Components\CronKick::maybe_run_due( self::SCAN_HOOK, [ $this, 'run_scan' ] );
            $status = Options::get( self::SCAN_STATUS_OPTION, 'pending' );
        }

        $result = Options::get( self::SCAN_RESULT_OPTION, null );

        if ( 'done' === $status && is_array( $result ) ) {
            return [
                'status'     => 'done',
                'applied'    => (bool) ( $result['applied'] ?? true ),
                'context'    => (string) ( $result['context'] ?? '' ),
                'language'   => (string) ( $result['language'] ?? '' ),
                'style'      => (string) ( $result['style'] ?? '' ),
                'tone'       => self::offered_tone( (string) ( $result['tone'] ?? '' ) ),
                'formality'  => (int) ( $result['formality'] ?? 5 ),
                'creativity' => (int) ( $result['creativity'] ?? 5 ),
                // The scan proposes terms to Pro only; they land only
                // through apply-lexicon.
                'lexiconSuggestions' => ( ! self::lexicon_proposals_allowed() || ! empty( $result['lexicon_applied'] ) )
                    ? []
                    : $this->suggested_terms( $result['lexicon'] ?? [] ),
            ];
        }

        if ( 'failed' === $status ) {
            return [
                'status' => 'failed',
                'reason' => (string) Options::get( 'translations.context.scan_reason', '' ),
            ];
        }

        $progress = Options::get( self::SCAN_PROGRESS_OPTION, [] );
        $progress = is_array( $progress ) ? $progress : [];
        $started  = (int) ( $progress['started'] ?? 0 );

        return [
            'status'         => 'pending',
            'phase'          => (string) ( $progress['phase'] ?? 'collecting' ),
            'sampled'        => (int) ( $progress['sampled'] ?? 0 ),
            'total'          => (int) ( $progress['total'] ?? 0 ),
            'elapsedSeconds' => $started > 0 ? max( 0, time() - $started ) : 0,
        ];
    }

    public function run_scan(): void {
        // One runner at a time: the scheduled event and the status route's
        // inline run must not analyse the same site twice.
        if ( get_transient( self::SCAN_LOCK_TRANSIENT ) ) {
            return;
        }
        set_transient( self::SCAN_LOCK_TRANSIENT, 1, self::SCAN_LOCK_SECONDS );

        $this->retry_scheduled = false;
        try {
            $this->run_scan_locked();
        } finally {
            delete_transient( self::SCAN_LOCK_TRANSIENT );
        }

        // Spawned only once the lock is released, so the retry is not turned
        // away by the run that scheduled it.
        if ( $this->retry_scheduled && function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    private function progress( string $phase, int $sampled, int $total ): void {
        $current = Options::get( self::SCAN_PROGRESS_OPTION, [] );
        $started = is_array( $current ) ? (int) ( $current['started'] ?? 0 ) : 0;
        Options::update(
            self::SCAN_PROGRESS_OPTION,
            [
                'phase'   => $phase,
                'sampled' => $sampled,
                'total'   => $total,
                'started' => $started > 0 ? $started : time(),
            ]
        );
    }

    private function run_scan_locked(): void {
        $samples = $this->collect_samples(
            function ( int $sampled, int $total ): void {
                $this->progress( 'collecting', $sampled, $total );
            }
        );
        if ( empty( $samples ) ) {
            Options::update( self::SCAN_STATUS_OPTION, 'failed' );
            Options::update( 'translations.context.scan_reason', 'no_scannable_content' );
            return;
        }

        $body = apply_filters(
            'translatewp_context_scan_request_body',
            [
				'samples'      => $this->bounded_samples( $samples ),
				'site_private' => $this->site_private_hint(),
			]
        );

        $this->progress( 'analysing', count( $samples ), count( $samples ) );

        // The scan is a site request like every other call to the service:
        // it carries the site's credentials and the metering headers.
        $scan_result = SiteRequests::post( '/v1/scanning/context', $body, self::SCAN_TIMEOUT_SECONDS );

        if ( is_wp_error( $scan_result ) ) {
            [ $reason, $status ] = $this->classify_scan_failure( $scan_result );
            $this->fail_scan( $reason, $status );
            return;
        }

        if ( ! $this->is_valid_scan_result( $scan_result ) ) {
            $this->fail_scan( 'invalid_scan_response' );
            return;
        }

        $scanned_context    = sanitize_textarea_field( (string) $scan_result['context'] );
        $scanned_style      = sanitize_text_field( (string) $scan_result['style'] );
        $scanned_tone       = self::offered_tone( sanitize_text_field( (string) $scan_result['tone'] ) );
        $scanned_formality  = (int) $scan_result['formality'];
        $scanned_creativity = (int) $scan_result['creativity'];

        $scanned_formality  = max( 0, min( 10, $scanned_formality ) );
        $scanned_creativity = max( 0, min( 10, $scanned_creativity ) );

        $scan_result['context']    = $scanned_context;
        $scan_result['language']   = self::language_tag( $scan_result['language'] ?? '' );
        $scan_result['style']      = $scanned_style;
        $scan_result['tone']       = $scanned_tone;
        $scan_result['formality']  = $scanned_formality;
        $scan_result['creativity'] = $scanned_creativity;

        // The scan applies its context and style only while the site holds
        // no translations. Once pages are translated it only fills the form;
        // the owner saves it through the retranslation confirmation. It never
        // adds lexicon terms by itself: on Pro they are proposed and land
        // only from the review.
        $apply                  = ! TranslationStore::has_translations();
        $scan_result['applied'] = $apply;

        Options::update( 'translations.context.last_scan_time', time() );

        if ( $apply ) {
            Options::update( 'translations.context.text_context', $scanned_context );
            Options::update( 'translations.style.type', $scanned_style );
            Options::update( 'translations.style.tone', $scanned_tone );
            Options::update( 'translations.style.formality', $scanned_formality );
            Options::update( 'translations.style.creativity', $scanned_creativity );
        }

        Options::update( self::SCAN_RESULT_OPTION, $scan_result );
        Options::update( self::SCAN_STATUS_OPTION, 'done' );
        Options::update( 'translations.context.scan_reason', '' );
    }

    /**
     * The failure the plugin reports from a service error. No HTTP status is a
     * transport failure (no answer); a service or provider error and a 5xx read
     * as the service being unavailable; the API's own failure code is
     * authoritative when it sends one.
     *
     * @return array{0:string,1:int} The reason and the HTTP status (0 = none).
     */
    private function classify_scan_failure( \WP_Error $error ): array {
        $data    = $error->get_error_data();
        $data    = is_array( $data ) ? $data : [];
        $status  = (int) ( $data['status'] ?? 0 );
        $payload = is_array( $data['payload'] ?? null ) ? $data['payload'] : [];
        $code    = is_string( $payload['code'] ?? null ) ? $payload['code'] : '';

        if ( ! empty( $data['transport'] ) || 0 === $status ) {
            $reason = 'context_scan_unreachable';
        } elseif ( 'scan_timeout' === $code || 504 === $status ) {
            $reason = 'context_scan_timeout';
        } elseif ( 'scan_output_unusable' === $code ) {
            $reason = 'invalid_scan_response';
        } elseif ( 'scan_service_unavailable' === $code || $status >= 500 ) {
            $reason = 'context_scan_service_unavailable';
        } elseif ( $status >= 400 ) {
            $reason = 'invalid_scan_response';
        } else {
            $reason = 'context_scan_service_unavailable';
        }

        return [ $reason, $status ];
    }

    /**
     * One retry on transient failure (mirrors SiteLanguageSync semantics);
     * a second failure marks the scan failed permanently. The retry is due at
     * once: the wizard is waiting, and on a host without a working cron
     * loopback the status route runs it once it is overdue.
     */
    private function fail_scan( string $reason, int $status = 0 ): void {
        \TranslateWP\Components\ActionLog::record(
            sprintf( 'Site context scan failed: %s%s', $reason, $status > 0 ? ' (HTTP ' . $status . ')' : '' ),
            'error'
        );
        if ( ! Options::get( self::SCAN_RETRY_OPTION, false ) ) {
            Options::update( self::SCAN_RETRY_OPTION, true );
            if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
                wp_schedule_single_event( time(), self::SCAN_HOOK );
            }
            $this->retry_scheduled = true;
            return;
        }

        Options::update( self::SCAN_STATUS_OPTION, 'failed' );
        Options::update( 'translations.context.scan_reason', $reason );
    }

    /**
     * The site's sample texts for the scan.
     *
     * @param callable|null $progress Called with (sampled, total) as posts are read.
     */
    private function collect_samples( ?callable $progress = null ): array {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        unset( $post_types['attachment'] );

        // Private pages are read only when the site translates them.
        $statuses      = PageExclusion::protected_pages_translated() ? [ 'publish', 'private' ] : [ 'publish' ];
        $posts         = [];
        $front_page_id = (int) get_option( 'page_on_front' );
        $front_page    = $front_page_id ? get_post( $front_page_id ) : null;

        if ( $front_page && in_array( $front_page->post_status, $statuses, true ) ) {
            $posts[] = $front_page;
        }

        $query = PageExclusion::protected_pages_translated() ? [] : [ 'has_password' => false ];

        foreach ( [ 'DESC', 'ASC' ] as $order ) {
            $posts = array_merge(
                $posts,
                get_posts(
                    $query + [
						'post_type'        => array_values( $post_types ),
						'post_status'      => $statuses,
						'numberposts'      => self::SAMPLE_QUERY_SIZE,
						'orderby'          => 'date',
						'order'            => $order,
						'suppress_filters' => false,
					]
                )
            );
        }

        $unique = [];
        foreach ( $posts as $post ) {
            if ( PageExclusion::is_excluded( (int) $post->ID ) ) {
                continue;
            }
            $unique[ $post->ID ] = $post;
        }
        // The queries run with other plugins' filters on, so the cap is
        // applied here and never left to the query size alone.
        $unique = array_slice( $unique, 0, self::SCAN_PAGE_CAP, true );
        $total  = count( $unique );

        $samples = [];
        $read    = 0;
        foreach ( $unique as $post ) {
            ++$read;
            if ( null !== $progress ) {
                $progress( $read, $total );
            }

            $sample = trim(
                wp_strip_all_tags(
                    implode(
                        "\n\n",
                        array_filter(
                            [
								\TranslateWP\Components\PlainText::title( $post ),
								$post->post_excerpt,
								$post->post_content,
							]
                        )
                    )
                )
            );

            if ( '' !== $sample ) {
                $samples[ $sample ] = $sample;
            }
        }

        return array_values( $samples );
    }

    /**
     * Cap each sample and the total payload so a content-heavy site cannot
     * slow the model down. A long sample is cut, never dropped: a front page
     * larger than the cap still reaches the scan. The pages share the total:
     * each may use an even part of what is left, so many long pages are all
     * read in part and a few long pages are read up to the sample cap.
     */
    private function bounded_samples( array $samples ): array {
        $bounded   = [];
        $remaining = self::SCAN_PAYLOAD_MAX_BYTES;
        $left      = count( $samples );

        foreach ( $samples as $sample ) {
            if ( $remaining <= 0 ) {
                break;
            }
            $sample = (string) $sample;
            $limit  = min( self::SCAN_SAMPLE_MAX_BYTES, intdiv( $remaining, max( 1, $left ) ) );
            --$left;
            if ( strlen( $sample ) > $limit ) {
                // mb_strcut never splits a multibyte character.
                $sample = trim( mb_strcut( $sample, 0, $limit, 'UTF-8' ) );
            }
            if ( '' === $sample ) {
                continue;
            }
            $bounded[]  = $sample;
            $remaining -= strlen( $sample );
        }

        return $bounded;
    }

    /**
     * Quick self-check: if the site cannot fetch its own home URL (ZT-gated,
     * loopback broken, or redirected to an auth host), the API's crawler
     * cannot either — tell it to use the locally-collected samples.
     */
    private function site_private_hint(): bool {
        $response = wp_remote_get(
            home_url( '/' ),
            [
                'blocking' => true,
                'timeout'  => self::SELF_CHECK_TIMEOUT_SECONDS,
                'redirection' => 0,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return true;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 400 ) {
            return true;
        }

        $location = (string) wp_remote_retrieve_header( $response, 'location' );
        if ( '' !== $location ) {
            $host = (string) wp_parse_url( $location, PHP_URL_HOST );
            if ( '' !== $host && (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) !== $host ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Formal is no longer offered as a tone; a scan that proposes it is
     * offered as Neutral, and the formality it proposes is kept apart.
     */
    public static function offered_tone( string $tone ): string {
        return 'formal' === $tone ? 'neutral' : $tone;
    }

    private function is_valid_scan_result( $scan_result ): bool {
        if ( ! is_array( $scan_result ) ) {
            return false;
        }

        $valid_styles = self::STYLE_PRESETS;
        $valid_tones  = self::TONE_PRESETS;

        return isset(
            $scan_result['context'],
            $scan_result['style'],
            $scan_result['tone'],
            $scan_result['formality'],
            $scan_result['creativity']
        )
            && is_string( $scan_result['context'] )
            && in_array( $scan_result['style'], $valid_styles, true )
            && in_array( $scan_result['tone'], $valid_tones, true )
            && is_numeric( $scan_result['formality'] )
            && is_numeric( $scan_result['creativity'] )
            && ( ! isset( $scan_result['lexicon'] ) || is_array( $scan_result['lexicon'] ) );
    }

    /**
     * Create lexicon terms (with their category) from the scan result.
     *
     * Runs only when a Pro owner applies the proposed terms from the review
     * (apply-lexicon); the scan itself never calls it. Existing terms with the same title are skipped so re-scanning does
     * not create duplicates. Language scope is set to the locales configured so far and
     * backfilled on setup completion (see Management::finish_setup) once the final
     * language list is known.
     */
    private function populate_lexicon( array $lexicon ): int {
        $added = 0;
        $locales = $this->configured_locales();

        $existing_titles = [];
        foreach ( get_posts(
            [
				'post_type'      => Lexicon::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
        ) as $existing_id ) {
            $existing_titles[ strtolower( trim( \TranslateWP\Components\PlainText::title( (int) $existing_id ) ) ) ] = true;
        }

        foreach ( $lexicon as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $term        = sanitize_text_field( (string) ( $entry['term'] ?? '' ) );
            $description = sanitize_textarea_field( (string) ( $entry['description'] ?? '' ) );
            $category    = sanitize_text_field( (string) ( $entry['category'] ?? '' ) );

            if ( '' === $term ) {
                continue;
            }

            $normalized_title = strtolower( trim( $term ) );
            if ( isset( $existing_titles[ $normalized_title ] ) ) {
                continue;
            }

            $post_id = wp_insert_post(
                [
					'post_type'    => Lexicon::POST_TYPE,
					'post_title'   => $term,
					'post_content' => $description,
					'post_status'  => 'publish',
				]
            );

            if ( is_wp_error( $post_id ) || ! $post_id ) {
                continue;
            }

            $existing_titles[ $normalized_title ] = true;
            $added++;

            if ( '' !== $category ) {
                $existing_term = get_term_by( 'name', $category, Lexicon::CATEGORY_TAXONOMY );

                if ( $existing_term && ! is_wp_error( $existing_term ) ) {
                    $category_id = (int) $existing_term->term_id;
                } else {
                    $inserted_term = wp_insert_term( $category, Lexicon::CATEGORY_TAXONOMY );
                    $category_id   = ( is_wp_error( $inserted_term ) || empty( $inserted_term['term_id'] ) )
                        ? 0
                        : (int) $inserted_term['term_id'];
                }

                if ( $category_id ) {
                    wp_set_object_terms( $post_id, [ $category_id ], Lexicon::CATEGORY_TAXONOMY, false );
                }
            }

            update_post_meta( $post_id, Lexicon::LANGUAGES_META_KEY, $locales );
        }

        return $added;
    }

    /**
     * Locales currently configured in the languages list (may be empty during setup).
     */
    private function configured_locales(): array {
        $configured = Options::get( 'languages.list', [] );
        $locales    = [];

        if ( is_array( $configured ) ) {
            foreach ( $configured as $language ) {
                if ( is_array( $language ) && ! empty( $language['locale'] ) ) {
                    $locales[] = (string) $language['locale'];
                } elseif ( is_string( $language ) && '' !== $language ) {
                    $locales[] = $language;
                }
            }
        }

        return array_values( array_unique( $locales ) );
    }

	/** The language the read found the content in, as a lowercase BCP 47 tag; empty when the model gave none. */
	public static function language_tag( $raw ): string {
		$tag = strtolower( str_replace( '_', '-', trim( (string) $raw ) ) );

		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $tag ) ? $tag : '';
	}
}
