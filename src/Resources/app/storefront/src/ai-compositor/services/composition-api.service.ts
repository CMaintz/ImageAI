import type {
    ProductType,
    CompositionOptions,
    Dimensions,
    RoomFolder,
    CompositionStartResponse,
    CompositionPollResponse,
    CompositionResult,
    UserImageIdentifiers
} from '../types';

const ENDPOINT_MAP: Record<ProductType, string> = {
    'Wexo Artwork': '/ai/compose/artwork',
    'Illux Wallpaper Customizable': '/ai/compose/artwork', // Uses product images like artwork
    'Illux Your Wallpaper': '/ai/compose/wallpaper',
    'Illux Photo': '/ai/compose/photo',
    'Illux Pop Art': '/ai/compose/photo',
    'Illux Collage Product': '/ai/compose/photo',
    'Illux Gift Card': '/ai/compose/artwork', // Hidden, but fallback
    'Illux Poster Frame': '/ai/compose/artwork', // Hidden, but fallback
};

const POLL_ENDPOINT = '/ai/compose/poll';
const STREAM_ENDPOINT = '/ai/compose/stream';

export interface SSECallbacks {
    onResult: (result: CompositionResult) => void;
    onProgress: (completed: number, total: number) => void;
    onComplete: (total: number, completed: number) => void;
    onError: (message: string) => void;
}

const USER_UPLOAD_TYPES: ProductType[] = [
    'Illux Photo',
    'Illux Your Wallpaper',
    'Illux Collage Product',
];

export class CompositionApiService {
    private readonly client: { post: Function; get: Function };

    constructor(httpClient: { post: Function; get: Function }) {
        this.client = httpClient;
    }

    getEndpointForType(productType: ProductType): string {
        return ENDPOINT_MAP[productType] ?? '/ai/compose/artwork';
    }

    isUserUploadType(productType: ProductType): boolean {
        return USER_UPLOAD_TYPES.includes(productType);
    }

    /**
     * Check if browser supports SSE (EventSource)
     */
    supportsSSE(): boolean {
        return typeof EventSource !== 'undefined';
    }

    compose(
        productType: ProductType,
        options: CompositionOptions,
        roomFolders: RoomFolder[],
        dimensions: Dimensions | null,
        productId?: string,
        userImageIdentifiers?: UserImageIdentifiers,
        customEnvironmentImage?: string,
        customEnvironmentMimeType?: string,
        streaming: boolean = false
    ): Promise<CompositionStartResponse> {
        return new Promise((resolve, reject) => {
            const endpoint = this.getEndpointForType(productType);
            const formData = new FormData();

            formData.append('options', JSON.stringify(options));
            formData.append('roomFolders', JSON.stringify(roomFolders));

            if (dimensions) {
                formData.append('dimensions', JSON.stringify(dimensions));
            }

            if (customEnvironmentImage) {
                formData.append('customEnvironmentImage', customEnvironmentImage);
                formData.append('customEnvironmentMimeType', customEnvironmentMimeType || 'image/jpeg');
            }

            // Enable streaming mode (prepare only, don't execute)
            if (streaming) {
                formData.append('streaming', '1');
            }

            if (this.isUserUploadType(productType)) {
                // Send identifiers for backend to resolve the user's uploaded image
                if (userImageIdentifiers?.storageToken && userImageIdentifiers?.filename) {
                    formData.append('storageToken', userImageIdentifiers.storageToken);
                    formData.append('filename', userImageIdentifiers.filename);
                } else if (userImageIdentifiers?.assetId) {
                    formData.append('assetId', userImageIdentifiers.assetId);
                }
            } else {
                if (productId) {
                    formData.append('productId', productId);
                }
            }

            this.client.post(endpoint, formData, (response: string) => {
                try {
                    if (response.trim().startsWith('<!DOCTYPE') || response.trim().startsWith('<html')) {
                        reject(new Error('Server error - received HTML instead of JSON'));
                        return;
                    }
                    resolve(JSON.parse(response));
                } catch (e) {
                    reject(new Error('Failed to parse response'));
                }
            }, 'application/json');
        });
    }

    /**
     * Connect to SSE stream for real-time composition results.
     *
     * Results are streamed as they arrive from the AI, reducing
     * time to first result from ~30-60s to ~8-15s.
     *
     * @returns AbortController to cancel the stream, and EventSource for cleanup
     */
    streamResults(
        jobId: string,
        callbacks: SSECallbacks
    ): { abort: () => void; eventSource: EventSource | null } {
        const url = `${STREAM_ENDPOINT}?jobId=${encodeURIComponent(jobId)}`;
        let eventSource: EventSource | null = null;

        try {
            eventSource = new EventSource(url);

            eventSource.addEventListener('result', (event: MessageEvent) => {
                try {
                    const result = JSON.parse(event.data) as CompositionResult;
                    callbacks.onResult(result);
                } catch (e) {
                    console.error('[AI Compositor] Failed to parse SSE result:', e);
                }
            });

            eventSource.addEventListener('progress', (event: MessageEvent) => {
                try {
                    const { completed, total } = JSON.parse(event.data);
                    callbacks.onProgress(completed, total);
                } catch (e) {
                    console.error('[AI Compositor] Failed to parse SSE progress:', e);
                }
            });

            eventSource.addEventListener('complete', (event: MessageEvent) => {
                try {
                    const { total, completed } = JSON.parse(event.data);
                    callbacks.onComplete(total, completed);
                } catch (e) {
                    // Complete without data is also valid
                    callbacks.onComplete(0, 0);
                }
                eventSource?.close();
            });

            eventSource.addEventListener('error', (event: MessageEvent) => {
                try {
                    const { message } = JSON.parse(event.data);
                    callbacks.onError(message || 'Unknown streaming error');
                } catch (e) {
                    callbacks.onError('Streaming connection error');
                }
                eventSource?.close();
            });

            // Handle connection errors (different from "error" events)
            eventSource.onerror = () => {
                // Only trigger error callback if connection was closed unexpectedly
                if (eventSource?.readyState === EventSource.CLOSED) {
                    // Connection closed - could be normal completion or error
                    // Don't call onError here as complete event handles normal closure
                }
            };

        } catch (e) {
            callbacks.onError('Failed to establish SSE connection');
        }

        return {
            abort: () => {
                eventSource?.close();
            },
            eventSource
        };
    }

    /**
     * Fallback to polling when SSE is not available or fails.
     * Polls until all results are received.
     */
    async pollUntilComplete(
        jobId: string,
        callbacks: SSECallbacks,
        pollInterval: number = 500
    ): Promise<void> {
        let completed = false;

        while (!completed) {
            try {
                const response = await this.poll(jobId);

                if (!response.success) {
                    callbacks.onError('Polling failed');
                    return;
                }

                // Send any new results
                for (const result of response.newResults) {
                    callbacks.onResult(result);
                }

                // Update progress
                callbacks.onProgress(response.completed, response.total);

                if (response.status === 'completed') {
                    callbacks.onComplete(response.total, response.completed);
                    completed = true;
                } else {
                    // Wait before next poll
                    await new Promise(resolve => setTimeout(resolve, pollInterval));
                }
            } catch (e) {
                callbacks.onError(e instanceof Error ? e.message : 'Polling error');
                return;
            }
        }
    }

    poll(jobId: string): Promise<CompositionPollResponse> {
        return new Promise((resolve, reject) => {
            const pollUrl = `${POLL_ENDPOINT}?jobId=${encodeURIComponent(jobId)}`;

            this.client.get(pollUrl, (response: string) => {
                try {
                    resolve(JSON.parse(response));
                } catch (e) {
                    reject(new Error('Failed to parse poll response'));
                }
            }, 'application/json');
        });
    }
}
