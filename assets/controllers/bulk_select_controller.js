import { Controller } from '@hotwired/stimulus';

/*
 * Sélection multiple dans les listes de l'administration.
 * Les cases des lignes sont rattachées au formulaire d'actions groupées par
 * leur attribut `form` : il reste hors du tableau, qui contient déjà un
 * formulaire de suppression par ligne (les formulaires ne s'imbriquent pas).
 *
 * - case d'en-tête : coche/décoche les lignes de son tableau ;
 * - Maj + clic : coche toute la plage depuis la dernière case cliquée ;
 * - seules les lignes visibles partent (listes à panneaux : conseil affiché) ;
 * - une action qui demande une cible (thématique, rôle, gouvernement…)
 *   affiche le champ correspondant.
 */
export default class extends Controller {
    static targets = ['checkbox', 'all', 'bar', 'count', 'action', 'param', 'form'];

    connect() {
        this.lastChecked = null;
        this.update();
    }

    toggle(event) {
        const box = event.target;
        if (event.shiftKey && this.lastChecked && this.lastChecked !== box) {
            const boxes = this.visibleBoxes();
            const [from, to] = [boxes.indexOf(this.lastChecked), boxes.indexOf(box)].sort((a, b) => a - b);
            if (from >= 0) {
                boxes.slice(from, to + 1).forEach((b) => { b.checked = box.checked; });
            }
        }
        this.lastChecked = box;
        this.update();
    }

    toggleAll(event) {
        const table = event.target.closest('table');
        this.checkboxTargets
            .filter((box) => box.closest('table') === table && !box.disabled)
            .forEach((box) => { box.checked = event.target.checked; });
        this.update();
    }

    clear() {
        this.checkboxTargets.forEach((box) => { box.checked = false; });
        this.update();
    }

    update() {
        const selected = this.selectedBoxes().length;
        this.barTarget.hidden = selected === 0;
        this.countTarget.textContent = `${selected} élément${selected > 1 ? 's' : ''} sélectionné${selected > 1 ? 's' : ''}`;

        this.allTargets.forEach((all) => {
            const boxes = this.checkboxTargets.filter((box) => box.closest('table') === all.closest('table') && !box.disabled);
            const checked = boxes.filter((box) => box.checked).length;
            all.checked = boxes.length > 0 && checked === boxes.length;
            all.indeterminate = checked > 0 && checked < boxes.length;
        });
        this.showParams();
    }

    showParams() {
        const option = this.actionTarget.selectedOptions[0];
        const needs = option?.dataset.param ?? '';
        this.paramTargets.forEach((field) => {
            const shown = field.dataset.param === needs;
            field.hidden = !shown;
            field.querySelectorAll('select, input').forEach((input) => { input.disabled = !shown; });
        });
    }

    submit(event) {
        const option = this.actionTarget.selectedOptions[0];
        const selected = this.selectedBoxes();
        if (!option?.value || selected.length === 0) {
            event.preventDefault();
            this.actionTarget.focus();
            return;
        }

        const message = option.dataset.confirm?.replace('%count%', String(selected.length));
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        // Lignes cochées mais masquées (autre conseil affiché) : elles ne partent pas.
        this.checkboxTargets.forEach((box) => {
            if (box.checked && !this.isVisible(box)) {
                box.checked = false;
            }
        });
    }

    selectedBoxes() {
        return this.visibleBoxes().filter((box) => box.checked);
    }

    visibleBoxes() {
        return this.checkboxTargets.filter((box) => this.isVisible(box));
    }

    isVisible(box) {
        return box.offsetParent !== null || box.getClientRects().length > 0;
    }
}
