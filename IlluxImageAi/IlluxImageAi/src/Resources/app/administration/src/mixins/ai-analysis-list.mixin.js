const { Criteria } = Shopware.Data;

export default {
    props: {
        initialPage: {
            type: Number,
            default: 1
                },
                initialLimit: {
                    type: Number,
                    default: 25
                        }
                        },

                        data() {
                                return {
                                    page: this.initialPage,
                                    limit: this.initialLimit,
                                    term: '',
                                    analysisItems: [],
                                    environmentImageItems: [],
                                    analysisTotal: 0,
                                    environmentImageTotal: 0,
                                    isLoading: true
                            };
    },

    methods: {
        async performListFetch(repository, buildCriteriaFn) {
            this.isLoading = true;
            try {
                const criteria = buildCriteriaFn(new Criteria(this.page, this.limit));
                if (this.term) {
                    criteria.setTerm(this.term);
                }
                const result = await repository.search(criteria, Shopware.Context.api);
                this.analysisItems = result;
                this.analysisTotal = result.total;
                return result;
            } catch (e) {
                // caller should handle notifications
                throw e;
            } finally {
                this.isLoading = false;
            }
        },

        // default criteria builder used when none provided
        defaultCriteriaBuilder(criteria) {
            // placeholder: components may override or pass a different builder
            criteria.addAssociation('product.cover.media');
            criteria.addAssociation('translations');
            return criteria;
        }
    }
};
