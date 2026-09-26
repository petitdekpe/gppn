import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['poster', 'video'];

    play() {
        this.posterTarget.hidden = true;
        this.videoTarget.hidden = false;
        this.videoTarget.play();
    }
}
