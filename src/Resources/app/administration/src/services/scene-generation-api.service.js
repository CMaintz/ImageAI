const {ApiService} = Shopware.Classes;

/**
 * API Service for Scene Generation
 */
export default class SceneGenerationApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'illux-ai-tools')
    {
        super(httpClient, loginService, apiEndpoint);
    }

    /**
     * Get available scene types from media folder structure
     * @returns {Promise}
     */
    getSceneTypes()
    {
        return this.httpClient.get(
            `/_action/${this.getApiBasePath()}/scene-types`,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get scene generation options (camera angles, lighting, etc.)
     * @returns {Promise}
     */
    getGenerationOptions()
    {
        return this.httpClient.get(
            `/_action/${this.getApiBasePath()}/scene-generation-options`,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get scene generation configuration (for editing)
     * @returns {Promise}
     */
    getConfig()
    {
        return this.httpClient.get(
            `/_action/${this.getApiBasePath()}/scene-generation-config`,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Update scene generation configuration
     * @param {Object} config - Configuration data
     * @returns {Promise}
     */
    updateConfig(config)
    {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/scene-generation-config`,
            config,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Generate scene images
     * @param {Object} config - Generation configuration
     * @returns {Promise}
     */
    generateSceneImages(config)
    {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/generate-scene-images`,
            config,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get pending scene images
     * @returns {Promise}
     */
    getPendingImages()
    {
        return this.httpClient.get(
            `/_action/${this.getApiBasePath()}/pending-scene-images`,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Approve a pending scene image
     * @param {string} pendingImageId - The pending image ID
     * @param {string} targetFolderId - The target folder ID (optional)
     * @returns {Promise}
     */
    approveImage(pendingImageId, targetFolderId = null)
    {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/approval/scene-image/approve`,
            JSON.stringify({ pendingImageId, targetFolderId }),
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Reject a pending scene image
     * @param {string} pendingImageId - The pending image ID
     * @returns {Promise}
     */
    rejectImage(pendingImageId)
    {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/approval/scene-image/reject`,
            JSON.stringify({ pendingImageId }),
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Batch approve multiple pending scene images
     * @param {Array<{pendingImageId: string, targetFolderId: string}>} approvals - Array of approvals
     * @returns {Promise}
     */
    batchApproveImages(approvals)
    {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/approval/scene-image/batch-approve`,
            JSON.stringify({ approvals }),
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Batch reject multiple pending scene images
     * @param {Array<string>} pendingImageIds - Array of pending image IDs to reject
     * @returns {Promise}
     */
    batchRejectImages(pendingImageIds)
    {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/approval/scene-image/batch-reject`,
            JSON.stringify({ pendingImageIds }),
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get prompt preview based on current configuration
     * Uses the backend ScenePromptBuilder to generate the exact prompt
     * @param {Object} config - Generation configuration
     * @returns {Promise}
     */
    getPromptPreview(config)
    {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/prompt-preview`,
            config,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get batch job status by ID
     * @param {string} batchJobId - The batch job ID
     * @returns {Promise}
     */
    getBatchJobStatus(batchJobId)
    {
        return this.httpClient.get(
            `/_action/${this.getApiBasePath()}/batch-job/${batchJobId}`,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Poll batch job status until completed or failed
     * @param {string} batchJobId - The batch job ID
     * @param {Object} callbacks - Callback functions
     * @param {Function} callbacks.onProgress - Called with job data on each poll
     * @param {Function} callbacks.onComplete - Called when job completes
     * @param {Function} callbacks.onError - Called when job fails
     * @param {number} interval - Polling interval in ms (default 2000)
     * @returns {Function} - Function to stop polling
     */
    pollBatchJobStatus(batchJobId, { onProgress, onComplete, onError, interval = 2000 })
    {
        let stopped = false;

        const poll = async() => {
            if (stopped) {
                return;
            }

            try {
                const job = await this.getBatchJobStatus(batchJobId);

                if (job.status === 'completed') {
                    if (onComplete) {
                        onComplete(job);
                    }
                    return;
                }

                if (job.status === 'failed') {
                    if (onError) {
                        onError(job.errorMessage || 'Scene generation failed');
                    }
                    return;
                }

                if (onProgress) {
                    onProgress(job);
                }

                if (!stopped) {
                    setTimeout(poll, interval);
                }
            } catch (error) {
                if (onError) {
                    onError(error.message || 'Failed to get batch job status');
                }
            }
        };

        poll();

        // Return stop function
        return () => {
            stopped = true;
        };
    }
}

Shopware.Application.addServiceProvider('sceneGenerationApiService', (container) => {
    return new SceneGenerationApiService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});
