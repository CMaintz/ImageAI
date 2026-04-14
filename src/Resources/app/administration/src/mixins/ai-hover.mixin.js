// Mixin handling a simple hover-card shown on mouseenter for cells
export default {
    data() {
        return {
            hoverVisible: false,
                hoverHtml: '',
                hoverX: 0,
                hoverY: 0,
                hoverTimeout: null,
                hoverShowTimeout: null
        };
    },

    methods: {
        showHover(content, evt, delay = 0.5) {

            if (this.hoverTimeout) {
                clearTimeout(this.hoverTimeout);
                this.hoverTimeout = null;
            }
            if (this.hoverShowTimeout) {
                clearTimeout(this.hoverShowTimeout);
                this.hoverShowTimeout = null;
            }

            const doShow = () => {
                this.hoverHtml = String(content ?? '');

                const offsetX = 12;
                const offsetY = 12;

                const viewportWidth = window.innerWidth;
                const viewportHeight = window.innerHeight;

                // Start position: to the right and below cursor
                let x = evt?.clientX ? (evt.clientX + offsetX) : 100;
                let y = evt?.clientY ? (evt.clientY + offsetY) : 100;

                // Make visible first, then adjust position after render
                this.hoverX = x;
                this.hoverY = y;
                this.hoverVisible = true;

                // After render, check actual dimensions and reposition if needed
                this.$nextTick(() => {
                    const card = this.$el?.querySelector('.ai-hover-card');
                    if (!card) {
                        return;
                    }

                    const rect = card.getBoundingClientRect();
                    const cardWidth = rect.width;
                    const cardHeight = rect.height;

                    // Check right boundary - flip to left of cursor if needed
                    if (x + cardWidth > viewportWidth - 20) {
                        x = Math.max(20, (evt?.clientX || 100) - cardWidth - offsetX);
                    }

                    // Check bottom boundary - position above cursor if needed
                    if (y + cardHeight > viewportHeight - 20) {
                        // Position so bottom of card is just above cursor (smaller gap when above)
                        y = Math.max(20, (evt?.clientY || 100) - cardHeight - 4);
                    }

                    this.hoverX = x;
                    this.hoverY = y;
                });
            };

            if (delay > 0) {
                this.hoverShowTimeout = setTimeout(doShow, delay);
            } else {
                doShow();
            }
        },

        hideHover(delay = 120) {
            // small delay to avoid flicker when moving between cell and card
            if (this.hoverTimeout) {
                clearTimeout(this.hoverTimeout);
            }
            if (this.hoverShowTimeout) {
                clearTimeout(this.hoverShowTimeout);
                this.hoverShowTimeout = null;
            }
            this.hoverTimeout = setTimeout(() => {
                this.hoverVisible = false;
                this.hoverHtml = '';
            }, delay);
        },

        cancelHover() {
            if (this.hoverTimeout) {
                clearTimeout(this.hoverTimeout);
            }
            if (this.hoverShowTimeout) {
                clearTimeout(this.hoverShowTimeout);
                this.hoverShowTimeout = null;
            }
            this.hoverVisible = false;
            this.hoverHtml = '';
        },

        onCellMouseEnterFullText(text, evt, delay = 300) {
            if (!text) {
                return;
            }
            const html = `<div class="hover-fulltext">${this.escapeHtml(text)}</div>`;
            this.showHover(html, evt, delay);
        },

        onTagsMouseEnter(propObj, evt, delay = 300) {
            if (!propObj) {
                return;
            }
            const html = this.propertiesToHtml(propObj);
            this.showHover(html, evt, delay);
        },

        onImageMouseEnter(imageUrl, evt, delay = 200) {
            if (!imageUrl) {
                return;
            }
            const html = `<img src="${imageUrl}" alt="Product preview" style="max-width: 300px; max-height: 300px; display: block;" />`;
            this.showHover(html, evt, delay);
        },

        onConfidenceMouseEnter(item, evt, delay = 300) {
            if (!this.hasConfidenceWarnings || !this.hasConfidenceWarnings(item)) {
                return;
            }
            const html = this.confidenceWarningsToHtml(item.confidenceWarnings);
            this.showHover(html, evt, delay);
        },

        onCellMouseLeave() {
            this.hideHover();
        }
    }
    };
