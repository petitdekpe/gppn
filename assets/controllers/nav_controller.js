import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu'];

    toggle() {
        const isOpen = this.menuTarget.classList.toggle('is-open');
        this.element.querySelector('.nav-toggle').setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}
