(function () {
    const settings = window.PBRadioSettings || {};
    const restBase = settings.restBase ? settings.restBase.replace(/\/$/, '') : '/wp-json/pbr/v1';

    const selectors = {
        grid: document.querySelector('#shows-grid'),
        playerTitle: document.getElementById('player-title'),
        playerStatus: document.getElementById('player-status'),
        playerFrame: document.getElementById('player-iframe'),
        playerBar: document.getElementById('sticky-player'),
        searchButton: document.querySelector('.masthead__search'),
        liveIndicator: document.querySelector('[data-live-indicator]'),
        liveLabel: document.querySelector('[data-live-label]'),
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
        livePollTimer: null,
    };

    const MAX_MATCH_PREVIEW = 4;

    const INTRO_UNDERSCORE = {
        titles: ["bnd", "bnd - album version"],
        artist: "no doubt",
    };
    const OUTRO_UNDERSCORE = { title: "the blue wrath", artist: "i monster" };

    function toKey(value) {
        return (value || "").toString().trim().toLowerCase();
    }

    function isIntroUnderscoreTrack(track) {
        if (toKey(track && track.artist) !== INTRO_UNDERSCORE.artist) {
            return false;
        }
        const titleKey = toKey(track && track.title);
        return INTRO_UNDERSCORE.titles.includes(titleKey);
    }

    function isOutroUnderscoreTrack(track) {
        return toKey(track && track.title) === OUTRO_UNDERSCORE.title && toKey(track && track.artist) === OUTRO_UNDERSCORE.artist;
    }

    function getVisibleTracks(tracks) {
        if (!Array.isArray(tracks) || !tracks.length) {
            return [];
        }
        const total = tracks.length;
        return tracks.map((track, index) => ({ track, index }))
            .filter(({ track, index }) => {
                if (index === 0 && isIntroUnderscoreTrack(track)) {
                    return false;
                }
                if (index === total - 1 && isOutroUnderscoreTrack(track)) {
                    return false;
                }
                return true;
            });
    }

    let searchTimer = null;
    let lastOverlayTrigger = null;
    let analyticsTabsBound = false;

    function formatNumber(value) {
        const num = Number(value || 0);
        return Number.isFinite(num) ? num.toLocaleString() : '0';
    }

    function truncateText(text, maxLength = 200) {
        if (!text) {
            return '';
        }
        if (text.length <= maxLength) {
            return text;
        }
        return `${text.slice(0, maxLength - 1).trim()}…`;
    }

    function updateFloatingSearchAnchor() {
        const button = selectors.searchButton;
        if (!button || !button.classList.contains('masthead__search--floating')) {
            return;
        }
        const shouldAnchor = window.scrollY <= 4;
        button.classList.toggle('is-anchored', shouldAnchor);
    }

    function updateLiveIndicator(data = {}) {
        const indicator = selectors.liveIndicator;
        if (!indicator) {
            return;
        }

        const isLive = Boolean(state.isLive);
        const label = selectors.liveLabel || indicator.querySelector('[data-live-label]');
        const nowPlaying = isLive && typeof data.now_playing === 'string' && data.now_playing.trim() !== ''
            ? data.now_playing.trim()
            : null;
        const labelText = isLive ? 'On Air' : 'Off Air';
        const composedText = nowPlaying ? `${labelText} • ${nowPlaying}` : labelText;

        indicator.setAttribute('data-live', isLive ? 'true' : 'false');
        indicator.setAttribute('aria-label', composedText);
        indicator.title = data.updated_at ? `Status updated ${new Date(data.updated_at).toLocaleString()}` : '';

        if (label) {
            label.textContent = composedText;
        }
    }

    function computeAnalytics(shows) {
        const totals = {
            showCount: shows.length,
            totalSeconds: 0,
            totalTracks: 0,
        };
        const tagCounts = new Map();
        const yearCounts = new Map();
        const tagYearly = new Map();
        const artists = new Set();
        const artistCounts = new Map();
        const artistLabels = new Map();
        const seenArtists = new Set();
        const freshArtistCounts = new Map();
        const tags = new Set();
        let longestShow = null;
        let longestDuration = 0;
        let densestShow = null;
        let densestTrackCount = 0;

        shows.forEach((show) => {
            const published = show.published_at || show.date || show.slug;
            const year = published ? new Date(published).getFullYear() : null;
            const duration = Number(show.duration_seconds || 0);
            if (Number.isFinite(duration)) {
                totals.totalSeconds += duration;
                if (duration > longestDuration) {
                    longestDuration = duration;
                    longestShow = show;
                }
            }
            if (year) {
                yearCounts.set(year, (yearCounts.get(year) || 0) + 1);
            }

            const visibleTracks = getVisibleTracks(show.tracks || []);
            totals.totalTracks += visibleTracks.length;
            if (visibleTracks.length > densestTrackCount) {
                densestTrackCount = visibleTracks.length;
                densestShow = show;
            }

            const showArtists = new Set();

            (show.tags || []).forEach((tag) => {
                if (!tag) {
                    return;
                }
                const key = tag.toLowerCase();
                tags.add(key);
                tagCounts.set(key, (tagCounts.get(key) || 0) + 1);
                if (year) {
                    if (!tagYearly.has(year)) {
                        tagYearly.set(year, new Map());
                    }
                    const yearly = tagYearly.get(year);
                    yearly.set(key, (yearly.get(key) || 0) + 1);
                }
            });
            visibleTracks.forEach(({ track }) => {
                if (track && track.artist) {
                    const key = track.artist.toLowerCase();
                    artists.add(key);
                    showArtists.add(key);
                    artistCounts.set(key, (artistCounts.get(key) || 0) + 1);
                    if (!artistLabels.has(key)) {
                        artistLabels.set(key, track.artist);
                    }
                }
            });

            if (year && showArtists.size) {
                showArtists.forEach((artistKey) => {
                    if (!seenArtists.has(artistKey)) {
                        seenArtists.add(artistKey);
                        freshArtistCounts.set(year, (freshArtistCounts.get(year) || 0) + 1);
                    }
                });
            }
        });

        const totalsOut = {
            showCount: totals.showCount,
            totalHours: totals.totalSeconds / 3600,
            avgMinutes: totals.showCount ? (totals.totalSeconds / totals.showCount) / 60 : 0,
            uniqueTags: tags.size,
            uniqueArtists: artists.size,
            avgTracks: totals.showCount ? totals.totalTracks / totals.showCount : 0,
        };

        const years = Array.from(yearCounts.entries())
            .sort((a, b) => a[0] - b[0])
            .map(([year, count]) => ({ year, count }));
        const maxYearCount = years.reduce((max, entry) => Math.max(max, entry.count), 1);

        const tagsTop = Array.from(tagCounts.entries())
            .sort((a, b) => b[1] - a[1])
            .slice(0, 20)
            .map(([tag, count]) => ({ tag, count }));
        const maxTagCount = tagsTop.reduce((max, entry) => Math.max(max, entry.count), 1);

        const freshArtists = years.map(({ year }) => ({ year, count: freshArtistCounts.get(year) || 0 }));
        const maxFreshCount = freshArtists.reduce((max, entry) => Math.max(max, entry.count), 1);

        const topArtists = Array.from(artistCounts.entries())
            .sort((a, b) => b[1] - a[1])
            .slice(0, 8)
            .map(([key, count]) => ({
                artist: artistLabels.get(key) || key,
                count,
            }));

        const determineYear = (show) => {
            if (!show) {
                return null;
            }
            const source = show.published_at || show.date || show.slug || '';
            const match = String(source).match(/(\d{4})/);
            return match ? Number(match[1]) : null;
        };

        const highlightShows = {
            longest: longestShow ? {
                slug: longestShow.slug,
                title: longestShow.title,
                durationSeconds: Number(longestShow.duration_seconds || 0),
                trackCount: getVisibleTracks(longestShow.tracks || []).length,
                tags: (longestShow.tags || []).slice(0, 3),
                description: longestShow.description || '',
                mixcloudUrl: longestShow.mixcloud_url || longestShow.mixcloud_embed_url || '',
                year: determineYear(longestShow),
            } : null,
            densest: densestShow ? {
                slug: densestShow.slug,
                title: densestShow.title,
                durationSeconds: Number(densestShow.duration_seconds || 0),
                trackCount: getVisibleTracks(densestShow.tracks || []).length,
                tags: (densestShow.tags || []).slice(0, 3),
                description: densestShow.description || '',
                mixcloudUrl: densestShow.mixcloud_url || densestShow.mixcloud_embed_url || '',
                year: determineYear(densestShow),
            } : null,
        };

        const yearSpan = years.length ? {
            start: years[0].year,
            end: years[years.length - 1].year,
        } : null;

        const tagStories = Array.from(tagCounts.entries())
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5)
            .map(([tag, total]) => {
                const startYear = yearSpan ? yearSpan.start : null;
                const endYear = yearSpan ? yearSpan.end : null;
                const startCount = startYear && tagYearly.get(startYear) ? (tagYearly.get(startYear).get(tag) || 0) : 0;
                const endCount = endYear && tagYearly.get(endYear) ? (tagYearly.get(endYear).get(tag) || 0) : 0;
                return {
                    tag,
                    total,
                    startYear,
                    endYear,
                    startCount,
                    endCount,
                    delta: endCount - startCount,
                };
            });

        return {
            totals: totalsOut,
            years,
            maxYearCount,
            tagsTop,
            maxTagCount,
            freshArtists,
            maxFreshCount,
            topArtists,
            highlightShows,
            tagStories,
            yearSpan,
        };
    }

    function renderAnalytics(shows) {
        const root = document.querySelector('[data-analytics-root]');
        if (!root) {
            return;
        }
        const stats = computeAnalytics(shows);
        renderAnalyticsTotals(stats.totals);
        renderAnalyticsYears(stats.years, stats.maxYearCount);
        renderAnalyticsTags(stats.tagsTop, stats.maxTagCount);
        renderAnalyticsFreshArtists(stats.freshArtists, stats.maxFreshCount);
        renderAnalyticsLegends(stats.topArtists);
        renderAnalyticsSpotlights(stats.highlightShows);
        renderAnalyticsTagStories(stats.tagStories, stats.yearSpan);

        if (!analyticsTabsBound) {
            bindAnalyticsTabs();
            analyticsTabsBound = true;
        }
    }

    function renderAnalyticsTotals(totals) {
        const container = document.querySelector('[data-analytics-totals]');
        if (!container) {
            return;
        }
        container.innerHTML = '';
        const entries = [
            ['Shows in archive', formatNumber(totals.showCount)],
            ['Unique tags', formatNumber(totals.uniqueTags)],
            ['Unique artists', formatNumber(totals.uniqueArtists)],
            ['Total runtime', `${formatNumber(totals.totalHours.toFixed(1))} hrs`],
            ['Average duration', `${formatNumber(totals.avgMinutes.toFixed(1))} mins`],
        ];
        entries.forEach(([label, value]) => {
            const dt = document.createElement('dt');
            dt.textContent = label;
            const dd = document.createElement('dd');
            dd.textContent = value;
            container.append(dt, dd);
        });
    }

    function renderAnalyticsYears(years, max) {
        const container = document.querySelector('[data-analytics-years]');
        if (!container) {
            return;
        }
        container.innerHTML = '';
        years.forEach(({ year, count }) => {
            const row = document.createElement('div');
            row.className = 'analytics-bar';
            const label = document.createElement('span');
            label.className = 'analytics-bar__label';
            label.textContent = year;
            const meter = document.createElement('span');
            meter.className = 'analytics-bar__meter';
            const ratio = max ? count / max : 0;
            meter.style.setProperty('--value', ratio);
            meter.setAttribute('aria-valuenow', String(count));
            meter.setAttribute('aria-valuemin', '0');
            meter.setAttribute('aria-valuemax', String(max));
            const value = document.createElement('span');
            value.className = 'analytics-bar__value';
            value.textContent = formatNumber(count);
            row.append(label, meter, value);
            container.appendChild(row);
        });
    }

    function renderAnalyticsTags(tags, max) {
        const container = document.querySelector('[data-analytics-tags]');
        if (!container) {
            return;
        }
        container.innerHTML = '';
        tags.forEach(({ tag, count }) => {
            const chip = document.createElement('span');
            chip.className = 'analytics-tag';
            const name = document.createElement('span');
            name.textContent = tag;
            const countEl = document.createElement('span');
            countEl.className = 'analytics-tag__count';
            countEl.textContent = formatNumber(count);
            chip.style.setProperty('--weight', max ? count / max : 0);
            chip.append(name, countEl);
            container.appendChild(chip);
        });
    }

    function renderAnalyticsFreshArtists(entries, max) {
        const container = document.querySelector('[data-analytics-fresh]');
        if (!container) {
            return;
        }
        container.innerHTML = '';
        if (!entries || !entries.length) {
            return;
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'analytics-linePlot';
        const maxValue = max || 1;
        entries.forEach(({ year, count }) => {
            const point = document.createElement('button');
            point.type = 'button';
            point.className = 'analytics-linePlot__point';
            const ratio = maxValue ? (count / maxValue) : 0;
            const height = Math.max(24, ratio * 120);
            point.style.setProperty('--height', `${height}px`);
            point.dataset.label = year;
            const value = document.createElement('span');
            value.textContent = formatNumber(count);
            point.appendChild(value);
            wrapper.appendChild(point);
        });
        container.appendChild(wrapper);
    }

    function renderAnalyticsLegends(entries) {
        const container = document.querySelector('[data-analytics-legends]');
        if (!container) {
            return;
        }
        container.innerHTML = '';

        if (!entries || !entries.length) {
            const empty = document.createElement('li');
            empty.className = 'analytics-legend';
            empty.textContent = 'Legends incoming once more shows land.';
            container.appendChild(empty);
            return;
        }

        entries.forEach(({ artist, count }, index) => {
            const item = document.createElement('li');
            item.className = 'analytics-legend';

            const rank = document.createElement('span');
            rank.className = 'analytics-legend__rank';
            rank.textContent = String(index + 1);

            const name = document.createElement('span');
            name.className = 'analytics-legend__name';
            name.textContent = artist;

            const value = document.createElement('span');
            value.className = 'analytics-legend__count';
            value.textContent = `${formatNumber(count)} spins`;

            item.append(rank, name, value);
            container.appendChild(item);
        });
    }

    function renderAnalyticsSpotlights(spotlights) {
        const container = document.querySelector('[data-analytics-spotlights]');
        if (!container) {
            return;
        }
        container.innerHTML = '';

        if (!spotlights) {
            return;
        }

        const entries = [
            ['longest', 'Longest Broadcast', 'Stretched the dial the furthest'],
            ['densest', 'Deepest Crate Dive', 'Packed the most tracks into one session'],
        ];

        entries.forEach(([key, title, subtitle]) => {
            const data = spotlights[key];
            if (!data) {
                return;
            }

            const card = document.createElement('div');
            card.className = 'analytics-spotlight';

            const heading = document.createElement('h3');
            heading.className = 'analytics-spotlight__title';
            heading.textContent = title;

            const metrics = document.createElement('p');
            metrics.className = 'analytics-spotlight__metrics';
            const durationLabel = formatDuration(data.durationSeconds || 0);
            metrics.textContent = `${durationLabel} • ${formatNumber(data.trackCount || 0)} tracks`;

            const showLine = document.createElement('p');
            showLine.className = 'analytics-spotlight__show';
            const labelParts = [];
            if (data.title) {
                labelParts.push(`“${data.title}”`);
            }
            if (data.year) {
                labelParts.push(String(data.year));
            }
            showLine.textContent = labelParts.join(' • ') || 'Untitled transmission';

            const description = document.createElement('p');
            description.className = 'analytics-spotlight__description';
            const summary = data.description ? truncateText(data.description, 200) : subtitle;
            description.textContent = summary;

            card.append(heading, metrics, showLine, description);

            if (data.tags && data.tags.length) {
                const tagsList = document.createElement('div');
                tagsList.className = 'analytics-spotlight__tags';
                data.tags.forEach((tag) => {
                    const chip = document.createElement('span');
                    chip.className = 'analytics-spotlight__tag';
                    chip.textContent = tag;
                    tagsList.appendChild(chip);
                });
                card.appendChild(tagsList);
            }

            if (data.mixcloudUrl) {
                const link = document.createElement('a');
                link.className = 'analytics-spotlight__cta';
                link.href = data.mixcloudUrl;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = 'Listen on Mixcloud';
                card.appendChild(link);
            }

            container.appendChild(card);
        });
    }

    function renderAnalyticsTagStories(stories, yearSpan) {
        const container = document.querySelector('[data-analytics-tagstories]');
        if (!container) {
            return;
        }
        container.innerHTML = '';

        if (!stories || !stories.length) {
            const empty = document.createElement('div');
            empty.className = 'analytics-tagStory';
            const summary = document.createElement('p');
            summary.className = 'analytics-tagStory__summary';
            summary.textContent = 'Tag stories will appear once more broadcasts are logged.';
            empty.appendChild(summary);
            container.appendChild(empty);
            return;
        }

        stories.forEach((story) => {
            const card = document.createElement('div');
            card.className = 'analytics-tagStory';

            const name = document.createElement('h3');
            name.className = 'analytics-tagStory__name';
            name.textContent = `#${story.tag}`;

            const trend = document.createElement('p');
            trend.className = 'analytics-tagStory__trend';
            if (story.startYear && story.endYear && story.startYear !== story.endYear) {
                const startLabel = `${story.startYear}: ${formatNumber(story.startCount || 0)}`;
                const endLabel = `${story.endYear}: ${formatNumber(story.endCount || 0)}`;
                trend.textContent = `${startLabel} → ${endLabel}`;
            } else if (story.startYear) {
                trend.textContent = `${story.startYear}: ${formatNumber(story.total || 0)}`;
            } else if (yearSpan) {
                trend.textContent = `${yearSpan.start} → ${yearSpan.end}`;
            } else {
                trend.textContent = 'Archive view';
            }

            const summary = document.createElement('p');
            summary.className = 'analytics-tagStory__summary';
            const delta = Number(story.delta || 0);
            const magnitude = Math.abs(delta);
            let narrative = '';
            if (story.startYear && story.endYear && story.startYear !== story.endYear) {
                if (delta > 0) {
                    narrative = `Surged by ${formatNumber(magnitude)} new spins in ${story.endYear}.`;
                } else if (delta < 0) {
                    narrative = `Cooled by ${formatNumber(magnitude)} plays after ${story.startYear}, but still core to the sound.`;
                } else {
                    narrative = 'Holding steady across the years.';
                }
            } else {
                narrative = 'Signature vibe across the archive.';
            }
            summary.textContent = `${formatNumber(story.total || 0)} total spins. ${narrative}`;

            card.append(name, trend, summary);
            container.appendChild(card);
        });
    }

    function bindAnalyticsTabs() {
        const root = document.querySelector('[data-analytics-tabs]');
        if (!root) {
            return;
        }
        const tabs = Array.from(root.querySelectorAll('[data-analytics-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-analytics-section]'));
        if (!tabs.length || !panels.length) {
            return;
        }

        tabs.forEach((tab) => {
            const isActive = tab.classList.contains('is-active');
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.tabIndex = isActive ? 0 : -1;

            tab.addEventListener('click', () => {
                const target = tab.dataset.analyticsTab;
                if (!target) {
                    return;
                }

                tabs.forEach((button) => {
                    const isActive = button === tab;
                    button.classList.toggle('is-active', isActive);
                    button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    button.tabIndex = isActive ? 0 : -1;
                });

                panels.forEach((panel) => {
                    const match = panel.dataset.analyticsSection === target;
                    panel.classList.toggle('is-active', match);
                    panel.toggleAttribute('hidden', !match);
                });
            });
        });
    }

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


    function normalizeShowDate(show) {
        const slugDate = parseSlugDate(show.slug);
        const human = show.human_date ? String(show.human_date).trim() : null;
        const isoSource = show.date || show.published_at || null;
        const dateObj = slugDate || parseIsoDate(isoSource);

        let full = human && human !== '' ? normalizeHumanReadableDate(human) : null;
        let short = null;

        if (dateObj && !Number.isNaN(dateObj.valueOf())) {
            short = formatShortDate(dateObj);
            if (!full) {
                full = formatFullDate(dateObj);
            }
        }

        return { full, short };
    }

    function parseSlugDate(slug) {
        if (!slug || typeof slug !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(slug)) {
            return null;
        }
        return new Date(`${slug}T12:00:00Z`);
    }

    function parseIsoDate(value) {
        if (!value) {
            return null;
        }

        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return new Date(`${value}T12:00:00Z`);
        }

        return new Date(value);
    }

    function parseHumanReadableDate(value) {
        if (!value) {
            return null;
        }
        const match = value.match(/^[^,]+,\s+([A-Za-z]+)\s+(\d{1,2})(?:st|nd|rd|th)?,\s*(\d{4})$/);
        if (!match) {
            return null;
        }
        const [, month, day, year] = match;
        return new Date(`${month} ${day} ${year} 12:00:00`);
    }

    function normalizeHumanReadableDate(value) {
        const parsed = parseHumanReadableDate(value);
        return parsed ? formatFullDate(parsed) : value;
    }

    function formatFullDate(date) {
        const weekday = date.toLocaleDateString(undefined, { weekday: 'long' });
        const month = date.toLocaleDateString(undefined, { month: 'long' });
        const day = date.getDate();
        const year = date.getFullYear();
        return `${weekday}, ${month} ${day}${getOrdinalSuffix(day)}, ${year}`;
    }

    function formatShortDate(date) {
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }).toUpperCase();
    }

    function getOrdinalSuffix(day) {
        const mod10 = day % 10;
        const mod100 = day % 100;
        if (mod10 === 1 && mod100 !== 11) return 'st';
        if (mod10 === 2 && mod100 !== 12) return 'nd';
        if (mod10 === 3 && mod100 !== 13) return 'rd';
        return 'th';
    }

    function generateFallbackBackground(show) {
        const baseAngle = 0;
        const brandGradient = 'linear-gradient(0deg, #3b4edd 0%, #d328d3 80%, #f706cd 100%)';
        if (!show || !show.slug) {
            return brandGradient;
        }
        const seed = show.slug || show.mixcloud_url || show.date || Math.random().toString();
        const hash = hashString(seed);
        const palettes = [
            ['#f706cd', '#3b4edd'],
            ['#d328d3', '#45a1ff'],
            ['#ff4fd8', '#11cbd7'],
            ['#ff5c8d', '#845ec2'],
            ['#4721ff', '#ff8a3d'],
            ['#3ddad7', '#845ec2'],
        ];
        const combo = palettes[hash % palettes.length];
        const angle = 110 + (hash % 40);
        return `linear-gradient(${angle}deg, ${combo[0]} 0%, ${combo[1]} 100%)`;
    }

    function hashString(value) {
        let hash = 0;
        for (let i = 0; i < value.length; i += 1) {
            hash = (hash << 5) - hash + value.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash);
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
            const fallbackBackground = generateFallbackBackground(show);

            media.style.backgroundImage = fallbackBackground;
            media.style.backgroundSize = 'cover';
            media.style.backgroundPosition = 'center';
            media.style.backgroundRepeat = 'no-repeat';
            media.style.removeProperty('background-blend-mode');

            if (show.hero_image) {
                const art = document.createElement('img');
                art.className = 'show-card__mediaArt';
                art.src = show.hero_image;
                art.alt = '';
                art.loading = 'lazy';
                art.decoding = 'async';

                const attachFallback = () => {
                    art.remove();
                    if (!media.querySelector('.show-card__mediaLogo') && settings.themeUrl) {
                        media.classList.add('show-card__media--fallback');
                        const fallbackLogo = document.createElement('img');
                        fallbackLogo.className = 'show-card__mediaLogo';
                        fallbackLogo.src = `${settings.themeUrl}/assets/logo.svg`;
                        fallbackLogo.alt = '';
                        media.appendChild(fallbackLogo);
                    }
                };

                art.addEventListener('error', attachFallback, { once: true });
                art.addEventListener('load', () => {
                    media.classList.add('show-card__media--hasArt');
                }, { once: true });

                media.appendChild(art);
            } else if (settings.themeUrl) {
                media.classList.add('show-card__media--fallback');
                const logoImg = document.createElement('img');
                logoImg.className = 'show-card__mediaLogo';
                logoImg.src = `${settings.themeUrl}/assets/logo.svg`;
                logoImg.alt = '';
                media.appendChild(logoImg);
            }

            const body = document.createElement('div');
            body.className = 'show-card__body';

            const title = document.createElement('h2');
            title.className = 'show-card__title';
            title.textContent = show.title || 'Untitled Transmission';

            const meta = document.createElement('div');
            meta.className = 'show-card__meta';
            const metaParts = [];
            if (show.duration_seconds) {
                metaParts.push(formatDuration(show.duration_seconds));
            }
            meta.textContent = metaParts.join(' • ');

            const { full: fullDate, short: shortDate } = normalizeShowDate(show);
            if (shortDate) {
                meta.dataset.shortDate = shortDate;
            }

            const dateLine = document.createElement('div');
            dateLine.className = 'show-card__date';
            dateLine.textContent = fullDate || shortDate || '';

            const tags = document.createElement('div');
            tags.className = 'show-card__tags';
            (show.tags || []).slice(0, 6).forEach((tag) => {
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
            if (fullDate || shortDate) {
                body.appendChild(dateLine);
            }
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
        const drawerFallback = generateFallbackBackground(show);
        hero.style.backgroundImage = drawerFallback;
        hero.style.backgroundSize = 'cover';
        hero.style.backgroundPosition = 'center';
        hero.style.backgroundRepeat = 'no-repeat';

        if (show.hero_image) {
            const heroImg = document.createElement('img');
            heroImg.className = 'show-drawer__heroImage';
            heroImg.src = show.hero_image;
            heroImg.alt = '';
            heroImg.loading = 'lazy';
            heroImg.decoding = 'async';
            hero.appendChild(heroImg);
        } else if (settings.themeUrl) {
            const heroLogo = document.createElement('img');
            heroLogo.className = 'show-drawer__heroLogo';
            heroLogo.src = `${settings.themeUrl}/assets/logo.svg`;
            heroLogo.alt = '';
            hero.appendChild(heroLogo);
        }
        drawer.content.appendChild(hero);

        const title = document.createElement('h2');
        title.className = 'show-drawer__title';
        title.id = 'show-drawer-heading';
        title.textContent = show.title || 'Untitled Transmission';
        drawer.content.appendChild(title);

        const meta = document.createElement('p');
        meta.className = 'show-drawer__meta';
        const metaPieces = [];
        if (show.duration_seconds) {
            metaPieces.push(formatDuration(show.duration_seconds));
        }

        meta.textContent = metaPieces.join(' • ');
        drawer.content.appendChild(meta);

        const { full: drawerFullDate } = normalizeShowDate(show);
        if (drawerFullDate) {
            const drawerDate = document.createElement('p');
            drawerDate.className = 'show-drawer__date';
            drawerDate.textContent = drawerFullDate;
            drawer.content.appendChild(drawerDate);
        }

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
        const visibleTracks = getVisibleTracks(show.tracks || []);

        if (visibleTracks.length) {
            visibleTracks.forEach(({ track, index }) => {
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
            const matches = buildMatchPreviews(show, state.searchTerm);
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

            if (matches.length) {
                const matchesList = document.createElement('div');
                matchesList.className = 'search-result__matches';
                matches.forEach((snippet) => {
                    const item = document.createElement('div');
                    item.className = 'search-result__match';
                    item.innerHTML = highlightTerm(snippet, state.searchTerm);
                    matchesList.appendChild(item);
                });
                left.appendChild(matchesList);
            }

            button.append(left);
            overlay.results.appendChild(button);
        });
    }

    function buildMatchPreviews(show, term) {
        const matches = [];
        const loweredTerm = term.trim().toLowerCase();

        if (!loweredTerm) {
            return matches;
        }

        const addMatch = (text) => {
            if (!text || matches.length >= MAX_MATCH_PREVIEW) {
                return;
            }
            matches.push(text);
        };

        if (show.title && show.title.toLowerCase().includes(loweredTerm)) {
            addMatch(show.title);
        }

        if (show.description && show.description.toLowerCase().includes(loweredTerm)) {
            addMatch(extractSnippet(show.description, loweredTerm));
        }

        (show.tags || []).forEach((tag) => {
            if (typeof tag === 'string' && tag.toLowerCase().includes(loweredTerm)) {
                addMatch(`#${tag}`);
            }
        });

        getVisibleTracks(show.tracks || []).map(({ track }) => track).forEach((track) => {
            const artist = (track.artist || '').toLowerCase();
            const title = (track.title || '').toLowerCase();
            const album = (track.album || '').toLowerCase();

            if (!artist.includes(loweredTerm) && !title.includes(loweredTerm) && !album.includes(loweredTerm)) {
                return;
            }

            const base = `${track.artist ? `${track.artist} — ` : ''}${track.title || ''}`.trim();
            const withAlbum = track.album ? `${base} • ${track.album}` : base;
            addMatch(withAlbum);
        });

        return matches.slice(0, MAX_MATCH_PREVIEW);
    }

    function extractSnippet(text, term) {
        const lowered = text.toLowerCase();
        const index = lowered.indexOf(term);

        if (index === -1) {
            return text.length > 120 ? `${text.slice(0, 117)}…` : text;
        }

        const start = Math.max(0, index - 30);
        const end = Math.min(text.length, index + term.length + 30);
        const snippet = text.slice(start, end).trim();
        return start > 0 ? `…${snippet}` : snippet;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function highlightTerm(text, term) {
        if (!term) {
            return escapeHtml(text);
        }

        const escapedText = escapeHtml(text);
        const escapedTerm = escapeRegExp(term);

        if (!escapedTerm) {
            return escapedText;
        }

        const regex = new RegExp(`(${escapedTerm})`, 'ig');
        return escapedText.replace(regex, '<mark class="search-result__highlight">$1</mark>');
    }

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
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

    async function loadLive(options = {}) {
        const silent = Boolean(options.silent);
        try {
            const data = await fetchJson('/live');
            state.isLive = Boolean(data?.is_live);
            updateLiveIndicator(data || {});

            if (data?.show) {
                cacheShow(data.show);
                const incomingSlug = data.show.slug || null;
                const currentSlug = state.currentShow ? state.currentShow.slug : null;
                const shouldUpdate = !state.currentShow || (incomingSlug && incomingSlug !== currentSlug);

                const allowUpdate = !silent || state.isLive || !state.currentShow;

                if (shouldUpdate && allowUpdate) {
                    setActiveShow(data.show, { autoplay: false });
                }
            }
        } catch (error) {
            console.error('Failed to load live data', error);
        }
    }

    function scheduleLiveRefresh() {
        const rawInterval = Number(settings.livePollInterval || 0);
        const interval = Number.isFinite(rawInterval) && rawInterval > 0 ? rawInterval : 30000;

        if (state.livePollTimer) {
            window.clearInterval(state.livePollTimer);
        }

        state.livePollTimer = window.setInterval(() => {
            loadLive({ silent: true });
        }, Math.max(10000, interval));
    }

    async function fetchAllShows(limit = 50) {
        const shows = [];
        let offset = 0;
        let total = Number.POSITIVE_INFINITY;

        while (offset < total) {
            const data = await fetchJson(`/shows?limit=${limit}&offset=${offset}`);
            const items = Array.isArray(data?.items) ? data.items : [];

            if (!items.length) {
                break;
            }

            shows.push(...items);

            total = typeof data?.total === 'number' ? data.total : shows.length;
            offset += items.length;

            if (items.length < limit) {
                break;
            }
        }

        return shows;
    }

    async function loadShows() {
        try {
            const items = await fetchAllShows();
            state.shows = items;
            state.showLookup = Object.create(null);
            state.shows.forEach((show) => cacheShow(show));

            if (state.currentShow && state.showLookup[state.currentShow.slug]) {
                state.currentShow = state.showLookup[state.currentShow.slug];
            }

            renderShows();
            renderAnalytics(state.shows);
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

    function initFloatingSearch() {
        const button = selectors.searchButton;
        if (!button) {
            return;
        }

        button.classList.add('masthead__search--floating');
        updateFloatingSearchAnchor();
        window.addEventListener('scroll', updateFloatingSearchAnchor, { passive: true });
        window.addEventListener('resize', updateFloatingSearchAnchor);
    }

    window.addEventListener('DOMContentLoaded', () => {
        bindEvents();
        initFloatingSearch();
        updateLiveIndicator();
        loadLive().finally(() => {
            scheduleLiveRefresh();
        });
        loadShows();
    });

    window.addEventListener('beforeunload', () => {
        if (state.livePollTimer) {
            window.clearInterval(state.livePollTimer);
            state.livePollTimer = null;
        }
        window.removeEventListener('scroll', updateFloatingSearchAnchor);
        window.removeEventListener('resize', updateFloatingSearchAnchor);
    });
})();
