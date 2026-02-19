function queryParams(p) {
    // Note: Languages table doesn't have deleted_at column, so no show_deleted parameter
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
    };
}

function reportReasonQueryParams(p) {
    return {
        ...p,
        "status": $('#filter_status').val(),
    };
}
function courseQueryParams(p) {
    let tableListType = $('.table-list-type.active').data('id'); // e.g. 0 = active, 1 = trashed
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
        show_deleted: tableListType
    };
}
function faqQueryParams(p) {
    let tableListType = $('.table-list-type.active').data('id'); // e.g. 0 = active, 1 = trashed
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
        show_deleted: tableListType
    };
}
function categoriesQueryParams(p) {
    let tableListType = $('.table-list-type.active').data('id'); // e.g. 0 = active, 1 = trashed
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
        show_deleted: tableListType
    };
}
function customFieldsQueryParams(p) {
    let tableListType = $('.table-list-type.active').data('id'); // e.g. 0 = active, 1 = trashed
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
        show_deleted: tableListType
    };
}
function courseLanguagesQueryParams(p) {
    let tableListType = $('.table-list-type.active').data('id'); // e.g. 0 = active, 1 = trashed
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
        show_deleted: tableListType
    };
}
function tagQueryParams(p) {
    let tableListType = $('.table-list-type.active').data('id'); // e.g. 0 = active, 1 = trashed
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
        show_deleted: tableListType
    };
}
function seoSettingsQueryParams(p) {
    let tableListType = $('.table-list-type.active').data('id'); // e.g. 0 = active, 1 = trashed
    return {
        offset: p.offset,
        limit: p.limit,
        sort: p.sort,
        order: p.order,
        search: p.search,
        show_deleted: tableListType
    };
}
