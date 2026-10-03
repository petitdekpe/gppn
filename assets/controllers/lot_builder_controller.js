import { Controller } from '@hotwired/stimulus';

/*
 * Constructeur de lot de l'espace média : chaque choix (sujet, langue,
 * format, raccourci intervenant ou période) met à jour la page sans la
 * recharger. La page est redemandée en arrière-plan et seules les zones qui
 * dépendent des choix (cibles `region` : raccourci, langues, formats,
 * récapitulatif) sont remplacées ; le calendrier, la liste des sujets, la
 * recherche en cours et la position dans la page restent en place.
 * L'adresse suit les choix : un rechargement ou un lien partagé les retrouve.
 */
export default class extends Controller {
    static targets = ['form', 'region'];

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    /** Case cochée, liste ou date modifiée : petite attente pour regrouper les clics rapprochés. */
    refresh(event) {
        if (!this.formTarget.contains(event.target) || event.target.type === 'search') {
            return;
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 150);
    }

    /** Boutons de raccourci de période (envoi du formulaire) : même mise à jour. */
    submit(event) {
        if (event.target !== this.formTarget) {
            return;
        }
        event.preventDefault();
        clearTimeout(this.timer);
        this.load(event.submitter);
    }

    async load(submitter = null) {
        const data = new FormData(this.formTarget);
        // Bouton cliqué (raccourci de période) : sa valeur, ajoutée à la main pour les navigateurs anciens.
        if (submitter?.name) {
            data.set(submitter.name, submitter.value);
        }
        const params = new URLSearchParams();
        data.forEach((value, key) => {
            if (value !== '') {
                params.append(key, value);
            }
        });
        const url = `${this.formTarget.action}?${params}`;

        // Une seule requête à la fois : la plus récente l'emporte.
        this.request?.abort();
        this.request = new AbortController();
        this.element.classList.add('is-loading');

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, signal: this.request.signal });
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');

            this.regionTargets.forEach((region) => {
                const fresh = page.querySelector(`[data-lot-builder-target~="region"][data-region="${region.dataset.region}"]`);
                if (fresh) {
                    this.keepOpenState(region, fresh);
                    region.replaceWith(document.importNode(fresh, true));
                }
            });
            history.replaceState(history.state, '', url);
        } catch (error) {
            if (error.name !== 'AbortError') {
                // Réseau indisponible : à défaut, rechargement classique.
                window.location.assign(url);
            }
        } finally {
            this.element.classList.remove('is-loading');
        }
    }

    /** Un volet replié ouvert par le visiteur le reste après la mise à jour. */
    keepOpenState(current, fresh) {
        const before = [current, ...current.querySelectorAll('details')].filter((el) => el.matches('details'));
        const after = [fresh, ...fresh.querySelectorAll('details')].filter((el) => el.matches('details'));
        before.forEach((details, index) => {
            if (details.open && after[index]) {
                after[index].open = true;
            }
        });
    }
}
