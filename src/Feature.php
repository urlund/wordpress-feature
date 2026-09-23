<?php

/**
 * Base class for declarative WordPress hook features.
 *
 * @package Urlund\WordPress
 */

namespace Urlund\WordPress;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
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
	 *     filter: array<string, array<string, array{method: string, priority: int}>>,
	 *     action: array<string, array<string, array{method: string, priority: int}>>
	 * }
	 */
	private $registered = array(
		'filter' => array(),
		'action' => array(),
	);

	/**
	 * @var array{
	 *     filter: array<string, array<string, array{method: string, priority: int}>>,
	 *     action: array<string, array<string, array{method: string, priority: int}>>
	 * }
	 */
	private $paused = array(
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
				'1.0.0'
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
				'1.0.0'
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
						'1.0.0'
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
						'1.0.0'
					);
					continue;
				}
				$class = $value;
			}

			if ( ! class_exists( $class, false ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf( 'Feature class %s could not be loaded.', $class ),
					'1.0.0'
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
	 * @param null|int|string|array{0: string, 1: int}|array<string, int> $config Hook config.
	 */
	public function add_filter( string $hook, $config = null ): void {
		$this->register_hook( 'filter', $hook, $config );
	}

	public function remove_filter( string $hook, ?string $method = null ): void {
		$this->unregister_hook( 'filter', $hook, $method );
	}

	public function pause_filter( string $hook, ?string $method = null ): void {
		$this->pause_hook( 'filter', $hook, $method );
	}

	public function resume_filter( string $hook, ?string $method = null ): void {
		$this->resume_hook( 'filter', $hook, $method );
	}

	/**
	 * @param null|int|string|array{0: string, 1: int}|array<string, int> $config Hook config.
	 */
	public function add_action( string $hook, $config = null ): void {
		$this->register_hook( 'action', $hook, $config );
	}

	public function remove_action( string $hook, ?string $method = null ): void {
		$this->unregister_hook( 'action', $hook, $method );
	}

	public function pause_action( string $hook, ?string $method = null ): void {
		$this->pause_hook( 'action', $hook, $method );
	}

	public function resume_action( string $hook, ?string $method = null ): void {
		$this->resume_hook( 'action', $hook, $method );
	}

	/**
	 * @return array<string, null|int|string|array{0: string, 1: int}|array<string, int>>
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
	 * @return array{0: string, 1: null|int|string|array{0: string, 1: int}|array<string, int>}
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

		if ( $this->is_method_priority_tuple( $value ) ) {
			return array( $key, $value );
		}

		if ( $this->is_method_priority_map( $value ) ) {
			return array( $key, $value );
		}

		throw new InvalidArgumentException(
			sprintf( 'Invalid hook config for "%s" in %s.', $key, static::class )
		);
	}

	/**
	 * @param mixed $value Candidate config value.
	 */
	protected function is_method_priority_tuple( $value ): bool {
		return is_array( $value )
			&& isset( $value[0], $value[1] )
			&& is_string( $value[0] )
			&& is_int( $value[1] )
			&& count( $value ) === 2;
	}

	/**
	 * @param mixed $value Candidate config value.
	 */
	protected function is_method_priority_map( $value ): bool {
		if ( ! is_array( $value ) || $value === array() ) {
			return false;
		}

		foreach ( $value as $method => $priority ) {
			if ( ! is_string( $method ) || $method === '' || ! is_int( $priority ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param null|int|string|array{0: string, 1: int}|array<string, int> $config Hook config.
	 * @return array<int, array{0: string, 1: int}>
	 */
	protected function expand_hook_configs( string $hook, $config ): array {
		if ( $config === null ) {
			return array( array( $hook, 10 ) );
		}

		if ( is_int( $config ) ) {
			return array( array( $hook, $config ) );
		}

		if ( is_string( $config ) ) {
			return array( array( $config, 10 ) );
		}

		if ( $this->is_method_priority_tuple( $config ) ) {
			return array( array( $config[0], $config[1] ) );
		}

		if ( $this->is_method_priority_map( $config ) ) {
			$pairs = array();
			foreach ( $config as $method => $priority ) {
				$pairs[] = array( $method, $priority );
			}
			return $pairs;
		}

		throw new InvalidArgumentException(
			sprintf( 'Invalid hook config for "%s" in %s.', $hook, static::class )
		);
	}

	/**
	 * Resolve a callback name to a WordPress callable and accepted_args.
	 *
	 * Prefers a class method, then falls back to a global function.
	 *
	 * @return array{0: callable, 1: int}
	 */
	private function resolve_callback( string $name, string $hook ): array {
		if ( method_exists( $this, $name ) ) {
			$ref = new ReflectionMethod( $this, $name );

			return array( array( $this, $name ), $ref->getNumberOfParameters() );
		}

		if ( function_exists( $name ) ) {
			$ref = new ReflectionFunction( $name );

			return array( $name, $ref->getNumberOfParameters() );
		}

		throw new InvalidArgumentException(
			sprintf(
				'Feature %s has no method or function %s for hook %s.',
				static::class,
				$name,
				$hook
			)
		);
	}

	/**
	 * @param null|int|string|array{0: string, 1: int}|array<string, int> $config Hook config.
	 */
	private function register_hook( string $type, string $hook, $config ): void {
		foreach ( $this->expand_hook_configs( $hook, $config ) as $pair ) {
			list( $method, $priority ) = $pair;
			$this->register_callback( $type, $hook, $method, $priority );
		}
	}

	private function register_callback( string $type, string $hook, string $method, int $priority ): void {
		$this->unregister_hook( $type, $hook, $method );

		list( $callback, $accepted_args ) = $this->resolve_callback( $method, $hook );

		if ( 'filter' === $type ) {
			add_filter( $hook, $callback, $priority, $accepted_args );
		} else {
			add_action( $hook, $callback, $priority, $accepted_args );
		}

		$this->registered[ $type ][ $hook ][ $method ] = array(
			'method'   => $method,
			'priority' => $priority,
		);
	}

	private function unregister_hook( string $type, string $hook, ?string $method = null ): void {
		if ( null === $method ) {
			if ( isset( $this->registered[ $type ][ $hook ] ) ) {
				foreach ( $this->registered[ $type ][ $hook ] as $entry ) {
					$this->detach_callback( $type, $hook, $entry );
				}
				unset( $this->registered[ $type ][ $hook ] );
			}

			unset( $this->paused[ $type ][ $hook ] );
			return;
		}

		if ( isset( $this->registered[ $type ][ $hook ][ $method ] ) ) {
			$this->detach_callback( $type, $hook, $this->registered[ $type ][ $hook ][ $method ] );
			unset( $this->registered[ $type ][ $hook ][ $method ] );

			if ( empty( $this->registered[ $type ][ $hook ] ) ) {
				unset( $this->registered[ $type ][ $hook ] );
			}
		}

		if ( isset( $this->paused[ $type ][ $hook ][ $method ] ) ) {
			unset( $this->paused[ $type ][ $hook ][ $method ] );

			if ( empty( $this->paused[ $type ][ $hook ] ) ) {
				unset( $this->paused[ $type ][ $hook ] );
			}
		}
	}

	/**
	 * @param array{method: string, priority: int} $entry Registered or paused callback entry.
	 */
	private function detach_callback( string $type, string $hook, array $entry ): void {
		list( $callback ) = $this->resolve_callback( $entry['method'], $hook );

		if ( 'filter' === $type ) {
			remove_filter( $hook, $callback, $entry['priority'] );
		} else {
			remove_action( $hook, $callback, $entry['priority'] );
		}
	}

	/**
	 * @param array{method: string, priority: int} $entry Paused callback entry.
	 */
	private function attach_callback( string $type, string $hook, array $entry ): void {
		$method   = $entry['method'];
		$priority = $entry['priority'];

		list( $callback, $accepted_args ) = $this->resolve_callback( $method, $hook );

		if ( 'filter' === $type ) {
			add_filter( $hook, $callback, $priority, $accepted_args );
		} else {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}

	private function pause_hook( string $type, string $hook, ?string $method = null ): void {
		if ( null === $method ) {
			if ( ! isset( $this->registered[ $type ][ $hook ] ) ) {
				return;
			}

			foreach ( array_keys( $this->registered[ $type ][ $hook ] ) as $registered_method ) {
				$this->pause_hook( $type, $hook, $registered_method );
			}
			return;
		}

		if ( ! isset( $this->registered[ $type ][ $hook ][ $method ] ) ) {
			return;
		}

		$entry = $this->registered[ $type ][ $hook ][ $method ];
		$this->detach_callback( $type, $hook, $entry );

		$this->paused[ $type ][ $hook ][ $method ] = $entry;
		unset( $this->registered[ $type ][ $hook ][ $method ] );

		if ( empty( $this->registered[ $type ][ $hook ] ) ) {
			unset( $this->registered[ $type ][ $hook ] );
		}
	}

	private function resume_hook( string $type, string $hook, ?string $method = null ): void {
		if ( null === $method ) {
			if ( ! isset( $this->paused[ $type ][ $hook ] ) ) {
				return;
			}

			foreach ( array_keys( $this->paused[ $type ][ $hook ] ) as $paused_method ) {
				$this->resume_hook( $type, $hook, $paused_method );
			}
			return;
		}

		if ( ! isset( $this->paused[ $type ][ $hook ][ $method ] ) ) {
			return;
		}

		$entry = $this->paused[ $type ][ $hook ][ $method ];
		$this->attach_callback( $type, $hook, $entry );

		$this->registered[ $type ][ $hook ][ $method ] = $entry;
		unset( $this->paused[ $type ][ $hook ][ $method ] );

		if ( empty( $this->paused[ $type ][ $hook ] ) ) {
			unset( $this->paused[ $type ][ $hook ] );
		}
	}
}
