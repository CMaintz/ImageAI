/**
 * Vuex store module for AI analysis results
 * Provides centralized state for analysis data across all tabs
 */

const {Criteria} = Shopware.Data;

export default {
    namespaced: true,

        state()
        {
            return {
                analysisResults: [],
                isLoading: false,
                isInitialized: false,
                lastFetchedAt: null,
                staleAfterMs: 60000,
                timeStatistics: {
                    totalMinutes: 0,
                    totalHours: 0,
                    totalDays: 0,
                    breakdown: {
                        properties: 0,
                        seo: 0,
                        description: 0
                    },
                    productCount: 0
                },
                statusCounts: {
                    processing: 0,
                    pending_review: 0,
                    approved: 0,
                    auto_approved: 0,
                    rejected: 0,
                    failed: 0
                }
            };
    },

    getters: {
        allResults: (state) => state.analysisResults,

        pendingReview: (state) => state.analysisResults.filter(r => r.status === 'pending_review'),
        processing: (state) => state.analysisResults.filter(r => r.status === 'processing'),
        approved: (state) => state.analysisResults.filter(r => r.status === 'approved'),
        autoApproved: (state) => state.analysisResults.filter(r => r.status === 'auto_approved'),
        rejected: (state) => state.analysisResults.filter(r => r.status === 'rejected'),
        failed: (state) => state.analysisResults.filter(r => r.status === 'failed'),

        successful: (state) => state.analysisResults.filter(
            r =>
            r.status === 'approved' || r.status === 'auto_approved'
        ),

    counts: (state) => ({
        total: Object.values(state.statusCounts).reduce((a, b) => a + b, 0),
        pendingReview: state.statusCounts.pending_review || 0,
        processing: state.statusCounts.processing || 0,
        approved: state.statusCounts.approved || 0,
        autoApproved: state.statusCounts.auto_approved || 0,
        rejected: state.statusCounts.rejected || 0,
        failed: state.statusCounts.failed || 0,
        successful: (state.statusCounts.approved || 0) + (state.statusCounts.auto_approved || 0)
        }),

    successRate: (state, getters) => {
        const counts = getters.counts;
        const finalized = counts.successful + counts.rejected + counts.failed;
        if (finalized === 0) {
            return 0;
        }
        return ((counts.successful / finalized) * 100).toFixed(1);
        },

        lastAnalysisDate: (state) => {
            if (state.analysisResults.length === 0) {
                return null;
            }
            const sorted = [...state.analysisResults].sort(
                (a, b) =>
                new Date(b.createdAt) - new Date(a.createdAt)
            );
            return sorted[0]?.createdAt || null;
        },

        timeSaved: (state) => state.timeStatistics,

        isLoading: (state) => state.isLoading,
        isInitialized: (state) => state.isInitialized,

        isStale: (state) => {
            if (!state.lastFetchedAt) {
                return true;
            }
            return (Date.now() - state.lastFetchedAt) > state.staleAfterMs;
        },

        getByStatus: (state) => (status) => {
            if (!status) {
                return state.analysisResults;
            }
            return state.analysisResults.filter(r => r.status === status);
        }
    },

    mutations: {
        setAnalysisResults(state, results)
        {
            state.analysisResults = results;
            state.lastFetchedAt = Date.now();
            state.isInitialized = true;
        },

        setLoading(state, isLoading)
        {
            state.isLoading = isLoading;
        },

        setTimeStatistics(state, stats)
        {
            state.timeStatistics = stats;
        },

        setStatusCounts(state, counts)
        {
            state.statusCounts = counts;
        },

        updateResult(state, updatedResult)
        {
            const index = state.analysisResults.findIndex(r => r.id === updatedResult.id);
            if (index !== -1) {
                state.analysisResults.splice(index, 1, updatedResult);
            }
        },

        removeResults(state, ids)
        {
            state.analysisResults = state.analysisResults.filter(r => !ids.includes(r.id));
        },

        markStale(state)
        {
            state.lastFetchedAt = null;
        },

        clearResults(state)
        {
            state.analysisResults = [];
            state.isInitialized = false;
            state.lastFetchedAt = null;
        }
    },

    actions: {
        /**
         * Load all analysis results from repository
         * Includes product cover and translations associations
         */
        async loadResults({commit, state}, {force = false} = {})
        {
            if (state.isInitialized && !force && state.lastFetchedAt &&
                (Date.now() - state.lastFetchedAt) < state.staleAfterMs) {
                return;
            }

            commit('setLoading', true);

            try {
                const repository = Shopware.Service('repositoryFactory').create('ai_analysis_result');
                const context = Shopware.Context.api;

                const criteria = new Criteria(1, 500);
                criteria.addAssociation('product.cover.media');
                criteria.addAssociation('translations');
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

                const result = await repository.search(criteria, context);

                const resultsArray = [];
                result.forEach(item => resultsArray.push(item));

                commit('setAnalysisResults', resultsArray);
            } catch (error) {
                console.error('Failed to load analysis results:', error);
                throw error;
            } finally {
                commit('setLoading', false);
            }
        },

        /**
         * Load time statistics from backend API
         */
        async loadTimeStatistics({commit})
        {
            try {
                const apiService = Shopware.Service('illuxAiAnalysisApiService');
                const response = await apiService.getTimeStatistics();

                if (response.success && response.data) {
                    commit('setTimeStatistics', {
                        totalMinutes: response.data.totalMinutes,
                        totalHours: response.data.totalHours,
                        totalDays: response.data.totalDays,
                        breakdown: response.data.breakdown,
                        productCount: response.data.productCount
                    });
                }
            } catch (error) {
                console.error('Failed to load time statistics:', error);
            }
        },

        /**
         * Load status counts from backend API (using DB aggregation)
         */
        async loadStatusCounts({commit})
        {
            try {
                const apiService = Shopware.Service('illuxAiAnalysisApiService');
                const response = await apiService.getAnalysisStats();

                if (response.success && response.stats) {
                    commit('setStatusCounts', response.stats);
                }
            } catch (error) {
                console.error('Failed to load status counts:', error);
            }
        },

        /**
         * Force refresh of all data
         */
        async refresh({dispatch})
        {
            await Promise.all([
                                  dispatch('loadResults', {force: true}),
                                  dispatch('loadTimeStatistics'),
                                  dispatch('loadStatusCounts')
                              ]);
        },

        /**
         * Initialize store - load data if not already loaded
         */
        async initialize({dispatch, state})
        {
            if (!state.isInitialized) {
                await dispatch('refresh');
            }
        },

        /**
         * Optimistic update after approval - update status locally
         * Then mark stale so next component access will refresh
         */
        markResultsApproved({commit, state}, ids)
        {
            ids.forEach(id => {
                const result = state.analysisResults.find(r => r.id === id);
                if (result) {
                    commit('updateResult', {...result, status: 'approved'});
                }
            });
            commit('markStale');
        },

        /**
         * Optimistic update after rejection
         */
        markResultsRejected({commit, state}, ids)
        {
            ids.forEach(id => {
                const result = state.analysisResults.find(r => r.id === id);
                if (result) {
                    commit('updateResult', {...result, status: 'rejected'});
                }
            });
            commit('markStale');
        }
    }
};
