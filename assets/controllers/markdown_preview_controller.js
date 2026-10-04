import { Controller } from '@hotwired/stimulus';

/*
 * Onglets « Écrire / Aperçu » des pages modifiables (admin > Pages) :
 * l'aperçu est rendu par le serveur, avec le même filtre Markdown que le site
 * (Admin\SitePageController::preview).
 */
export default class extends Controller {
    static targets = ['input', 'editor', 'output', 'writeTab', 'previewTab'];
    static values = { url: String, token: String };

    write() {
        this.show(false);
        this.inputTarget.focus();
    }

    async preview() {
        this.show(true);
        this.outputTarget.textContent = 'Chargement de l’aperçu…';

        const data = new FormData();
        data.set('content', this.inputTarget.value);
        data.set('_token', this.tokenValue);
        try {
            const response = await fetch(this.urlValue, { method: 'POST', body: data, headers: { Accept: 'text/html' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            this.outputTarget.innerHTML = await response.text();
        } catch {
            this.outputTarget.textContent = 'Aperçu indisponible. Rechargez la page et réessayez.';
        }
    }

    show(preview) {
        this.editorTarget.hidden = preview;
        this.outputTarget.hidden = !preview;
        this.writeTabTarget.classList.toggle('is-active', !preview);
        this.previewTabTarget.classList.toggle('is-active', preview);
        this.writeTabTarget.setAttribute('aria-selected', String(!preview));
        this.previewTabTarget.setAttribute('aria-selected', String(preview));
    }
}
