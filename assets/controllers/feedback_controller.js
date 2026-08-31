import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['rating', 'choice'];

    choose({ params: { rating }, currentTarget }) {
        this.ratingTarget.value = rating;
        this.choiceTargets.forEach((choice) => {
            choice.classList.toggle('is-active', choice === currentTarget);
        });
    }
}
