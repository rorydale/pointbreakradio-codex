<?php
/** @var string */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('pbradio-body'); ?>>
<a class="skip-link" href="#pbr-archive">Skip to main content</a>
<header class="site-header" role="banner">
    <div class="masthead">
        <div class="masthead__logo" role="img" aria-label="Point Break Radio">
            <img
                class="masthead__logoImage masthead__logoImage--horizontal"
                src="<?php echo esc_url(get_template_directory_uri() . '/assets/logo_hz.svg'); ?>"
                alt="Point Break Radio"
            />
            <img
                class="masthead__logoImage masthead__logoImage--stacked"
                src="<?php echo esc_url(get_template_directory_uri() . '/assets/logo.svg'); ?>"
                alt="Point Break Radio"
            />
        </div>
        <nav class="masthead__nav" aria-label="Main navigation">
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container' => false,
                'menu_class' => 'masthead__menu',
                'fallback_cb' => static function (): void {
                    echo '<ul class="masthead__menu"><li><a href="#shows">Archive</a></li><li><a href="#live">Live</a></li></ul>';
                },
            ]);
            ?>
        </nav>
        <div class="masthead__actions">
            <div class="masthead__live" data-live-indicator role="status" aria-live="polite">
                <span class="masthead__liveDot" aria-hidden="true"></span>
                <span class="masthead__liveLabel" data-live-label>Off Air</span>
            </div>
            <button type="button" class="masthead__search" data-open-search aria-haspopup="dialog">
                <span class="masthead__search-label">Search</span>
                <span class="masthead__key-hint" aria-hidden="true"><kbd>⌘</kbd><kbd>K</kbd></span>
                <span class="visually-hidden">Open search overlay (Ctrl+K on Windows)</span>
            </button>
        </div>
    </div>
</header>
