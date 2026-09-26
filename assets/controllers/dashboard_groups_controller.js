import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'gppn.admin.dashboard.closedGroups';

/*
 * Mémorise, par navigateur, les groupes du tableau de bord repliés
 * (<details data-group="…">). Sans stockage disponible, tout reste ouvert.
 */
export default class extends Controller {
    static targets = ['group'];

    connect() {
        const closed = this.readClosed();
        this.groupTargets.forEach((group) => {
            if (closed.includes(group.dataset.group)) {
                group.open = false;
            }
            group.addEventListener('toggle', this.save);
        });
    }

    disconnect() {
        this.groupTargets.forEach((group) => group.removeEventListener('toggle', this.save));
    }

    save = () => {
        const closed = this.groupTargets.filter((group) => !group.open).map((group) => group.dataset.group);
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(closed));
        } catch {
            // Stockage indisponible (navigation privée…) : l'état n'est simplement pas retenu.
        }
    };

    readClosed() {
        try {
            const value = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
            return Array.isArray(value) ? value : [];
        } catch {
            return [];
        }
    }
}
