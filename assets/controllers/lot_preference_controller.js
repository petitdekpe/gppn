import { Controller } from '@hotwired/stimulus';

/*
 * Préférences de lot de l'espace média (langues, formats, intervenant).
 * Au clic sur « Télécharger le lot », une fenêtre propose d'enregistrer les
 * choix en cours sous un nom, sauf si le média a coché « Ne plus me
 * proposer », si ces choix sont déjà une de ses préférences ou s'il n'y a
 * rien à retenir. Le panneau « Mes préférences » applique, supprime ou
 * enregistre une préférence à tout moment.
 *
 * Chaque échange envoie aussi les choix en cours du constructeur de lot et
 * reçoit le panneau à jour (MediaSpacePreferenceController). L'état du
 * panneau (data-prompt, data-savable, data-saved) suit les choix : c'est une
 * zone rafraîchie par lot_builder_controller.js.
 */
export default class extends Controller {
    static targets = ['panel', 'dialog', 'form', 'error', 'dismiss', 'submit', 'skip', 'choices', 'download'];
    static values = { saveUrl: String, promptUrl: String, token: String };

    /** Formulaire de téléchargement : proposer d'abord d'enregistrer les choix. */
    download(event) {
        if (this.confirmed) {
            this.confirmed = false;
            return;
        }
        const { prompt, savable, saved } = this.panelTarget.dataset;
        if (prompt !== 'true' || savable !== 'true' || saved === 'true') {
            return;
        }
        event.preventDefault();
        this.open(true);
    }

    /** Bouton « Enregistrer mes choix » du panneau. */
    openSave() {
        this.open(false);
    }

    open(withDownload) {
        this.withDownload = withDownload;
        this.dismissTarget.hidden = !withDownload;
        if (this.hasSubmitTarget) {
            this.submitTarget.textContent = withDownload ? 'Enregistrer et télécharger' : 'Enregistrer';
        }
        this.skipTarget.textContent = withDownload ? 'Télécharger sans enregistrer' : 'Annuler';
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    /** Fermeture (bouton, Échap) : la fenêtre repart vide à la prochaine ouverture. */
    closed() {
        this.formTarget.reset();
        this.errorTarget.hidden = true;
    }

    async save(event) {
        event.preventDefault();
        const html = await this.post(this.saveUrlValue, new FormData(this.formTarget));
        if (html === null) {
            return;
        }
        this.close();
        this.panelTarget.outerHTML = html;
        if (this.withDownload) {
            this.startDownload();
        }
    }

    /** « Télécharger sans enregistrer » ou « Annuler ». */
    skip() {
        this.close();
        if (this.withDownload) {
            this.startDownload();
        }
    }

    /**
     * Case « Ne plus me proposer » : enregistrée dès qu'elle change, avant
     * tout téléchargement (qui pourrait interrompre la requête) et même si
     * la fenêtre est ensuite fermée par la croix ou Échap. Les boutons restent
     * désactivés pendant l'envoi (voir post).
     */
    async togglePrompt(event) {
        const checkbox = event.currentTarget;
        const prompt = !checkbox.checked;
        const data = new FormData();
        data.set('proposer', prompt ? '1' : '0');
        if (await this.post(this.promptUrlValue, data) === null) {
            checkbox.checked = !checkbox.checked;
            return;
        }
        this.panelTarget.dataset.prompt = String(prompt);
    }

    async delete({ params: { url, name } }) {
        if (!window.confirm(`Supprimer la préférence « ${name} » ?`)) {
            return;
        }
        const html = await this.post(url);
        if (html !== null) {
            this.panelTarget.outerHTML = html;
        } else {
            window.alert('La suppression a échoué. Réessayez.');
        }
    }

    /** Appliquer une préférence en gardant les sujets déjà cochés. */
    apply(event) {
        event.preventDefault();
        const url = new URL(event.currentTarget.href);
        const choices = new FormData(this.choicesTarget);
        ['sujet[]', 'conseil'].forEach((key) => {
            choices.getAll(key).forEach((value) => url.searchParams.append(key, value));
        });
        window.location.assign(url);
    }

    startDownload() {
        if (!this.hasDownloadTarget) {
            return;
        }
        this.confirmed = true;
        this.downloadTarget.requestSubmit();
    }

    /**
     * Envoie les choix en cours (et les champs donnés) ; renvoie le panneau à
     * jour, ou null en affichant l'erreur dans la fenêtre.
     */
    async post(url, extra = null) {
        const data = new FormData(this.choicesTarget);
        extra?.forEach((value, key) => data.append(key, value));
        data.set('_token', this.tokenValue);

        const buttons = this.panelTarget.querySelectorAll('button');
        buttons.forEach((button) => { button.disabled = true; });
        try {
            const response = await fetch(url, { method: 'POST', body: data, headers: { Accept: 'text/html' } });
            const text = await response.text();
            if (response.ok) {
                return text;
            }
            this.showError(response.status === 422 ? text : 'L’enregistrement a échoué. Rechargez la page et réessayez.');
        } catch {
            this.showError('Connexion impossible. Vérifiez votre réseau et réessayez.');
        } finally {
            buttons.forEach((button) => { button.disabled = false; });
        }

        return null;
    }

    showError(message) {
        if (this.dialogTarget.open) {
            this.errorTarget.textContent = message;
            this.errorTarget.hidden = false;
        }
    }
}
