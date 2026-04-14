export type ProductType =
    | 'Wexo Artwork'
    | 'Illux Your Wallpaper'
    | 'Illux Wallpaper Customizable'
    | 'Illux Photo'
    | 'Illux Pop Art'
    | 'Illux Collage Product'
    | 'Illux Gift Card'
    | 'Illux Poster Frame';

export interface RoomFolder {
    folderId: string;
    name: string;
}

export interface CompositionOptions {
    [groupLabel: string]: {
        groupId: string;
        optionId: string;
        groupLabel: string;
        optionLabel: string;
        dimensions?: Dimensions;
        isFramingComponent?: boolean;
    };
}

export interface Dimensions {
    width: number;
    height: number;
    unit: string;
}

export interface CompositionStartResponse {
    success: boolean;
    jobId?: string;
    total?: number;
    environments?: string[];
    error?: string;
}

export interface CompositionPollResponse {
    success: boolean;
    status: 'processing' | 'completed';
    total: number;
    completed: number;
    newResults: CompositionResult[];
    error?: string;
}

export interface CompositionResult {
    sceneName: string;
    label: string;
    image: string | null;
    error?: string;
}

export interface PlaceholderSet {
    main: HTMLElement;
    thumbnail: HTMLElement;
    index: number;
}

export interface AiCompositorOptions {
    composeBtnSelector: string;
    roomToggleSelector: string;
    roomGridSelector: string;
    selectionCountSelector: string;
    environmentUploadSelector: string;
    loadingClass: string;
    selectedClass: string;
}

export interface GallerySliderPlugin {
    destroy(): void;
    rebuild(): void;
    _initSlider(): void;
    _slider?: TinySliderInstance;
    getCurrentSliderIndex(): number;
}

export interface TinySliderInstance {
    getInfo(): TinySliderInfo;
    goTo(index: number): void;
    events: TinySliderEvents;
}

export interface TinySliderEvents {
    on(event: string, callback: () => void): void;
}

export interface TinySliderInfo {
    slideCount: number;
    cloneCount: number;
    index: number;
    slideItems: HTMLCollection;
}

/**
 * Identifiers for resolving user-uploaded images on the backend.
 * Supports both GraphicalAssistance (storageToken + filename) and ChiliPublish (assetId).
 */
export interface UserImageIdentifiers {
    /** GraphicalAssistance session storage key */
    storageToken?: string;
    /** GraphicalAssistance uploaded filename */
    filename?: string;
    /** ChiliPublish EditorAsset ID */
    assetId?: string;
}
