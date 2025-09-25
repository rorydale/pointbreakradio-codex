<?php
/** @var string */
?>
<footer class="site-footer" role="contentinfo">
    <p class="site-footer__note">Broadcasting from the fringe since <?php echo esc_html(date('Y')); ?>.</p>
</footer>
<div id="sticky-player" class="player-bar" role="region" aria-label="Now Playing">
    <div class="player-bar__led" aria-hidden="true"></div>
    <div class="player-bar__meta">
        <span id="player-status" class="player-bar__status">Offline</span>
        <span id="player-title" class="player-bar__title">Loading the freshest signal...</span>
    </div>
    <div class="player-bar__vu" aria-hidden="true">
        <span class="player-bar__bar"></span>
        <span class="player-bar__bar"></span>
        <span class="player-bar__bar"></span>
        <span class="player-bar__bar"></span>
    </div>
    <div class="player-bar__embed" id="player-embed">
        <iframe
            id="player-iframe"
            title="Point Break Radio stream"
            src="about:blank"
            loading="lazy"
            allow="autoplay"
            allowtransparency="true"
            frameborder="0"
        ></iframe>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
