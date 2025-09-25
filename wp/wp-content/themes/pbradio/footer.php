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
<div id="search-overlay" class="search-overlay" aria-hidden="true">
    <div class="search-overlay__backdrop" data-overlay-close></div>
    <div class="search-overlay__panel" role="dialog" aria-modal="true" aria-labelledby="search-overlay-title">
        <div class="search-overlay__header">
            <h2 id="search-overlay-title" class="search-overlay__title">Quick Scan</h2>
            <button type="button" class="search-overlay__close" data-overlay-close aria-label="Close search">
                <span aria-hidden="true">✕</span>
            </button>
        </div>
        <div class="search-overlay__control">
            <label class="search-overlay__label" for="search-overlay-input">
                <span class="visually-hidden">Search shows</span>
            </label>
            <input id="search-overlay-input" class="search-overlay__input" type="search" name="q" autocomplete="off" placeholder="Search shows, tags, tracks…" />
            <div class="search-overlay__hint" aria-hidden="true">
                Use ⬆︎/⬇︎ to highlight, Enter to tune in
            </div>
        </div>
        <div id="search-overlay-results" class="search-overlay__results" role="listbox" aria-label="Search results" aria-live="polite">
            <p class="search-overlay__empty">Type to scan the airwaves.</p>
        </div>
    </div>
</div>
<aside id="show-drawer" class="show-drawer" aria-hidden="true" aria-labelledby="show-drawer-heading">
    <div class="show-drawer__scrim" data-drawer-close></div>
    <div class="show-drawer__inner">
        <button type="button" class="show-drawer__close" data-drawer-close aria-label="Close show details">
            <span aria-hidden="true">✕</span>
        </button>
        <div id="show-drawer-content" class="show-drawer__content">
            <p class="show-drawer__empty">Choose a broadcast to view the tracklist.</p>
        </div>
    </div>
</aside>
<?php wp_footer(); ?>
</body>
</html>
