import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';
import { CompositionApiService } from './services/composition-api.service';
import { GalleryManagerService } from './services/gallery-manager.service';
import { RoomSelectorHelper } from './helpers/room-selector.helper';
import { getSelectedOptions, parseSizeDimensions } from './helpers/option-parser.helper';
import type { ProductType, AiCompositorOptions, CompositionResult, UserImageIdentifiers } from './types';

declare const window: Window & {
    PluginManager?: {
        getPluginInstanceFromElement(el: Element, name: string): unknown;
    };
};

export default class AiCompositorPlugin extends Plugin {
    static options: AiCompositorOptions = {
        composeBtnSelector: '[data-ai-compose-trigger]',
        roomToggleSelector: '[data-room-toggle]',
        roomGridSelector: '[data-room-grid]',
        selectionCountSelector: '[data-selection-count]',
        environmentUploadSelector: '[data-environment-upload]',
        loadingClass: 'is-loading',
        selectedClass: 'is-selected',
    };

    declare options: AiCompositorOptions;
    declare el: HTMLElement;

    private api!: CompositionApiService;
    private gallery!: GalleryManagerService;
    private roomSelector!: RoomSelectorHelper;
    private productType!: ProductType;
    private hasComposed: boolean = false;
    private isComposing: boolean = false;
    private jobId: string | null = null;
    private originalBtnText: string = '';
    private customEnvironmentImage: string | null = null;
    private customEnvironmentMimeType: string | null = null;
    private hasUserUploadedImage: boolean = false;
    private sseConnection: { abort: () => void; eventSource: EventSource | null } | null = null;
    private completedCount: number = 0;
    private totalCount: number = 0;

    init(): void {
        console.log('[AiCompositor] Plugin initializing on element:', this.el);
        const client = new HttpClient();
        this.api = new CompositionApiService(client);
        this.gallery = new GalleryManagerService();
        this.roomSelector = new RoomSelectorHelper(
            parseInt(this.el.dataset.maxRooms || '5', 10),
            this.options.selectedClass
        );
        this.productType = (this.el.dataset.productType as ProductType) || 'Illux Artwork';
        console.log('[AiCompositor] Product type:', this.productType);
        this._registerEvents();
    }

    private _registerEvents(): void {
        console.log('[AiCompositor] Registering events...');
        const composeBtn = this.el.querySelector(this.options.composeBtnSelector);
        if (composeBtn) {
            composeBtn.addEventListener('click', this._onComposeClick.bind(this));
        }

        const roomToggles = this.el.querySelectorAll(this.options.roomToggleSelector);
        roomToggles.forEach(toggle => {
            toggle.addEventListener('click', this._onRoomToggleClick.bind(this));
        });

        this._registerOptionChangeListener();

        const environmentUpload = this.el.querySelector(this.options.environmentUploadSelector);
        console.log('[AiCompositor] Environment upload element:', environmentUpload, 'Selector:', this.options.environmentUploadSelector);
        if (environmentUpload) {
            environmentUpload.addEventListener('change', this._onEnvironmentUpload.bind(this));
            console.log('[AiCompositor] Environment upload listener registered');
        } else {
            console.warn('[AiCompositor] Environment upload element NOT FOUND!');
        }

        // Listen for GraphicalAssistance events (user uploading their product image)
        if (this.api.isUserUploadType(this.productType)) {
            document.addEventListener('illux.graphical-assistance-added', this._onUserImageAdded.bind(this));
            document.addEventListener('illux.graphical-assistance-reset', this._onUserImageReset.bind(this));

            // Listen for ChiliPublish design saved event to restore gallery after editing
            document.addEventListener('simple-design-saved', ((event: Event) => {
                this._onDesignSaved(event as CustomEvent<{ previewId?: string }>);
            }) as EventListener);

            // Check if user already has an uploaded image (from previous page visit or session)
            // This syncs JS state with server-side session state
            this.hasUserUploadedImage = this._getUserImageIdentifiers(true) !== null;

            this._updateButtonState();
        }
    }

    /**
     * Called when ChiliPublish/SimpleDesignEditor saves a design after cropping.
     * Restores the gallery visibility and adds the user's uploaded image to the gallery.
     */
    private _onDesignSaved(event: CustomEvent<{ previewId?: string }>): void {
        // Mark that user has uploaded an image
        this.hasUserUploadedImage = true;
        this._updateButtonState();

        // Restore gallery visibility by removing tns-hidden class
        const galleryWrapper = document.querySelector('.cms-element-image-gallery');
        if (galleryWrapper?.classList.contains('tns-hidden')) {
            galleryWrapper.classList.remove('tns-hidden');

            // Add the user's uploaded image to the gallery if we have a preview ID
            const previewId = event.detail?.previewId;
            if (previewId) {
                this._addUserImageToGallery(previewId);
            }

            // Rebuild the gallery slider to restore normal functionality
            this.gallery.rebuildSlider();
        }
    }

    /**
     * Add the user's uploaded/cropped image to the gallery as the first slide.
     */
    private async _addUserImageToGallery(previewId: string): Promise<void> {
        // Build the preview URL from the previewId
        const previewUrl = `/illux/chili/design-preview/${previewId}/full/1`;

        const mainContainer = this.gallery.getMainContainer();
        const thumbContainer = this.gallery.getThumbContainer();

        if (!mainContainer || !thumbContainer) {
            console.warn('[AiCompositor] Gallery containers not found');
            return;
        }

        // Remove any existing user-uploaded slides (to prevent duplicates)
        mainContainer.querySelectorAll('.user-uploaded-main').forEach(el => el.remove());
        thumbContainer.querySelectorAll('.user-uploaded-thumbnail').forEach(el => el.remove());

        // Get display mode from existing slides
        const displayMode = this.gallery.getDisplayMode();
        const minHeight = this.gallery.getMinHeight();
        const displayModeClass = displayMode === 'cover' ? 'is-cover' : displayMode === 'contain' ? 'is-contain' : '';

        // Create main slide
        const mainSlide = document.createElement('div');
        mainSlide.className = 'gallery-slider-item-container user-uploaded-main';
        mainSlide.innerHTML = `
            <div class="gallery-slider-item ${displayModeClass}" style="min-height: ${minHeight};">
                <img class="gallery-slider-image"
                     src="${previewUrl}"
                     alt="Your uploaded design"
                     loading="eager"
                     data-cover-image="true">
            </div>
        `;

        // Create thumbnail
        const thumbSlide = document.createElement('div');
        thumbSlide.className = 'gallery-slider-thumbnails-item user-uploaded-thumbnail';
        thumbSlide.innerHTML = `
            <div class="gallery-slider-thumbnails-item-inner">
                <img class="gallery-slider-thumbnails-image"
                     src="${previewUrl}"
                     alt="Your uploaded design"
                     loading="eager">
            </div>
        `;

        // Insert at the beginning of the containers (before AI compositor button in thumb)
        if (mainContainer.firstChild) {
            mainContainer.insertBefore(mainSlide, mainContainer.firstChild);
        } else {
            mainContainer.appendChild(mainSlide);
        }

        // Insert thumbnail at the beginning, before the AI compositor button
        const aiButton = thumbContainer.querySelector('.ai-compositor-thumbnail-btn');
        if (aiButton) {
            thumbContainer.insertBefore(thumbSlide, aiButton);
        } else if (thumbContainer.firstChild) {
            thumbContainer.insertBefore(thumbSlide, thumbContainer.firstChild);
        } else {
            thumbContainer.appendChild(thumbSlide);
        }
    }

    private _onUserImageAdded(): void {
        this.hasUserUploadedImage = true;
        this._updateButtonState();
    }

    private _onUserImageReset(): void {
        this.hasUserUploadedImage = false;
        this._updateButtonState();
    }

    private _onEnvironmentUpload(event: Event): void {
        console.log('[AiCompositor] Environment upload triggered');
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];

        if (!file) {
            console.log('[AiCompositor] No file selected, clearing state');
            this.customEnvironmentImage = null;
            this.customEnvironmentMimeType = null;
            this._updateUploadUI(null);
            this._setRoomSelectorsEnabled(true);
            this._updateButtonState();
            return;
        }

        console.log('[AiCompositor] File selected:', file.name, 'Size:', file.size, 'Type:', file.type);

        const reader = new FileReader();
        reader.onload = (e) => {
            console.log('[AiCompositor] FileReader onload triggered');
            const result = e.target?.result as string;

            // Remove data URL prefix to get base64
            const base64Data = result?.split(',')[1];
            if (!base64Data || base64Data.length === 0) {
                console.error('[AiCompositor] Failed to extract base64 data from result');
                this._showError('Failed to process uploaded file');
                return;
            }

            console.log('[AiCompositor] Base64 data extracted, length:', base64Data.length);
            this.customEnvironmentImage = base64Data;
            this.customEnvironmentMimeType = file.type;

            this._updateUploadUI(file.name);
            // Clear room selection since custom environment is now selected
            this._clearRoomSelection();
            // _updateButtonState handles disabling room selectors when custom env is set
            this._updateButtonState();
            console.log('[AiCompositor] Upload UI updated, button state updated');
        };
        reader.onerror = (err) => {
            console.error('[AiCompositor] FileReader error:', err);
            this._showError('Failed to read uploaded file');
        };
        reader.readAsDataURL(file);
    }

    private _clearRoomSelection(): void {
        const roomToggles = this.el.querySelectorAll(this.options.roomToggleSelector);
        roomToggles.forEach(toggle => {
            toggle.classList.remove(this.options.selectedClass);
        });
        this.roomSelector.clear();
        this._updateSelectionUI();
    }

    private _setRoomSelectorsEnabled(enabled: boolean): void {
        const roomGrid = this.el.querySelector(this.options.roomGridSelector) as HTMLElement;
        if (roomGrid) {
            roomGrid.style.opacity = enabled ? '1' : '0.5';
            roomGrid.style.pointerEvents = enabled ? 'auto' : 'none';
        }
        const selectionInfo = this.el.querySelector('.ai-compositor-selection-info') as HTMLElement;
        if (selectionInfo) {
            selectionInfo.style.opacity = enabled ? '1' : '0.5';
        }
    }

    private _updateUploadUI(fileName: string | null): void {
        const uploadBtn = this.el.querySelector('.ai-compositor-upload-btn');
        const uploadText = this.el.querySelector('.ai-compositor-upload-text');

        if (uploadBtn && uploadText) {
            // Remove existing clear button if any
            const existingClearBtn = uploadBtn.querySelector('.ai-compositor-upload-clear');
            if (existingClearBtn) {
                existingClearBtn.remove();
            }

            if (fileName) {
                uploadBtn.classList.add('has-file');
                // Truncate long filenames
                const displayName = fileName.length > 25 ? fileName.substring(0, 22) + '...' : fileName;
                uploadText.textContent = displayName;

                // Add clear button
                const clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'ai-compositor-upload-clear';
                clearBtn.innerHTML = '&times;';
                clearBtn.title = 'Remove uploaded image';
                clearBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this._clearCustomEnvironment();
                });
                uploadBtn.appendChild(clearBtn);
            } else {
                uploadBtn.classList.remove('has-file');
                uploadText.textContent = 'Upload your own room';
            }
        }
    }

    private _clearCustomEnvironment(): void {
        // Clear the custom environment data
        this.customEnvironmentImage = null;
        this.customEnvironmentMimeType = null;

        // Reset file input
        const environmentUpload = this.el.querySelector(this.options.environmentUploadSelector) as HTMLInputElement;
        if (environmentUpload) {
            environmentUpload.value = '';
        }

        // Update UI
        this._updateUploadUI(null);

        // _updateButtonState handles re-enabling room selectors when custom env is cleared
        this._updateButtonState();
    }

    private _updateButtonState(): void {
        const composeBtn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;

        // Enable button if rooms are selected OR custom environment is uploaded
        const hasRoomSelection = this.roomSelector.getCount() > 0;
        const hasCustomEnvironment = !!this.customEnvironmentImage && this.customEnvironmentImage.length > 0;
        const hasEnvironmentSelection = hasRoomSelection || hasCustomEnvironment;

        // For user-upload types (photo, wallpaper), also require user to have uploaded their product image
        const isUserUploadType = this.api.isUserUploadType(this.productType);
        const needsUserImage = isUserUploadType && !this.hasUserUploadedImage;

        const canGenerate = hasEnvironmentSelection && !needsUserImage;

        if (composeBtn) {
            composeBtn.disabled = !canGenerate;
        }

        // Room selectors and environment upload are mutually exclusive:
        // - Room selectors disabled when custom environment is uploaded
        // - Environment upload disabled when rooms are selected
        // For user-upload types, also require user to have uploaded their product image first
        if (isUserUploadType) {
            // User upload types: need product image first, then mutual exclusion
            const shouldEnableRoomSelectors = this.hasUserUploadedImage && !hasCustomEnvironment;
            const shouldEnableEnvironmentUpload = this.hasUserUploadedImage && !hasRoomSelection;
            this._setRoomSelectorsEnabled(shouldEnableRoomSelectors);
            this._setEnvironmentUploadEnabled(shouldEnableEnvironmentUpload);
        } else {
            // Non-user-upload types (Artwork): mutual exclusion only
            this._setRoomSelectorsEnabled(!hasCustomEnvironment);
            this._setEnvironmentUploadEnabled(!hasRoomSelection);
        }
    }

    private _setEnvironmentUploadEnabled(enabled: boolean): void {
        const uploadLabel = this.el.querySelector('.ai-compositor-upload-label') as HTMLLabelElement;
        const uploadInput = this.el.querySelector(this.options.environmentUploadSelector) as HTMLInputElement;

        if (uploadLabel) {
            uploadLabel.style.opacity = enabled ? '1' : '0.5';
            uploadLabel.style.pointerEvents = enabled ? 'auto' : 'none';
        }
        if (uploadInput) {
            uploadInput.disabled = !enabled;
        }
    }

    private _onRoomToggleClick(event: Event): void {
        const toggle = event.currentTarget as HTMLElement;
        this.roomSelector.toggle(toggle);
        this._updateSelectionUI();
    }

    private _updateSelectionUI(): void {
        const countEl = this.el.querySelector(this.options.selectionCountSelector) as HTMLElement;
        this.roomSelector.updateCounterUI(countEl);

        this._updateButtonState();

        const composeBtn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (composeBtn && this.hasComposed) {
            const textSpan = composeBtn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                textSpan.textContent = 'Refresh Previews';
            }
        }
    }

    private _registerOptionChangeListener(): void {
        const variantForm = document.querySelector('[data-variant-switch="true"]');
        if (variantForm) {
            if (window.PluginManager) {
                try {
                    const variantPlugin = window.PluginManager.getPluginInstanceFromElement(
                        variantForm,
                        'VariantSwitch'
                    ) as { $emitter?: { subscribe(event: string, cb: () => void): void } };

                    if (variantPlugin?.$emitter) {
                        variantPlugin.$emitter.subscribe('onChange', () => this._onOptionsChanged());
                    }
                } catch { /* ignore */ }
            }

            variantForm.addEventListener('change', Debouncer.debounce(() => this._onOptionsChanged(), 100) as EventListener);
        }

        const componentForm = document.querySelector('.product-detail-configurator-form, .product-configurator');
        if (componentForm && componentForm !== variantForm) {
            componentForm.addEventListener('change', Debouncer.debounce(() => this._onOptionsChanged(), 100) as EventListener);
        }
    }

    private _onOptionsChanged(): void {
        if (this.hasComposed) {
            const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
            if (btn) {
                btn.disabled = false;
                btn.classList.remove(this.options.loadingClass);

                const textSpan = btn.querySelector('.ai-compose-btn__text');
                if (textSpan) {
                    textSpan.textContent = 'Refresh Preview';
                }
            }
        }
    }

    private async _onComposeClick(event: Event): Promise<void> {
        event.preventDefault();

        const selectedRooms = this.roomSelector.getSelected();
        const hasCustomEnvironment = this.customEnvironmentImage !== null && this.customEnvironmentImage.length > 0;

        if (selectedRooms.length === 0 && !hasCustomEnvironment) {
            this._showError('Please select at least one room type or upload your own');
            return;
        }

        if (this.isComposing) {
            return;
        }

        this.isComposing = true;
        this._showLoading();

        const selectedOptions = getSelectedOptions();

        let sizeDimensions = null;
        for (const [groupLabel, option] of Object.entries(selectedOptions)) {
            if (groupLabel.toLowerCase().includes('size') || groupLabel.toLowerCase().includes('størrelse')) {
                sizeDimensions = parseSizeDimensions(option.optionLabel);
                if (sizeDimensions) {
                    option.dimensions = sizeDimensions;
                }
                break;
            }
        }

        const productId = this.el.dataset.productId;

        // For user upload types, get the upload identifiers to send to backend
        // Backend will fetch the actual image from storage (no round-trip of large data)
        let userImageInfo: UserImageIdentifiers | undefined;

        if (this.api.isUserUploadType(this.productType)) {
            const identifiers = this._getUserImageIdentifiers();
            userImageInfo = identifiers ?? undefined;
            if (!userImageInfo) {
                this._showError('Please upload an image first');
                this.isComposing = false;
                this._hideLoading();
                return;
            }
        }

        // Determine environments locally and create placeholders before API call
        // This gives immediate visual feedback to the user
        const environments = hasCustomEnvironment
            ? ['Custom Environment']
            : selectedRooms.map(r => r.name);

        this._createPlaceholders(environments);

        // Use SSE streaming if browser supports it for faster first result
        const useStreaming = this.api.supportsSSE();

        try {
            const response = await this.api.compose(
                this.productType,
                selectedOptions,
                selectedRooms,
                sizeDimensions,
                productId,
                userImageInfo,
                this.customEnvironmentImage ?? undefined,
                this.customEnvironmentMimeType ?? undefined,
                useStreaming
            );

            if (response.success && response.jobId) {
                this.totalCount = response.total;
                this.completedCount = 0;

                if (useStreaming) {
                    this._startStreaming(response.jobId);
                } else {
                    this._startPolling(response.jobId);
                }
            } else {
                this.isComposing = false;
                this._hideLoading();
                this._resetAfterError();
                this._showError(response.error || 'Failed to start composition');
            }
        } catch (e) {
            console.error('[AiCompositor] Request error:', e);
            this.isComposing = false;
            this._hideLoading();
            this._resetAfterError();
            this._showError(e instanceof Error ? e.message : 'Request failed');
        }
    }

    private _clearCompositions(): void {
        this.gallery.clearCompositions();
    }

    private _createPlaceholders(environments: string[]): void {
        const galleryPlugin = this.gallery.getGalleryPlugin();
        if (!galleryPlugin) {
            this._showError('Gallery not found');
            return;
        }

        const mainContainer = this.gallery.getMainContainer();
        if (mainContainer) {
            (mainContainer as HTMLElement).style.visibility = 'hidden';
        }

        try {
            galleryPlugin.destroy();
        } catch {
        }

        this._clearCompositions();

        requestAnimationFrame(() => {
            this.gallery.createPlaceholders(environments);

            const roomSelectorIndex = mainContainer
                ? Array.from(mainContainer.children).findIndex(slide =>
                    slide.classList.contains('ai-compositor-main-slide')
                )
                : -1;

            console.log('[AiCompositor] Room selector index:', roomSelectorIndex, 'Total slides:', mainContainer?.children.length);

            if (roomSelectorIndex >= 0 && galleryPlugin._sliderSettings) {
                galleryPlugin._sliderSettings.startIndex = roomSelectorIndex;
                if (galleryPlugin._thumbnailSliderSettings) {
                    galleryPlugin._thumbnailSliderSettings.startIndex = roomSelectorIndex;
                }
            }

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    try {
                        galleryPlugin._initSlider();
                        console.log('[AiCompositor] Slider initialized with startIndex:', roomSelectorIndex);

                        if (mainContainer) {
                            (mainContainer as HTMLElement).style.visibility = '';
                        }

                        setTimeout(() => {
                            this.gallery.reinitializePlugins();
                        }, 50);
                    } catch {
                    }
                });
            });
        });
    }

    private _startPolling(jobId: string): void {
        this.jobId = jobId;
        this._pollForResults();
    }

    /**
     * Start SSE streaming for real-time composition results.
     * Falls back to polling if SSE connection fails.
     */
    private _startStreaming(jobId: string): void {
        this.jobId = jobId;

        // Close any existing connection
        if (this.sseConnection) {
            this.sseConnection.abort();
            this.sseConnection = null;
        }

        this.sseConnection = this.api.streamResults(jobId, {
            onResult: (result: CompositionResult) => {
                this.completedCount++;
                this.gallery.injectComposition(result);
                this._updateProgressUI();
            },
            onProgress: (completed: number, total: number) => {
                this.completedCount = completed;
                this.totalCount = total;
                this._updateProgressUI();
            },
            onComplete: (total: number, completed: number) => {
                this.totalCount = total;
                this.completedCount = completed;
                this._hideLoading();
                this._onComplete();
                this._cleanupSSE();
            },
            onError: (message: string) => {
                console.warn('[AiCompositor] SSE error, falling back to polling:', message);
                this._cleanupSSE();
                // Fall back to polling on SSE error
                this._startPolling(jobId);
            }
        });
    }

    private _cleanupSSE(): void {
        if (this.sseConnection) {
            this.sseConnection.abort();
            this.sseConnection = null;
        }
    }

    /**
     * Update the loading button text to show progress.
     */
    private _updateProgressUI(): void {
        const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (btn) {
            const textSpan = btn.querySelector('.ai-compose-btn__text');
            if (textSpan && this.totalCount > 0) {
                textSpan.textContent = `Generating... ${this.completedCount}/${this.totalCount}`;
            }
        }
    }

    private async _pollForResults(): Promise<void> {
        if (!this.jobId) return;

        try {
            const data = await this.api.poll(this.jobId);

            if (!data.success) {
                this.isComposing = false;
                this._hideLoading();
                this._showError(data.error || 'Polling failed');
                return;
            }

            if (data.newResults?.length > 0) {
                data.newResults.forEach((result: CompositionResult) => {
                    this.completedCount++;
                    this.gallery.injectComposition(result);
                });
                this._updateProgressUI();
            }

            if (data.status === 'processing') {
                setTimeout(() => this._pollForResults(), 500);
            } else {
                this._hideLoading();
                this._onComplete();
            }
        } catch (e) {
            console.error('[AiCompositor] Polling error:', e);
            this.isComposing = false;
            this._hideLoading();
            this._showError('Polling error');
        }
    }

    private _onComplete(): void {
        this.isComposing = false;
        this.hasComposed = true;

        // Reset custom environment state after composition
        // This allows users to do another composition with room selection
        this.customEnvironmentImage = null;
        this.customEnvironmentMimeType = null;
        this._updateUploadUI(null);

        // Reset file input so the same file can be re-uploaded
        const environmentUpload = this.el.querySelector(this.options.environmentUploadSelector) as HTMLInputElement;
        if (environmentUpload) {
            environmentUpload.value = '';
        }

        // _updateButtonToRefresh calls _updateButtonState which re-enables room selectors
        this._updateButtonToRefresh();

        // Reinitialize plugins and ensure we stay on the room selector slide
        // User can navigate to AI images themselves via thumbnails
        requestAnimationFrame(() => {
            this.gallery.reinitializePlugins();
            // Navigate back to room selector in case slider position drifted
            this._navigateToRoomSelector();
        });
    }

    private _navigateToRoomSelector(): void {
        const galleryPlugin = this.gallery.getGalleryPlugin();
        if (!galleryPlugin?._slider) {
            return;
        }

        // Find the room selector slide (it contains .ai-compositor-main-slide)
        const info = galleryPlugin._slider.getInfo();
        const slideItems = Array.from(info.slideItems) as HTMLElement[];

        // Filter out clones (TinySlider adds clones for infinite loop)
        // Clones have tns-slide-cloned class
        const realSlideItems = slideItems.filter(item => !item.classList.contains('tns-slide-cloned'));

        const roomSelectorIndex = realSlideItems.findIndex(item =>
            item.classList.contains('ai-compositor-main-slide')
        );

        if (roomSelectorIndex >= 0) {
            galleryPlugin._slider.goTo(roomSelectorIndex);
            galleryPlugin._thumbnailSlider?.goTo(roomSelectorIndex);
        }
    }

    private _showLoading(): void {
        const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (btn) {
            btn.classList.add(this.options.loadingClass);
            btn.disabled = true;

            const textSpan = btn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                this.originalBtnText = textSpan.textContent || '';
                textSpan.textContent = 'Generating...';
            }

            // Add spinner if not already present
            if (!btn.querySelector('.ai-compose-btn__spinner')) {
                const spinner = document.createElement('span');
                spinner.className = 'ai-compose-btn__spinner';
                spinner.style.cssText = 'display:inline-block;width:16px;height:16px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spinner-border 0.75s linear infinite;margin-left:8px;vertical-align:middle;';
                btn.appendChild(spinner);
            }
        }
    }

    private _hideLoading(): void {
        const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (btn) {
            btn.classList.remove(this.options.loadingClass);
            btn.disabled = false;

            const textSpan = btn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                textSpan.textContent = this.hasComposed ? 'Refresh Preview' : (this.originalBtnText || 'See in Room');
            }

            // Remove spinner
            const spinner = btn.querySelector('.ai-compose-btn__spinner');
            if (spinner) {
                spinner.remove();
            }
        }
    }

    private _updateButtonToRefresh(): void {
        this.hasComposed = true;

        const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (btn) {
            const textSpan = btn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                textSpan.textContent = 'Refresh Previews';
            }
            // Use _updateButtonState which correctly checks both rooms AND custom environment
            this._updateButtonState();
        }
    }

    private _resetAfterError(): void {
        // Clear any placeholders that were created
        this.gallery.clearCompositions();

        // Rebuild the slider to remove placeholder slots
        const galleryPlugin = this.gallery.getGalleryPlugin();
        if (galleryPlugin) {
            try {
                galleryPlugin.destroy();
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        try {
                            galleryPlugin._initSlider();
                        } catch { /* ignore */ }
                    });
                });
            } catch { /* ignore */ }
        }

        // Reset custom environment state
        this.customEnvironmentImage = null;
        this.customEnvironmentMimeType = null;
        this._updateUploadUI(null);

        // Reset file input
        const environmentUpload = this.el.querySelector(this.options.environmentUploadSelector) as HTMLInputElement;
        if (environmentUpload) {
            environmentUpload.value = '';
        }

        // _updateButtonState handles re-enabling room selectors
        this._updateButtonState();
    }

    /**
     * Get identifiers for the user's uploaded image.
     * Backend will resolve the actual image from storage using these identifiers.
     * Supports GraphicalAssistance (storageToken + filename) and ChiliPublish (assetId).
     *
     * @param silent If true, don't log errors when no identifiers found (used for state checks)
     */
    private _getUserImageIdentifiers(silent: boolean = false): UserImageIdentifiers | null {
        const productId = this.el.dataset.productId;
        if (!productId) {
            return null;
        }

        // Try GraphicalAssistance first (primary upload system)
        const storageInput = document.querySelector<HTMLInputElement>(
            `input[name="lineItems[${productId}][assistanceStorage]"]`
        );
        const fileInputs = document.querySelectorAll<HTMLInputElement>(
            `input[name="lineItems[${productId}][uploadedFiles][]"]`
        );
        const firstFileInput = fileInputs[0];

        if (storageInput?.value && firstFileInput?.value) {
            const identifiers: UserImageIdentifiers = {
                storageToken: storageInput.value,
                filename: firstFileInput.value,
            };
            return identifiers;
        }

        // Try ChiliPublish (EditorAsset ID)
        const chiliAssetInput = document.querySelector<HTMLInputElement>(
            `input[name="lineItems[${productId}][editorAssetId]"]`
        );

        if (chiliAssetInput?.value) {
            const identifiers: UserImageIdentifiers = {
                assetId: chiliAssetInput.value,
            };
            return identifiers;
        }

        return null;
    }

    private _showError(message: string): void {
        const container = this.el.querySelector('.ai-compositor-room-selector');
        if (container) {
            const existingError = container.querySelector('.ai-compose-error');
            if (existingError) existingError.remove();

            const errorDiv = document.createElement('div');
            errorDiv.className = 'ai-compose-error alert alert-danger mt-2';
            errorDiv.style.cssText = 'font-size: 0.85rem; padding: 0.5rem 1rem;';
            errorDiv.textContent = message;
            container.appendChild(errorDiv);

            setTimeout(() => errorDiv.remove(), 5000);
        }
    }
}
