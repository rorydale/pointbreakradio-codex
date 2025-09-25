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
            <img src="<?php echo esc_url(get_template_directory_uri() . '/assets/logo.svg'); ?>" alt="" />
            <span class="masthead__wordmark">Point Break Radio</span>
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
    </div>
</header>
