import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['frame', 'media', 'x', 'y', 'readout'];

    connect() {
        this.dragging = false;
        this.onPointerMove = this.onPointerMove.bind(this);
        this.onPointerUp = this.onPointerUp.bind(this);
    }

    disconnect() {
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
    }

    start(event) {
        event.preventDefault();
        this.dragging = true;
        this.lastX = event.clientX;
        this.lastY = event.clientY;
        this.frameTarget.classList.add('is-dragging');
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
    }

    onPointerMove(event) {
        if (!this.dragging) {
            return;
        }

        const rect = this.frameTarget.getBoundingClientRect();
        const deltaX = event.clientX - this.lastX;
        const deltaY = event.clientY - this.lastY;
        this.lastX = event.clientX;
        this.lastY = event.clientY;

        const nextX = this.clamp(this.currentX() - (deltaX / rect.width) * 100);
        const nextY = this.clamp(this.currentY() - (deltaY / rect.height) * 100);

        this.xTarget.value = Math.round(nextX);
        this.yTarget.value = Math.round(nextY);
        this.render();
    }

    onPointerUp() {
        this.dragging = false;
        this.frameTarget.classList.remove('is-dragging');
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
    }

    reset() {
        this.xTarget.value = 50;
        this.yTarget.value = 50;
        this.render();
    }

    render() {
        const x = this.currentX();
        const y = this.currentY();
        this.mediaTarget.style.objectPosition = `${x}% ${y}%`;
        if (this.hasReadoutTarget) {
            this.readoutTarget.textContent = `${x}% / ${y}%`;
        }
    }

    currentX() {
        return parseFloat(this.xTarget.value) || 50;
    }

    currentY() {
        return parseFloat(this.yTarget.value) || 50;
    }

    clamp(value) {
        return Math.min(100, Math.max(0, Math.round(value)));
    }
}
