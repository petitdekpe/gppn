import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['step', 'indicator'];

    connect() {
        this.showStep(0);
    }

    next(event) {
        event.preventDefault();
        if (!this.element.reportValidity()) {
            return;
        }
        this.showStep(this.currentIndex + 1);
    }

    prev(event) {
        event.preventDefault();
        this.showStep(this.currentIndex - 1);
    }

    showStep(index) {
        index = Math.max(0, Math.min(this.stepTargets.length - 1, index));
        this.currentIndex = index;
        this.stepTargets.forEach((step, i) => {
            step.hidden = i !== index;
        });
        this.indicatorTargets.forEach((indicator, i) => {
            indicator.classList.toggle('is-active', i === index);
        });
    }
}
