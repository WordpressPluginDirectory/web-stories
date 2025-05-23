<?php
/**
 * Class Stories_Autosaves_Controller
 *
 * @link      https://github.com/googleforcreators/web-stories-wp
 *
 * @copyright 2020 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 */

/**
 * Copyright 2020 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types = 1);

namespace Google\Web_Stories\REST_API;

use WP_Post;
use WP_REST_Autosaves_Controller;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stories_Autosaves_Controller class.
 *
 * @phpstan-import-type Schema from \Google\Web_Stories\REST_API\Stories_Base_Controller
 */
class Stories_Autosaves_Controller extends WP_REST_Autosaves_Controller {

	/**
	 * Parent post controller.
	 */
	protected WP_REST_Controller $parent_controller;

	/**
	 * The base of the parent controller's route.
	 */
	protected string $parent_base;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $parent_post_type Post type of the parent.
	 */
	public function __construct( string $parent_post_type ) {
		parent::__construct( $parent_post_type );

		/**
		 * Post type instance.
		 *
		 * @var \WP_Post_Type $post_type_object
		 */
		$post_type_object = get_post_type_object( $parent_post_type );

		/**
		 * Parent controller instance.
		 *
		 * @var WP_REST_Controller $parent_controller
		 */
		$parent_controller = $post_type_object->get_rest_controller();

		$this->parent_controller = $parent_controller;
		$this->parent_base       = ! empty( $post_type_object->rest_base ) ? (string) $post_type_object->rest_base : $post_type_object->name;
		$this->namespace         = ! empty( $post_type_object->rest_namespace ) ? (string) $post_type_object->rest_namespace : 'wp/v2';
	}

	/**
	 * Registers the routes for autosaves.
	 *
	 * Used to override the create_item() callback.
	 *
	 * @since 1.0.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes(): void {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->parent_base . '/(?P<id>[\d]+)/' . $this->rest_base,
			[
				'args'   => [
					'parent' => [
						'description' => __( 'The ID for the parent of the object.', 'web-stories' ),
						'type'        => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->parent_controller->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			],
			true // required so that the existing route is overridden.
		);
	}

	/**
	 * Prepares a single template output for response.
	 *
	 * Adds post_content_filtered field to output.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post         $post Post object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @phpstan-param WP_REST_Request<array{context: string}> $request
	 */
	public function prepare_item_for_response( $post, $request ): WP_REST_Response {
		$response = parent::prepare_item_for_response( $post, $request );
		$fields   = $this->get_fields_for_response( $request );
		$schema   = $this->get_item_schema();

		/**
		 * Response data.
		 *
		 * @var array<string,mixed> $data
		 */
		$data = $response->get_data();

		if ( ! empty( $schema['properties']['story_data'] ) && rest_is_field_included( 'story_data', $fields ) ) {
			$post_story_data    = json_decode( $post->post_content_filtered, true );
			$data['story_data'] = rest_sanitize_value_from_schema( $post_story_data, $schema['properties']['story_data'] );
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->filter_response_by_context( $data, $context );
		$links   = $response->get_links();

		// Wrap the data in a response object.
		$response = new WP_REST_Response( $data );
		foreach ( $links as $rel => $rel_links ) {
			foreach ( $rel_links as $link ) {
				// @phpstan-ignore method.internal (false positive)
				$response->add_link( $rel, $link['href'], $link['attributes'] );
			}
		}

		/** This filter is documented in wp-includes/rest-api/endpoints/class-wp-rest-autosaves-controller.php */
		return apply_filters( 'rest_prepare_autosave', $response, $post, $request );
	}

	/**
	 * Retrieves the story's schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema data.
	 *
	 * @phpstan-return Schema
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			/**
			 * Schema.
			 *
			 * @phpstan-var Schema $schema
			 */
			$schema = $this->add_additional_fields_schema( $this->schema );
			return $schema;
		}

		$autosaves_schema = parent::get_item_schema();
		$stories_schema   = $this->parent_controller->get_item_schema();

		$autosaves_schema['properties']['story_data'] = $stories_schema['properties']['story_data'];

		$this->schema = $autosaves_schema;

		/**
		 * Schema.
		 *
		 * @phpstan-var Schema $schema
		 */
		$schema = $this->add_additional_fields_schema( $this->schema );
		return $schema;
	}
}
