import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['body'];

    toggle(event) {
        const isOpen = this.bodyTarget.classList.toggle('is-open');
        event.currentTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}
