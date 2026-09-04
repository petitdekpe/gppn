import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel', 'input', 'toggle'];

    connect() {
        this.onDocumentClick = this.onDocumentClick.bind(this);
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
    }

    toggle(event) {
        event.stopPropagation();
        const isOpen = this.panelTarget.classList.toggle('is-open');
        this.toggleTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen) {
            this.inputTarget.focus();
        }
    }

    close() {
        this.panelTarget.classList.remove('is-open');
        this.toggleTarget.setAttribute('aria-expanded', 'false');
    }

    onDocumentClick(event) {
        if (this.panelTarget.classList.contains('is-open') && !this.element.contains(event.target)) {
            this.close();
        }
    }
}
