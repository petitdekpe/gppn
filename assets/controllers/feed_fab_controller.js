import { Controller } from '@hotwired/stimulus';

/*
 * Bouton flottant du fil vertical : passe en style inversé (.is-over-footer)
 * dès qu'il se trouve au-dessus du pied de page, dont le fond bleu nuit est
 * le même que le sien.
 */
export default class extends Controller {
    connect() {
        this.footer = document.querySelector('.site-footer');
        if (!this.footer) {
            return;
        }
        window.addEventListener('scroll', this.schedule, { passive: true });
        window.addEventListener('resize', this.schedule, { passive: true });
        this.update();
    }

    disconnect() {
        window.removeEventListener('scroll', this.schedule);
        window.removeEventListener('resize', this.schedule);
        cancelAnimationFrame(this.frame);
    }

    schedule = () => {
        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => this.update());
    };

    update() {
        const fab = this.element.getBoundingClientRect();
        const footerTop = this.footer.getBoundingClientRect().top;
        this.element.classList.toggle('is-over-footer', footerTop < fab.top + fab.height / 2);
    }
}
