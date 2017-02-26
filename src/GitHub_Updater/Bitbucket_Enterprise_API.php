<?php
/**
 * GitHub Updater
 *
 * @package   GitHub_Updater
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 */

namespace Fragen\GitHub_Updater;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Bitbucket_Enterprise_API
 *
 * Get remote data from a self-hosted Bitbucket Server repo.
 * Assumes an owner == project_key
 *
 * @package Fragen\GitHub_Updater
 * @author  Andy Fragen
 * @author  Bjorn Wijers
 */
class Bitbucket_Enterprise_API extends Bitbucket_API {

	/**
	 * Holds loose class method name.
	 *
	 * @var null
	 */
	private static $method = null;

	/**
	 * Constructor.
	 *
	 * @param object $type
	 */
	public function __construct( $type ) {
		$this->type     = $type;
		$this->response = $this->get_repo_cache();

		$this->load_hooks();

		if ( ! isset( self::$options['bitbucket_enterprise_username'] ) ) {
			self::$options['bitbucket_enterprise_username'] = null;
		}
		if ( ! isset( self::$options['bitbucket_enterprise_password'] ) ) {
			self::$options['bitbucket_enterprise_password'] = null;
		}
		add_site_option( 'github_updater', self::$options );
	}

	/**
	 * Load hooks for Bitbucket authentication headers.
	 */
	public function load_hooks() {
		add_filter( 'http_request_args', array( &$this, 'maybe_authenticate_http' ), 5, 2 );
		add_filter( 'http_request_args', array( &$this, 'http_release_asset_auth' ), 15, 2 );
	}

	/**
	 * Remove hooks for Bitbucket authentication headers.
	 */
	public function remove_hooks() {
		remove_filter( 'http_request_args', array( &$this, 'maybe_authenticate_http' ) );
		remove_filter( 'http_request_args', array( &$this, 'http_release_asset_auth' ) );
		remove_filter( 'http_request_args', array( &$this, 'ajax_maybe_authenticate_http' ) );
	}

	/**
	 * Read the remote file and parse headers.
	 *
	 * @param $file
	 *
	 * @return bool
	 */
	public function get_remote_info( $file ) {
		$response = isset( $this->response[ $file ] ) ? $this->response[ $file ] : false;

		if ( ! $response ) {
			self::$method = 'file';
			$path         = '/1.0/projects/:owner/repos/:repo/browse/' . $file;

			$response = $this->api( $path );

			if ( $response ) {
				$contents = $this->bbenterprise_recombine_response( $response );
				$response = $this->get_file_headers( $contents, $this->type->type );
				$this->set_repo_cache( $file, $response );
			}
		}

		if ( $this->validate_response( $response ) || ! is_array( $response ) ) {
			return false;
		}

		$response['dot_org'] = $this->get_dot_org_data();
		$this->set_file_info( $response );

		return true;
	}

	/**
	 * Get the remote info for tags.
	 *
	 * @return bool
	 */
	public function get_remote_tag() {
		$repo_type = $this->return_repo_type();
		$response  = isset( $this->response['tags'] ) ? $this->response['tags'] : false;

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo/tags' );

			if ( ! $response ||
			     ( isset( $response->size ) && $response->size < 1 ) ||
			     isset( $response->errors )
			) {
				$response          = new \stdClass();
				$response->message = 'No tags found';
			}

			if ( $response ) {
				$response = $this->parse_tag_response( $response );
				$this->set_repo_cache( 'tags', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->parse_tags( $response, $repo_type );

		return true;
	}

	/**
	 * Read the remote CHANGES.md file
	 *
	 * @param $changes
	 *
	 * @return bool
	 */
	public function get_remote_changes( $changes ) {
		$response = isset( $this->response['changes'] ) ? $this->response['changes'] : false;

		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type ) ) {
			$response = array();
			$content  = $this->get_local_info( $this->type, $changes );
			if ( $content ) {
				$response['changes'] = $content;
				$this->set_repo_cache( 'changes', $response );
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			self::$method = 'changes';
			$response     = $this->bbenterprise_fetch_raw_file( $changes );

			if ( ! $response ) {
				$response          = new \stdClass();
				$response->message = 'No changelog found';
			}

			if ( $response ) {
				$response = wp_remote_retrieve_body( $response );
				$response = $this->parse_changelog_response( $response );
				$this->set_repo_cache( 'changes', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$parser    = new \Parsedown;
		$changelog = $parser->text( $response['changes'] );

		$this->type->sections['changelog'] = $changelog;

		return true;
	}

	/**
	 * Read and parse remote readme.txt.
	 *
	 * @return bool
	 */
	public function get_remote_readme() {
		if ( ! $this->exists_local_file( 'readme.txt' ) ) {
			return false;
		}

		$response = isset( $this->response['readme'] ) ? $this->response['readme'] : false;

		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type ) ) {
			$response = new \stdClass();
			$content  = $this->get_local_info( $this->type, 'readme.txt' );
			if ( $content ) {
				$response->data = $content;
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			self::$method = 'readme';
			$response     = $this->bbenterprise_fetch_raw_file( 'readme.txt' );

			if ( ! $response ) {
				$response          = new \stdClass();
				$response->message = 'No readme found';
			}

			if ( $response ) {
				$response = wp_remote_retrieve_body( $response );
				$response = $this->parse_readme_response( $response );
			}
		}

		if ( $response && isset( $response->data ) ) {
			$file     = $response->data;
			$parser   = new Readme_Parser( $file );
			$response = $parser->parse_data();
			$this->set_repo_cache( 'readme', $response );
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->set_readme_info( $response );

		return true;
	}

	/**
	 * Read the repository meta from API
	 *
	 * @return bool
	 */
	public function get_repo_meta() {
		$response = isset( $this->response['meta'] ) ? $this->response['meta'] : false;

		if ( $this->exit_no_update( $response ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo' );

			if ( $response ) {
				$response = $this->parse_meta_response( $response );
				$this->set_repo_cache( 'meta', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->repo_meta = $response;
		$this->add_meta_repo_object();

		return true;
	}

	/**
	 * Create array of branches and download links as array.
	 *
	 * @return bool
	 */
	public function get_remote_branches() {
		$branches = array();
		$response = isset( $this->response['branches'] ) ? $this->response['branches'] : false;

		if ( $this->exit_no_update( $response, true ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo/branches' );
			if ( $response->values ) {
				foreach ( $response->values as $value ) {
					$branch              = $value->displayId;
					$branches[ $branch ] = $this->construct_download_link( false, $branch );
				}
				$this->type->branches = $branches;
				$this->set_repo_cache( 'branches', $branches );

				return true;
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->branches = $response;

		return true;
	}

	/**
	 * Construct $this->type->download_link using Bitbucket API
	 *
	 * @param boolean $rollback      for theme rollback
	 * @param boolean $branch_switch for direct branch changing
	 *
	 * @return string $endpoint
	 */
	public function construct_download_link( $rollback = false, $branch_switch = false ) {
		/**
		 * Downloads requires the official stash-archive plugin which enables
		 * subdirectory support using the prefix query argument
		 *
		 * @link https://bitbucket.org/atlassian/stash-archive
		 */
		$download_link_base = implode( '/', array(
			$this->type->enterprise,
			'rest/archive/1.0/projects',
			$this->type->owner,
			'repos',
			$this->type->repo,
			'archive',
		) );

		self::$method = 'download_link';
		$endpoint     = $this->add_endpoints( $this, '' );

		if ( $branch_switch ) {
			$endpoint = urldecode( add_query_arg( 'at', $branch_switch, $endpoint ) );
		}

		return $download_link_base . $endpoint;
	}

	/**
	 * Create Bitbucket Server API endpoints.
	 *
	 * @param $git      object
	 * @param $endpoint string
	 *
	 * @return string
	 */
	protected function add_endpoints( $git, $endpoint ) {
		switch ( self::$method ) {
			case 'meta':
			case 'tags':
			case 'translation':
				break;
			case 'file':
			case 'readme':
				$endpoint = add_query_arg( 'at', $git->type->branch, $endpoint );
				break;
			case 'changes':
				$endpoint = add_query_arg( array( 'at' => $git->type->branch, 'raw' => '' ), $endpoint );
				break;
			case 'download_link':
				/*
				 * Add a prefix query argument to create a subdirectory with the same name
				 * as the repo, e.g. 'my-repo' becomes 'my-repo/'
				 * Required for using stash-archive.
				 */
				$defaults = array( 'prefix' => $git->type->repo . '/', 'at' => $git->type->branch );
				$endpoint = add_query_arg( $defaults, $endpoint );
				if ( ! empty( $git->type->tags ) ) {
					$endpoint = urldecode( add_query_arg( 'at', $git->type->newest_tag, $endpoint ) );
				}
				break;
			default:
				break;
		}

		return $endpoint;
	}

	/**
	 * Get/process Language Packs.
	 *
	 * @TODO Bitbucket Enterprise
	 *
	 * @param array $headers Array of headers of Language Pack.
	 *
	 * @return bool When invalid response.
	 */
	public function get_language_pack( $headers ) {
		$response = ! empty( $this->response['languages'] ) ? $this->response['languages'] : false;
		$type     = explode( '_', $this->type->type );

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/' . $headers['owner'] . '/' . $headers['repo'] . '/src/master/language-pack.json' );

			if ( $this->validate_response( $response ) ) {
				return false;
			}

			if ( $response ) {
				$response = json_decode( $response->data );

				foreach ( $response as $locale ) {
					$package = array( 'https://bitbucket.org', $headers['owner'], $headers['repo'], 'raw/master' );
					$package = implode( '/', $package ) . $locale->package;

					$response->{$locale->language}->package = $package;
					$response->{$locale->language}->type    = $type[1];
					$response->{$locale->language}->version = $this->type->remote_version;
				}

				$this->set_repo_cache( 'languages', $response );
			}
		}
		$this->type->language_packs = $response;
	}

	/**
	 * The Bitbucket Server REST API does not support downloading files directly at the moment
	 * therefore we'll use this to construct urls to fetch the raw files ourselves.
	 *
	 * @param string $file filename
	 *
	 * @return bool|array false upon failure || return wp_safe_remote_get() response array
	 **/
	private function bbenterprise_fetch_raw_file( $file ) {
		$file         = urlencode( $file );
		$download_url = '/1.0/projects/:owner/repos/:repo/browse/' . $file;
		$download_url = $this->add_endpoints( $this, $download_url );
		$download_url = $this->get_api_url( $download_url );

		$response = wp_safe_remote_get( $download_url );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return $response;
	}

	/**
	 * Combines separate text lines from API response into one string with \n line endings.
	 * Code relying on raw text can now parse it.
	 *
	 * @param string $response
	 *
	 * @return string Combined lines of text returned by API
	 */
	private function bbenterprise_recombine_response( $response ) {
		$remote_info_file = '';
		$json_decoded     = is_string( $response ) ? json_decode( $response ) : null;
		$response         = empty( $json_decoded ) ? $response : $json_decoded;
		if ( isset( $response->lines ) ) {
			foreach ( $response->lines as $line ) {
				$remote_info_file .= $line->text . "\n";
			}
		}

		return $remote_info_file;
	}

	/**
	 * Parse API response and return array of meta variables.
	 *
	 * @param object $response Response from API call.
	 *
	 * @return array $arr Array of meta variables.
	 */
	protected function parse_meta_response( $response ) {
		$arr      = array();
		$response = array( $response );

		array_filter( $response, function( $e ) use ( &$arr ) {
			$arr['private']      = ! $e->public;
			$arr['last_updated'] = null;
			$arr['watchers']     = 0;
			$arr['forks']        = 0;
			$arr['open_issues']  = 0;
		} );

		return $arr;
	}

	/**
	 * Parse API response and return array with changelog.
	 *
	 * @param object $response Response from API call.
	 *
	 * @return array $arr Array of changes in base64.
	 */
	protected function parse_changelog_response( $response ) {
		return array( 'changes' => $this->bbenterprise_recombine_response( $response ) );
	}

	/**
	 * Parse API response and return object with readme body.
	 *
	 * @param string $response
	 *
	 * @return object $response
	 */
	protected function parse_readme_response( $response ) {
		$content        = $this->bbenterprise_recombine_response( $response );
		$response       = new \stdClass();
		$response->data = $content;

		return $response;
	}
}