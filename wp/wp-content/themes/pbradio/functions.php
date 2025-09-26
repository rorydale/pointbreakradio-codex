<?php

if (! defined('ABSPATH')) {
    exit;
}

defined('PBRADIO_VERSION') || define('PBRADIO_VERSION', wp_get_theme()->get('Version'));

defined('PBRADIO_TEXT_DOMAIN') || define('PBRADIO_TEXT_DOMAIN', 'pbradio');

add_action('after_setup_theme', function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);

    register_nav_menus([
        'primary' => __('Primary Menu', PBRADIO_TEXT_DOMAIN),
    ]);
});

add_action('wp_enqueue_scripts', function (): void {
    $theme = wp_get_theme();
    $version = $theme->get('Version') ?: PBRADIO_VERSION;
    $dir = get_template_directory_uri();

    wp_enqueue_style('pbradio-app', $dir . '/assets/app.css', [], $version);
    wp_enqueue_script('pbradio-app', $dir . '/assets/app.js', [], $version, true);

    wp_localize_script('pbradio-app', 'PBRadioSettings', [
        'restBase' => esc_url_raw(rest_url('pbr/v1')),
        'siteTitle' => get_bloginfo('name'),
        'themeUrl' => esc_url_raw(get_template_directory_uri()),
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
});
