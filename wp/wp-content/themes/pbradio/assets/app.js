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

    const overlay = {
        root: document.getElementById('search-overlay'),
        input: document.getElementById('search-overlay-input'),
        results: document.getElementById('search-overlay-results'),
        closeButtons: document.querySelectorAll('[data-overlay-close]'),
        openers: document.querySelectorAll('[data-open-search]'),
        panel: document.querySelector('#search-overlay .search-overlay__panel'),
    };

    const drawer = {
        root: document.getElementById('show-drawer'),
        content: document.getElementById('show-drawer-content'),
        closeButtons: document.querySelectorAll('[data-drawer-close]'),
    };

    const state = {
        shows: [],
        showLookup: Object.create(null),
        currentShow: null,
        isLive: false,
        searchTerm: '',
        searchResults: [],
        searchLoading: false,
        activeTrackIndex: null,
    };

    let searchTimer = null;
    let lastOverlayTrigger = null;

    function cacheShow(show) {
        if (!show || !show.slug) {
            return;
        }

        state.showLookup[show.slug] = show;
    }

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

    function syncActiveCard() {
        if (!selectors.grid) {
            return;
        }

        selectors.grid.querySelectorAll('.show-card--active').forEach((card) => {
            card.classList.remove('show-card--active');
        });

        if (!state.currentShow) {
            return;
        }

        const button = selectors.grid.querySelector(`.show-card__button[data-slug="${state.currentShow.slug}"]`);
        const card = button ? button.closest('.show-card') : null;
        if (card) {
            card.classList.add('show-card--active');
        }
    }

    function setActiveShow(show, context = {}) {
        if (!show || !selectors.playerFrame) {
            return;
        }

        const autoplay = context.autoplay !== undefined ? context.autoplay : true;
        const targetSlug = show.slug || null;
        const previousSlug = state.currentShow ? state.currentShow.slug : null;

        cacheShow(show);

        state.currentShow = show;

        if (context.trackIndex !== undefined) {
            state.activeTrackIndex = context.trackIndex;
        } else if (targetSlug && targetSlug !== previousSlug) {
            state.activeTrackIndex = Array.isArray(show.tracks) && show.tracks.length ? 0 : null;
        }

        selectors.playerTitle.textContent = show.title || 'Untitled Transmission';
        selectors.playerStatus.textContent = state.isLive ? 'Live' : 'Playback';
        selectors.playerStatus.setAttribute('data-live', state.isLive ? 'true' : 'false');

        const embedUrl = show.mixcloud_embed_url || show.mixcloud_url || '';
        if (embedUrl) {
            const url = new URL(embedUrl, window.location.origin);
            if (autoplay) {
                url.searchParams.set('autoplay', '1');
            } else {
                url.searchParams.delete('autoplay');
            }
            const nextSrc = url.toString();
            if (selectors.playerFrame.src !== nextSrc) {
                selectors.playerFrame.src = nextSrc;
            }
        }

        selectors.playerBar?.setAttribute('data-loaded', 'true');
        document.title = `${show.title} · ${settings.siteTitle || 'Point Break Radio'}`;

        syncActiveCard();
        updateDrawerContent(show);
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
            article.dataset.slug = show.slug;

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
            if (state.currentShow && show.slug === state.currentShow.slug) {
                article.classList.add('show-card--active');
            }
            selectors.grid.appendChild(article);
        });
    }

    function openDrawer(show, options = {}) {
        if (!drawer.root) {
            return;
        }

        updateDrawerContent(show, { focus: options.focus !== false });
        drawer.root.classList.add('is-open');
        drawer.root.setAttribute('aria-hidden', 'false');
        document.body.classList.add('has-drawer-open');
    }

    function closeDrawer() {
        if (!drawer.root) {
            return;
        }
        drawer.root.classList.remove('is-open');
        drawer.root.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('has-drawer-open');
    }

    function highlightActiveTrack(show, trackIndex) {
        if (!drawer.content) {
            return;
        }

        drawer.content.querySelectorAll('.show-drawer__trackButton').forEach((button) => {
            button.classList.remove('is-active');
        });

        if (trackIndex === null || trackIndex === undefined) {
            return;
        }

        const selector = `.show-drawer__trackButton[data-index="${trackIndex}"][data-slug="${show.slug}"]`;
        const target = drawer.content.querySelector(selector);
        if (target) {
            target.classList.add('is-active');
        }
    }

    function updateDrawerContent(show, options = {}) {
        if (!drawer.content) {
            return;
        }

        drawer.content.innerHTML = '';

        if (!show) {
            const empty = document.createElement('p');
            empty.className = 'show-drawer__empty';
            empty.textContent = 'Choose a broadcast to view the tracklist.';
            drawer.content.appendChild(empty);
            return;
        }

        const hero = document.createElement('div');
        hero.className = 'show-drawer__hero';
        if (show.hero_image) {
            hero.style.backgroundImage = `url(${show.hero_image})`;
        } else {
            hero.style.backgroundImage = 'linear-gradient(135deg, rgba(255,79,216,0.4), rgba(69,161,255,0.25))';
        }
        drawer.content.appendChild(hero);

        const title = document.createElement('h2');
        title.className = 'show-drawer__title';
        title.id = 'show-drawer-heading';
        title.tabIndex = -1;
        title.textContent = show.title || 'Untitled Transmission';
        drawer.content.appendChild(title);

        const meta = document.createElement('p');
        meta.className = 'show-drawer__meta';
        const pieces = [];
        if (show.year) {
            pieces.push(show.year);
        }
        if (show.duration_seconds) {
            pieces.push(formatDuration(show.duration_seconds));
        }
        if (show.published_at) {
            const date = new Date(show.published_at);
            if (!Number.isNaN(date.valueOf())) {
                pieces.push(date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }));
            }
        }
        meta.textContent = pieces.join(' • ');
        drawer.content.appendChild(meta);

        if (Array.isArray(show.tags) && show.tags.length) {
            const tagWrap = document.createElement('div');
            tagWrap.className = 'show-drawer__tags';
            show.tags.forEach((tag) => {
                const chip = document.createElement('span');
                chip.className = 'show-drawer__tag';
                chip.textContent = tag;
                tagWrap.appendChild(chip);
            });
            drawer.content.appendChild(tagWrap);
        }

        if (show.description) {
            const description = document.createElement('p');
            description.className = 'show-drawer__description';
            description.textContent = show.description;
            drawer.content.appendChild(description);
        }

        const tracksHeading = document.createElement('h3');
        tracksHeading.className = 'show-drawer__tracks-heading';
        tracksHeading.textContent = 'Tracklist';
        drawer.content.appendChild(tracksHeading);

        const tracksList = document.createElement('ol');
        tracksList.className = 'show-drawer__tracks';

        if (Array.isArray(show.tracks) && show.tracks.length) {
            show.tracks.forEach((track, index) => {
                const item = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'show-drawer__trackButton';
                button.dataset.index = String(index);
                button.dataset.slug = show.slug;

                const titleLine = document.createElement('span');
                titleLine.className = 'show-drawer__trackTitle';
                titleLine.textContent = `${track.artist || 'Unknown'} — ${track.title || 'Untitled'}`;

                const metaLine = document.createElement('span');
                metaLine.className = 'show-drawer__trackMeta';
                const metaBits = [];
                if (track.album) {
                    metaBits.push(track.album);
                }
                if (track.duration_seconds) {
                    metaBits.push(formatDuration(track.duration_seconds));
                }
                if (track.mood) {
                    metaBits.push(track.mood);
                }
                metaLine.textContent = metaBits.join(' • ');

                button.append(titleLine);
                if (metaBits.length) {
                    button.append(metaLine);
                }
                item.append(button);
                tracksList.append(item);
            });
        } else {
            const emptyTrack = document.createElement('li');
            const note = document.createElement('p');
            note.className = 'show-drawer__empty';
            note.textContent = 'Tracklist coming soon.';
            emptyTrack.append(note);
            tracksList.append(emptyTrack);
        }

        drawer.content.appendChild(tracksList);

        highlightActiveTrack(show, state.activeTrackIndex);

        if (options.focus) {
            requestAnimationFrame(() => {
                title.focus();
            });
        }
    }

    function selectShow(show, context = {}) {
        setActiveShow(show, context);
        if (context.openDrawer) {
            openDrawer(show, { focus: true });
        }
    }

    function handleCardClick(event) {
        const button = event.target.closest('.show-card__button');
        if (!button) {
            return;
        }

        event.preventDefault();
        const slug = button.dataset.slug;
        const show = state.showLookup[slug] || state.shows.find((item) => item.slug === slug);
        if (show) {
            selectShow(show, { autoplay: true, openDrawer: true });
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

    function renderSearchResults() {
        if (!overlay.results) {
            return;
        }

        overlay.results.innerHTML = '';

        if (!state.searchTerm.trim()) {
            const idle = document.createElement('p');
            idle.className = 'search-overlay__empty';
            idle.textContent = 'Type to scan the airwaves.';
            overlay.results.appendChild(idle);
            return;
        }

        if (state.searchLoading) {
            const status = document.createElement('p');
            status.className = 'search-overlay__status';
            status.textContent = 'Scanning frequencies…';
            overlay.results.appendChild(status);
            return;
        }

        if (!state.searchResults.length) {
            const empty = document.createElement('p');
            empty.className = 'search-overlay__empty';
            empty.textContent = 'No transmissions matched that search.';
            overlay.results.appendChild(empty);
            return;
        }

        state.searchResults.forEach((entry, index) => {
            const show = entry.show;
            cacheShow(show);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'search-result';
            button.dataset.slug = show.slug;
            button.dataset.index = String(index);
            button.setAttribute('role', 'option');
            button.setAttribute('aria-label', `Play ${show.title}`);

            const left = document.createElement('div');
            left.className = 'search-result__left';

            const title = document.createElement('div');
            title.className = 'search-result__title';
            title.textContent = show.title || 'Untitled Transmission';

            const meta = document.createElement('div');
            meta.className = 'search-result__meta';
            const bits = [];
            if (show.year) {
                bits.push(show.year);
            }
            if (show.duration_seconds) {
                bits.push(formatDuration(show.duration_seconds));
            }
            if (show.tags && show.tags.length) {
                bits.push(show.tags.slice(0, 2).join(', '));
            }
            meta.textContent = bits.join(' • ');

            left.append(title, meta);

            const score = document.createElement('span');
            score.className = 'search-result__score';
            score.textContent = entry.score ? `${entry.score.toFixed(2)}` : '';

            button.append(left, score);
            overlay.results.appendChild(button);
        });
    }

    function performSearch(term) {
        if (!term.trim()) {
            state.searchResults = [];
            state.searchLoading = false;
            renderSearchResults();
            return;
        }

        state.searchLoading = true;
        renderSearchResults();

        fetchJson(`/search?q=${encodeURIComponent(term)}`)
            .then((data) => {
                const items = Array.isArray(data?.items) ? data.items : [];
                state.searchResults = items.map((entry) => ({
                    show: entry.show,
                    score: typeof entry.score === 'number' ? entry.score : null,
                }));
            })
            .catch(() => {
                state.searchResults = [];
            })
            .finally(() => {
                state.searchLoading = false;
                renderSearchResults();
            });
    }

    function openSearchOverlay(trigger) {
        if (!overlay.root) {
            return;
        }

        if (trigger instanceof HTMLElement) {
            lastOverlayTrigger = trigger;
        }

        overlay.root.classList.add('is-open');
        overlay.root.setAttribute('aria-hidden', 'false');
        document.body.classList.add('has-search-open');

        if (overlay.input) {
            overlay.input.value = state.searchTerm;
            overlay.input.focus({ preventScroll: true });
            overlay.input.select();
        }

        renderSearchResults();
    }

    function closeSearchOverlay(options = {}) {
        if (!overlay.root) {
            return;
        }

        overlay.root.classList.remove('is-open');
        overlay.root.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('has-search-open');

        if (options.restoreFocus && lastOverlayTrigger instanceof HTMLElement) {
            lastOverlayTrigger.focus();
        }
    }

    function focusFirstResult() {
        if (!overlay.results) {
            return;
        }
        const first = overlay.results.querySelector('.search-result');
        if (first) {
            first.focus();
        }
    }

    function handleSearchInput(event) {
        const term = event.target.value || '';
        state.searchTerm = term;

        if (searchTimer) {
            window.clearTimeout(searchTimer);
        }

        searchTimer = window.setTimeout(() => {
            performSearch(term);
        }, 220);
    }

    function handleSearchInputKeydown(event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            focusFirstResult();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeSearchOverlay({ restoreFocus: true });
        }
    }

    function handleResultsClick(event) {
        const button = event.target.closest('.search-result');
        if (!button) {
            return;
        }
        event.preventDefault();
        const slug = button.dataset.slug;
        const entry = state.searchResults.find((item) => item.show.slug === slug);
        if (entry) {
            closeSearchOverlay({ restoreFocus: true });
            selectShow(entry.show, { autoplay: true, openDrawer: true });
        }
    }

    function moveResultFocus(currentButton, direction) {
        if (!overlay.results) {
            return;
        }
        const buttons = Array.from(overlay.results.querySelectorAll('.search-result'));
        if (!buttons.length) {
            return;
        }
        const currentIndex = buttons.indexOf(currentButton);
        const nextIndex = (currentIndex + direction + buttons.length) % buttons.length;
        buttons[nextIndex].focus();
    }

    function handleResultKeyDown(event) {
        const button = event.target.closest('.search-result');
        if (!button) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveResultFocus(button, 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveResultFocus(button, -1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            button.click();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeSearchOverlay({ restoreFocus: true });
        }
    }

    function handleTrackInteraction(event) {
        const button = event.target.closest('.show-drawer__trackButton');
        if (!button) {
            return;
        }
        const slug = button.dataset.slug;
        const index = Number.parseInt(button.dataset.index || '0', 10);
        const show = state.showLookup[slug];
        if (!show) {
            return;
        }
        state.activeTrackIndex = Number.isNaN(index) ? null : index;
        const shouldAutoplay = !state.currentShow || state.currentShow.slug !== slug;
        setActiveShow(show, { autoplay: shouldAutoplay, trackIndex: state.activeTrackIndex });
        highlightActiveTrack(show, state.activeTrackIndex);
    }

    function handleGlobalKeydown(event) {
        const isInputElement = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) || event.target.isContentEditable;

        if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
            if (isInputElement) {
                return;
            }
            event.preventDefault();
            openSearchOverlay(event.target instanceof HTMLElement ? event.target : null);
        } else if (event.key === 'Escape') {
            if (overlay.root && overlay.root.classList.contains('is-open')) {
                event.preventDefault();
                closeSearchOverlay({ restoreFocus: true });
            } else if (drawer.root && drawer.root.classList.contains('is-open')) {
                event.preventDefault();
                closeDrawer();
            }
        }
    }

    async function loadLive() {
        try {
            const data = await fetchJson('/live');
            state.isLive = Boolean(data?.is_live);
            if (data?.show) {
                cacheShow(data.show);
                setActiveShow(data.show, { autoplay: false });
            }
        } catch (error) {
            console.error('Failed to load live data', error);
        }
    }

    async function loadShows() {
        try {
            const data = await fetchJson('/shows');
            const items = Array.isArray(data?.items) ? data.items : [];
            state.shows = items;
            state.showLookup = Object.create(null);
            state.shows.forEach((show) => cacheShow(show));

            if (state.currentShow && state.showLookup[state.currentShow.slug]) {
                state.currentShow = state.showLookup[state.currentShow.slug];
            }

            renderShows();
            syncActiveCard();

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

    function bindEvents() {
        if (selectors.grid) {
            selectors.grid.addEventListener('click', handleCardClick);
            selectors.grid.addEventListener('keydown', handleCardKeyDown);
        }

        overlay.openers.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                openSearchOverlay(button);
            });
        });

        overlay.closeButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                closeSearchOverlay({ restoreFocus: true });
            });
        });

        if (overlay.input) {
            overlay.input.addEventListener('input', handleSearchInput);
            overlay.input.addEventListener('keydown', handleSearchInputKeydown);
        }

        if (overlay.results) {
            overlay.results.addEventListener('click', handleResultsClick);
            overlay.results.addEventListener('keydown', handleResultKeyDown);
        }

        drawer.closeButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                closeDrawer();
            });
        });

        if (drawer.root) {
            drawer.root.addEventListener('click', (event) => {
                if (event.target === drawer.root.querySelector('.show-drawer__scrim')) {
                    closeDrawer();
                }
            });
        }

        if (drawer.content) {
            drawer.content.addEventListener('click', handleTrackInteraction);
            drawer.content.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    handleTrackInteraction(event);
                }
            });
        }

        document.addEventListener('keydown', handleGlobalKeydown);
    }

    window.addEventListener('DOMContentLoaded', () => {
        bindEvents();
        loadLive();
        loadShows();
    });
})();
