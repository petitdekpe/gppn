import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['slide'];

    go({ params: { index } }) {
        this.slideTargets.forEach((slide, i) => {
            slide.classList.toggle('is-active', i === index);
        });
    }
}
