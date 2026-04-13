// column factory to produce column definitions for different lists
export function createBaseColumns($tc)
{
    return [
        { property: 'product.cover', label: $tc('ai-image-tools.gridShared.columns.image'), allowResize: false, sortable: false, width: '80px' },
        { property: 'product.name', label: $tc('ai-image-tools.gridShared.columns.productName'), primary: true, allowResize: true },
        { property: 'totalConfidenceScore', label: $tc('ai-image-tools.gridShared.columns.confidence'), allowResize: true },
        { property: 'analyzedProperties', label: $tc('ai-image-tools.gridShared.columns.analyzedProperties'), allowResize: true, width: '150px' },
        { property: 'seoKeywords', label: $tc('ai-image-tools.gridShared.columns.seoKeywords'), allowResize: true },
        { property: 'metaTitle', label: $tc('ai-image-tools.gridShared.columns.metaTitle'), allowResize: true },
        { property: 'metaDescription', label: $tc('ai-image-tools.gridShared.columns.metaDescription'), allowResize: true },
        { property: 'productDescription', label: $tc('ai-image-tools.gridShared.columns.productDescription'), allowResize: true },
        { property: 'createdAt', label: $tc('ai-image-tools.gridShared.columns.analysisDate'), allowResize: true }
    ];
}

export function createApprovalColumns($tc)
{
    // Approval doesn't need 'status'
    return createBaseColumns($tc);
}

export function createListColumns($tc)
{
    // List view includes status column + error column
    const cols = createBaseColumns($tc);
    cols.splice(2, 0, { property: 'status', label: $tc('ai-image-tools.gridShared.columns.status'), allowResize: true, width: '150px' });

    cols.push({ property: 'errorMessage', label: $tc('ai-image-tools.gridShared.columns.errorMessage'), allowResize: true });

    return cols;
}
