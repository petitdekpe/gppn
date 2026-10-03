import { Controller } from '@hotwired/stimulus';

/*
 * Bouton « Partager » d'un contenu (partials/_share.html.twig), sur les
 * cartes, le bloc « À la une » et la fiche vidéo :
 * - sur mobile (écran tactile), feuille de partage du téléphone (WhatsApp,
 *   Facebook, SMS…) quand le navigateur la propose ;
 * - sinon, menu des réseaux et copie du lien. Le menu est un « popover » :
 *   il s'affiche au-dessus de la page, sans être rogné par la carte, et se
 *   ferme d'un clic à côté ou avec Échap.
 */
export default class extends Controller {
    static targets = ['label', 'menu', 'toggle'];
    static values = { url: String, title: String };

    connect() {
        this.onToggle = (event) => {
            this.toggleTarget.setAttribute('aria-expanded', String(event.newState === 'open'));
        };
        this.close = this.close.bind(this);
        if (this.hasMenuTarget) {
            this.menuTarget.addEventListener('toggle', this.onToggle);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
        window.removeEventListener('scroll', this.close);
        if (this.hasMenuTarget) {
            this.menuTarget.removeEventListener('toggle', this.onToggle);
        }
    }

    async share() {
        if (navigator.share && window.matchMedia('(hover: none) and (pointer: coarse)').matches) {
            try {
                await navigator.share({ title: this.titleValue, url: this.urlValue });
            } catch {
                // Partage annulé par le visiteur : rien à faire.
            }
            return;
        }

        if (!this.hasMenuTarget) {
            this.copy();
            return;
        }

        const menu = this.menuTarget;
        if (!menu.showPopover) {
            // Navigateur ancien, sans popover : menu affiché sous le bouton.
            menu.classList.toggle('is-open');
            return;
        }
        if (menu.matches(':popover-open')) {
            menu.hidePopover();
            return;
        }
        menu.showPopover();
        this.position();
        // Menu positionné par rapport à l'écran : on le ferme si la page défile.
        window.addEventListener('scroll', this.close, { once: true, passive: true });
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.flash('Lien copié');
        } catch {
            this.flash('Copie impossible');
        }
        this.close();
    }

    close() {
        if (!this.hasMenuTarget) {
            return;
        }
        if (this.menuTarget.matches?.(':popover-open')) {
            this.menuTarget.hidePopover();
        }
        this.menuTarget.classList.remove('is-open');
    }

    /** Sous le bouton, ou au-dessus s'il n'y a pas la place ; jamais hors de l'écran. */
    position() {
        const button = this.toggleTarget.getBoundingClientRect();
        const menu = this.menuTarget;
        const gap = 6;
        const below = button.bottom + gap + menu.offsetHeight <= window.innerHeight;
        menu.style.top = `${below ? button.bottom + gap : Math.max(8, button.top - gap - menu.offsetHeight)}px`;
        menu.style.left = `${Math.min(Math.max(8, button.left), window.innerWidth - menu.offsetWidth - 8)}px`;
    }

    flash(message) {
        const initial = this.labelTarget.dataset.initial ?? this.labelTarget.textContent;
        this.labelTarget.dataset.initial = initial;
        this.labelTarget.textContent = message;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.labelTarget.textContent = initial; }, 2000);
    }
}
