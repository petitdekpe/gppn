import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['poster', 'video'];
    static values = { autoplayMobile: Boolean };

    connect() {
        const isPhone = window.matchMedia('(max-width: 767px)').matches;

        this.selectVersion(isPhone);

        if (this.autoplayMobileValue && isPhone) {
            this.autoplay();
        }
    }

    // L'attribut media des <source> d'une <video> n'est pas appliqué de façon
    // fiable par les navigateurs (partials/_video_sources.html.twig) : on tranche
    // ici. Téléphone → les sources Mobile (placées en tête) sont gardées, la
    // vidéo TV reste en repli ; ordinateur → les sources Mobile sont retirées.
    selectVersion(isPhone) {
        const phoneSources = this.videoTarget.querySelectorAll('source[data-phone-version]');
        if (phoneSources.length === 0) {
            return;
        }

        phoneSources.forEach((source) => (isPhone ? source.removeAttribute('media') : source.remove()));
        // Le navigateur a déjà choisi sa source au chargement de la page : on relance la sélection.
        this.videoTarget.load();
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
