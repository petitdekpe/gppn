import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'item', 'empty'];

    filter() {
        const query = this.inputTarget.value.trim().toLowerCase();
        let visibleCount = 0;

        this.itemTargets.forEach((item) => {
            const matches = query === '' || item.dataset.searchText.includes(query);
            item.hidden = !matches;
            // Un sujet d'un conseil masqué (calendrier de l'espace média) ne compte pas.
            if (matches && !item.parentElement.closest('[hidden]')) {
                visibleCount += 1;
            }
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visibleCount > 0;
        }
    }
}
