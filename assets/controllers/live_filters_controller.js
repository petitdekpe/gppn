import { Controller } from '@hotwired/stimulus';

/*
 * Filtres des listes de contenus (partials/_video_filters.html.twig) :
 * chaque choix met à jour la page sans la recharger. La page est redemandée
 * en arrière-plan et seules les zones `data-live-region` (compteur, filtres,
 * onglets, résultats) sont remplacées : position dans la page, panneau de
 * filtres ouvert sur mobile et volets dépliés restent en place.
 *
 * Les étiquettes de filtres actifs, « Réinitialiser », les onglets
 * (liens data-live-link) et la pagination passent par le même chemin.
 * L'adresse suit les choix (rechargement, partage). Une page sans zone de
 * résultats est simplement chargée.
 */
export default class extends Controller {
    connect() {
        this.onClick = this.onClick.bind(this);
        document.addEventListener('click', this.onClick);
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('click', this.onClick);
    }

    /** Case, liste ou date modifiée : petite attente pour regrouper les clics rapprochés. */
    change() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(this.urlFor()), 250);
    }

    submit(event) {
        event.preventDefault();
        clearTimeout(this.timer);
        // Mobile : « Voir N capsules » referme le panneau sur des résultats déjà à jour.
        if (event.submitter?.hasAttribute('data-live-filters-close')) {
            this.closePanel();
            this.scrollToResults();
            return;
        }
        this.load(this.urlFor(event.submitter));
    }

    onClick(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.target.closest('a[data-live-link], [data-live-region="results"] .pagination a');
        if (!link || link.origin !== window.location.origin) {
            return;
        }
        event.preventDefault();
        this.load(link.href, { scroll: link.closest('.pagination') !== null });
    }

    urlFor(submitter = null) {
        const data = new FormData(this.element);
        if (submitter?.name) {
            data.set(submitter.name, submitter.value);
        }
        const params = new URLSearchParams();
        data.forEach((value, key) => {
            if (value !== '') {
                params.append(key, value);
            }
        });
        const query = params.toString();

        return `${this.element.action}${query ? `?${query}` : ''}`;
    }

    async load(url, { scroll = false } = {}) {
        if (!document.querySelector('[data-live-region="results"]')) {
            window.Turbo ? window.Turbo.visit(url) : window.location.assign(url);
            return;
        }

        // Une seule requête à la fois : la plus récente l'emporte.
        window.liveFiltersRequest?.abort();
        const request = new AbortController();
        window.liveFiltersRequest = request;
        document.documentElement.classList.add('is-filtering');
        const focus = this.focusedField();

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, signal: request.signal });
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');

            document.querySelectorAll('[data-live-region]').forEach((region) => {
                const fresh = page.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`);
                if (fresh) {
                    keepOpenState(region, fresh);
                    region.replaceWith(document.importNode(fresh, true));
                }
            });
            history.replaceState(history.state, '', url);
            restoreFocus(focus);
            if (scroll) {
                this.scrollToResults();
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.location.assign(url);
            }
        } finally {
            if (window.liveFiltersRequest === request) {
                document.documentElement.classList.remove('is-filtering');
            }
        }
    }

    focusedField() {
        const active = document.activeElement;

        return active?.form === this.element && active.name ? { name: active.name, value: ['checkbox', 'radio'].includes(active.type) ? active.value : null } : null;
    }

    closePanel() {
        const panel = this.element.closest('.filters-panel');
        panel?.querySelector('.filters-panel__body')?.classList.remove('is-open');
        panel?.querySelector('.filters-panel__toggle')?.setAttribute('aria-expanded', 'false');
    }

    scrollToResults() {
        document.querySelector('[data-live-region="results"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

/** Volets (« + N autres », « Plus de filtres »…) ouverts par le visiteur : ils le restent. */
function keepOpenState(current, fresh) {
    const before = current.querySelectorAll('details');
    const after = fresh.querySelectorAll('details');
    before.forEach((details, index) => {
        if (details.open && after[index]) {
            after[index].open = true;
        }
    });
}

/** Le champ manipulé garde le focus (navigation au clavier). */
function restoreFocus(focus) {
    if (!focus) {
        return;
    }
    const selector = `[data-live-region="filters-form"] [name="${CSS.escape(focus.name)}"]${focus.value !== null ? `[value="${CSS.escape(focus.value)}"]` : ''}`;
    document.querySelector(selector)?.focus({ preventScroll: true });
}
