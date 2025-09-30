<?php
/**
 * Template Name: Broadcast Analytics
 */

global $post;

get_header();
?>
<main id="pbr-analytics" class="analytics" role="main">
    <section class="analytics__intro">
        <h1 class="analytics__title">Signal Intelligence Dashboard</h1>
        <p class="analytics__lede">A retro-futurist scan of the Point Break Radio archive. Tune into genre surges, broadcast stamina, and the tags that light up the dial.</p>
    </section>
    <nav class="analytics-tabs" role="tablist" aria-label="Analytics views" data-analytics-tabs>
        <button type="button" class="analytics-tabs__tab is-active" data-analytics-tab="foundation" id="analytics-tab-foundation" role="tab" aria-selected="true">
            Core Signals
        </button>
        <button type="button" class="analytics-tabs__tab" data-analytics-tab="stories" id="analytics-tab-stories" role="tab" aria-selected="false">
            Signal Stories
        </button>
    </nav>
    <section class="analytics__panel is-active" data-analytics-section="foundation" role="tabpanel" aria-labelledby="analytics-tab-foundation">
        <div class="analytics__grid" data-analytics-root>
            <article class="analytics-card" data-analytics-panel="totals">
                <header class="analytics-card__header">
                    <h2 class="analytics-card__title">Archive Pulse</h2>
                    <p class="analytics-card__meta">Live snapshot of the vault</p>
                </header>
                <div class="analytics-card__body">
                    <dl class="analytics-metrics" data-analytics-totals></dl>
                </div>
            </article>
            <article class="analytics-card" data-analytics-panel="years">
                <header class="analytics-card__header">
                    <h2 class="analytics-card__title">Broadcast Timeline</h2>
                    <p class="analytics-card__meta">Shows per year</p>
                </header>
                <div class="analytics-card__body">
                    <div class="analytics-bars" data-analytics-years></div>
                </div>
            </article>
            <article class="analytics-card" data-analytics-panel="tags">
                <header class="analytics-card__header">
                    <h2 class="analytics-card__title">Tag Frequency</h2>
                    <p class="analytics-card__meta">Most common vibes across the archive</p>
                </header>
                <div class="analytics-card__body">
                    <div class="analytics-cloud" data-analytics-tags></div>
                </div>
            </article>
            <article class="analytics-card" data-analytics-panel="fresh">
                <header class="analytics-card__header">
                    <h2 class="analytics-card__title">First Spins</h2>
                    <p class="analytics-card__meta">Artists making their PBR debut each year</p>
                </header>
                <div class="analytics-card__body">
                    <div class="analytics-line" data-analytics-fresh></div>
                </div>
            </article>
        </div>
    </section>
    <section class="analytics__panel" data-analytics-section="stories" role="tabpanel" aria-labelledby="analytics-tab-stories" hidden>
        <div class="analytics__grid analytics__grid--stories" data-analytics-stories>
            <article class="analytics-card" data-analytics-panel="legends">
                <header class="analytics-card__header">
                    <h2 class="analytics-card__title">Transmission Legends</h2>
                    <p class="analytics-card__meta">Artists who keep orbiting the Point Break signal</p>
                </header>
                <div class="analytics-card__body">
                    <ol class="analytics-legends" data-analytics-legends></ol>
                </div>
            </article>
            <article class="analytics-card" data-analytics-panel="spotlights">
                <header class="analytics-card__header">
                    <h2 class="analytics-card__title">Epic Broadcasts</h2>
                    <p class="analytics-card__meta">Shows that bent the schedule and packed the crates</p>
                </header>
                <div class="analytics-card__body">
                    <div class="analytics-spotlights" data-analytics-spotlights></div>
                </div>
            </article>
            <article class="analytics-card" data-analytics-panel="vibes">
                <header class="analytics-card__header">
                    <h2 class="analytics-card__title">Vibe Drift</h2>
                    <p class="analytics-card__meta">How flagship tags shift across the years</p>
                </header>
                <div class="analytics-card__body">
                    <div class="analytics-tagStories" data-analytics-tagstories></div>
                </div>
            </article>
        </div>
    </section>
</main>
<?php
get_footer();
