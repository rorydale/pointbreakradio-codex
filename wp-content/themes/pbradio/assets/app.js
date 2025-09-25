(function () {
    const settings = window.PBRadioSettings || {};
    const restBase = settings.restBase ? settings.restBase.replace(/\/$/, '') : '/wp-json/pbr/v1';

    const selectors = {
        grid: document.querySelector('#shows-grid'),
        playerTitle: document.getElementById('player-title'),
        playerStatus: document.getElementById('player-status'),
        playerFrame: document.getElementById('player-iframe'),
        playerBar: document.getElementById('sticky-player'),
    };

    const state = {
        shows: [],
        currentShow: null,
        isLive: false,
    };

    function formatDuration(seconds) {
        const mins = Math.max(1, Math.round((seconds || 0) / 60));
        return `${mins} min${mins === 1 ? '' : 's'}`;
    }

    async function fetchJson(path, options = {}) {
        const isAbsolute = /^https?:/i.test(path);
        const url = isAbsolute ? path : `${restBase}${path}`;
        const headers = options.headers ? { ...options.headers } : {};

        if (options.method && options.method.toUpperCase() !== 'GET') {
            headers['Content-Type'] = 'application/json';
            if (settings.nonce) {
                headers['X-WP-Nonce'] = settings.nonce;
            }
        }

        const response = await fetch(url, { ...options, headers });

        if (!response.ok) {
            throw new Error(`Request failed (${response.status})`);
        }

        return response.json();
    }

    function setActiveShow(show, context = { autoplay: true }) {
        if (!show || !selectors.playerFrame) {
            return;
        }

        state.currentShow = show;
        selectors.playerTitle.textContent = show.title || 'Untitled Transmission';
        selectors.playerStatus.textContent = state.isLive ? 'Live' : 'Playback';
        selectors.playerStatus.setAttribute('data-live', state.isLive ? 'true' : 'false');

        const embedUrl = show.mixcloud_embed_url || show.mixcloud_url || '';
        if (embedUrl) {
            const url = new URL(embedUrl, window.location.origin);
            if (context.autoplay) {
                url.searchParams.set('autoplay', '1');
            }
            selectors.playerFrame.src = url.toString();
        }

        selectors.playerBar?.setAttribute('data-loaded', 'true');
        document.title = `${show.title} · ${settings.siteTitle || 'Point Break Radio'}`;
    }

    function renderShows() {
        if (!selectors.grid) {
            return;
        }

        selectors.grid.innerHTML = '';

        if (!state.shows.length) {
            const placeholder = document.createElement('div');
            placeholder.className = 'shows-grid__empty';
            placeholder.setAttribute('role', 'status');
            placeholder.textContent = 'No shows found yet. Check back soon.';
            selectors.grid.appendChild(placeholder);
            return;
        }

        state.shows.forEach((show, index) => {
            const article = document.createElement('article');
            article.className = 'show-card';

            const button = document.createElement('button');
            button.className = 'show-card__button';
            button.type = 'button';
            button.setAttribute('aria-label', `Play ${show.title}`);
            button.dataset.slug = show.slug;

            const media = document.createElement('div');
            media.className = 'show-card__media';
            media.style.backgroundImage = show.hero_image ? `url(${show.hero_image})` : 'radial-gradient(circle, rgba(255,79,216,0.32), rgba(5,5,16,0.9))';

            const body = document.createElement('div');
            body.className = 'show-card__body';

            const title = document.createElement('h2');
            title.className = 'show-card__title';
            title.textContent = show.title || 'Untitled Transmission';

            const meta = document.createElement('div');
            meta.className = 'show-card__meta';
            const parts = [];
            if (show.year) {
                parts.push(show.year);
            }
            if (show.duration_seconds) {
                parts.push(formatDuration(show.duration_seconds));
            }
            if (show.published_at) {
                const date = new Date(show.published_at);
                if (!Number.isNaN(date.valueOf())) {
                    parts.push(date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }));
                }
            }
            meta.textContent = parts.join(' • ');

            const tags = document.createElement('div');
            tags.className = 'show-card__tags';
            (show.tags || []).slice(0, 4).forEach((tag) => {
                const chip = document.createElement('span');
                chip.className = 'show-card__tag';
                chip.textContent = tag;
                tags.appendChild(chip);
            });

            const description = document.createElement('p');
            description.className = 'show-card__description';
            description.textContent = show.description || 'No description available yet.';

            const cta = document.createElement('span');
            cta.className = 'show-card__cta';
            cta.textContent = index === 0 ? 'Dial in' : 'Play show';

            body.append(title, meta);
            if (tags.childNodes.length) {
                body.appendChild(tags);
            }
            body.append(description, cta);
            button.append(media, body);
            article.appendChild(button);
            selectors.grid.appendChild(article);
        });
    }

    function handleCardClick(event) {
        const button = event.target.closest('.show-card__button');
        if (!button) {
            return;
        }

        event.preventDefault();
        const slug = button.dataset.slug;
        const show = state.shows.find((item) => item.slug === slug);
        if (show) {
            setActiveShow(show, { autoplay: true });
        }
    }

    function handleCardKeyDown(event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const button = event.target.closest('.show-card__button');
        if (!button) {
            return;
        }

        event.preventDefault();
        button.click();
    }

    async function loadLive() {
        try {
            const data = await fetchJson('/live');
            state.isLive = Boolean(data?.is_live);
            if (data?.show) {
                setActiveShow(data.show, { autoplay: false });
            }
        } catch (error) {
            console.error('Failed to load live data', error);
        }
    }

    async function loadShows() {
        try {
            const data = await fetchJson('/shows');
            state.shows = Array.isArray(data?.items) ? data.items : [];
            renderShows();
            if (!state.currentShow && state.shows.length) {
                setActiveShow(state.shows[0], { autoplay: false });
            }
        } catch (error) {
            console.error('Failed to load show archive', error);
            if (selectors.grid) {
                selectors.grid.innerHTML = '';
                const errorNode = document.createElement('div');
                errorNode.className = 'shows-grid__empty';
                errorNode.textContent = 'Signal lost. Try refreshing the page.';
                selectors.grid.appendChild(errorNode);
            }
        }
    }

    if (selectors.grid) {
        selectors.grid.addEventListener('click', handleCardClick);
        selectors.grid.addEventListener('keydown', handleCardKeyDown);
    }

    window.addEventListener('DOMContentLoaded', () => {
        loadLive();
        loadShows();
    });
})();
