<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\Access;

/**
 * The grant a site admin makes in Settings: which users who can edit pages
 * may manage this site's translations. Administrators only, on both routes.
 */
class AccessGrants extends BaseApiEndpoints {

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/admin' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With', 'X-WP-Nonce' ] );

		$list = new GetEndpoint( $this->namespace, 'translation-managers', [ $this, 'managers' ], [ $this, 'admin_permissions_check' ] );
		$list->useMiddleware( $cors );

		$save = new PostEndpoint( $this->namespace, 'translation-managers', [ $this, 'save_managers' ], [ $this, 'admin_write_permissions_check' ] );
		$save->useMiddleware( $cors );

		return [ $list, $save ];
	}

	public function managers( $request ) {
		unset( $request );

		return $this->card();
	}

	public function save_managers( $request ) {
		$granted = $request->get_param( 'granted' );
		$roles   = $request->get_param( 'roles' );
		if ( null === $granted && null === $roles ) {
			return new \WP_Error( 'invalid_parameter', 'granted or roles is required', [ 'status' => 400 ] );
		}
		if ( null !== $granted && ! is_array( $granted ) ) {
			return new \WP_Error( 'invalid_parameter', 'granted must be a list of user ids', [ 'status' => 400 ] );
		}
		if ( null !== $roles ) {
			$refusal = \TranslateWP\Components\SettingsScope::refusal_for_group( 'role_permissions' );
			if ( $refusal ) {
				return $refusal;
			}
			if ( ! is_array( $roles ) ) {
				return new \WP_Error( 'invalid_parameter', 'roles must be a map of capabilities to roles', [ 'status' => 400 ] );
			}
			Access::set_role_map( $roles );
		}
		if ( null !== $granted ) {
			Access::set_granted( $granted );
		}

		return $this->card();
	}

	/** What the "Who can manage translations" card shows. */
	private function card(): array {
		$names         = \TranslateWP\Components\Audience::role_names();
		$administrator = (string) ( $names['administrator'] ?? __( 'Administrator', 'translate' ) );
		unset( $names['administrator'] );
		$group = \TranslateWP\Components\SettingsScope::resolve_group( 'role_permissions' );

		return [
			'users'    => Access::eligible_users(),
			'roles'    => Access::role_map(),
			'roleList' => array_map(
                static fn( $slug, $name ) => [
					'slug' => (string) $slug,
					'name' => (string) $name,
                ],
                array_keys( $names ),
                array_values( $names )
            ),
			'editable' => null === $group || ! empty( $group['editable'] ),
			// The site's own name for the role, in the site's language.
			'administratorName' => $administrator,
		];
	}
}
