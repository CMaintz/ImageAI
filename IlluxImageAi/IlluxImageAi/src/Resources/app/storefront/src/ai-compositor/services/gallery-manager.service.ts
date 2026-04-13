import type { CompositionResult, PlaceholderSet, GallerySliderPlugin } from '../types';

declare const window: Window & {
    PluginManager?: {
        getPluginInstanceFromElement(el: Element, name: string): unknown;
        initializePlugin(name: string, selector: string): void;
    };
};

export class GalleryManagerService {
    private placeholders: Map<string, PlaceholderSet> = new Map();

    getGalleryPlugin(): GallerySliderPlugin | null {
        const galleryElement = document.querySelector('[data-gallery-slider="true"]');
        if (!galleryElement || !window.PluginManager) {
            return null;
        }

        try {
            return window.PluginManager.getPluginInstanceFromElement(galleryElement, 'GallerySlider') as GallerySliderPlugin;
        } catch {
            return null;
        }
    }

    getMainContainer(): HTMLElement | null {
        return document.querySelector('[data-gallery-slider-container="true"]');
    }

    getThumbContainer(): HTMLElement | null {
        return document.querySelector('[data-gallery-slider-thumbnails="true"]');
    }

    getDotsContainer(): HTMLElement | null {
        return document.querySelector('.base-slider-dots');
    }

    rebuildSlider(): void {
        const galleryPlugin = this.getGalleryPlugin();
        if (galleryPlugin) {
            galleryPlugin.rebuild();
        }
    }

    getDisplayMode(): 'cover' | 'contain' | 'standard' {
        const existingItem = document.querySelector('.gallery-slider-item:not(.ai-composited-main .gallery-slider-item)');
        if (existingItem?.classList.contains('is-cover')) return 'cover';
        if (existingItem?.classList.contains('is-contain')) return 'contain';
        return 'standard';
    }

    getMinHeight(): string {
        const existingItem = document.querySelector('.gallery-slider-item:not(.ai-composited-main .gallery-slider-item)') as HTMLElement;
        if (!existingItem) return '400px';

        const inlineMinHeight = existingItem.style.minHeight;
        if (inlineMinHeight) return inlineMinHeight;

        const computed = window.getComputedStyle(existingItem);
        const computedMinHeight = computed.getPropertyValue('min-height');
        if (computedMinHeight && computedMinHeight !== '0px' && computedMinHeight !== 'auto') {
            return computedMinHeight;
        }
        return '400px';
    }

    createPlaceholders(environments: string[]): Map<string, PlaceholderSet> {
        const mainContainer = this.getMainContainer();
        const thumbContainer = this.getThumbContainer();
        const dotsContainer = this.getDotsContainer();

        if (!mainContainer || !thumbContainer) {
            return this.placeholders;
        }

        const aiButtonThumb = thumbContainer.querySelector('.ai-compositor-thumbnail-btn');
        const existingItemCount = mainContainer.children.length;
        const displayMode = this.getDisplayMode();
        const minHeight = this.getMinHeight();

        environments.forEach((sceneName, index) => {

            const mainPlaceholder = this.createMainPlaceholder(sceneName, index, displayMode, minHeight);
            const thumbPlaceholder = this.createThumbPlaceholder(sceneName, index);

            mainContainer.appendChild(mainPlaceholder);
            this.insertThumbnailAfterAiButton(thumbContainer, thumbPlaceholder, aiButtonThumb, index);

            if (dotsContainer) {
                // Calculate dot index: existingItemCount includes product images + room selector
                // Dot data-nav-dot is 1-based, slider.goTo() uses 0-based (navDot - 1)
                // So for slide at position (existingItemCount + index), dot should be (existingItemCount + index + 1)
                const slidePosition = existingItemCount + index; // 0-based slide position
                const dotIndex = slidePosition + 1; // 1-based for data-nav-dot

                const dot = document.createElement('button');
                dot.className = 'base-slider-dot ai-composited-dot';
                dot.setAttribute('data-nav-dot', String(dotIndex));
                dot.setAttribute('aria-label', `Go to slide ${dotIndex}`);
                dot.setAttribute('tabindex', '-1');
                if (index < environments.length - 1) {
                    dot.style.marginRight = '5px';
                }

                // Add click handler since dynamically added dots aren't in slider's _dots array
                dot.addEventListener('click', () => {
                    const galleryPlugin = this.getGalleryPlugin();
                    if (galleryPlugin?._slider) {
                        galleryPlugin._slider.goTo(slidePosition);
                    }
                });

                dotsContainer.appendChild(dot);
            }

            this.addPlaceholderToZoomModal(sceneName);

            this.placeholders.set(sceneName, {
                main: mainPlaceholder,
                thumbnail: thumbPlaceholder,
                index
            });
        });

        return this.placeholders;
    }

    injectComposition(composition: CompositionResult): void {
        const set = this.placeholders.get(composition.sceneName);
        if (!set) {
            return;
        }

        if (composition.image) {
            this.updateMainSlide(set.main, composition);
            this.updateThumbnail(set.thumbnail, composition);
            this.updateZoomModalImage(composition);
            set.main.classList.add('loaded');
            set.thumbnail.classList.add('loaded');
        } else {
            this.showCompositionError(set, composition);
        }
    }

    clearCompositions(): void {
        document.querySelectorAll('.ai-composited-main').forEach(el => el.remove());
        document.querySelectorAll('.ai-composited-thumbnail').forEach(el => el.remove());
        document.querySelectorAll('.ai-composited-dot').forEach(el => el.remove());
        this.removeZoomModalElements();
        this.placeholders.clear();
    }

    reinitializePlugins(): void {
        if (!window.PluginManager) return;

        try {
            const galleryRow = document.querySelector('.gallery-slider-row');
            if (!galleryRow) return;

            if (galleryRow.hasAttribute('data-magnifier')) {
                const existingMagnifier = window.PluginManager.getPluginInstanceFromElement(galleryRow, 'Magnifier') as { destroy?: () => void } | null;
                if (existingMagnifier?.destroy) {
                    try { existingMagnifier.destroy(); } catch { /* ignore */ }
                }
                window.PluginManager.initializePlugin('Magnifier', '[data-magnifier]');
            }

            const zoomModalContainer = galleryRow.querySelector('[data-zoom-modal]');
            if (zoomModalContainer) {
                const existingZoomModal = window.PluginManager.getPluginInstanceFromElement(zoomModalContainer, 'ZoomModal') as { init?: () => void } | null;
                if (existingZoomModal?.init) {
                    existingZoomModal.init();
                } else {
                    window.PluginManager.initializePlugin('ZoomModal', '[data-zoom-modal]');
                }
            }

            // Rebuild zoom modal's internal slider if it exists
            this.rebuildZoomModalSlider();

            // Hook into slider's indexChanged to manage our dots' active state
            this.setupDotActiveStateHandler();
        } catch { /* ignore */ }
    }

    /**
     * Rebuild the zoom modal's internal gallery slider to include dynamically added AI compositions.
     */
    private rebuildZoomModalSlider(): void {
        const zoomModal = document.querySelector('.zoom-modal');
        if (!zoomModal || !window.PluginManager) return;

        const zoomGalleryElement = zoomModal.querySelector('[data-gallery-slider="true"]');
        if (!zoomGalleryElement) return;

        try {
            const zoomGalleryPlugin = window.PluginManager.getPluginInstanceFromElement(
                zoomGalleryElement,
                'GallerySlider'
            ) as GallerySliderPlugin | null;

            if (zoomGalleryPlugin?.rebuild) {
                zoomGalleryPlugin.rebuild();
            }
        } catch {
            // Ignore rebuild errors
        }
    }

    /**
     * Listen for slider index changes and update our AI composition dots' active state.
     * Shopware's _setActiveDot() only manages dots in its static _dots array,
     * so we need to handle our dynamically added dots separately.
     */
    private setupDotActiveStateHandler(): void {
        const galleryPlugin = this.getGalleryPlugin();
        // Check both _slider and _slider.events exist before trying to attach handler
        if (!galleryPlugin?._slider?.events) {
            return;
        }

        try {
            galleryPlugin._slider.events.on('indexChanged', () => {
                this.updateAiDotsActiveState();
            });

            // Also update immediately
            this.updateAiDotsActiveState();
        } catch {
            // Ignore handler setup errors
        }
    }

    /**
     * Update active state for AI composition dots based on current slide.
     */
    private updateAiDotsActiveState(): void {
        const galleryPlugin = this.getGalleryPlugin();
        if (!galleryPlugin?._slider) return;

        const currentIndex = galleryPlugin.getCurrentSliderIndex();
        const aiDots = document.querySelectorAll('.ai-composited-dot');

        aiDots.forEach(dot => {
            const dotNavIndex = parseInt(dot.getAttribute('data-nav-dot') || '0', 10);
            const dotSlideIndex = dotNavIndex - 1; // Convert 1-based to 0-based

            if (dotSlideIndex === currentIndex) {
                dot.classList.add('tns-nav-active');
            } else {
                dot.classList.remove('tns-nav-active');
            }
        });

        // Also deselect product image dots when on room selector or AI slides
        // Room selector and AI slides don't have product dots, so Shopware's code
        // will already deselect them (it removes active from all, then adds to current if found)
    }

    getPlaceholders(): Map<string, PlaceholderSet> {
        return this.placeholders;
    }

    private createMainPlaceholder(sceneName: string, index: number, displayMode: string, minHeight: string): HTMLElement {
        const minHeightStyle = (displayMode === 'cover' || displayMode === 'contain')
            ? `style="min-height: ${minHeight}"`
            : '';

        const container = document.createElement('div');
        container.className = 'gallery-slider-item-container ai-composited-main';
        container.dataset.scene = sceneName;
        container.dataset.index = String(index);
        container.innerHTML = `
            <div class="gallery-slider-item is-${displayMode} js-magnifier-container" ${minHeightStyle}>
                <div style="display:flex;align-items:center;justify-content:center;min-height:400px;background:#f8f9fa;">
                    <div style="text-align:center;">
                        ${this.createInlineSpinner(50)}
                        <div style="font-size:0.85em;color:#6c757d;margin-top:10px;">${sceneName}</div>
                    </div>
                </div>
            </div>
        `;
        return container;
    }

    private createThumbPlaceholder(sceneName: string, index: number): HTMLElement {
        const item = document.createElement('div');
        item.className = 'gallery-slider-thumbnails-item ai-composited-thumbnail';
        item.dataset.scene = sceneName;
        item.dataset.index = String(index);
        item.innerHTML = `
            <div class="gallery-slider-thumbnails-item-inner" style="width:100%;height:100%;">
                <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-height:80px;background:#f8f9fa;">
                    ${this.createInlineSpinner(24)}
                </div>
            </div>
        `;
        return item;
    }

    /**
     * Creates an iOS-style spinner with fully inline styles.
     * Uses child divs instead of pseudo-elements so everything can be inline.
     */
    private createInlineSpinner(size: number): string {
        const gradientBg = `linear-gradient(0deg, rgb(0 0 0/50%) 30%, transparent 30% 70%, rgb(0 0 0/100%) 70%) 50%/8% 100%, linear-gradient(90deg, rgb(0 0 0/25%) 30%, transparent 30% 70%, rgb(0 0 0/75%) 70%) 50%/100% 8%`;
        const baseStyle = `width:${size}px;height:${size}px;border-radius:50%;background:${gradientBg};background-repeat:no-repeat;position:absolute;top:0;left:0;`;

        return `
            <div style="width:${size}px;height:${size}px;position:relative;animation:l23 1s infinite steps(12);">
                <div style="${baseStyle}opacity:1;"></div>
                <div style="${baseStyle}opacity:0.915;transform:rotate(30deg);"></div>
                <div style="${baseStyle}opacity:0.83;transform:rotate(60deg);"></div>
            </div>
        `;
    }

    private insertThumbnailAfterAiButton(container: HTMLElement, thumb: HTMLElement, aiButton: Element | null, index: number): void {
        if (aiButton && aiButton.nextSibling) {
            if (index === 0) {
                container.insertBefore(thumb, aiButton.nextSibling);
            } else {
                const lastThumb = container.querySelector(`.ai-composited-thumbnail[data-index="${index - 1}"]`);
                if (lastThumb?.nextSibling) {
                    container.insertBefore(thumb, lastThumb.nextSibling);
                } else {
                    container.appendChild(thumb);
                }
            }
        } else if (aiButton) {
            container.appendChild(thumb);
        } else {
            container.appendChild(thumb);
        }
    }

    private updateMainSlide(container: HTMLElement, composition: CompositionResult): void {
        const displayMode = this.getDisplayMode();
        const mainItem = container.querySelector('.gallery-slider-item') as HTMLElement;
        if (!mainItem) return;

        const existingMinHeight = mainItem.style.minHeight;
        mainItem.className = `gallery-slider-item is-${displayMode} js-magnifier-container`;
        if (existingMinHeight) mainItem.style.minHeight = existingMinHeight;

        const objectFitAttr = (displayMode === 'cover' || displayMode === 'contain')
            ? `data-object-fit="${displayMode}"`
            : '';

        mainItem.innerHTML = `
            <img src="${composition.image}"
                 alt="${composition.label} - AI Composed"
                 class="img-fluid gallery-slider-image magnifier-image js-magnifier-image"
                 title="${composition.label}"
                 data-full-image="${composition.image}"
                 ${objectFitAttr}
                 style="aspect-ratio: 1;"
                 tabindex="0">
        `;

        this.fadeInImage(mainItem.querySelector('img'));
    }

    private updateThumbnail(container: HTMLElement, composition: CompositionResult): void {
        const thumbInner = container.querySelector('.gallery-slider-thumbnails-item-inner');
        if (!thumbInner) return;

        thumbInner.innerHTML = `
            <img src="${composition.image}"
                 alt="${composition.label}"
                 class="gallery-slider-thumbnails-image"
                 title="${composition.label}">
        `;

        this.fadeInImage(thumbInner.querySelector('img'));
    }

    private fadeInImage(img: HTMLElement | null): void {
        if (!img) return;
        img.style.opacity = '0';
        setTimeout(() => {
            img.style.transition = 'opacity 0.3s ease-in';
            img.style.opacity = '1';
        }, 10);
    }

    private showCompositionError(set: PlaceholderSet, composition: CompositionResult): void {
        const mainItem = set.main.querySelector('.gallery-slider-item');
        if (mainItem) {
            mainItem.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;min-height:400px;background:#f8d7da;color:#842029;"><div style="text-align:center;"><div style="font-weight:bold;margin-bottom:10px;">${composition.label}</div><div>Error: ${composition.error || 'Failed'}</div></div></div>`;
        }

        const thumbInner = set.thumbnail.querySelector('.gallery-slider-thumbnails-item-inner');
        if (thumbInner) {
            thumbInner.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;min-height:80px;background:#f8d7da;border:2px solid #f5c2c7;border-radius:4px;padding:10px;font-size:0.7em;color:#842029;text-align:center;">Error</div>`;
        }

        set.main.classList.add('error');
        set.thumbnail.classList.add('error');
    }

    private addPlaceholderToZoomModal(sceneName: string): void {
        const zoomModal = document.querySelector('.zoom-modal');
        if (!zoomModal) return;

        const zoomSliderContainer = zoomModal.querySelector('[data-gallery-slider-container]');
        const zoomThumbContainer = zoomModal.querySelector('[data-gallery-slider-thumbnails]');

        if (zoomSliderContainer) {
            const zoomSlide = document.createElement('div');
            zoomSlide.className = 'gallery-slider-item ai-composited-zoom';
            zoomSlide.dataset.scene = sceneName;
            zoomSlide.innerHTML = `
                <div class="image-zoom-container">
                    <div style="display:flex;align-items:center;justify-content:center;min-height:400px;background:#f8f9fa;">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
            `;
            zoomSliderContainer.appendChild(zoomSlide);
        }

        if (zoomThumbContainer) {
            const zoomThumb = document.createElement('div');
            zoomThumb.className = 'gallery-slider-thumbnails-item ai-composited-zoom-thumb';
            zoomThumb.dataset.scene = sceneName;
            zoomThumb.innerHTML = `
                <div class="gallery-slider-thumbnails-item-inner">
                    <div style="display:flex;align-items:center;justify-content:center;min-height:60px;background:#f8f9fa;">
                        <div class="spinner-border spinner-border-sm text-primary"></div>
                    </div>
                </div>
            `;

            // Add click handler for zoom modal thumbnail navigation
            zoomThumb.addEventListener('click', () => {
                this.navigateZoomModalToScene(sceneName);
            });

            zoomThumbContainer.appendChild(zoomThumb);
        }
    }

    private updateZoomModalImage(composition: CompositionResult): void {
        const zoomModal = document.querySelector('.zoom-modal');
        if (!zoomModal) return;

        const zoomSlide = zoomModal.querySelector(`.gallery-slider-item[data-scene="${composition.sceneName}"]`);
        const zoomThumb = zoomModal.querySelector(`.gallery-slider-thumbnails-item[data-scene="${composition.sceneName}"]`);

        if (zoomSlide) {
            zoomSlide.innerHTML = `
                <div class="image-zoom-container" data-image-zoom="true">
                    <img src="${composition.image}"
                         alt="${composition.label}"
                         class="gallery-slider-image js-image-zoom-element"
                         title="${composition.label}">
                </div>
            `;
        }

        if (zoomThumb) {
            const inner = zoomThumb.querySelector('.gallery-slider-thumbnails-item-inner');
            if (inner) {
                inner.innerHTML = `
                    <img src="${composition.image}"
                         alt="${composition.label}"
                         class="gallery-slider-thumbnails-image">
                `;
            }
        }
    }

    private removeZoomModalElements(): void {
        const zoomModal = document.querySelector('.zoom-modal');
        if (!zoomModal) return;
        zoomModal.querySelectorAll('.ai-composited-zoom, .ai-composited-zoom-thumb').forEach(el => el.remove());
    }

    /**
     * Navigate the zoom modal's slider to show the specified scene.
     */
    private navigateZoomModalToScene(sceneName: string): void {
        const zoomModal = document.querySelector('.zoom-modal');
        if (!zoomModal || !window.PluginManager) return;

        const zoomGalleryElement = zoomModal.querySelector('[data-gallery-slider="true"]');
        if (!zoomGalleryElement) return;

        // Find the index of the slide with this scene
        const allSlides = zoomModal.querySelectorAll('[data-gallery-slider-container="true"] > *');
        let targetIndex = -1;

        allSlides.forEach((slide, index) => {
            if (slide.getAttribute('data-scene') === sceneName) {
                targetIndex = index;
            }
        });

        if (targetIndex === -1) {
            return;
        }

        try {
            const zoomGalleryPlugin = window.PluginManager.getPluginInstanceFromElement(
                zoomGalleryElement,
                'GallerySlider'
            ) as GallerySliderPlugin | null;

            if (zoomGalleryPlugin?._slider) {
                zoomGalleryPlugin._slider.goTo(targetIndex);
            }
        } catch {
            // Ignore navigation errors
        }
    }
}
