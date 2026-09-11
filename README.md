# WordPress Feature

Declarative WordPress hook registration for PHP feature classes.

`Urlund\WordPress\Feature` lets you declare `$filters` and `$actions` on a class. On construct, hooks are registered automatically. Parent declarations are merged, instances are tracked in a registry, and hooks can be added, removed, paused, or resumed at runtime.

## Requirements

- PHP 7.4+
- WordPress (uses `add_filter` / `add_action` / `_doing_it_wrong`)

## Installation

```bash
composer require urlund/wordpress-feature
```

Available on [Packagist](https://packagist.org/packages/urlund/wordpress-feature). Composer PSR-4 autoloads `Urlund\WordPress\Feature` from `src/Feature.php`.

You can also load it manually without Composer. Copy [`src/Feature.php`](src/Feature.php) into your project and require it once:

```php
require_once __DIR__ . '/inc/class-feature.php';
```

The file is guarded with `class_exists`, so it is safe to `require_once` from a plugin and a theme, or alongside Composer.

```php
use Urlund\WordPress\Feature;
```

## Quick start

```php
use Urlund\WordPress\Feature;

class Auth extends Feature {
    protected $filters = [
        'wp_authenticate_user',
        'woocommerce_login_redirect' => 20,
        'foo_filter' => 'bar_callback',
        'baz_filter' => ['baz_callback', 20],
    ];

    protected $actions = [
        'init',
        'admin_init' => 20,
        'wp_loaded' => 'setup',
    ];

    public function wp_authenticate_user( WP_User $user ) {
        return $user;
    }

    public function woocommerce_login_redirect( string $redirect, WP_User $user ): string {
        return $redirect;
    }

    public function bar_callback( $value, $param1, $param2 ) {
        return $value;
    }

    public function baz_callback( $value, $param1 ) {
        return $value;
    }

    public function init(): void {
    }

    public function admin_init(): void {
    }

    public function setup(): void {
    }
}

Feature::bootstrap( Auth::class );
```

## Hook DSL

Each `$filters` / `$actions` entry normalizes to hook name, callback method, and priority. The same entry shapes apply to both. `accepted_args` is taken from `ReflectionMethod::getNumberOfParameters()` on the callback.

| Entry | Hook | Method | Priority |
| --- | --- | --- | --- |
| `'wp_authenticate_user'` | same | same | `10` |
| `'admin_init' => 20` | same | same | `20` |
| `'foo_filter' => 'bar_callback'` | `foo_filter` | `bar_callback` | `10` |
| `'wp_loaded' => ['setup', 20]` | `wp_loaded` | `setup` | `20` |

Invalid shapes throw `InvalidArgumentException`. Missing callback methods also throw.

## Parent merging

Redeclaring `$filters` / `$actions` on a child replaces the PHP property, so Feature walks the class hierarchy with Reflection and merges by hook name. Children override parents for the same hook.

```php
class BaseAuth extends Feature {
    protected $filters = [ 'the_content' ];
    protected $actions = [ 'init' ];
}

class Auth extends BaseAuth {
    protected $filters = [
        'wp_authenticate_user',
        'the_content' => 20, // overrides parent priority
    ];

    protected $actions = [
        'admin_init',
        'init' => 20, // overrides parent priority
    ];
}
```

## Bootstrap

Instantiate one or many features. Optional file paths load classes that are not autoloaded yet (`Auth::class` is a compile-time string and does not require the class to exist).

```php
Feature::bootstrap( Auth::class );

Feature::bootstrap( [
    Auth::class,
    Checkout::class,
] );

Feature::bootstrap( [
    Auth::class => __DIR__ . '/features/class-auth.php',
    Checkout::class,
] );
```

Already-registered features are skipped. Missing classes trigger `_doing_it_wrong`.

## Registry

```php
if ( Feature::has( Auth::class ) ) {
    Feature::get( Auth::class )->remove_filter( 'baz_filter' );
    Feature::get( Auth::class )->remove_action( 'init' );
}
```

Constructing the same feature class twice triggers `_doing_it_wrong` and does not re-register hooks. The first instance stays canonical in `get()`.

## Runtime add / remove / pause

Same config shapes as the DSL value side (`null`, `int`, `string`, or `[method, priority]`):

```php
$auth = Feature::get( Auth::class );

$auth->add_filter( 'the_content', 20 );
$auth->add_action( 'init', 'setup' );
$auth->remove_filter( 'the_content' );
$auth->remove_action( 'init' );
```

`remove_*` uses the instance registry so WordPress receives the original `[$this, $method]` callback and priority. Remove is permanent (metadata is discarded).

Pause temporarily detaches a hook but keeps method and priority so it can be resumed later:

```php
$auth->pause_filter( 'baz_filter' );
$auth->resume_filter( 'baz_filter' );
$auth->pause_action( 'init' );
$auth->resume_action( 'init' );
```

Other features (or any plugin code) can pause or resume another feature's hooks via the registry:

```php
Feature::get( Auth::class )->pause_filter( 'baz_filter' );
Feature::get( Auth::class )->resume_filter( 'baz_filter' );
Feature::get( Auth::class )->pause_action( 'init' );
Feature::get( Auth::class )->resume_action( 'init' );
```

## License

MIT
