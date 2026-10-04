import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['poster', 'video'];

    play() {
        this.posterTarget.hidden = true;
        // Sur téléphone, le cadre passe en 9:16 si la version Mobile est lue (voir app.css).
        this.element.classList.add('is-playing');
        this.videoTarget.hidden = false;
        this.videoTarget.play();
    }
}
