import type { AiCompositorOptions, ProductType } from '../types';
import type { CompositionApiService } from '../services/composition-api.service';
import type { RoomSelectorHelper } from './room-selector.helper';

/**
 * Helper class for managing UI state in the AI Compositor plugin.
 * Handles button states, loading indicators, error messages, and selection UI.
 */
export class UiStateHelper {
    constructor(
        private el: HTMLElement,
        private options: AiCompositorOptions,
        private api: CompositionApiService,
        private roomSelector: RoomSelectorHelper
    ) {}

    /**
     * Update the compose button state based on current selections.
     */
    updateButtonState(
        productType: ProductType,
        hasUserUploadedImage: boolean,
        customEnvironmentImage: string | null
    ): void {
        const composeBtn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;

        const hasRoomSelection = this.roomSelector.getCount() > 0;
        const hasCustomEnvironment = customEnvironmentImage !== null && customEnvironmentImage.length > 0;
        const hasEnvironmentSelection = hasRoomSelection || hasCustomEnvironment;

        const isUserUploadType = this.api.isUserUploadType(productType);
        const needsUserImage = isUserUploadType && !hasUserUploadedImage;

        const canGenerate = hasEnvironmentSelection && !needsUserImage;

        if (composeBtn) {
            composeBtn.disabled = !canGenerate;
        }

        // Room selectors should be disabled when custom environment is selected
        if (this.api.isUserUploadType(productType)) {
            const shouldEnableRoomSelectors = hasUserUploadedImage && !hasCustomEnvironment;
            this.setRoomSelectorsEnabled(shouldEnableRoomSelectors);
            this.setEnvironmentUploadEnabled(hasUserUploadedImage);
        } else {
            this.setRoomSelectorsEnabled(!hasCustomEnvironment);
        }
    }

    /**
     * Update button text to "Refresh Previews" after composition.
     */
    updateButtonToRefresh(): void {
        const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (btn) {
            const textSpan = btn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                textSpan.textContent = 'Refresh Previews';
            }
        }
    }

    /**
     * Update selection counter UI.
     */
    updateSelectionUI(hasComposed: boolean): void {
        const countEl = this.el.querySelector(this.options.selectionCountSelector) as HTMLElement;
        this.roomSelector.updateCounterUI(countEl);

        const composeBtn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (composeBtn && hasComposed) {
            const textSpan = composeBtn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                textSpan.textContent = 'Refresh Previews';
            }
        }
    }

    /**
     * Enable or disable room selector grid.
     */
    setRoomSelectorsEnabled(enabled: boolean): void {
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

    /**
     * Enable or disable environment upload input.
     */
    setEnvironmentUploadEnabled(enabled: boolean): void {
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

    /**
     * Show loading state on compose button.
     */
    showLoading(originalBtnTextRef: { value: string }): void {
        const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (btn) {
            btn.classList.add(this.options.loadingClass);
            btn.disabled = true;

            const textSpan = btn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                originalBtnTextRef.value = textSpan.textContent || '';
                textSpan.textContent = 'Generating...';
            }

            if (!btn.querySelector('.ai-compose-btn__spinner')) {
                const spinner = document.createElement('span');
                spinner.className = 'ai-compose-btn__spinner';
                spinner.style.cssText = 'display:inline-block;width:16px;height:16px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spinner-border 0.75s linear infinite;margin-left:8px;vertical-align:middle;';
                btn.appendChild(spinner);
            }
        }
    }

    /**
     * Hide loading state on compose button.
     */
    hideLoading(hasComposed: boolean, originalBtnText: string): void {
        const btn = this.el.querySelector(this.options.composeBtnSelector) as HTMLButtonElement;
        if (btn) {
            btn.classList.remove(this.options.loadingClass);
            btn.disabled = false;

            const textSpan = btn.querySelector('.ai-compose-btn__text');
            if (textSpan) {
                textSpan.textContent = hasComposed ? 'Refresh Preview' : (originalBtnText || 'See in Room');
            }

            const spinner = btn.querySelector('.ai-compose-btn__spinner');
            if (spinner) {
                spinner.remove();
            }
        }
    }

    /**
     * Show error message in the room selector area.
     */
    showError(message: string): void {
        console.error('[UiState] Error:', message);

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
