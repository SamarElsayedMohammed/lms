function imageFormatter(value) {
    if (value) {
        // Handle array of images (for feature sections)
        if (Array.isArray(value)) {
            if (value.length === 0) {
                return '-';
            }
            var html = '';
            for (var i = 0; i < value.length; i++) {
                if (value[i]) {
                    html += '<a class="image-popup-no-margins one-image" href="' + value[i] + '">' +
                        '<img class="rounded avatar-md shadow img-fluid" alt="" src="' + value[i] + '" width="55">' +
                        '</a>';
                    if (i < value.length - 1) {
                        html += '<br>';
                    }
                }
            }
            return html;
        }
        // Handle single image (for other cases)
        return '<a class="image-popup-no-margins one-image" href="' + value + '">' +
            '<img class="rounded avatar-md shadow img-fluid" alt="" src="' + value + '" width="55">' +
            '</a>';
    } else {
        return '-';
    }
}

function videoFormatter(value) {
    if (value) {
        return '<div style="text-align: center;">' +
            '<a href="' + value + '" target="_blank" title="Play Video">' +
                '<i class="fas fa-video" style="font-size: 25px;"></i>' +
            '</a>' +
        '</div>';
    } else {
        return '<div style="text-align: center;">-</div>';
    }
}
// Detail formatter for Bootstrap Table
function detailFormatter(index, row) {
    var html = [];
    html.push('<div class="p-3">');
    html.push('<p><strong>ID:</strong> ' + row.id + '</p>');
    html.push('<p><strong>Name:</strong> ' + row.name + '</p>');
    html.push('<p><strong>Slug:</strong> ' + row.slug + '</p>');
    html.push('<p><strong>Description:</strong> ' + (row.description || 'N/A') + '</p>');
    html.push('<p><strong>Status:</strong> ' + row.status_formatted + '</p>');
    html.push('<p><strong>Created At:</strong> ' + row.created_at + '</p>');
    html.push('</div>');
    return html.join('');
}

function categoryNameFormatter(value, row) {
    let buttonHtml = '';
    if (row.subcategories_count > 0) {
        buttonHtml = `<button class="btn icon btn-xs btn-icon rounded-pill toggle-subcategories float-left btn-outline-primary text-center"
                            style="padding:.20rem; font-size:.875rem;cursor: pointer; margin-right: 5px;" data-id="${row.id}">
                        <i class="fa fa-plus"></i>
                      </button>`;
    } else {
        buttonHtml = `<span style="display:inline-block; width:30px;"></span>`;
    }
    return `${buttonHtml}${value}`;

}
function actionColumnFormatter(value, row, index)
{
    // Wrap action buttons in a flex container for consistent alignment
    if (!value) return '';
    return '<div class="action-column-menu">' + value + '</div>';
}

function subCategoryFormatter(value, row) {
    // Handle undefined, null, or empty values
    if (value === null || value === undefined || value === '') {
        value = 0;
    }
    // Ensure value is a number
    value = parseInt(value) || 0;
    let url = `/category/${row.id}/subcategories`;
    return '<span> <div class="category_count">' + value + ' Sub Categories</div></span>';
}

function statusFormatter(value, row, index) {
    // Handle null, undefined, or missing values
    if (value === null || value === undefined || value === '') {
        value = 0;
    }
    // Convert to number if string
    if (typeof value === 'string') {
        value = value === '1' || value === 'true' ? 1 : 0;
    }
    // Check if status is active
    let checked = (value == 1 || value === true || value === '1' || value === 'true') ? 'checked' : '';
    // Ensure row.id exists
    const rowId = row && row.id ? row.id : (index !== undefined ? 'status_' + index : 'status_unknown');
    return `
        <div class="custom-control custom-switch custom-switch-2">
            <input type="checkbox" class="custom-control-input update-status" id="${rowId}" ${checked}>
            <label class="custom-control-label" for="${rowId}">&nbsp;</label>
        </div>
    `;
}



function subCategoryNameFormatter(value, row, level) {
    let dataLevel = 0;
    let indent = level * 35;
    let buttonHtml = '';
    if (row.subcategories_count > 0) {
        buttonHtml = `<button class="btn icon btn-xs btn-icon rounded-pill toggle-subcategories float-left btn-outline-primary text-center"
                            style="padding:.20rem; cursor: pointer; margin-right: 5px;" data-id="${row.id}" data-level="${dataLevel}">
                        <i class="fa fa-plus"></i>
                      </button>`;
    } else {
        buttonHtml = `<span style="display:inline-block; width:30px;"></span>`;
    }
    dataLevel += 1;
    return `<div style="padding-left:${indent}px;" class="justify-content-center">${buttonHtml}<span>${value}</span></div>`;

}

function customFieldFormatter(value, row) {
    let url = `/category/${row.id}/custom-fields`;
    return '<a href="' + url + '"> <div class="category_count">' + value + ' Custom Fields</div></a>';
}
function autoApproveItemSwitchFormatter(value, row) {
    return `<div class="form-check form-switch">
        <input class="form-check-input switch1 update-auto-approve-status" id="${row.id}" type="checkbox" role="switch" ${value ? 'checked' : ''}>
    </div>`;
}

function yesAndNoStatusFormatter(value,row,index) {
    if (value) {
        return "<span class='badge badge-success'>"+trans("Yes")+"</span>";
    } else {
        return "<span class='badge badge-danger'>"+trans("No")+ "</span>";
    }
}



function formFieldDefaultValuesFormatter(value, row,index) {
    let html = '';
    if (row.default_values && row.default_values.length) {
        html += '<ul>'
        $.each(row.default_values, function (index, value) {
            html += "<i class='fa fa-arrow-right' aria-hidden='true'></i> " + value + "<br>"
        });
    } else {
        html = '<div class="text-center">-</div>';
    }
    return html;
}

function yesAndNoFormatter(value) {
    if (value) {
        return '<span class="badge badge-success">Yes</span>';
    } else {
        return '<span class="badge badge-danger">No</span>';
    }
}


function capitalizeNameFormatter(value) {
    if (typeof value === 'string' && value.length > 0) {
        return value.charAt(0).toUpperCase() + value.slice(1);
    }
    return value; // or return ''; depending on your requirement
}

function sentenceCaseFormatter(value) {
    if (typeof value === 'string' && value.length > 0) {
        // Convert underscore-separated values to title case
        // e.g., "top_rated_courses" -> "Top Rated Courses"
        return value
            .split('_')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join(' ');
    }
    return value;
}

function courseLearningsFormatter(value, row) {
    let html = '';
    if(row.learnings && row.learnings.length > 0){
        html = '<ul>';
        $.each(row.learnings, function (index, item) {
            html += "<li>" + item.title + "</li>";
        });
        html += '</ul>';
    } else {
        html = '<div class="text-center">-</div>';
    }
    return html;
}

function courseRequirementsFormatter(value, row) {
    let html = '';
    if(row.requirements && row.requirements.length > 0){
        html = '<ul>';
        $.each(row.requirements, function (index, item) {
            html += "<li>" + item.requirement + "</li>";
        });
        html += '</ul>';
    } else {
        html = '<div class="text-center">-</div>';
    }
    return html;
}

function courseTagsFormatter(value, row) {
    let html = '';
    if(row.tags && row.tags.length > 0){
        html = '<ul>';
        $.each(row.tags, function (index, item) {
            html += "<li>" + item.tag + "</li>";
        });
        html += '</ul>';
    } else {
        html = '<div class="text-center">-</div>';
    }
    return html;
}

function courseChapterStatusFormatter(value, row) {
    let checked = row.status == true ? 'checked' : '';
    return `
        <div class="custom-control custom-switch custom-switch-2">
            <input type="checkbox" class="custom-control-input change-status-btn" id="${row.id}" data-id="${row.id}" data-type="${row.type}" data-isactive="${row.status}" ${checked}>
            <label class="custom-control-label" for="${row.id}">&nbsp;</label>
        </div>
    `;
}

function viewDetailsFormatter(value, row) {
    return `<a href='' class='btn icon btn-xs btn-rounded btn-icon rounded-pill btn-info view-details-btn' title='Add Curriculum' data-toggle='modal' data-target='#viewDetailsModal' data-id="${row.id}" data-type="${row.type}" data-url="${row.particular_details_url}"><i class='fas fa-eye'></i></a>`;
}

/**
 * Common function to setup status export for Bootstrap Tables
 * This function automatically handles status column export (Active/Deactive) for all tables
 * 
 * Usage: Call this function in $(document).ready() after table initialization
 * Example: setupStatusExport('#table_list', 'is_active'); // for is_active column
 * Example: setupStatusExport('#table_list', 'status'); // for status column
 * 
 * @param {string} tableSelector - jQuery selector for the table (e.g., '#table_list')
 * @param {string} statusField - Name of the status field (e.g., 'is_active' or 'status')
 */
function setupStatusExport(tableSelector, statusField) {
    statusField = statusField || 'is_active'; // Default to 'is_active'
    tableSelector = tableSelector || '#table_list'; // Default to '#table_list'
    
    const $table = $(tableSelector);
    if (!$table.length) {
        console.warn('Table not found:', tableSelector);
        return;
    }
    
    // Wait for table to be initialized
    setTimeout(function() {
        const tableInstance = $table.data('bootstrap.table');
        
        if (!tableInstance) {
            console.warn('Bootstrap Table instance not found for:', tableSelector);
            return;
        }
        
        // Check if status_export column exists
        const columns = tableInstance.options.columns;
        let hasStatusExportColumn = false;
        
        if (columns && columns[0]) {
            for (let i = 0; i < columns[0].length; i++) {
                if (columns[0][i].field === 'status_export') {
                    hasStatusExportColumn = true;
                    break;
                }
            }
        }
        
        // If status_export column exists, ensure it's visible for export but hidden from display
        if (hasStatusExportColumn) {
            // Update export options to exclude the original status column and include status_export
            if (!tableInstance.options.exportOptions) {
                tableInstance.options.exportOptions = {};
            }
            
            // Get current ignoreColumn array or create new one
            let ignoreColumns = tableInstance.options.exportOptions.ignoreColumn || [];
            if (typeof ignoreColumns === 'string') {
                ignoreColumns = JSON.parse(ignoreColumns);
            }
            
            // Add status field to ignore list if not already there
            if (ignoreColumns.indexOf(statusField) === -1) {
                ignoreColumns.push(statusField);
            }
            
            // Ensure 'operate' is in ignore list
            if (ignoreColumns.indexOf('operate') === -1) {
                ignoreColumns.push('operate');
            }
            
            // IMPORTANT: Make sure status_export is NOT in ignore list
            const statusExportIndex = ignoreColumns.indexOf('status_export');
            if (statusExportIndex !== -1) {
                ignoreColumns.splice(statusExportIndex, 1);
            }
            
            tableInstance.options.exportOptions.ignoreColumn = ignoreColumns;
            
            // Also ensure status_export column is visible for export
            if (columns && columns[0]) {
                for (let i = 0; i < columns[0].length; i++) {
                    if (columns[0][i].field === 'status_export') {
                        columns[0][i].visible = true;
                        columns[0][i].export = true;
                        break;
                    }
                }
            }
        }
    }, 500);
}

/**
 * Helper function to format status value for export
 * Converts 0/1, true/false, '0'/'1' to 'Active'/'Deactive'
 * 
 * @param {*} value - Status value (0, 1, true, false, '0', '1', etc.)
 * @returns {string} - 'Active' or 'Deactive'
 */
function formatStatusForExport(value) {
    if (value === null || value === undefined || value === '') {
        return 'Deactive';
    }
    
    // Handle various formats
    const isActive = value == 1 || 
                    value === '1' || 
                    value === 'true' || 
                    value === true || 
                    value === 1 ||
                    (typeof value === 'string' && value.toLowerCase() === 'active');
    
    return isActive ? 'Active' : 'Deactive';
}

// Auto-setup status export for all tables on page load
$(document).ready(function() {
    // Add CSS to hide status_export columns
    if (!$('#status-export-css').length) {
        $('<style id="status-export-css">')
            .text(`
                /* Hide status_export column from display but keep it for export */
                #table_list th[data-field="status_export"],
                #table_list td[data-field="status_export"],
                table[data-toggle="table"] th[data-field="status_export"],
                table[data-toggle="table"] td[data-field="status_export"] {
                    display: none !important;
                }
            `)
            .appendTo('head');
    }
    
    // Auto-detect and setup status export for tables with status_export column
    setTimeout(function() {
        $('table[data-toggle="table"]').each(function() {
            const $table = $(this);
            const tableInstance = $table.data('bootstrap.table');
            
            if (tableInstance) {
                const columns = tableInstance.options.columns;
                let hasStatusField = false;
                let statusFieldName = 'is_active';
                
                // Check for is_active or status column
                if (columns && columns[0]) {
                    for (let i = 0; i < columns[0].length; i++) {
                        if (columns[0][i].field === 'is_active' || columns[0][i].field === 'status') {
                            hasStatusField = true;
                            statusFieldName = columns[0][i].field;
                            
                            // Check if status_export column exists
                            let hasStatusExport = false;
                            for (let j = 0; j < columns[0].length; j++) {
                                if (columns[0][j].field === 'status_export') {
                                    hasStatusExport = true;
                                    break;
                                }
                            }
                            
                            // If status_export exists, setup export
                            if (hasStatusExport) {
                                setupStatusExport('#' + $table.attr('id') || tableSelector, statusFieldName);
                            }
                            break;
                        }
                    }
                }
            }
        });
    }, 1000);
});
