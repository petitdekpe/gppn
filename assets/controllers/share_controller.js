import { Controller } from '@hotwired/stimulus';

/*
 * Bouton « Partager » des cartes (affiché sur mobile, voir .video-card__share) :
 * feuille de partage du téléphone (WhatsApp, Facebook, SMS…) quand le
 * navigateur la propose, sinon copie du lien.
 */
export default class extends Controller {
    static targets = ['label'];
    static values = { url: String, title: String };

    async share() {
        if (navigator.share) {
            try {
                await navigator.share({ title: this.titleValue, url: this.urlValue });
            } catch {
                // Partage annulé par le visiteur : rien à faire.
            }
            return;
        }

        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.flash('Lien copié');
        } catch {
            this.flash('Copie impossible');
        }
    }

    flash(message) {
        const initial = this.labelTarget.textContent;
        this.labelTarget.textContent = message;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.labelTarget.textContent = initial; }, 2000);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
