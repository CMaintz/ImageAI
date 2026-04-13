import type { CompositionOptions, Dimensions } from '../types';

export function getSelectedOptions(): CompositionOptions {
    const options: CompositionOptions = {};

    const variantForm = document.querySelector('[data-variant-switch="true"]');
    if (variantForm) {
        variantForm.querySelectorAll('input[type="radio"]:checked').forEach((radio) => {
            const optionInfo = extractRadioOptionInfo(radio as HTMLInputElement);
            if (optionInfo) {
                options[optionInfo.groupLabel] = optionInfo;
            }
        });

        variantForm.querySelectorAll('select').forEach((select) => {
            if ((select as HTMLSelectElement).value) {
                const optionInfo = extractSelectOptionInfo(select as HTMLSelectElement);
                if (optionInfo) {
                    options[optionInfo.groupLabel] = optionInfo;
                }
            }
        });
    }

    const componentForm = document.querySelector('.product-detail-configurator-form, .product-configurator');
    if (componentForm && componentForm !== variantForm) {
        componentForm.querySelectorAll('input[type="radio"]:checked').forEach((radio) => {
            const optionInfo = extractRadioOptionInfo(radio as HTMLInputElement);
            if (optionInfo && !options[optionInfo.groupLabel]) {
                options[optionInfo.groupLabel] = optionInfo;
            }
        });

        componentForm.querySelectorAll('select').forEach((select) => {
            if ((select as HTMLSelectElement).value) {
                const optionInfo = extractSelectOptionInfo(select as HTMLSelectElement);
                if (optionInfo && !options[optionInfo.groupLabel]) {
                    options[optionInfo.groupLabel] = optionInfo;
                }
            }
        });
    }

    // Get component offcanvas options (Frame, Size, etc.)
    const offcanvasOptions = getComponentOffcanvasOptions();
    Object.assign(options, offcanvasOptions);

    return options;
}

/**
 * Extract component options from offcanvas configuration inputs.
 * These are stored differently from standard Shopware variant options.
 */
function getComponentOffcanvasOptions(): CompositionOptions {
    const options: CompositionOptions = {};

    // Find all component type hidden inputs (Frame, Size, etc.)
    const componentInputs = document.querySelectorAll<HTMLInputElement>('.component-offcanvas-id-input');

    componentInputs.forEach((inputEl) => {
        // Skip if no selection (data-component-option-global-id is the PropertyGroupOption ID)
        const optionId = inputEl.dataset.componentOptionGlobalId;
        if (!optionId) return;

        // Find parent containers
        const configComponent = inputEl.closest('.configuration-component');
        const configOption = inputEl.closest('.configuration-option');
        if (!configOption) return;

        // Get component type ID
        const componentTypeId = configComponent?.getAttribute('data-component-type-id') ||
                                inputEl.dataset.componentTypeMappingId || '';

        // Get the display label from sibling title input
        const titleInput = configOption.querySelector<HTMLInputElement>('.component-offcanvas-title');
        const optionLabel = titleInput?.value?.trim() || '';

        // Get group label from the option title element
        const titleElement = configOption.querySelector('.configuration-option-title');
        let groupLabel = '';
        if (titleElement) {
            // Get text content, excluding nested elements like inputs
            const clone = titleElement.cloneNode(true) as HTMLElement;
            clone.querySelectorAll('*').forEach(el => el.remove());
            groupLabel = cleanGroupLabel(clone.textContent?.trim() || '');
        }

        // Check if this is a frame component via data attribute
        const isFrame = inputEl.dataset.framingComponent === 'true' ||
                        inputEl.hasAttribute('data-framing-component');

        // If we still don't have a proper label, check if optionLabel contains frame-related words
        if (!groupLabel || groupLabel === componentTypeId) {
            if (isFrame) {
                groupLabel = 'Frame';
            } else {
                // Check optionLabel for known category keywords (e.g., "Egetræ svæveramme S40-10")
                const lowerOption = optionLabel.toLowerCase();
                if (lowerOption.includes('ramme') || lowerOption.includes('frame') || lowerOption.includes('svæveramme')) {
                    groupLabel = 'Frame';
                } else if (!groupLabel) {
                    groupLabel = componentTypeId;
                }
            }
        }

        const optionData: CompositionOptions[string] = {
            groupId: componentTypeId,
            optionId: optionId,
            groupLabel: groupLabel,
            optionLabel: optionLabel,
            isFramingComponent: isFrame,
        };

        options[groupLabel] = optionData;
    });

    // Get global dimensions (Size) - these are separate inputs
    const widthInput = document.querySelector<HTMLInputElement>('.component-offcanvas-width');
    const heightInput = document.querySelector<HTMLInputElement>('.component-offcanvas-height');
    if (widthInput?.value && heightInput?.value) {
        const width = parseInt(widthInput.value, 10);
        const height = parseInt(heightInput.value, 10);

        if (!isNaN(width) && !isNaN(height)) {
            const sizeKey = Object.keys(options).find(k =>
                k.toLowerCase().includes('size') || k.toLowerCase().includes('størrelse')
            );
            const sizeOption = sizeKey ? options[sizeKey] as CompositionOptions[string]: undefined;

            if (sizeOption) {
                sizeOption.dimensions = { width, height, unit: 'cm' };
            } else {
                options['Size'] = {
                    groupId: 'size',
                    optionId: '',
                    groupLabel: 'Size',
                    optionLabel: `${width} × ${height} cm`,
                    dimensions: { width, height, unit: 'cm' }
                };
            }
        }
    }

    return options;
}

export function parseSizeDimensions(sizeLabel: string): Dimensions | null {
    if (!sizeLabel) {
        return null;
    }

    const patterns = [
        /(\d+)\s*[xX×]\s*(\d+)\s*(cm|mm|in)?/,
        /w:\s*(\d+)\s*h:\s*(\d+)/i,
        /(\d+)\s*cm\s*[xX×]\s*(\d+)\s*cm/
    ];

    for (const pattern of patterns) {
        const match = sizeLabel.match(pattern);
        if (match && match[1] && match[2]) {
            return {
                width: parseInt(match[1], 10),
                height: parseInt(match[2], 10),
                unit: match[3] || 'cm'
            };
        }
    }

    return null;
}

function extractRadioOptionInfo(radio: HTMLInputElement): CompositionOptions[string] | null {
    const groupId = radio.name;
    const optionId = radio.value;

    let groupLabel = groupId;
    const fieldset = radio.closest('fieldset');
    if (fieldset) {
        const legend = fieldset.querySelector('legend, .product-detail-configurator-option-label');
        if (legend) {
            groupLabel = legend.textContent?.trim() || groupId;
        }
    }

    groupLabel = cleanGroupLabel(groupLabel);

    let optionLabel = optionId;
    const label = radio.closest('label') || document.querySelector(`label[for="${radio.id}"]`);
    if (label) {
        optionLabel = label.textContent?.trim() || optionId;
    } else {
        const parent = radio.parentElement;
        if (parent) {
            const textSpan = parent.querySelector('.option-label, .product-detail-configurator-option-label');
            if (textSpan) {
                optionLabel = textSpan.textContent?.trim() || optionId;
            }
        }
    }

    return { groupId, optionId, groupLabel, optionLabel };
}

function extractSelectOptionInfo(select: HTMLSelectElement): CompositionOptions[string] | null {
    const groupId = select.name;
    const optionId = select.value;
    const selectedOption = select.options[select.selectedIndex];
    const optionLabel = selectedOption ? selectedOption.text.trim() : optionId;

    let groupLabel = groupId;
    const labelEl = document.querySelector(`label[for="${select.id}"]`);
    if (labelEl) {
        groupLabel = labelEl.textContent?.trim().replace(':', '') || groupId;
    } else {
        const parentLabel = select.closest('label');
        if (parentLabel) {
            const labelText = parentLabel.childNodes[0];
            if (labelText && labelText.nodeType === Node.TEXT_NODE) {
                groupLabel = labelText.textContent?.trim().replace(':', '') || groupId;
            }
        }
    }

    groupLabel = cleanGroupLabel(groupLabel);

    return { groupId, optionId, groupLabel, optionLabel };
}

function cleanGroupLabel(label: string): string {
    if (!label) {
        return label;
    }

    let cleaned = label
        .replace(/^vælg\s+/i, '')
        .replace(/^choose\s+/i, '')
        .replace(/^select\s+/i, '')
        .replace(/^pick\s+/i, '')
        .replace(/\s+types?$/i, '')
        .replace(/\s*:\s*$/, '')
        .trim();

    const lowerCleaned = cleaned.toLowerCase();
    if (lowerCleaned.includes('material')) {
        return 'Material';
    }
    if (lowerCleaned.includes('ramme') || lowerCleaned.includes('frame')) {
        return 'Frame';
    }
    if (lowerCleaned.includes('størrelse') || lowerCleaned.includes('size')) {
        return 'Size';
    }

    return cleaned;
}
