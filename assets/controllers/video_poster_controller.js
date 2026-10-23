import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['poster', 'video'];
    static values = { autoplayMobile: Boolean };

    connect() {
        if (this.autoplayMobileValue && window.matchMedia('(max-width: 639px)').matches) {
            this.autoplay();
        }
    }

    // Arrivée depuis un contenu sur téléphone : lecture immédiate. Les navigateurs
    // mobiles refusent souvent le son sans geste sur la page, on retente alors en
    // muet (l'utilisateur réactive le son via les contrôles) puis, à défaut,
    // on garde la vignette cliquable.
    async autoplay() {
        this.show();
        try {
            await this.videoTarget.play();
        } catch {
            try {
                this.videoTarget.muted = true;
                await this.videoTarget.play();
            } catch {
                this.videoTarget.muted = false;
                this.videoTarget.hidden = true;
                this.posterTarget.hidden = false;
                this.element.classList.remove('is-playing');
            }
        }
    }

    show() {
        this.posterTarget.hidden = true;
        // Sur téléphone, le cadre passe en 9:16 si la version Mobile est lue (voir app.css).
        this.element.classList.add('is-playing');
        this.videoTarget.hidden = false;
    }

    play(event) {
        // La vignette entière lance la lecture ; les liens et boutons du pied
        // (Télécharger, Partager, Voir la vidéo…) gardent leur propre action.
        if (event?.target.closest('a, .share, button:not(.video-detail__poster-play)') && event.currentTarget === this.posterTarget) {
            return;
        }

        this.show();
        this.videoTarget.play();
    }
}
