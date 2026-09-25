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

Each `$filters` / `$actions` entry normalizes to hook name, callback, and priority. The same entry shapes apply to both. Named callbacks resolve to a class method first, then to a global function (`function_exists`). `accepted_args` is taken from reflection on the resolved callback (`ReflectionMethod` or `ReflectionFunction`).

| Entry | Hook | Method | Priority |
| --- | --- | --- | --- |
| `'wp_authenticate_user'` | same | same | `10` |
| `'admin_init' => 20` | same | same | `20` |
| `'foo_filter' => 'bar_callback'` | `foo_filter` | `bar_callback` | `10` |
| `'wp_loaded' => ['setup', 20]` | `wp_loaded` | `setup` | `20` |
| `'wp_enqueue_scripts' => ['enqueue_styles', 'enqueue_scripts']` | `wp_enqueue_scripts` | both | `10` each |
| `'wp_enqueue_scripts' => ['enqueue_styles', ['enqueue_scripts', 20]]` | `wp_enqueue_scripts` | both | `10` / `20` |

A bare two-element `[method, priority]` value is always a **single** callback. For several callbacks on one hook, see [Multiple callbacks](#multiple-callbacks).

Global WordPress helpers work when named explicitly:

```php
protected $filters = [
    'show_admin_bar' => '__return_false',
    'woocommerce_enable_setup_wizard' => ['__return_false', 20],
];
```

Invalid shapes throw `InvalidArgumentException`. Missing callbacks (neither a class method nor a global function) also throw.

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

## Multiple callbacks

Most features need one callback per hook. When one feature must attach several methods to the same hook, use a method list. Bare method names default to priority `10`; nest a `[method, priority]` tuple for a custom priority:

```php
protected $actions = [
    'wp_enqueue_scripts' => [
        'enqueue_styles',
        'enqueue_scripts',
    ],
];

protected $actions = [
    'wp_enqueue_scripts' => [
        'enqueue_styles',
        'enqueue_more_styles',
        ['enqueue_scripts', 20],
    ],
];

public function enqueue_styles(): void {
}

public function enqueue_more_styles(): void {
}

public function enqueue_scripts(): void {
}
```

The same list form works on `$filters`.

A pure method→priority map is also valid when every callback has an explicit priority:

```php
protected $filters = [
    'the_content' => [
        'sanitize' => 10,
        'append'   => 20,
    ],
];
```

Do not mix bare method names with `method => priority` in one array (map-in-list). That shape is not supported — use a nested `[method, priority]` tuple instead:

```php
// Not supported — invalid
'wp_enqueue_scripts' => [
    'enqueue_styles',
    'enqueue_scripts' => 20,
],

// Supported — nested tuple
'wp_enqueue_scripts' => [
    'enqueue_styles',
    ['enqueue_scripts', 20],
],
```

Identity inside a feature is `(hook, method)`; priority is metadata only, not part of the key.

Parent merging still replaces the **whole** hook config when a child redeclares that hook (a list or map replaces a single callback and vice versa).

Target one callback or all for a hook:

```php
$auth->pause_filter( 'the_content' );           // all callbacks
$auth->pause_filter( 'the_content', 'append' ); // only append
$auth->resume_filter( 'the_content', 'append' );
$auth->remove_filter( 'the_content', 'sanitize' );
```

`resume_*` and `remove_*` use the same optional second `$method` argument (including for actions).

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

Same config shapes as the DSL value side (`null`, `int`, `string`, `[method, priority]`, a method→priority map, or a method list — see [Multiple callbacks](#multiple-callbacks)):

```php
$auth = Feature::get( Auth::class );

$auth->add_filter( 'the_content', 20 );
$auth->add_action( 'init', 'setup' );
$auth->remove_filter( 'the_content' );
$auth->remove_action( 'init' );
```

`remove_*` uses the instance registry so WordPress receives the original resolved callback (`[$this, $method]` or a global function name) and priority. Remove is permanent (metadata is discarded). An optional second `$method` argument targets one callback when several are registered on the same hook.

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
