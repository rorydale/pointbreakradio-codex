<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
// define( 'DB_NAME', 'db' );

/** Database username */
// define( 'DB_USER', 'db' );

/** Database password */
// define( 'DB_PASSWORD', 'db' );

/** Database hostname */
// define( 'DB_HOST', 'db' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '%th@qao#IB]5UOy7?d]Ze#.,[0aMl)@HVB5eYAbgC^Rm^Y:Ya!0{eG-lBMV.]#(6' );
define( 'SECURE_AUTH_KEY',   'Y6f%~8hrR[eC};Bx ?YYc1/J#Y|@bLk<`.5}=xq?08rMG@4 -vyBnV96$yR#4z,J' );
define( 'LOGGED_IN_KEY',     'P ePz&W~nz3j?<8x%I6=xy[P[U*0{Dq,GQMx[*gu5B*5btevwD&z&X&5gs+IuEP1' );
define( 'NONCE_KEY',         'N )Fw$PBd|k|mtz-t2K8<INvz09/W6R#7@t7I|w4Gps3 ^>V>?%nrF4FZkMaufmB' );
define( 'AUTH_SALT',         '#9n7xo47){oux{wrv<;!xMPb/nKr!|FQJe,uH{@VX(6)j>T}axZ<>I`17WE[s/bi' );
define( 'SECURE_AUTH_SALT',  'F81~7i|]g:PSPcA;J?$}{:>qBC+gv|Yx)h>nD}x{~xw-UFvMB<$%jTom_co0%o&&' );
define( 'LOGGED_IN_SALT',    'G&;ShpEH}2UBX9g53_ytZ+:q-+.6<E*jnS=,n*y(}69/V`^zZKz~K0u+[W(|FsqG' );
define( 'NONCE_SALT',        'sA =7Qdo2nt$:#]:H+.d_[:<nXS/hPRFv3b>~&(Cp1)LMzT8bHE7BHak,0G+qZ?n' );
define( 'WP_CACHE_KEY_SALT', ';Eje`X9UKMPHpVQ<KW$^>=[%YJQP7G2N*RLlLXDXQREa054%u!+![@c71R*fN$I^' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */

define('WP_CONTENT_DIR', dirname(__DIR__) . '/wp-content');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('WP_CONTENT_URL', $scheme . '://' . $host . '/wp-content');


/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
// Include for ddev-managed settings in wp-config-ddev.php.
$ddev_settings = __DIR__ . '/wp-config-ddev.php';
if (is_readable($ddev_settings) && ! defined('DB_USER')) {
    require_once $ddev_settings;
}

require_once ABSPATH . 'wp-settings.php';
