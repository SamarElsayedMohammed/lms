/*
* Common JS is used to write code which is generally used for all the UI components
* Specific component related code won't be written here
*/

"use strict";

// Global fallback functions for form callbacks
window.formSuccessFunction = function(response) {
    console.log('Default formSuccessFunction called with response:', response);
    // Default behavior - you can customize this as needed
    if (response && response.message) {
        // Show success message if available
        console.log('Success:', response.message);
    }
};

// Additional common global functions that might be referenced
window.formErrorFunction = function(response) {
    console.log('Default formErrorFunction called with response:', response);
    if (response && response.message) {
        console.log('Error:', response.message);
    }
};

window.formPreSubmitFunction = function() {
    console.log('Default formPreSubmitFunction called');
    return true; // Allow form submission by default
};
$(document).ready(function () {
    $('#table_list').on('all.bs.table', function () {
        $('#toolbar').parent().addClass('col-12  col-md-7 col-lg-7 p-0');
    })
    $(function () {
        $('[data-toggle="tooltip"]').tooltip()
    })
    
    // Global handler to prevent table refresh during and after export
    if (typeof jQuery !== 'undefined' || typeof $ !== 'undefined') {
        (function($) {
            let preventRefreshAfterExport = false;
            let exportTimeout = null;
            let lastExportTime = 0;
            let isExporting = false;
            
            // Function to override refresh method for a table
            function overrideTableRefresh($table) {
                const bootstrapTable = $table.data('bootstrap.table');
                if (bootstrapTable && !bootstrapTable._refreshOverridden) {
                    const originalRefresh = bootstrapTable.refresh;
                    bootstrapTable.refresh = function(options) {
                        const now = Date.now();
                        // Block ALL refresh calls during export or if export happened recently (within 5 seconds)
                        if (isExporting || preventRefreshAfterExport || (now - lastExportTime < 5000)) {
                            // Completely block refresh during and after export
                            return this;
                        }
                        return originalRefresh.call(this, options);
                    };
                    bootstrapTable._refreshOverridden = true;
                }
            }
            
            // Listen for export start events on all bootstrap tables
            $(document).on('export.bs.table', 'table[data-toggle="table"]', function (e, name, args) {
                isExporting = true;
                preventRefreshAfterExport = true;
                lastExportTime = Date.now();
                // Clear any existing timeout
                if (exportTimeout) {
                    clearTimeout(exportTimeout);
                }
            });
            
            // After export completes, prevent refresh for a period
            $(document).on('exported.bs.table', 'table[data-toggle="table"]', function (e, name, args) {
                isExporting = false;
                // Prevent refresh for 5 seconds after export completes
                exportTimeout = setTimeout(function() {
                    preventRefreshAfterExport = false;
                }, 5000);
            });
            
            // Intercept refresh events directly and block them during/after export
            $(document).on('refresh.bs.table', 'table[data-toggle="table"]', function(e) {
                const now = Date.now();
                // Block ALL refresh calls during export or if export happened recently
                if (isExporting || preventRefreshAfterExport || (now - lastExportTime < 5000)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    e.stopPropagation();
                    return false;
                }
            });
            
            // Override refresh for existing tables immediately
            setTimeout(function() {
                $('table[data-toggle="table"]').each(function() {
                    overrideTableRefresh($(this));
                });
            }, 100);
            
            // Override refresh when tables are initialized
            $(document).on('post-body.bs.table', 'table[data-toggle="table"]', function() {
                overrideTableRefresh($(this));
            });
            
            // Also override when table is fully loaded
            $(document).on('load-success.bs.table', 'table[data-toggle="table"]', function() {
                overrideTableRefresh($(this));
            });
            
            // Intercept at init time as well
            $(document).on('init-table.bs.table', 'table[data-toggle="table"]', function() {
                overrideTableRefresh($(this));
            });
        })(typeof jQuery !== 'undefined' ? jQuery : $);
    }

    if ($('.permission-tree').length > 0) {
        $(function () {
            $('.permission-tree').on('changed.jstree', function (e, data) {
                // let i, j = [];
                let html = "";
                for (let i = 0, j = data.selected.length; i < j; i++) {
                    let permissionName = data.instance.get_node(data.selected[i]).data.name;
                    if (permissionName) {
                        html += "<input type='hidden' name='permission[]' value='" + permissionName + "'/>"
                    }
                }
                $('#permission-list').html(html);
            }).jstree({
                "plugins": ["checkbox"],
            });
        });
    }
})
//Setup CSRF Token default in AJAX Request
$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
    }
});

$('#create-form,.create-form,.create-form-without-reset').on('submit', function (e) {
    e.preventDefault();
    let formElement = $(this);
    let submitButtonElement = $(this).find(':submit');
    let url = $(this).attr('action');

    // Preserve file inputs before any validation to prevent them from being cleared
    const fileInputs = formElement.find('input[type="file"]');
    const preservedFileData = {};
    fileInputs.each(function() {
        const input = this;
        const inputId = $(input).attr('id') || $(input).attr('name');
        if (input.files && input.files.length > 0) {
            preservedFileData[inputId] = {
                files: Array.from(input.files),
                input: input
            };
        }
    });

    let preSubmitFunction = $(this).data('pre-submit-function');
    if (preSubmitFunction) {
        //If custom function name is set in the Form tag then call that function
        try {
            if (typeof window[preSubmitFunction] === 'function') {
                if (window[preSubmitFunction]() === false) {
                    // Restore file inputs if pre-submit validation failed
                    restoreFileInputs(preservedFileData);
                    return false;
                }
            } else {
                console.warn('Pre-submit function ' + preSubmitFunction + ' is not defined.');
            }
        } catch (error) {
            console.error('Error calling pre-submit function:', error);
            // Restore file inputs on error
            restoreFileInputs(preservedFileData);
        }
    }
    let customSuccessFunction = $(this).data('success-function');
    let customErrorFunction = $(this).data('error-function');

    // noinspection JSUnusedLocalSymbols

    function successCallback(response) {
        if (!$(formElement).hasClass('create-form-without-reset')) {
            formElement[0].reset();
            $(".select2").val("").trigger('change');
            $('.filepond').filepond('removeFile');
        }
        $('#table_list').bootstrapTable('refresh');
        if (customSuccessFunction) {
            //If custom function name is set in the Form tag then call that function
            try {
                if (typeof window[customSuccessFunction] === 'function') {
                    window[customSuccessFunction](response);
                } else {
                    console.warn('Function ' + customSuccessFunction + ' is not defined. Using default formSuccessFunction.');
                    window.formSuccessFunction(response);
                }
            } catch (error) {
                console.error('Error calling custom success function:', error);
                window.formSuccessFunction(response);
            }
        }
    }

    function errorCallback(response) {
        if (customErrorFunction) {
            //If custom error function name is set in the Form tag then call that function
            try {
                if (typeof window[customErrorFunction] === 'function') {
                    window[customErrorFunction](response);
                } else {
                    console.warn('Error function ' + customErrorFunction + ' is not defined. Using default error handling.');
                    if (response && response.message) {
                        if (typeof showSwalErrorToast === 'function') {
                            showSwalErrorToast(response.message, '');
                        } else {
                            showErrorToast(response.message);
                        }
                    }
                }
            } catch (error) {
                console.error('Error calling custom error function:', error);
                if (response && response.message) {
                    if (typeof showSwalErrorToast === 'function') {
                        showSwalErrorToast(response.message, '');
                    } else {
                        showErrorToast(response.message);
                    }
                }
            }
        } else {
            // Default error handling
            if (response && response.message) {
                if (typeof showSwalErrorToast === 'function') {
                    showSwalErrorToast(response.message, '');
                } else {
                    showErrorToast(response.message);
                }
            }
        }
    }

    // Create FormData after validation passes to preserve file inputs
    // Pass preserved file data to formAjaxRequest so it can restore if validation fails
    formAjaxRequest('POST', url, formElement, submitButtonElement, successCallback, errorCallback, preservedFileData);
})

// Helper function to restore file inputs
function restoreFileInputs(preservedFileData) {
    Object.keys(preservedFileData).forEach(function(inputId) {
        const preserved = preservedFileData[inputId];
        const input = preserved.input;
        
        // Check if the file input was cleared
        if (!input.files || input.files.length === 0) {
            // Use DataTransfer API to restore files
            try {
                const dataTransfer = new DataTransfer();
                preserved.files.forEach(function(file) {
                    dataTransfer.items.add(file);
                });
                input.files = dataTransfer.files;
                
                // Trigger change event to update preview if needed
                $(input).trigger('change');
            } catch (e) {
                console.warn('Could not restore file input:', inputId, e);
            }
        }
    });
}

$('#edit-form,.edit-form').on('submit', function (e) {
    e.preventDefault();
    let formElement = $(this);
    let submitButtonElement = $(this).find(':submit');
    $(formElement).parents('modal').modal('hide');
    // let url = $(this).attr('action') + "/" + data.get('edit_id');
    let url = $(this).attr('action');
    let preSubmitFunction = $(this).data('pre-submit-function');
    if (preSubmitFunction) {
        //If custom function name is set in the Form tag then call that function
        try {
            if (typeof window[preSubmitFunction] === 'function') {
                window[preSubmitFunction]();
            } else {
                console.warn('Pre-submit function ' + preSubmitFunction + ' is not defined.');
            }
        } catch (error) {
            console.error('Error calling pre-submit function:', error);
        }
    }
    let customSuccessFunction = $(this).data('success-function');
    let customErrorFunction = $(this).data('error-function');

    // noinspection JSUnusedLocalSymbols
    function successCallback(response) {
        formElement.parsley().reset();
        $('#table_list').bootstrapTable('refresh');
        setTimeout(function () {
            $('#editModal').modal('hide');
            $(formElement).parents('.modal').modal('hide');
        }, 1000)
        if (customSuccessFunction) {
            //If custom function name is set in the Form tag then call that function
            try {
                if (typeof window[customSuccessFunction] === 'function') {
                    window[customSuccessFunction](response);
                } else {
                    console.warn('Function ' + customSuccessFunction + ' is not defined. Using default formSuccessFunction.');
                    window.formSuccessFunction(response);
                }
            } catch (error) {
                console.error('Error calling custom success function:', error);
                window.formSuccessFunction(response);
            }
        }
    }

    function errorCallback(response) {
        if (customErrorFunction) {
            //If custom error function name is set in the Form tag then call that function
            try {
                if (typeof window[customErrorFunction] === 'function') {
                    window[customErrorFunction](response);
                } else {
                    console.warn('Error function ' + customErrorFunction + ' is not defined. Using default error handling.');
                    if (response && response.message) {
                        if (typeof showSwalErrorToast === 'function') {
                            showSwalErrorToast(response.message, '');
                        } else {
                            showErrorToast(response.message);
                        }
                    }
                }
            } catch (error) {
                console.error('Error calling custom error function:', error);
                if (response && response.message) {
                    if (typeof showSwalErrorToast === 'function') {
                        showSwalErrorToast(response.message, '');
                    } else {
                        showErrorToast(response.message);
                    }
                }
            }
        } else {
            // Default error handling
            if (response && response.message) {
                if (typeof showSwalErrorToast === 'function') {
                    showSwalErrorToast(response.message, '');
                } else {
                    showErrorToast(response.message);
                }
            }
        }
    }

    // Create FormData after validation passes to preserve file inputs
    formAjaxRequest('PUT', url, formElement, submitButtonElement, successCallback, errorCallback);
})

$(document).on('click', '.delete-form', function (e) {
    e.preventDefault();
    showDeletePopupModal($(this).attr('href'), {
        successCallBack: function () {
            $('#table_list').bootstrapTable('refresh');
        }, errorCallBack: function (response) {
            // showErrorToast(response.message);
        }
    })
})

// Fix delete, edit, and status buttons click on mobile devices for Bootstrap Tables
$(document).on('post-body.bs.table', '#table_list', function() {
    // Apply mobile-friendly styles to action buttons
    var actionButtons = '.delete-form, .edit-data, .edit_btn, .set-form-url';
    $(actionButtons).css({
        'pointer-events': 'auto',
        'touch-action': 'manipulation',
        'cursor': 'pointer',
        'z-index': '10',
        'position': 'relative',
        'min-width': '44px',
        'min-height': '44px',
        'display': 'inline-flex',
        'align-items': 'center',
        'justify-content': 'center'
    });

    // Apply mobile-friendly styles to status toggle switches
    $('.update-status, .custom-control-input.update-status, .custom-switch .custom-control-input').css({
        'pointer-events': 'auto',
        'touch-action': 'manipulation',
        'cursor': 'pointer',
        'z-index': '10',
        'position': 'relative',
        'min-width': '44px',
        'min-height': '44px'
    });

    // Ensure touch events work properly for action buttons
    $(actionButtons).off('touchstart.mobile-fix touchend.mobile-fix click.mobile-fix').on('touchstart.mobile-fix', function(e) {
        e.stopPropagation();
        var $this = $(this);
        var touchTime = Date.now();
        
        // Prevent double-firing by checking if touch was already handled
        if (!$this.data('touch-handled')) {
            $this.data('touch-handled', true);
            $this.data('touch-time', touchTime);
            
            // Prevent default click behavior
            $this.on('click.mobile-fix', function(clickEvent) {
                var clickTime = Date.now();
                var touchTime = $this.data('touch-time') || 0;
                // If click happens within 500ms of touch, prevent it
                if (clickTime - touchTime < 500) {
                    clickEvent.preventDefault();
                    clickEvent.stopPropagation();
                    clickEvent.stopImmediatePropagation();
                    return false;
                }
            });
            
            // Handle the action on touchend
            $this.on('touchend.mobile-fix', function(touchEndEvent) {
                touchEndEvent.preventDefault();
                touchEndEvent.stopPropagation();
                
                // Only trigger if it's a valid tap (not a swipe)
                if (!touchEndEvent.changedTouches || touchEndEvent.changedTouches.length > 0) {
                    // Trigger the click event which will be handled by common.js
                    $this.off('click.mobile-fix');
                    $this.trigger('click');
                }
                
                // Reset after delay
                setTimeout(function() {
                    $this.data('touch-handled', false);
                    $this.off('click.mobile-fix touchend.mobile-fix');
                }, 500);
            });
        }
    });

    // Ensure touch events work properly for status switches
    $('.update-status, .custom-control-input.update-status, .custom-switch .custom-control-input').off('touchstart.mobile-status').on('touchstart.mobile-status', function(e) {
        e.stopPropagation();
        var $this = $(this);
        // Prevent double-firing by checking if change was already handled
        if (!$this.data('touch-handled')) {
            $this.data('touch-handled', true);
            setTimeout(function() {
                $this.data('touch-handled', false);
            }, 300);
            // For checkboxes, toggle the checked state and trigger change
            if ($this.is(':checkbox')) {
                $this.prop('checked', !$this.prop('checked'));
                $this.trigger('change');
            } else {
                // For other inputs, trigger change event
                $this.trigger('change');
            }
        }
    });
})

$(document).on('click', '.restore-data', function (e) {
    e.preventDefault();
    showRestorePopupModal($(this).attr('href'), {
        successCallBack: function () {
            $('#table_list').bootstrapTable('refresh');
        }
    })
})

$(document).on('click', '.trash-data', function (e) {
    e.preventDefault();
    showPermanentlyDeletePopupModal($(this).attr('href'), {
        successCallBack: function () {
            $('#table_list').bootstrapTable('refresh');
        }
    })
})

$(document).on('click', '.set-form-url', function (e) {
    //This event will be called when user clicks on the edit button of the bootstrap table
    e.preventDefault();
    $('#edit-form,.edit-form').attr('action', $(this).attr('href'));
})

$(document).on('click', '.delete-form-reload', function (e) {
    e.preventDefault();
    showDeletePopupModal($(this).attr('href'), {
        successCallBack: function () {
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
    })
})

// Change event for Status toggle change in Bootstrap-table
$(document).on('change', '.update-status', function () {
    // let tableElement = $(this).parents('table');
    let tableElement = $(this).closest('[data-table]'); // now works with div or table
    let url = $(tableElement).data('custom-status-change-url') || window.baseurl + "common/change-status";
    ajaxRequest('PUT', url, {
        id: $(this).attr('id'),
        table: $(tableElement).data('table'),
        column: $(tableElement).data('status-column') || "",
        status: $(this).is(':checked') ? 1 : 0
    }, null, function (response) {
        showSuccessToast(response.message);
        $('#table_list').bootstrapTable('refresh');
    }, function (error) {
        showErrorToast(error.message);
    })
})


// Fire Ajax request when the Bootstrap-table rows are rearranged
// $('#table_list').on('reorder-row.bs.table', function (element, rows) {
//     let url = $(element.currentTarget).data('custom-reorder-row-url') || window.baseurl + "common/change-row-order";
//     ajaxRequest('PUT', url, {
//         table: $(element.currentTarget).data('table'),
//         column: $(element.currentTarget).data('reorder-column') || "",
//         data: rows
//     }, null, function (success) {
//         $('#table_list').bootstrapTable('refresh');
//         showSuccessToast(success.message);
//     }, function (error) {
//         showErrorToast(error.message);
//     })
// })

$('.preview-image-file').on('change', function () {
    const [file] = this.files
    if (file) {
        $('.preview-image').attr('src', URL.createObjectURL(file));
    }
})


$('.form-redirection').on('submit', function (e) {
    let parsley = $(this).parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
    });
    parsley.validate();
    if (parsley.isValid()) {
        $(this).find(':submit').attr('disabled', true);
    }
})

$('#editlanguage-form,.editlanguage-form').on('submit', function (e) {
    e.preventDefault();
    let formElement = $(this);
    let submitButtonElement = $(this).find(':submit');
    $(formElement).parents('modal').modal('hide');
    // let url = $(this).attr('action') + "/" + data.get('edit_id');
    let url = $(this).attr('action');
    let preSubmitFunction = $(this).data('pre-submit-function');
    if (preSubmitFunction) {
        //If custom function name is set in the Form tag then call that function
        try {
            if (typeof window[preSubmitFunction] === 'function') {
                window[preSubmitFunction]();
            } else {
                console.warn('Pre-submit function ' + preSubmitFunction + ' is not defined.');
            }
        } catch (error) {
            console.error('Error calling pre-submit function:', error);
        }
    }
    let customSuccessFunction = $(this).data('success-function');

    // noinspection JSUnusedLocalSymbols
    function successCallback(response) {
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }
    // Create FormData after validation passes to preserve file inputs
    formAjaxRequest('PUT', url, formElement, submitButtonElement, successCallback);
})

$('.img_input').click(function () {
    $('#edit_cs_image').click();
});
$('.preview-image-file').on('change', function () {
    const [file] = this.files
    if (file) {
        $('.preview-image').attr('src', URL.createObjectURL(file));
    }
})
$('#type').on('change', function () {
    if ($.inArray($(this).val(), ['checkbox', 'radio', 'dropdown']) > -1) {
        $('#field-values-div').slideDown(500);
        $('.min-max-fields').slideUp(500);
    } else if ($.inArray($(this).val(), ['fileinput']) > -1) {
        $('.min-max-fields').slideUp(500);
    } else {
        $('#field-values-div').slideUp(500);
        $('.min-max-fields').slideDown(500);
    }
});
$('.form-redirection').on('submit', function (e) {
    let parsley = $(this).parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
    });
    parsley.validate();
    if (parsley.isValid()) {
        $(this).find(':submit').attr('disabled', true);
    }
})

$('.img_input').click(function () {
    $('#edit_cs_image').click();
});
$('.preview-image-file').on('change', function () {
    const [file] = this.files
    if (file) {
        $('.preview-image').attr('src', URL.createObjectURL(file));
    }
})
$('#type').on('change', function () {
    if ($.inArray($(this).val(), ['checkbox', 'radio', 'dropdown']) > -1) {
        $('#field-values-div').slideDown(500);
        $('.min-max-fields').slideUp(500);
    } else if ($.inArray($(this).val(), ['fileinput']) > -1) {
        $('.min-max-fields').slideUp(500);
    } else {
        $('#field-values-div').slideUp(500);
        $('.min-max-fields').slideDown(500);
    }
});
$('.form-redirection').on('submit', function (e) {
    let parsley = $(this).parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
    });
    parsley.validate();
    if (parsley.isValid()) {
        $(this).find(':submit').attr('disabled', true);
    }
})

$('.img_input').click(function () {
    $('#edit_cs_image').click();
});
$('.preview-image-file').on('change', function () {
    const [file] = this.files
    if (file) {
        $('.preview-image').attr('src', URL.createObjectURL(file));
    }
})
$('#type').on('change', function () {
    if ($.inArray($(this).val(), ['checkbox', 'radio', 'dropdown']) > -1) {
        $('#field-values-div').slideDown(500);
        $('.min-max-fields').slideUp(500);
    } else if ($.inArray($(this).val(), ['fileinput']) > -1) {
        $('.min-max-fields').slideUp(500);
    } else {
        $('#field-values-div').slideUp(500);
        $('.min-max-fields').slideDown(500);
    }
});
$('.form-redirection').on('submit', function (e) {
    let parsley = $(this).parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset], :hidden'
    });
    parsley.validate();
    if (parsley.isValid()) {
        $(this).find(':submit').attr('disabled', true);
    }
})
$(document).ready(function () {
    $(document).on('click', '.table-list-type', function (e) {
        e.preventDefault();

        // Remove active class from all, then add to clicked
        $('.table-list-type').removeClass('active');
        $(this).addClass('active');

        // Refresh the table which will trigger courseQueryParams
        $('#table_list').bootstrapTable('refresh');
    });
});

// Change event for Status toggle of curriculum
$(document).on('change', '.update-curriculum-status', function () {
    // let tableElement = $(this).parents('table');
    let type = $(this).data('type');
    let id = $(this).data('id');
    let url = $(this).data('url');
    ajaxRequest('PUT', url, {
        id: id,
        type: type,
        status: $(this).is(':checked') ? 1 : 0
    }, null, function (response) {
        showSuccessToast(response.message);
        $('#table_list').bootstrapTable('refresh');
    }, function (error) {
        showErrorToast(error.message);
    })
})

/**
 * Clear validation errors from edit form while preserving required asterisks in labels
 * This function should be called when opening an edit modal or closing it
 * @param {jQuery} formElement - The form element (e.g., $('#editForm'))
 */
window.clearEditFormValidationErrors = function(formElement) {
    if (!formElement || formElement.length === 0) {
        return;
    }
    
    // Reset Parsley validation
    try {
        var parsleyInstance = formElement.parsley();
        if (parsleyInstance) {
            parsleyInstance.reset();
        }
    } catch (e) {
        // Parsley not initialized, continue with manual cleanup
    }
    
    // Remove all error classes and messages
    formElement.find('.parsley-error').removeClass('parsley-error');
    formElement.find('.parsley-success').removeClass('parsley-success');
    formElement.find('.parsley-errors-list').remove();
    formElement.find('input, select, textarea').removeClass('is-invalid').removeClass('parsley-error');
    
    // Remove any inline error messages (but keep asterisks in labels)
    formElement.find('.invalid-feedback, .parsley-required, .parsley-type, .parsley-pattern, .parsley-min, .parsley-max').remove();
    
    // Remove text-danger only if it's not inside a label (to preserve required asterisks)
    formElement.find('.text-danger').filter(function() {
        return !$(this).closest('label').length;
    }).remove();
};

/**
 * Setup modal close handler to clear validation errors for edit forms
 * This automatically applies to all modals with edit forms
 */
$(document).ready(function() {
    // Apply to all modals that contain edit forms
    $(document).on('hidden.bs.modal', '.modal', function() {
        var modal = $(this);
        var editForm = modal.find('.edit-form, #edit-form, form[class*="edit"]');
        
        if (editForm.length > 0) {
            window.clearEditFormValidationErrors(editForm);
        }
    });
    
    // Also clear errors when edit button is clicked (before populating form)
    $(document).on('click', '.edit-data, .edit_btn, .set-form-url', function() {
        // Small delay to ensure modal is shown first
        setTimeout(function() {
            var modal = $(this).closest('.modal');
            if (modal.length === 0) {
                // Try to find modal by common IDs
                modal = $('#editModal, [id*="EditModal"], [id*="editModal"]');
            }
            if (modal.length > 0) {
                var editForm = modal.find('.edit-form, #edit-form, form[class*="edit"]');
                if (editForm.length > 0) {
                    window.clearEditFormValidationErrors(editForm);
                }
            }
        }.bind(this), 100);
    });
});