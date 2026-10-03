import { Controller } from '@hotwired/stimulus';

/*
 * Bouton « Générer depuis la vidéo TV » du formulaire d'un contenu : le
 * serveur capture l'image à la seconde choisie et remplace la couverture.
 * En fetch, pour ne pas perdre ce qui est en cours de saisie dans le formulaire.
 */
export default class extends Controller {
    static targets = ['second', 'button', 'status', 'preview', 'caption'];
    static values = { url: String, token: String };

    async generate() {
        const second = this.secondTarget.value.trim();
        this.buttonTarget.disabled = true;
        this.setStatus(`Capture de l’image à ${second} s…`);

        try {
            const data = new FormData();
            data.append('_token', this.tokenValue);
            data.append('second', second);
            const response = await fetch(this.urlValue, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            const body = await response.json().catch(() => null);

            if (!response.ok || !body?.url) {
                this.setStatus(body?.error ?? `Échec de la génération (erreur ${response.status}).`, true);
                return;
            }

            this.previewTarget.src = body.url;
            this.previewTarget.hidden = false;
            this.captionTarget.hidden = false;
            this.captionTarget.textContent = 'Image actuelle (tirée de la vidéo) :';
            this.setStatus(`Couverture remplacée par l’image à ${body.second} s. Elle est déjà enregistrée.`);
        } catch {
            this.setStatus('Connexion au serveur perdue : réessayez.', true);
        } finally {
            this.buttonTarget.disabled = false;
        }
    }

    setStatus(text, isError = false) {
        this.statusTarget.textContent = text;
        this.statusTarget.classList.toggle('form-error', isError);
    }
}
