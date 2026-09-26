import { Controller } from '@hotwired/stimulus';

/*
 * « Voir plus » : affiche les éléments masqués (cibles `item`) puis retire le
 * bouton. Le bouton est un lien : sans JavaScript, il mène à une page
 * listant tout.
 */
export default class extends Controller {
    static targets = ['item', 'button'];

    show(event) {
        event.preventDefault();
        this.itemTargets.forEach((item) => {
            item.hidden = false;
        });
        this.buttonTarget.remove();
        this.itemTargets[0]?.focus();
    }
}
