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
    <section class="analytics__grid" data-analytics-root>
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
        <article class="analytics-card" data-analytics-panel="durations">
            <header class="analytics-card__header">
                <h2 class="analytics-card__title">Runtime Drift</h2>
                <p class="analytics-card__meta">Average show length by year</p>
            </header>
            <div class="analytics-card__body">
                <div class="analytics-line" data-analytics-durations></div>
            </div>
        </article>
    </section>
</main>
<?php
get_footer();
