import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu'];

    // Hauteur réelle de l'en-tête collant (--site-header-height) : les titres
    // collants (sujets de la page Contenus) se placent juste dessous.
    connect() {
        this.resizeObserver = new ResizeObserver(() => {
            document.documentElement.style.setProperty('--site-header-height', `${this.element.offsetHeight}px`);
        });
        this.resizeObserver.observe(this.element);
    }

    disconnect() {
        this.resizeObserver?.disconnect();
    }

    toggle() {
        const isOpen = this.menuTarget.classList.toggle('is-open');
        this.element.querySelector('.nav-toggle').setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}
