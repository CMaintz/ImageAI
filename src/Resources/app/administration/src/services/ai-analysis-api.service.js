const {ApiService} = Shopware.Classes;

export default class AiAnalysisApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '/_action/illux-ai-tools')
    {
        super(httpClient, loginService, apiEndpoint);
    }

    /**
     * Analyze a single product
     * @param {string} productId - The product ID
     * @returns {Promise}
     */
    analyzeProduct(productId)
    {
        const apiRoute = `${this.getApiBasePath()}/analyze-product/${productId}`;
        return this.httpClient.post(
            apiRoute,
            {},
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Analyze multiple products
     * @param {string[]} productIds - Array of product IDs
     * @returns {Promise}
     */
    analyzeProducts(productIds)
    {
        const apiRoute = `${this.getApiBasePath()}/analyze-products`;
        return this.httpClient.post(
            apiRoute,
            { productIds },
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Analyze all eligible products
     * @param {Object} filters - Filter options
     * @returns {Promise}
     */
    analyzeAllProducts(filters)
    {
        const apiRoute = `${this.getApiBasePath()}/analyze-all-products`;
        return this.httpClient.post(
            apiRoute,
            { filters },
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get analysis statistics
     * @returns {Promise}
     */
    getAnalysisStats()
    {
        const apiRoute = `${this.getApiBasePath()}/analysis-stats`;
        return this.httpClient.get(
            apiRoute,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * List analysis results for approval
     * @param {string|null} status - Optional status filter
     * @returns {Promise}
     */
    list(status = null)
    {
        const qs = status ? `?status=${status}` : '';
        const apiRoute = `${this.getApiBasePath()}/approval/list${qs}`;
        return this.httpClient.get(
            apiRoute,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Approve multiple analysis results in batch
     * @param {string[]} ids - Array of analysis IDs
     * @returns {Promise}
     */
    approveAnalysisResults(ids)
    {
        const apiRoute = `${this.getApiBasePath()}/approval/analysis/approve`;
        return this.httpClient.post(
            apiRoute,
            { ids },
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Reject multiple analysis results in batch
     * @param {string[]} ids - Array of analysis IDs
     * @returns {Promise}
     */
    rejectAnalysisResults(ids)
    {
        const apiRoute = `${this.getApiBasePath()}/approval/analysis/reject`;
        return this.httpClient.post(
            apiRoute,
            { ids },
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get time saved statistics
     * @returns {Promise}
     */
    getTimeStatistics()
    {
        const apiRoute = `${this.getApiBasePath()}/time-statistics`;
        return this.httpClient.get(
            apiRoute,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Get active analysis job (if any running)
     * Used to resume progress display when switching tabs
     * @returns {Promise}
     */
    getActiveJob()
    {
        const apiRoute = `${this.getApiBasePath()}/active-job`;
        return this.httpClient.get(
            apiRoute,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Stop running analysis by batch job ID
     * @param {string} batchJobId - The batch job ID to cancel
     * @returns {Promise}
     */
    stopAnalysis(batchJobId)
    {
        const apiRoute = `${this.getApiBasePath()}/stop-analysis/${batchJobId}`;
        return this.httpClient.post(
            apiRoute,
            {},
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
        const apiRoute = `${this.getApiBasePath()}/batch-job/${batchJobId}`;
        return this.httpClient.get(
            apiRoute,
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
                        onError(job.errorMessage || 'Batch job failed');
                    }
                    return;
                }

                if (job.status === 'cancelled') {
                    // Job was cancelled - not an error, just stop polling
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
        //TODO promise returned by poll is ignored
        poll();

        // Return stop function
        return () => {
            stopped = true;
        };
    }

    /**
     * Get suggested property options from AI analysis results
     * @returns {Promise}
     */
    getSuggestedOptions()
    {
        const apiRoute = `${this.getApiBasePath()}/suggested-options`;
        return this.httpClient.get(
            apiRoute,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Approve and create property options from suggestions
     * @param {Array} options - Array of {propertyGroup: string, optionName: string}
     * @returns {Promise}
     */
    approveSuggestedOptions(options)
    {
        const apiRoute = `${this.getApiBasePath()}/suggested-options/approve`;
        return this.httpClient.post(
            apiRoute,
            { options },
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Reject a suggested property option
     * @param {string} propertyGroup - Property group name
     * @param {string} optionName - Option name to reject
     * @returns {Promise}
     */
    rejectSuggestedOption(propertyGroup, optionName)
    {
        const apiRoute = `${this.getApiBasePath()}/suggested-options/reject`;
        return this.httpClient.post(
            apiRoute,
            { propertyGroup, optionName },
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Create a new AI-managed property group
     * @param {Object} data - Property group data
     * @param {string} data.name - Property group name
     * @param {string} [data.displayType='text'] - Display type
     * @param {string} [data.sortingType='alphanumeric'] - Sorting type
     * @param {boolean} [data.filterable=true] - Whether filterable
     * @param {boolean} [data.visibleOnProductDetailPage=true] - Visible on PDP
     * @param {number} [data.position=1] - Position
     * @param {Object} [data.translations] - Translations by language ID
     * @param {Array} [data.options] - Options with name and translations
     * @returns {Promise}
     */
    createPropertyGroup(data)
    {
        const apiRoute = `${this.getApiBasePath()}/property-group`;
        return this.httpClient.post(
            apiRoute,
            data,
            { headers: this.getBasicHeaders() }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
}
Shopware.Application.addServiceProvider('imageAiAnalysisApiService', (container) => {
    return new AiAnalysisApiService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});
