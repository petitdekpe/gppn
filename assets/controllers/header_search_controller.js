import { Controller } from '@hotwired/stimulus';

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

const GROUPS = [
    { key: 'people', title: 'Intervenants', icon: 'fa-user-tie' },
    { key: 'contents', title: 'Contenus', icon: 'fa-circle-play' },
    { key: 'thematics', title: 'Thématiques', icon: 'fa-tag' },
    { key: 'languages', title: 'Langues', icon: 'fa-language' },
];

/*
 * Barre de recherche de l'en-tête : ouverture sur mobile, et suggestions à
 * mesure que l'on tape (dès 2 caractères). Les intervenants viennent en
 * premier, avec le raccourci vers leur page ; puis contenus, thématiques et
 * langues, et « Voir tous les résultats ». Flèches haut/bas pour parcourir,
 * Entrée pour suivre la suggestion choisie, Échap pour fermer.
 */
export default class extends Controller {
    static targets = ['panel', 'input', 'toggle', 'list'];
    static values = { suggestUrl: String };

    connect() {
        this.onDocumentClick = this.onDocumentClick.bind(this);
        document.addEventListener('click', this.onDocumentClick);
        this.active = -1;
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        clearTimeout(this.timer);
        this.request?.abort();
    }

    toggle(event) {
        event.stopPropagation();
        const isOpen = this.panelTarget.classList.toggle('is-open');
        this.toggleTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen) {
            this.inputTarget.focus();
        } else {
            this.hideSuggestions();
        }
    }

    close() {
        this.panelTarget.classList.remove('is-open');
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        this.hideSuggestions();
    }

    onDocumentClick(event) {
        if (!this.element.contains(event.target)) {
            this.hideSuggestions();
            if (this.panelTarget.classList.contains('is-open')) {
                this.close();
            }
        }
    }

    // ---- Suggestions ----------------------------------------------------

    suggest() {
        clearTimeout(this.timer);
        const text = this.inputTarget.value.trim();
        if (text.length < 2) {
            this.hideSuggestions();
            return;
        }
        this.timer = setTimeout(() => this.fetchSuggestions(text), 180);
    }

    async fetchSuggestions(text) {
        this.request?.abort();
        this.request = new AbortController();
        try {
            const response = await fetch(`${this.suggestUrlValue}?q=${encodeURIComponent(text)}`, {
                headers: { Accept: 'application/json' },
                signal: this.request.signal,
            });
            if (!response.ok) {
                return;
            }
            this.render(await response.json(), text);
        } catch {
            // Requête annulée par une frappe plus récente, ou réseau indisponible : pas de suggestions.
        }
    }

    render(data, text) {
        const sections = GROUPS
            .filter((group) => (data[group.key] ?? []).length > 0)
            .map((group) => `
                <div class="header-search__group" role="group" aria-label="${group.title}">
                    <p class="header-search__group-title">${group.title}</p>
                    ${data[group.key].map((item) => `
                        <a class="header-search__item${group.key === 'people' ? ' header-search__item--person' : ''}" role="option" href="${escapeHtml(item.url)}">
                            <i class="fa-solid ${group.icon}" aria-hidden="true"></i>
                            <span class="header-search__item-text">
                                <strong>${escapeHtml(item.label)}</strong>
                                ${item.detail ? `<span>${escapeHtml(item.detail)}</span>` : ''}
                            </span>
                            ${group.key === 'people' ? `<span class="header-search__item-cta">Sa page${item.count ? ` · ${item.count}` : ''} →</span>` : ''}
                        </a>`).join('')}
                </div>`)
            .join('');

        this.listTarget.innerHTML = `${sections || '<p class="header-search__empty">Aucune suggestion : lancez la recherche.</p>'}
            <a class="header-search__item header-search__item--all" role="option" href="${escapeHtml(data.all)}">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="header-search__item-text"><strong>Voir tous les résultats pour « ${escapeHtml(text)} »</strong></span>
            </a>`;
        this.listTarget.querySelectorAll('[role="option"]').forEach((option, index) => {
            option.id = `header-search-option-${index}`;
        });
        this.active = -1;
        this.listTarget.hidden = false;
        this.inputTarget.setAttribute('aria-expanded', 'true');
    }

    hideSuggestions() {
        if (!this.hasListTarget) {
            return;
        }
        this.listTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
        this.active = -1;
    }

    /** Flèches pour parcourir les suggestions, Entrée pour suivre, Échap pour fermer. */
    navigate(event) {
        const options = this.listTarget.hidden ? [] : [...this.listTarget.querySelectorAll('[role="option"]')];
        if (event.key === 'Escape') {
            this.hideSuggestions();
            return;
        }
        if (options.length === 0 || !['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
            return;
        }
        if (event.key === 'Enter') {
            if (this.active >= 0) {
                event.preventDefault();
                options[this.active].click();
            }
            return;
        }
        event.preventDefault();
        this.active = (this.active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        options.forEach((option, index) => option.classList.toggle('is-active', index === this.active));
        this.inputTarget.setAttribute('aria-activedescendant', options[this.active].id);
        options[this.active].scrollIntoView({ block: 'nearest' });
    }
}
