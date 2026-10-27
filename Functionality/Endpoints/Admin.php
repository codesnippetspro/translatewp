<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Middleware\Cors;
use PluboRoutes\Endpoint\PostEndpoint;
use TranslateWP\Functionality\Endpoints\BaseApiEndpoints;
use TranslateWP\Components\Access;
use TranslateWP\Components\DashboardTranslations;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\Options;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SwitcherPlacement;
use TranslateWP\Components\SiteLanguageSync;
use TranslateWP\Components\SiteUsage;
use TranslateWP\Components\SiteRequests;
use TranslateWP\Components\Sites;
use TranslateWP\Entities\CPTs\Lexicon;
use TranslateWP\Functionality\KeepWarm;

class Admin extends BaseApiEndpoints {
	public const SEO_CRAWLER_INHERIT_SUPPORTED = true;

	private const ADMIN_DATA_PATHS = [
		'languages.list',
		'languages.switcher',
		'languages.seo',
		'translations.settings',
		'translations.context',
		'translations.style',
		'setup',
		'advanced',
	];

	private const SEO_ALLOWED_BOTS = [
		'GPTBot',
		'ClaudeBot',
		'Claude-Web',
		'PerplexityBot',
		'Google-Extended',
		'CCBot',
		'Bytespider',
		'Amazonbot',
		'Applebot-Extended',
	];

	private const SEO_BOT_DEFAULT_DECISIONS = [ 'allow', 'deny', 'inherit' ];

	private const SEO_BOT_LANGUAGE_DECISIONS = [ 'allow', 'deny', 'inherit' ];

	private const SEO_BOOLEAN_KEYS = [
		'force_lowercase',
		'remember_last_choice',
		'redirect_after_switch',
		'output_hreflang',
		'include_x_default',
		'sitemaps',
		'exclude_disabled_languages',
	];

	public function __construct( $plugin_name, $plugin_version ) {
        parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/admin' );
    }

    public function define_endpoints() {
        $cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

        $get_post_types = new GetEndpoint(
            $this->namespace,
            'get-post-types',
            [ $this, 'get_post_types' ],
            [ $this, 'settings_permissions_check' ]
        );
        $get_post_types->useMiddleware( $cors );

        $get_roles = new GetEndpoint(
            $this->namespace,
            'get-roles',
            [ $this, 'get_roles' ],
            [ $this, 'settings_permissions_check' ]
        );
        $get_roles->useMiddleware( $cors );

        $get_data = new GetEndpoint(
            $this->namespace,
            'get-data',
            [ $this, 'get_data' ],
            [ $this, 'settings_data_permissions_check' ]
        );
        $get_data->useMiddleware( $cors );

        $update_data = new PostEndpoint(
            $this->namespace,
            'update-data',
            [ $this, 'update_data' ],
            [ $this, 'settings_permissions_check' ]
        );
        $update_data->useMiddleware( $cors );

        $create_lexicon_term = new PostEndpoint(
            $this->namespace,
            'create-lexicon-term',
            [ $this, 'create_lexicon_term' ],
            [ $this, 'lexicon_or_edit_permissions_check' ]
        );
        $create_lexicon_term->useMiddleware( $cors );

        $update_lexicon_term = new PostEndpoint(
            $this->namespace,
            'update-lexicon-term',
            [ $this, 'update_lexicon_term' ],
            [ $this, 'lexicon_permissions_check' ]
        );
        $update_lexicon_term->useMiddleware( $cors );

        $delete_lexicon_term = new PostEndpoint(
            $this->namespace,
            'delete-lexicon-term',
            [ $this, 'delete_lexicon_term' ],
            [ $this, 'lexicon_permissions_check' ]
        );
        $delete_lexicon_term->useMiddleware( $cors );

        $get_lexicon_terms = new GetEndpoint(
            $this->namespace,
            'get-lexicon-terms',
            [ $this, 'get_lexicon_terms' ],
            [ $this, 'lexicon_or_edit_permissions_check' ]
        );
        $get_lexicon_terms->useMiddleware( $cors );

        $get_lexicon_categories = new GetEndpoint(
            $this->namespace,
            'get-lexicon-categories',
            [ $this, 'get_lexicon_categories' ],
            [ $this, 'lexicon_or_edit_permissions_check' ]
        );
        $get_lexicon_categories->useMiddleware( $cors );

        $clear_cache = new PostEndpoint(
            $this->namespace,
            'clear-cache',
            [ $this, 'clear_cache' ],
            [ $this, 'admin_permissions_check' ]
        );
        $clear_cache->useMiddleware( $cors );

        $dashboard_metrics = new GetEndpoint(
            $this->namespace,
            'dashboard-metrics',
            [ $this, 'dashboard_metrics' ],
            [ $this, 'admin_permissions_check' ]
        );
        $dashboard_metrics->useMiddleware( $cors );

        $settings_revision = new GetEndpoint(
            $this->namespace,
            'settings-revision',
            [ $this, 'settings_revision' ],
            [ $this, 'settings_permissions_check' ]
        );
        $settings_revision->useMiddleware( $cors );

        $retranslation_estimate = new GetEndpoint(
            $this->namespace,
            'retranslation-estimate',
            [ $this, 'retranslation_estimate' ],
            [ $this, 'retranslate_permissions_check' ]
        );
        $retranslation_estimate->useMiddleware( $cors );

		$switcher_position = new PostEndpoint(
			$this->namespace,
			'switcher-position',
			[ $this, 'save_switcher_position' ],
			[ $this, 'settings_write_permissions_check' ]
		);

        return [
			$switcher_position,
            $get_post_types,
            $get_roles,
            $get_data,
            $update_data,
            $create_lexicon_term,
            $update_lexicon_term,
            $delete_lexicon_term,
            $get_lexicon_terms,
            $get_lexicon_categories,
            $clear_cache,
            $dashboard_metrics,
            $settings_revision,
            $retranslation_estimate,
        ];
    }

	public function save_switcher_position( $request ) {
		$kind  = SwitcherPlacement::SIDE_TAB === sanitize_key( (string) $request->get_param( 'kind' ) ) ? 'side_tab' : 'floating';
		$phone = 'mobile' === sanitize_key( (string) $request->get_param( 'device' ) );
		if ( 'side_tab' === $kind && ! ProEligibility::unlocks_pro_features() ) {
			return new \WP_Error( 'pro_required', __( 'The side tab needs TranslateWP Pro.', 'translate' ), [ 'status' => 403 ] );
		}
		$position = 'side_tab' === $kind
			? SwitcherPlacement::sanitize_side_tab_position( $request->get_param( 'position' ), $phone )
			: self::sanitize_switcher_position( $request->get_param( 'position' ) );
		if ( null === $position ) {
			return new \WP_Error( 'invalid_position', __( 'A valid position is required.', 'translate' ), [ 'status' => 400 ] );
		}
		// The server, not the caller, decides which place moves: the one of
		// the type the site renders.
		$rendered = SwitcherPlacement::effective_type( Options::get( 'languages.switcher', [] ) );
		if ( ( 'side_tab' === $kind ) !== ( SwitcherPlacement::SIDE_TAB === $rendered ) ) {
			return new \WP_Error( 'position_mismatch', __( 'The switcher changed type; reload the page and drag it again.', 'translate' ), [ 'status' => 400 ] );
		}
		// A phone-width screen keeps its own position; a wide one is the default.
		$key = SwitcherPlacement::POSITION_KEYS[ $kind ][ $phone ? 1 : 0 ];
		// A drop moves only the floating position and never competes with a
		// settings form, so the place the user let go of is saved as is; the
		// revision guard applies to settings saves, not to a drag.
		$settings_request = new \WP_REST_Request( 'POST', '' );
		$settings_request->set_body_params(
			[
				'option' => 'languages.switcher',
				'data' => [ $key => $position ],
			]
		);
		$result = $this->update_data( $settings_request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return [
			'success' => true,
			'position' => $position,
			'revision' => $result['revision'],
		];
	}

    public function get_post_types( $request ) {
        return get_post_types( [ 'public' => true ], 'objects' );
    }

    public function get_roles( $request ) {
        return \TranslateWP\Components\Audience::role_names();
    }

    public function get_data( $request ) {
        $option = (string) $request->get_param( 'option' );

        if ( ! $option ) {
            return new \WP_Error( 'missing_parameter', 'Option is required', [ 'status' => 400 ] );
        }

        if ( ! $this->is_admin_data_path_allowed( $option ) ) {
            return new \WP_Error( 'permission_denied', 'Option is not available', [ 'status' => 403 ] );
        }

        // The route decides who gets here; a non-administrator who manages
        // something is held to the settings their capabilities cover.
        if ( Access::is_delegate() && ! Access::may_read_setting( $option ) ) {
            return new \WP_Error( 'permission_denied', 'Option is not available', [ 'status' => 403 ] );
        }

        $value = Options::get( $option, [] );

        if ( 'languages.seo' === $option && is_array( $value ) ) {
            $value = $this->force_ai_crawler_object_shape( $value );
        }

        return $value;
    }

    /**
     * PHP serialises an empty associative array as a JSON array, so an empty
     * `ai_crawlers.bots` map, or a bot's empty per-language map, would reach the
     * admin as `[]` instead of `{}`. Cast those empty maps to objects so the
     * REST response carries the map shape the admin expects.
     */
    private function force_ai_crawler_object_shape( array $seo ): array {
        if ( ! isset( $seo['ai_crawlers'] ) || ! is_array( $seo['ai_crawlers'] ) ) {
            return $seo;
        }

        $crawlers = $seo['ai_crawlers'];

        if ( isset( $crawlers['bots'] ) && is_array( $crawlers['bots'] ) ) {
            $bots = $crawlers['bots'];

            foreach ( $bots as $name => $bot ) {
                if ( is_array( $bot ) && isset( $bot['languages'] ) && is_array( $bot['languages'] ) && empty( $bot['languages'] ) ) {
                    $bots[ $name ]['languages'] = (object) [];
                }
            }

            $crawlers['bots'] = empty( $bots ) ? (object) [] : $bots;
        }

        $seo['ai_crawlers'] = $crawlers;

        return $seo;
    }

    public function update_data( $request ) {
        $option = (string) $request->get_param( 'option' );
        $raw_data = $request->get_param( 'data' );

        if ( is_array( $raw_data ) ) {
            $data = $raw_data;
        } else {
            $data = json_decode( (string) $raw_data, true );
        }

        if ( ! $option || null === $data ) {
            return new \WP_Error( 'missing_parameter', 'Option and data are required', [ 'status' => 400 ] );
        }

        if ( ! $this->is_admin_data_path_allowed( $option ) ) {
            return new \WP_Error( 'permission_denied', 'Option is not available', [ 'status' => 403 ] );
        }

        if ( Access::is_delegate() && ! Access::may_write_setting( $option ) ) {
            return new \WP_Error( 'permission_denied', 'Option is not available', [ 'status' => 403 ] );
        }

        // A save carries the revision its form was loaded from; a
        // different stored revision means another tab saved in between, and
        // the save is refused with a 409 that names the current revision.
        $sent_revision = $request->get_param( 'revision' );
        if ( null !== $sent_revision && '' !== $sent_revision ) {
            $current_revision = self::settings_revision_value();
            if ( (int) $sent_revision !== $current_revision ) {
                return new \WP_Error(
                    'settings_changed',
                    __( 'These settings were changed in another tab. Reload to see the latest.', 'translate' ),
                    [
                        'status'   => 409,
                        'revision' => $current_revision,
                    ]
                );
            }
        }

        if ( 'languages.seo' === $option ) {
            $existing = Options::get( 'languages.seo', [] );
            $patch    = $this->sanitize_languages_seo_patch( is_array( $data ) ? $data : [] );
            $data     = $this->merge_associative( is_array( $existing ) ? $existing : [], $patch );
            // The submitted bot list is the whole list: a deep merge would keep
            // a removed crawler, so a save carrying bots replaces the stored map.
            if ( isset( $patch['ai_crawlers']['bots'] ) ) {
                $data['ai_crawlers']['bots'] = $patch['ai_crawlers']['bots'];
            }
        }

        if ( 'languages.switcher' === $option ) {
            $existing = Options::get( 'languages.switcher', [] );
            $patch    = $this->sanitize_languages_switcher_patch( is_array( $data ) ? $data : [] );
            // "Reset position" sends an explicit null for the wide place of
            // one type; both widths of that type go back to their default.
            $resets = [];
            foreach ( SwitcherPlacement::POSITION_KEYS as $keys ) {
                if ( is_array( $data ) && array_key_exists( $keys[0], $data ) && null === $data[ $keys[0] ] ) {
                    $resets = array_merge( $resets, $keys );
                }
            }
            $data = $this->merge_associative( is_array( $existing ) ? $existing : [], $patch );
            foreach ( $resets as $key ) {
                unset( $data[ $key ] );
            }
        }

        // Free text the translator is guided by is stored trimmed, so a field
        // holding only whitespace is saved as empty rather than as guidance.
        if ( in_array( $option, [ 'translations.context', 'translations.style' ], true ) ) {
            $data = self::trim_strings( $data );
        }

        // The translate-for-visitors switch is retired; a form loaded
        // before the update may still send its key, which is dropped so it
        // is never stored and never trips a locked group.
        if ( 'translations.settings' === $option && is_array( $data ) ) {
            unset( $data['translation_enabled'] );
        }

        $locked = \TranslateWP\Components\SettingsScope::refusal_for_write( $option, $data );
        if ( null !== $locked ) {
            return $locked;
        }

        // A languages list always names one default: it is the source every
        // translation is made from and the language the wizard was set up in.
        if ( 'languages.list' === $option && '' === self::default_locale_of( $data ) ) {
            return new \WP_Error(
                'missing_default_language',
                __( 'Choose a default language before saving.', 'translate' ),
                [ 'status' => 400 ]
            );
        }
		if ( 'languages.list' === $option ) {
			$address_refusal = \TranslateWP\Components\LanguageSlugs::address_refusal( $data );
			if ( null !== $address_refusal ) {
				return $address_refusal;
			}
		}

        $slug_clash = 'languages.list' === $option ? \TranslateWP\Components\LanguageSlugs::clash( $data, Options::get( 'languages.list', [] ) ) : [];
        if ( [] !== $slug_clash ) {
            return \TranslateWP\Components\LanguageSlugs::refusal( $slug_clash );
        }

        $previous_default = 'languages.list' === $option ? self::default_locale_of( Options::get( 'languages.list', [] ) ) : '';
        $previous_settings = 'translations.settings' === $option ? Options::get( 'translations.settings', [] ) : [];
        $previous_switcher = 'languages.switcher' === $option ? Options::get( 'languages.switcher', [] ) : [];

        $profiles_before = self::model_profiles();
        $result          = Options::update( $option, $data );

        if ( 'languages.list' === $option ) {
            SiteLanguageSync::sync_active_languages( true );

            // Moving the default language changes what every
            // translation is made from, so it retranslates once; adding or
            // removing a translation language does not.
            if ( '' !== $previous_default && self::default_locale_of( $data ) !== $previous_default ) {
                do_action( 'translatewp_translation_affecting_settings_saved' );
            }
        }

        // The brief and the style enter every translation's fingerprint; a save
        // that leaves the fingerprint as it was translates nothing again.
        if ( in_array( $option, KeepWarm::TRANSLATION_AFFECTING_OPTION_PATHS, true ) ) {
            $changed_languages = array_keys( array_diff_assoc( self::model_profiles(), $profiles_before ) );
            if ( $changed_languages ) {
                do_action( 'translatewp_translation_affecting_settings_saved', $changed_languages );
            }
        }

        // translations.settings mixes the three groups. Only a model
        // key marks every translation stale; a localizer key bumps the
        // localizer version so the pages already translated are reported as
        // waiting, and the rest changes nothing about stored translations.
        if ( 'translations.settings' === $option ) {
            $changed = self::changed_keys( is_array( $previous_settings ) ? $previous_settings : [], is_array( $data ) ? $data : [] );
            if ( array_intersect( $changed, KeepWarm::MODEL_SETTING_KEYS ) ) {
                do_action( 'translatewp_translation_affecting_settings_saved' );
            }
            if ( array_intersect( $changed, KeepWarm::LOCALIZER_SETTING_KEYS ) ) {
                do_action( 'translatewp_localizer_settings_saved' );
            }
        }

        if ( is_array( $result ) && ! empty( $result['success'] ) ) {
            $result['revision'] = self::bump_settings_revision();

            // The switcher config is printed into every page, so a full-page
            // cache would keep serving the previous switcher until it expires.
            // A drag only moves floating_position; purging every page cache on
            // each drag is too costly, so only a settings change purges.
            if ( 'languages.switcher' === $option && self::switcher_settings_changed( is_array( $previous_switcher ) ? $previous_switcher : [], is_array( $data ) ? $data : [] ) ) {
                \TranslateWP\Components\PageCachePurge::purge_all( 'switcher_saved' );
            }

            // Who is served translations decides what a cached page holds.
            if ( 'translations.settings' === $option && in_array( \TranslateWP\Components\Audience::SETTING_KEY, self::changed_keys( is_array( $previous_settings ) ? $previous_settings : [], is_array( $data ) ? $data : [] ), true ) ) {
                \TranslateWP\Components\PageCachePurge::purge_all( 'audience_saved' );
            }
        }

        return $result;
    }

    public const SETTINGS_REVISION_OPTION = 'translatewp_settings_revision';

    public static function settings_revision_value(): int {
        return max( 0, (int) get_option( self::SETTINGS_REVISION_OPTION, 0 ) );
    }

    private static function bump_settings_revision(): int {
        $next = self::settings_revision_value() + 1;
        update_option( self::SETTINGS_REVISION_OPTION, $next, false );

        return $next;
    }

    public function settings_revision( $request ) {
        unset( $request );

        return [ 'revision' => self::settings_revision_value() ];
    }

    /**
     * What a translation-affecting save translates again, in the site's own
     * figures from the index: the distinct translated pages and the
     * translation languages they are in. It reads the index only, so it never
     * calls the service or the translation model, and it states no token figure.
     */
    public function retranslation_estimate( $request ) {
        unset( $request );

        $pages     = [];
        $languages = [];
        foreach ( ( new IndexQueue() )->completed_rows() as $row ) {
            $language = (string) $row['language'];
            if ( '' === $language ) {
                continue;
            }
            $pages[ (string) $row['url'] ]  = true;
            $languages[ $language ]         = true;
        }

        return [
            'pages'     => count( $pages ),
            'languages' => count( $languages ),
        ];
    }

    public function clear_cache( $request ) {
        unset( $request );

        $response = SiteRequests::post( '/v1/translation/cache/clear', [ 'siteId' => Sites::registered_id() ], 15 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'success' => true,
            'deleted' => (int) ( $response['deleted'] ?? 0 ),
        ];
    }

    public function dashboard_metrics( $request ) {
        unset( $request );

        $languages_config = Options::get( 'languages.list', [] );
        if ( ! is_array( $languages_config ) ) {
            $languages_config = [];
        }

        // Usage and cache figures come from the service's per-site usage, so a
        // site translated through background indexing reports real totals and
        // every visitor served from the cache counts as a hit.
        $data                      = [];
        $usage                     = SiteUsage::dashboard_usage();
        $data['tokensToday']       = $usage['tokensToday'];
        $data['tokensThisMonth']   = $usage['tokensThisMonth'];
        $data['tokensVsYesterday'] = $usage['tokensVsYesterday'];
        $data['tokenTrend']        = $usage['tokenTrend'];
        $data['cacheHitsToday']    = $usage['cacheHitsToday'];
        $data['cacheHitRatio']     = $usage['cacheHitRatio'];

        // Translated content comes from the index queue: finished rows in the
        // last day and distinct translated pages per language.
        $translations              = DashboardTranslations::summary( new IndexQueue(), $languages_config, time() );
        $data['translationsToday'] = $translations['translationsToday'];
        $data['languageUsage']     = $translations['languageUsage'];
        $data['recentContent']     = $translations['recentContent'];

        $data['recentLexicon'] = $this->recent_lexicon();
        $data['anchorMissing'] = array_map( static fn( array $page ): string => $page['url'], \TranslateWP\Components\AnchorMisses::pages() );

        return $data;
    }

    /**
     * Trims every string in a settings payload, nested arrays included.
     *
     * @param mixed $data Decoded settings payload.
     * @return mixed The same payload with its strings trimmed.
     */
    public static function trim_strings( $data ) {
        if ( is_string( $data ) ) {
            return trim( $data );
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = self::trim_strings( $value );
            }
        }

        return $data;
    }

    private function recent_lexicon(): array {
        $lexicon_posts = get_posts(
            [
				'post_type'      => Lexicon::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
        );

        $now = current_time( 'timestamp' );
        $recent_lexicon = array_map(
            static function ( $post ) use ( $now ) {
				$diff = $now - strtotime( $post->post_date );
				if ( $diff < DAY_IN_SECONDS ) {
					$added = 'Today';
				} elseif ( $diff < 2 * DAY_IN_SECONDS ) {
					$added = 'Yesterday';
				} else {
					$days = (int) floor( $diff / DAY_IN_SECONDS );
					$added = $days . ' days ago';
				}
				return [
					'term'  => \TranslateWP\Components\PlainText::title( $post ),
					'added' => $added,
					'href'  => '#',
				];
			},
            $lexicon_posts
        );

        return $recent_lexicon;
    }

    public const LEXICON_TERM_MAX_LENGTH = 200;

    public function create_lexicon_term( $request ) {
        $term = sanitize_text_field( (string) $request->get_param( 'term' ) );
        $description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
        $languages = (array) $request->get_param( 'languages' );
        $categories = (array) $request->get_param( 'categories' );
        $new_categories = (array) $request->get_param( 'new_categories' );

        if ( ! $term ) {
            return new \WP_Error( 'missing_parameter', 'Term is required', [ 'status' => 400 ] );
        }

        if ( mb_strlen( $term ) > self::LEXICON_TERM_MAX_LENGTH ) {
            return new \WP_Error( 'term_too_long', sprintf( 'A term can be at most %d characters long.', self::LEXICON_TERM_MAX_LENGTH ), [ 'status' => 400 ] );
        }

        $sanitized_languages = array_values( array_filter( array_map( 'sanitize_text_field', $languages ) ) );
        $existing_category_ids = array_values( array_filter( array_map( 'intval', $categories ) ) );
        $sanitized_new_categories = array_values( array_filter( array_map( 'sanitize_text_field', $new_categories ) ) );

        $post_id = wp_insert_post(
            [
				'post_type' => Lexicon::POST_TYPE,
				'post_title' => $term,
				'post_content' => $description,
				'post_status' => 'publish',
			],
            true
        );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $new_category_ids = [];
        foreach ( $sanitized_new_categories as $new_category_name ) {
            $existing_term = get_term_by( 'name', $new_category_name, Lexicon::CATEGORY_TAXONOMY );

            if ( $existing_term && ! is_wp_error( $existing_term ) ) {
                $new_category_ids[] = (int) $existing_term->term_id;
                continue;
            }

            $inserted_term = wp_insert_term( $new_category_name, Lexicon::CATEGORY_TAXONOMY );
            if ( is_wp_error( $inserted_term ) || empty( $inserted_term['term_id'] ) ) {
                continue;
            }

            $new_category_ids[] = (int) $inserted_term['term_id'];
        }

        $term_ids = array_values( array_unique( array_merge( $existing_category_ids, $new_category_ids ) ) );
        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $post_id, $term_ids, Lexicon::CATEGORY_TAXONOMY, false );
        }

        update_post_meta( $post_id, Lexicon::LANGUAGES_META_KEY, $sanitized_languages );

        do_action( 'translatewp_lexicon_changed' );

        return [
            'id' => $post_id,
            'success' => true,
        ];
    }

    public function update_lexicon_term( $request ) {
        $post_id = (int) $request->get_param( 'id' );
        $term = sanitize_text_field( (string) $request->get_param( 'term' ) );
        $description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
        $languages = (array) $request->get_param( 'languages' );
        $categories = (array) $request->get_param( 'categories' );
        $new_categories = (array) $request->get_param( 'new_categories' );

        if ( ! $post_id ) {
            return new \WP_Error( 'missing_parameter', 'ID is required', [ 'status' => 400 ] );
        }

        $post = get_post( $post_id );
        if ( ! $post || Lexicon::POST_TYPE !== $post->post_type ) {
            return new \WP_Error( 'not_found', 'Lexicon term not found', [ 'status' => 404 ] );
        }

        if ( ! $term ) {
            return new \WP_Error( 'missing_parameter', 'Term is required', [ 'status' => 400 ] );
        }

        $sanitized_languages = array_values( array_filter( array_map( 'sanitize_text_field', $languages ) ) );
        $existing_category_ids = array_values( array_filter( array_map( 'intval', $categories ) ) );
        $sanitized_new_categories = array_values( array_filter( array_map( 'sanitize_text_field', $new_categories ) ) );

        $updated_post_id = wp_update_post(
            [
				'ID' => $post_id,
				'post_title' => $term,
				'post_content' => $description,
			],
            true
        );

        if ( is_wp_error( $updated_post_id ) ) {
            return $updated_post_id;
        }

        $new_category_ids = [];
        foreach ( $sanitized_new_categories as $new_category_name ) {
            $existing_term = get_term_by( 'name', $new_category_name, Lexicon::CATEGORY_TAXONOMY );

            if ( $existing_term && ! is_wp_error( $existing_term ) ) {
                $new_category_ids[] = (int) $existing_term->term_id;
                continue;
            }

            $inserted_term = wp_insert_term( $new_category_name, Lexicon::CATEGORY_TAXONOMY );
            if ( is_wp_error( $inserted_term ) || empty( $inserted_term['term_id'] ) ) {
                continue;
            }

            $new_category_ids[] = (int) $inserted_term['term_id'];
        }

        $term_ids = array_values( array_unique( array_merge( $existing_category_ids, $new_category_ids ) ) );
        wp_set_object_terms( $post_id, $term_ids, Lexicon::CATEGORY_TAXONOMY, false );

        update_post_meta( $post_id, Lexicon::LANGUAGES_META_KEY, $sanitized_languages );

        do_action( 'translatewp_lexicon_changed' );

        return [
            'id' => $post_id,
            'success' => true,
        ];
    }

    public function delete_lexicon_term( $request ) {
        $post_id = (int) $request->get_param( 'id' );

        if ( ! $post_id ) {
            return new \WP_Error( 'missing_parameter', 'ID is required', [ 'status' => 400 ] );
        }

        $post = get_post( $post_id );
        if ( ! $post || Lexicon::POST_TYPE !== $post->post_type ) {
            return new \WP_Error( 'not_found', 'Lexicon term not found', [ 'status' => 404 ] );
        }

        $deleted = wp_delete_post( $post_id, true );
        if ( ! $deleted ) {
            return new \WP_Error( 'delete_failed', 'Failed to delete lexicon term', [ 'status' => 500 ] );
        }

        do_action( 'translatewp_lexicon_changed' );

        return [
            'id' => $post_id,
            'success' => true,
        ];
    }

    public function get_lexicon_terms( $request ) {
        $per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
        if ( 1 === $per_page && ! $request->get_param( 'per_page' ) ) {
            $per_page = 10;
        }

        $page = max( 1, (int) $request->get_param( 'page' ) );
        if ( 1 === $page && ! $request->get_param( 'page' ) ) {
            $page = 1;
        }

        $search = sanitize_text_field( (string) $request->get_param( 'search' ) );

        $query_args = [
            'post_type' => Lexicon::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            's' => $search,
        ];

        $query = new \WP_Query( $query_args );
        $items = [];

        foreach ( $query->posts as $post ) {
            $post_id = (int) $post->ID;
            $languages = get_post_meta( $post_id, Lexicon::LANGUAGES_META_KEY, true );
            $terms = wp_get_post_terms( $post_id, Lexicon::CATEGORY_TAXONOMY );

            $normalized_languages = [];
            if ( is_array( $languages ) ) {
                $normalized_languages = array_values( array_filter( array_map( 'sanitize_text_field', $languages ) ) );
            }

            $normalized_categories = [];
            if ( ! is_wp_error( $terms ) ) {
                $normalized_categories = array_map(
                    static function ( $term ) {
						return [
							'id' => (int) $term->term_id,
							'name' => $term->name,
							'slug' => $term->slug,
						];
					},
                    $terms
                );
            }

            $items[] = [
                'id' => $post_id,
                'term' => \TranslateWP\Components\PlainText::decode( (string) $post->post_title ),
                'description' => (string) $post->post_content,
                'languages' => $normalized_languages,
                'categories' => $normalized_categories,
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
                'has_next' => $page < (int) $query->max_num_pages,
                'has_prev' => $page > 1,
            ],
        ];
    }

    public function get_lexicon_categories( $request ) {
        unset( $request );
		$terms = get_terms(
			[
				'taxonomy'   => Lexicon::CATEGORY_TAXONOMY,
				'hide_empty' => false,
			]
		);
        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        return array_map(
			static fn( $term ): array => [
				'id'   => (int) $term->term_id,
				'name' => (string) $term->name,
				'slug' => (string) $term->slug,
            ],
            $terms
        );
    }

	private function is_admin_data_path_allowed( string $path ): bool {
		return in_array( $path, self::ADMIN_DATA_PATHS, true );
	}

	private const SWITCHER_TRIGGERS       = [ 'name_flag', 'flag', 'icon' ];
	private const SWITCHER_ICON_STYLES    = [ 'rectangle', 'square', 'circle' ];
	private const SWITCHER_REGIONAL_NAMES = [ 'full', 'short' ];
	private const SWITCHER_BACKGROUND_STYLES = [ 'solid', 'transparent', 'glass' ];
	private const SWITCHER_COLOR_KEYS     = [ 'text_color', 'background_color', 'border_color', 'highlight_text', 'highlight_bg', 'loading_border_color' ];
	private const SWITCHER_PIXEL_KEYS     = [ 'offset', 'icon_size', 'padding' ];
	private const PENDING_MESSAGE_MAX_LENGTH = 200;
	private const SWITCHER_PIXEL_MAX   = 500;
	private const SWITCHER_POSITION_MAX = 100000;
	private const SWITCHER_ANCHORS = [ 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];

	/**
	 * Builds a sanitised patch for `languages.switcher` containing only the keys present
	 * in the submitted data. A colour that is not a hex colour, or a type or icon style
	 * outside the known set, is dropped from the patch so the stored value stays as it was.
	 */
	/** The keys whose value differs between two settings arrays. */
	private static function switcher_settings_changed( array $before, array $after ): bool {
		// A drag only moves a stored place and does not count; a reset
		// removes one, which every cached page must reflect.
		$places = array_merge( ...array_values( SwitcherPlacement::POSITION_KEYS ) );
		foreach ( $places as $key ) {
			if ( isset( $before[ $key ] ) && ! isset( $after[ $key ] ) ) {
				return true;
			}
		}
		return [] !== array_diff( self::changed_keys( $before, $after ), $places );
	}

	private static function changed_keys( array $before, array $after ): array {
		$changed = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				$changed[] = (string) $key;
			}
		}

		return $changed;
	}

	private static function sanitize_switcher_position( $value ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['anchor'] ?? null ) ) {
			return null;
		}
		$anchor = sanitize_key( $value['anchor'] );
		if ( ! in_array( $anchor, self::SWITCHER_ANCHORS, true ) ) {
			return null;
		}
		$position = [ 'anchor' => $anchor ];
		foreach ( [ 'x', 'y' ] as $axis ) {
			$raw = $value[ $axis ] ?? null;
			if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) || ! is_finite( (float) $raw ) ) {
				return null;
			}
			$pixel = (float) sanitize_text_field( (string) $raw );
			$position[ $axis ] = (int) min( self::SWITCHER_POSITION_MAX, max( 0, $pixel ) );
		}
		return $position;
	}

	private function sanitize_languages_switcher_patch( array $data ): array {
		$patch = [];
		foreach ( [ 'floating_position', 'floating_position_mobile' ] as $key ) {
			$position = self::sanitize_switcher_position( $data[ $key ] ?? null );
			if ( null !== $position ) {
				$patch[ $key ] = $position;
			}
		}

		foreach ( SwitcherPlacement::POSITION_KEYS['side_tab'] as $index => $key ) {
			$position = SwitcherPlacement::sanitize_side_tab_position( $data[ $key ] ?? null, 1 === $index );
			if ( null !== $position ) {
				$patch[ $key ] = $position;
			}
		}

		if ( array_key_exists( 'type', $data ) && in_array( $data['type'], SwitcherPlacement::TYPES, true )
			&& ( SwitcherPlacement::SIDE_TAB !== $data['type'] || ProEligibility::unlocks_pro_features() ) ) {
			$patch['type'] = $data['type'];
		}

		if ( array_key_exists( 'trigger', $data ) && in_array( $data['trigger'], self::SWITCHER_TRIGGERS, true ) ) {
			$patch['trigger'] = $data['trigger'];
		}

		if ( array_key_exists( 'icon_style', $data ) && in_array( $data['icon_style'], self::SWITCHER_ICON_STYLES, true ) ) {
			$patch['icon_style'] = $data['icon_style'];
		}

		if ( array_key_exists( 'show_flag', $data ) ) {
			$patch['show_flag'] = (bool) $data['show_flag'];
		}

		if ( array_key_exists( 'native_names', $data ) ) {
			$patch['native_names'] = (bool) $data['native_names'];
		}

		if ( array_key_exists( 'regional_names', $data ) && in_array( $data['regional_names'], self::SWITCHER_REGIONAL_NAMES, true ) ) {
			$patch['regional_names'] = $data['regional_names'];
		}

		if ( array_key_exists( 'background_style', $data ) && in_array( $data['background_style'], self::SWITCHER_BACKGROUND_STYLES, true ) ) {
			$patch['background_style'] = $data['background_style'];
		}

		foreach ( self::SWITCHER_COLOR_KEYS as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$color = self::hex_color( $data[ $key ] );

			if ( null !== $color ) {
				$patch[ $key ] = $color;
			}
		}

		foreach ( self::SWITCHER_PIXEL_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) && is_numeric( $data[ $key ] ) ) {
				$patch[ $key ] = min( self::SWITCHER_PIXEL_MAX, max( 0, (int) $data[ $key ] ) );
			}
		}

		if ( array_key_exists( 'anchor_fallback', $data ) && in_array( $data['anchor_fallback'], SwitcherPlacement::ANCHOR_FALLBACKS, true ) ) {
			$patch['anchor_fallback'] = $data['anchor_fallback'];
		}

		if ( array_key_exists( 'hide_branding', $data ) ) {
			$patch['hide_branding'] = (bool) $data['hide_branding'];
		}

		if ( array_key_exists( 'pending_message', $data ) && is_string( $data['pending_message'] ) ) {
			$message                  = sanitize_text_field( $data['pending_message'] );
			$patch['pending_message'] = function_exists( 'mb_substr' )
				? mb_substr( $message, 0, self::PENDING_MESSAGE_MAX_LENGTH )
				: substr( $message, 0, self::PENDING_MESSAGE_MAX_LENGTH );
		}

		return $patch;
	}

	/**
	 * Returns the colour as a hex value, with an optional alpha channel
	 * (`#rgb`, `#rgba`, `#rrggbb` or `#rrggbbaa`), or null when it is not one.
	 */
	private static function hex_color( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		return 1 === preg_match( '/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ? $value : null;
	}

	/**
	 * Builds a sanitised patch for `languages.seo` containing only the keys actually present
	 * in the submitted data, so absent keys leave the currently stored value untouched on merge.
	 */
	private function sanitize_languages_seo_patch( array $data ): array {
		$patch = [];

		foreach ( self::SEO_BOOLEAN_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$patch[ $key ] = (bool) $data[ $key ];
			}
		}

		if ( array_key_exists( 'url_structure', $data ) ) {
			$patch['url_structure'] = sanitize_key( (string) $data['url_structure'] );
		}

		if ( array_key_exists( 'redirect_changed_slugs', $data ) ) {
			$patch['redirect_changed_slugs'] = sanitize_text_field( (string) $data['redirect_changed_slugs'] );
		}

		if ( array_key_exists( 'cookie_ttl', $data ) ) {
			$patch['cookie_ttl'] = max( 0, (int) $data['cookie_ttl'] );
		}

		if ( array_key_exists( 'ai_crawlers', $data ) && is_array( $data['ai_crawlers'] ) ) {
			$patch['ai_crawlers'] = $this->sanitize_ai_crawlers_patch( $data['ai_crawlers'] );
		}

		return $patch;
	}

	private function sanitize_ai_crawlers_patch( array $ai_crawlers ): array {
		$patch = [];

		if ( array_key_exists( 'preset_answer_engines', $ai_crawlers ) ) {
			$patch['preset_answer_engines'] = (bool) $ai_crawlers['preset_answer_engines'];
		}

		if ( array_key_exists( 'bots', $ai_crawlers ) && is_array( $ai_crawlers['bots'] ) ) {
			$configured_locales = $this->configured_locales();
			$sanitized_bots     = [];
			$listed_by_lower    = [];
			foreach ( self::SEO_ALLOWED_BOTS as $listed_bot ) {
				$listed_by_lower[ strtolower( $listed_bot ) ] = $listed_bot;
			}
			$seen = [];

			foreach ( $ai_crawlers['bots'] as $bot_name => $bot_settings ) {
				if ( ! is_array( $bot_settings ) ) {
					continue;
				}

				$bot_name = self::sanitize_bot_name( (string) $bot_name );
				if ( '' === $bot_name ) {
					continue;
				}

				// A name that matches a listed crawler, in any case, is that
				// listed crawler; the first entry for a name wins so a
				// duplicate never adds a second row.
				$lower    = strtolower( $bot_name );
				$bot_name = $listed_by_lower[ $lower ] ?? $bot_name;
				if ( isset( $seen[ $lower ] ) ) {
					continue;
				}
				$seen[ $lower ] = true;

				$default_decision = 'inherit';
				if ( in_array( $bot_settings['default'] ?? null, self::SEO_BOT_DEFAULT_DECISIONS, true ) ) {
					$default_decision = $bot_settings['default'];
				}

				$sanitized_languages = [];
				if ( is_array( $bot_settings['languages'] ?? null ) ) {
					foreach ( $bot_settings['languages'] as $locale => $decision ) {
						$locale = sanitize_text_field( (string) $locale );

						if ( ! in_array( $locale, $configured_locales, true ) ) {
							continue;
						}

						if ( ! in_array( $decision, self::SEO_BOT_LANGUAGE_DECISIONS, true ) ) {
							continue;
						}

						$sanitized_languages[ $locale ] = $decision;
					}
				}

				$sanitized_bots[ $bot_name ] = [
					'default'   => $default_decision,
					'languages' => $sanitized_languages,
				];
			}

			$patch['bots'] = $sanitized_bots;
		}

		return $patch;
	}

	/**
	 * A crawler name, listed or site-supplied, that is safe to store and to
	 * write into robots.txt as `User-agent: <name>`. Returns '' to refuse an
	 * empty, over-long value, or one carrying a control character, line break,
	 * comment marker or a leading colon, any of which could add a robots.txt
	 * line or break the field.
	 */
	private static function sanitize_bot_name( string $name ): string {
		$name = trim( $name );
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name ) ) {
			return '';
		}

		return $name;
	}

	/** The model profile per translation language, as the fingerprint keys on it. */
	private static function model_profiles(): array {
		$profiles = [];
		foreach ( \TranslateWP\Components\TranslationProxySettings::target_languages() as $language ) {
			$profiles[ $language ] = \TranslateWP\Components\TranslationFingerprint::stable_stringify(
				\TranslateWP\Components\TranslationFingerprint::model_profile( \TranslateWP\Components\TranslationProxySettings::ai_settings( $language ) )
			);
		}
		return $profiles;
	}

	/** The default locale of a languages list, or '' when none is flagged. */
	public static function default_locale_of( $languages ): string {
		if ( ! is_array( $languages ) ) {
			return '';
		}
		foreach ( $languages as $language ) {
			if ( is_array( $language ) && ! empty( $language['default'] ) && ! empty( $language['locale'] ) ) {
				return (string) $language['locale'];
			}
		}
		return '';
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

	/**
	 * Deep-merges $patch onto $existing: nested associative arrays merge recursively,
	 * any other value in $patch overwrites; keys absent from $patch are left untouched.
	 */
	private function merge_associative( array $existing, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && is_array( $existing[ $key ] ?? null ) ) {
				$existing[ $key ] = $this->merge_associative( $existing[ $key ], $value );
				continue;
			}

			$existing[ $key ] = $value;
		}

		return $existing;
	}
}
