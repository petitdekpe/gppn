import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Part de la barre réservée à l'envoi des octets ; le reste couvre le
// traitement serveur (déplacement des fichiers, enregistrement), dont on ne
// connaît pas la durée : la barre y avance lentement sans dépasser 99 %.
const UPLOAD_SHARE = 0.95;
const PROCESSING_CAP = 0.995;

/*
 * Envoie le formulaire en XHR lorsqu'au moins un fichier est joint, afin
 * d'afficher la progression de l'upload dans une modale. Fermer la modale
 * (croix, Échap, clic sur le voile) demande confirmation puis annule l'envoi.
 * 100 % ne s'affiche qu'une fois l'enregistrement confirmé par le serveur.
 */
export default class extends Controller {
    static targets = ['dialog', 'progress', 'bar', 'percent', 'status', 'main', 'confirm'];

    submit(event) {
        const form = event.target;
        if (event.defaultPrevented || !this.hasSelectedFile(form)) {
            return;
        }
        event.preventDefault();

        const formData = new FormData(form, event.submitter);
        const xhr = new XMLHttpRequest();
        this.xhr = xhr;

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                this.setProgress((e.loaded / e.total) * UPLOAD_SHARE);
            }
        });
        xhr.upload.addEventListener('load', () => {
            this.setProgress(UPLOAD_SHARE);
            this.statusTarget.textContent = 'Fichiers envoyés, enregistrement en cours…';
            this.startProcessingCreep();
        });
        xhr.addEventListener('load', () => this.handleResponse(xhr));
        xhr.addEventListener('error', () => this.fail("L'envoi a échoué. Vérifiez votre connexion puis réessayez."));

        xhr.open((form.getAttribute('method') || 'POST').toUpperCase(), form.action);
        xhr.setRequestHeader('Accept', 'text/html, application/xhtml+xml');
        xhr.send(formData);

        this.open();
    }

    // Croix de fermeture, touche Échap ou clic sur le voile.
    requestClose(event) {
        event?.preventDefault();
        if (!this.xhr) {
            this.close();
            return;
        }
        this.mainTarget.hidden = true;
        this.confirmTarget.hidden = false;
    }

    backdropClick(event) {
        if (event.target === this.dialogTarget) {
            this.requestClose(event);
        }
    }

    // Certains navigateurs ferment la modale sans événement « cancel »
    // (Échap répété) : on la rouvre sur la confirmation tant que l'envoi court.
    closed() {
        if (this.xhr) {
            this.dialogTarget.showModal();
            this.requestClose();
        }
    }

    resume() {
        this.confirmTarget.hidden = true;
        this.mainTarget.hidden = false;
    }

    abort() {
        this.stopProcessingCreep();
        this.xhr?.abort();
        this.xhr = null;
        this.close();
    }

    close() {
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    open() {
        this.setProgress(0);
        this.statusTarget.textContent = 'Envoi des fichiers en cours…';
        this.statusTarget.classList.remove('is-error');
        this.mainTarget.hidden = false;
        this.confirmTarget.hidden = true;
        this.dialogTarget.showModal();
    }

    async handleResponse(xhr) {
        this.stopProcessingCreep();
        this.xhr = null;
        if (xhr.status === 0 || xhr.status >= 500) {
            this.fail(`Le serveur a renvoyé une erreur (${xhr.status}). Réessayez plus tard.`);
            return;
        }

        // Redirection = contenu enregistré ; sinon le formulaire revient avec ses erreurs.
        const redirected = xhr.responseURL !== '' && xhr.responseURL !== this.formUrl();
        if (redirected) {
            this.setProgress(1);
            this.statusTarget.textContent = 'Contenu enregistré.';
            // Laisse voir le 100 % avant de changer de page.
            await new Promise((resolve) => setTimeout(resolve, 400));
        }
        this.close();
        Turbo.visit(xhr.responseURL || window.location.href, {
            action: redirected ? 'advance' : 'replace',
            response: { statusCode: xhr.status, responseHTML: xhr.responseText, redirected },
        });
    }

    fail(message) {
        this.stopProcessingCreep();
        this.xhr = null;
        this.statusTarget.textContent = message;
        this.statusTarget.classList.add('is-error');
    }

    // Avance vers PROCESSING_CAP en ralentissant (2 % de l'écart restant
    // toutes les 200 ms) : montre que le serveur travaille sans jamais
    // prétendre avoir fini.
    startProcessingCreep() {
        this.stopProcessingCreep();
        this.ratio = UPLOAD_SHARE;
        this.creep = setInterval(() => {
            this.setProgress(this.ratio + (PROCESSING_CAP - this.ratio) * 0.02);
        }, 200);
    }

    stopProcessingCreep() {
        clearInterval(this.creep);
        this.creep = null;
    }

    disconnect() {
        this.stopProcessingCreep();
    }

    setProgress(ratio) {
        this.ratio = ratio;
        // Arrondi inférieur : 99,6 % reste affiché 99 %, jamais 100 % par anticipation.
        const percent = Math.floor(ratio * 100);
        this.barTarget.style.width = `${percent}%`;
        this.percentTarget.textContent = `${percent} %`;
        this.progressTarget.setAttribute('aria-valuenow', String(percent));
    }

    hasSelectedFile(form) {
        return Array.from(form.querySelectorAll('input[type="file"]')).some((input) => input.files.length > 0);
    }

    formUrl() {
        return new URL(this.element.querySelector('form').action, window.location.href).href;
    }
}
