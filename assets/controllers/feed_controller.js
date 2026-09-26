import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['scroller', 'item', 'sentinel'];
    static values = {
        moreUrl: String,
        nextPage: Number,
        hasMore: Boolean,
    };

    connect() {
        this.loading = false;

        this.playObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const video = entry.target.querySelector('.feed-item__video');
                if (!video) return;

                if (entry.isIntersecting) {
                    video.play().catch(() => {});
                } else {
                    video.pause();
                }
            });
        }, { threshold: 0.6 });

        this.itemTargets.forEach((item) => this.playObserver.observe(item));

        if (this.hasSentinelTarget) {
            this.loadObserver = new IntersectionObserver((entries) => {
                if (entries[0].isIntersecting) {
                    this.loadMore();
                }
            }, { root: this.scrollerTarget });

            this.loadObserver.observe(this.sentinelTarget);
        }
    }

    disconnect() {
        this.playObserver?.disconnect();
        this.loadObserver?.disconnect();
    }

    togglePlay(event) {
        const video = event.currentTarget;

        if (video.paused) {
            video.play().catch(() => {});
        } else {
            video.pause();
        }
    }

    toggleMute(event) {
        const button = event.currentTarget;
        const video = button.closest('.feed-item')?.querySelector('.feed-item__video');
        if (!video) return;

        video.muted = !video.muted;

        const icon = button.querySelector('i');
        icon.classList.toggle('fa-volume-xmark', video.muted);
        icon.classList.toggle('fa-volume-high', !video.muted);
    }

    async loadMore() {
        if (this.loading || !this.hasMoreValue) return;
        this.loading = true;

        try {
            const response = await fetch(`${this.moreUrlValue}?page=${this.nextPageValue}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();

            this.sentinelTarget.insertAdjacentHTML('beforebegin', data.html);
            this.itemTargets.forEach((item) => this.playObserver.observe(item));

            this.nextPageValue = data.nextPage;
            this.hasMoreValue = data.hasMore;

            if (!data.hasMore) {
                this.loadObserver.disconnect();
                this.sentinelTarget.remove();
            }
        } finally {
            this.loading = false;
        }
    }
}
