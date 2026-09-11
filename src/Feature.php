<?php

/**
 * Base class for declarative WordPress hook features.
 *
 * @package Urlund\WordPress
 */

namespace Urlund\WordPress;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

if ( class_exists( __NAMESPACE__ . '\\Feature', false ) ) {
	return;
}

class Feature {

	/** @var array<int|string, mixed> */
	protected $actions = array();

	/** @var array<int|string, mixed> */
	protected $filters = array();

	/** @var array<string, self> */
	private static $instances = array();

	/**
	 * @var array{
	 *     filter: array<string, array{method: string, priority: int}>,
	 *     action: array<string, array{method: string, priority: int}>
	 * }
	 */
	private $registered = array(
		'filter' => array(),
		'action' => array(),
	);

	public function __construct() {
		if ( self::has( static::class ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: feature class name, 2: feature class name */
					'Feature %1$s already instantiated. Use Feature::get( %2$s::class ) instead.',
					static::class,
					static::class
				),
				defined( 'NKT_DEV_VERSION' ) ? NKT_DEV_VERSION : '1.0.0'
			);
			return;
		}

		self::$instances[ static::class ] = $this;

		foreach ( $this->collect_merged_hooks( 'filters' ) as $hook => $config ) {
			$this->add_filter( $hook, $config );
		}

		foreach ( $this->collect_merged_hooks( 'actions' ) as $hook => $config ) {
			$this->add_action( $hook, $config );
		}
	}

	/**
	 * @param class-string<self> $class Feature class name.
	 */
	public static function get( string $class ): ?self {
		return self::$instances[ $class ] ?? null;
	}

	/**
	 * @param class-string<self> $class Feature class name.
	 */
	public static function has( string $class ): bool {
		return isset( self::$instances[ $class ] );
	}

	/**
	 * Load (optional) and instantiate one or more features.
	 *
	 * @param class-string<self>|array<int|class-string<self>, class-string<self>|string> $features Class string or map/list of features.
	 */
	public static function bootstrap( $features ): void {
		if ( is_string( $features ) ) {
			$features = array( $features );
		}

		if ( ! is_array( $features ) ) {
			_doing_it_wrong(
				__METHOD__,
				'Expected a class string or an array of features.',
				defined( 'NKT_DEV_VERSION' ) ? NKT_DEV_VERSION : '1.0.0'
			);
			return;
		}

		foreach ( $features as $key => $value ) {
			if ( is_string( $key ) ) {
				$class = $key;
				$path  = $value;

				if ( ! is_string( $path ) ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf( 'Feature path for %s must be a string.', $class ),
						defined( 'NKT_DEV_VERSION' ) ? NKT_DEV_VERSION : '1.0.0'
					);
					continue;
				}

				if ( ! class_exists( $class, false ) ) {
					require_once $path;
				}
			} else {
				if ( ! is_string( $value ) ) {
					_doing_it_wrong(
						__METHOD__,
						'Feature list entries must be class strings.',
						defined( 'NKT_DEV_VERSION' ) ? NKT_DEV_VERSION : '1.0.0'
					);
					continue;
				}
				$class = $value;
			}

			if ( ! class_exists( $class, false ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Feature class %s could not be loaded.', $class ),
					defined( 'NKT_DEV_VERSION' ) ? NKT_DEV_VERSION : '1.0.0'
				);
				continue;
			}

			if ( self::has( $class ) ) {
				continue;
			}

			new $class();
		}
	}

	/**
	 * @param null|int|string|array{0: string, 1: int} $config Hook config.
	 */
	public function add_filter( string $hook, $config = null ): void {
		$this->register_hook( 'filter', $hook, $config );
	}

	public function remove_filter( string $hook ): void {
		$this->unregister_hook( 'filter', $hook );
	}

	/**
	 * @param null|int|string|array{0: string, 1: int} $config Hook config.
	 */
	public function add_action( string $hook, $config = null ): void {
		$this->register_hook( 'action', $hook, $config );
	}

	public function remove_action( string $hook ): void {
		$this->unregister_hook( 'action', $hook );
	}

	/**
	 * @return array<string, null|int|string|array{0: string, 1: int}>
	 */
	protected function collect_merged_hooks( string $property ): array {
		$merged      = array();
		$hierarchy   = array_values( array_reverse( class_parents( static::class ) ?: array() ) );
		$hierarchy[] = static::class;

		foreach ( $hierarchy as $class ) {
			$ref = new ReflectionClass( $class );

			if ( ! $ref->hasProperty( $property ) ) {
				continue;
			}

			$prop = $ref->getProperty( $property );
			if ( $prop->getDeclaringClass()->getName() !== $class ) {
				continue;
			}

			$defaults = $ref->getDefaultProperties();
			$entries  = $defaults[ $property ] ?? array();

			if ( ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $key => $value ) {
				list( $hook, $config ) = $this->normalize_hook_entry( $key, $value );
				$merged[ $hook ]       = $config;
			}
		}

		return $merged;
	}

	/**
	 * @param int|string $key   Array key from $filters / $actions.
	 * @param mixed      $value Array value from $filters / $actions.
	 * @return array{0: string, 1: null|int|string|array{0: string, 1: int}}
	 */
	protected function normalize_hook_entry( $key, $value ): array {
		if ( is_int( $key ) ) {
			if ( ! is_string( $value ) || $value === '' ) {
				throw new InvalidArgumentException(
					sprintf( 'Invalid hook entry in %s: list values must be non-empty hook name strings.', static::class )
				);
			}

			return array( $value, null );
		}

		if ( ! is_string( $key ) || $key === '' ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid hook entry in %s: hook names must be non-empty strings.', static::class )
			);
		}

		if ( $value === null || is_int( $value ) || is_string( $value ) ) {
			return array( $key, $value );
		}

		if (
			is_array( $value )
			&& isset( $value[0], $value[1] )
			&& is_string( $value[0] )
			&& is_int( $value[1] )
			&& count( $value ) === 2
		) {
			return array( $key, $value );
		}

		throw new InvalidArgumentException(
			sprintf( 'Invalid hook config for "%s" in %s.', $key, static::class )
		);
	}

	/**
	 * @param null|int|string|array{0: string, 1: int} $config Hook config.
	 * @return array{0: string, 1: int}
	 */
	protected function resolve_hook_registration( string $hook, $config ): array {
		$method   = $hook;
		$priority = 10;

		if ( $config === null ) {
			return array( $method, $priority );
		}

		if ( is_int( $config ) ) {
			return array( $method, $config );
		}

		if ( is_string( $config ) ) {
			return array( $config, $priority );
		}

		if ( is_array( $config ) ) {
			return array( $config[0], $config[1] );
		}

		throw new InvalidArgumentException(
			sprintf( 'Invalid hook config for "%s" in %s.', $hook, static::class )
		);
	}

	protected function resolve_accepted_args( string $method ): int {
		$ref = new ReflectionMethod( $this, $method );

		return $ref->getNumberOfParameters();
	}

	/**
	 * @param null|int|string|array{0: string, 1: int} $config Hook config.
	 */
	private function register_hook( string $type, string $hook, $config ): void {
		if ( isset( $this->registered[ $type ][ $hook ] ) ) {
			$this->unregister_hook( $type, $hook );
		}

		list( $method, $priority ) = $this->resolve_hook_registration( $hook, $config );

		if ( ! method_exists( $this, $method ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Feature %s has no method %s for hook %s.', static::class, $method, $hook )
			);
		}

		$accepted_args = $this->resolve_accepted_args( $method );
		$callback      = array( $this, $method );

		if ( 'filter' === $type ) {
			add_filter( $hook, $callback, $priority, $accepted_args );
		} else {
			add_action( $hook, $callback, $priority, $accepted_args );
		}

		$this->registered[ $type ][ $hook ] = array(
			'method'   => $method,
			'priority' => $priority,
		);
	}

	private function unregister_hook( string $type, string $hook ): void {
		if ( ! isset( $this->registered[ $type ][ $hook ] ) ) {
			return;
		}

		$entry    = $this->registered[ $type ][ $hook ];
		$callback = array( $this, $entry['method'] );

		if ( 'filter' === $type ) {
			remove_filter( $hook, $callback, $entry['priority'] );
		} else {
			remove_action( $hook, $callback, $entry['priority'] );
		}

		unset( $this->registered[ $type ][ $hook ] );
	}
}
