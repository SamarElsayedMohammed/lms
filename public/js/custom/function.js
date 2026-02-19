"use strict";

function trans(label) {
    // Check if languageLabels exists and has the label
    if (window.languageLabels && window.languageLabels.hasOwnProperty(label)) {
        return window.languageLabels[label];
    }
    
    // Provide default translations for common delete modal strings
    const defaultTranslations = {
        "Are you sure": "Are you sure?",
        "You wont be able to revert this": "You won't be able to revert this!",
        "Yes Delete": "Yes, delete it!",
        "Yes Restore it": "Yes, restore it!",
        "Cancel": "Cancel"
    };
    
    // Return default translation if available, otherwise return the original label
    return defaultTranslations[label] || label;
}

/**
 * ============================================
 * SWEETALERT COMMON FUNCTIONS - USE THESE IN ALL VIEWS
 * ============================================
 * These functions centralize all SweetAlert success/error messages
 * to avoid code duplication across view files.
 */

/**
 * Show SweetAlert Success Toast (Top Right)
 * @param {String} message - Success message
 * @param {String} title - Optional title (default: empty - only message shown)
 * @param {Number} timer - Auto-close timer in ms (default: 3000)
 * @param {Function} callback - Optional callback after toast closes
 */
function showSwalSuccessToast(message, title = '', timer = 3000, callback = null) {
    if (typeof Swal === 'undefined') {
        console.warn('SweetAlert2 is not loaded');
        return;
    }
    
    const config = {
        icon: 'success',
        text: message,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: timer,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    };
    
    // Only add title if provided
    if (title) {
        config.title = title;
    }
    
    if (callback && typeof callback === 'function') {
        Swal.fire(config).then(callback);
    } else {
        Swal.fire(config);
    }
}

/**
 * Show SweetAlert Error Toast (Top Right)
 * @param {String} message - Error message
 * @param {String} title - Optional title (default: empty - only message shown)
 * @param {Number} timer - Auto-close timer in ms (default: 4000)
 * @param {Function} callback - Optional callback after toast closes
 */
function showSwalErrorToast(message, title = '', timer = 4000, callback = null) {
    if (typeof Swal === 'undefined') {
        console.warn('SweetAlert2 is not loaded');
        return;
    }
    
    const config = {
        icon: 'error',
        text: message,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: timer,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    };
    
    // Only add title if provided
    if (title) {
        config.title = title;
    }
    
    if (callback && typeof callback === 'function') {
        Swal.fire(config).then(callback);
    } else {
        Swal.fire(config);
    }
}

/**
 * Show SweetAlert Warning Toast (Top Right)
 * @param {String} message - Warning message
 * @param {String} title - Optional title (default: empty - only message shown)
 * @param {Number} timer - Auto-close timer in ms (default: 4000)
 * @param {Function} callback - Optional callback after toast closes
 */
function showSwalWarningToast(message, title = '', timer = 4000, callback = null) {
    if (typeof Swal === 'undefined') {
        console.warn('SweetAlert2 is not loaded');
        return;
    }
    
    const config = {
        icon: 'warning',
        text: message,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: timer,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    };
    
    // Only add title if provided
    if (title) {
        config.title = title;
    }
    
    if (callback && typeof callback === 'function') {
        Swal.fire(config).then(callback);
    } else {
        Swal.fire(config);
    }
}

/**
 * Show SweetAlert Success Toast with HTML content
 * @param {String} html - HTML content for message
 * @param {String} title - Optional title (default: empty - only message shown)
 * @param {Number} timer - Auto-close timer in ms (default: 3000)
 * @param {Number} width - Toast width in px (default: auto)
 * @param {Function} callback - Optional callback after toast closes
 */
function showSwalSuccessToastHTML(html, title = '', timer = 3000, width = null, callback = null) {
    if (typeof Swal === 'undefined') {
        console.warn('SweetAlert2 is not loaded');
        return;
    }
    
    const config = {
        icon: 'success',
        html: html,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: timer,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    };
    
    // Only add title if provided
    if (title) {
        config.title = title;
    }
    
    if (width) {
        config.width = width;
    }
    
    if (callback && typeof callback === 'function') {
        Swal.fire(config).then(callback);
    } else {
        Swal.fire(config);
    }
}

/**
 * Show SweetAlert Error Toast with HTML content
 * @param {String} html - HTML content for message
 * @param {String} title - Optional title (default: empty - only message shown)
 * @param {Number} timer - Auto-close timer in ms (default: 4000)
 * @param {Number} width - Toast width in px (default: auto)
 * @param {Function} callback - Optional callback after toast closes
 */
function showSwalErrorToastHTML(html, title = '', timer = 4000, width = null, callback = null) {
    if (typeof Swal === 'undefined') {
        console.warn('SweetAlert2 is not loaded');
        return;
    }
    
    const config = {
        icon: 'error',
        html: html,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: timer,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    };
    
    // Only add title if provided
    if (title) {
        config.title = title;
    }
    
    if (width) {
        config.width = width;
    }
    
    if (callback && typeof callback === 'function') {
        Swal.fire(config).then(callback);
    } else {
        Swal.fire(config);
    }
}

/**
 * Show SweetAlert Modal (Centered, with Confirm Button)
 * @param {String} message - Message text
 * @param {String} title - Optional title
 * @param {String} icon - Icon type: 'success', 'error', 'warning', 'info', 'question' (default: 'info')
 * @param {String} confirmButtonText - Confirm button text (default: "OK")
 * @param {Function} callback - Optional callback when confirmed
 */
function showSwalModal(message, title = null, icon = 'info', confirmButtonText = null, callback = null) {
    if (typeof Swal === 'undefined') {
        console.warn('SweetAlert2 is not loaded');
        return;
    }
    
    const config = {
        icon: icon,
        title: title || trans('Information'),
        text: message,
        confirmButtonText: confirmButtonText || trans('OK'),
        confirmButtonColor: '#3085d6'
    };
    
    if (callback && typeof callback === 'function') {
        Swal.fire(config).then(callback);
    } else {
        Swal.fire(config);
    }
}

/**
 * Show SweetAlert Modal with HTML content
 * @param {String} html - HTML content
 * @param {String} title - Optional title
 * @param {String} icon - Icon type (default: 'info')
 * @param {String} confirmButtonText - Confirm button text (default: "OK")
 * @param {Function} callback - Optional callback when confirmed
 */
function showSwalModalHTML(html, title = null, icon = 'info', confirmButtonText = null, callback = null) {
    if (typeof Swal === 'undefined') {
        console.warn('SweetAlert2 is not loaded');
        return;
    }
    
    const config = {
        icon: icon,
        title: title || trans('Information'),
        html: html,
        confirmButtonText: confirmButtonText || trans('OK'),
        confirmButtonColor: '#3085d6'
    };
    
    if (callback && typeof callback === 'function') {
        Swal.fire(config).then(callback);
    } else {
        Swal.fire(config);
    }
}

function showErrorToast(message) {
    // Use SweetAlert toast instead of Toastify
    showSwalErrorToast(message);
}

function showSuccessToast(message) {
    // Use SweetAlert toast instead of Toastify
    showSwalSuccessToast(message);
}

function showWarningToast(message) {
    Toastify({
        text: message,
        duration: 6000,
        close: !0,
        style: {
            background: "linear-gradient(to right, #a7b000, #b08d00)"
        }
    }).showToast();
}

/**
 * Common Image File Validation Functions
 * These functions can be used across all pages to avoid code duplication
 */

/**
 * Validate image file type and size
 * @param {File} file - The file object to validate
 * @param {Object} options - Validation options
 * @param {Array} options.allowedExtensions - Array of allowed extensions (default: ['jpg', 'jpeg', 'png', 'svg', 'webp'])
 * @param {Number} options.maxSizeMB - Maximum file size in MB (default: 7)
 * @returns {Object} - {valid: boolean, error: string|null, fileSizeMB: number}
 */
function validateImageFile(file, options = {}) {
    const defaultOptions = {
        allowedExtensions: ['jpg', 'jpeg', 'png', 'svg', 'webp'],
        maxSizeMB: 7
    };
    
    const opts = Object.assign({}, defaultOptions, options);
    const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
    
    // Check file type
    const fileExtension = file.name.split('.').pop().toLowerCase();
    if (!opts.allowedExtensions.includes(fileExtension)) {
        const allowedTypes = opts.allowedExtensions.join(', ').toUpperCase();
        return {
            valid: false,
            error: 'Invalid file type. Please choose ' + allowedTypes + ' image.',
            fileSizeMB: fileSizeMB
        };
    }
    
    // Check file size
    const maxFileSize = opts.maxSizeMB * 1024 * 1024; // Convert MB to bytes
    if (file.size > maxFileSize) {
        return {
            valid: false,
            error: 'Please upload an image file that is ' + opts.maxSizeMB + 'MB or less. Your file size is ' + fileSizeMB + ' MB.',
            fileSizeMB: fileSizeMB
        };
    }
    
    return {
        valid: true,
        error: null,
        fileSizeMB: fileSizeMB
    };
}

/**
 * Display image file size error in error div and show SweetAlert
 * @param {jQuery} imageInput - jQuery object of the file input
 * @param {jQuery} imgError - jQuery object of the error div
 * @param {String} errorMessage - Error message to display
 * @param {Boolean} showSwal - Whether to show SweetAlert (default: true)
 */
function showImageFileError(imageInput, imgError, errorMessage, showSwal = true) {
    // Display error in error div
    if (imgError.length) {
        imgError.html('<i class="fas fa-exclamation-circle mr-1"></i><strong>' + errorMessage + '</strong>').css({
            'display': 'block',
            'color': '#DC3545',
            'font-weight': '500',
            'margin-top': '5px'
        });
    } else {
        // Fallback: create error div if it doesn't exist
        imageInput.after('<div class="img_error text-danger mt-1" style="color:#DC3545; font-weight: 500; min-height: 20px; display: block;"><i class="fas fa-exclamation-circle mr-1"></i><strong>' + errorMessage + '</strong></div>');
    }
    
    // Store error in data attribute
    imageInput.data('validation-error', errorMessage);
    imageInput.attr('data-validation-error', errorMessage);
    
    // Add error classes for visual feedback
    imageInput.addClass('is-invalid');
    imageInput.closest('.form-group').addClass('has-error');
    
    // Show SweetAlert toast notification
    if (showSwal && typeof Swal !== 'undefined') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 5000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });
        
        Toast.fire({
            icon: 'error',
            title: errorMessage
        });
    }
    
    // Scroll to error
    setTimeout(function() {
        const errorElement = imgError.length ? imgError : imageInput.siblings('.img_error');
        if (errorElement.length && errorElement.is(':visible')) {
            $('html, body').animate({
                scrollTop: errorElement.offset().top - 150
            }, 500);
        }
    }, 100);
}

/**
 * Clear image file error
 * @param {jQuery} imageInput - jQuery object of the file input
 * @param {jQuery} imgError - jQuery object of the error div
 */
function clearImageFileError(imageInput, imgError) {
    if (imgError.length) {
        imgError.html('').css('display', 'none');
    }
    imageInput.removeData('validation-error');
    imageInput.removeAttr('data-validation-error');
    imageInput.removeClass('is-invalid');
    imageInput.closest('.form-group').removeClass('has-error');
}

/**
 * Common image file change handler
 * This function can be used to handle image file validation on any page
 * @param {jQuery} fileInput - jQuery object of the file input
 * @param {Object} options - Options for validation and preview
 * @param {Function} options.onValid - Callback when file is valid
 * @param {Function} options.onError - Callback when file has error
 * @param {Object} options.validation - Validation options (same as validateImageFile)
 */
function handleImageFileChange(fileInput, options = {}) {
    const file = fileInput[0].files[0];
    const imgError = fileInput.closest('.form-group').find('.img_error');
    const previewContainer = fileInput.siblings('.preview-image-container');
    const previewImg = previewContainer.find('.preview-image');
    
    // Clear previous errors
    clearImageFileError(fileInput, imgError);
    
    if (!file) {
        if (previewContainer.length) {
            previewContainer.hide();
        }
        return;
    }
    
    // Validate file
    const validation = validateImageFile(file, options.validation || {});
    
    if (!validation.valid) {
        // Show error
        showImageFileError(fileInput, imgError, validation.error, options.showSwal !== false);
        fileInput.val('');
        if (previewContainer.length) {
            previewContainer.hide();
        }
        
        if (options.onError && typeof options.onError === 'function') {
            options.onError(validation.error, file);
        }
        return;
    }
    
    // File is valid
    if (options.onValid && typeof options.onValid === 'function') {
        options.onValid(file);
    } else {
        // Default: show preview
        if (previewContainer.length && previewImg.length) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.attr('src', e.target.result);
                previewContainer.show();
            };
            reader.readAsDataURL(file);
        }
    }
}

/**
 *
 * @param type
 * @param url
 * @param data
 * @param {function} beforeSendCallback
 * @param {function} successCallback - This function will be executed if no Error will occur
 * @param {function} errorCallback - This function will be executed if some error will occur
 * @param {function} finalCallback - This function will be executed after all the functions are executed
 * @param processData
 */
function ajaxRequest(type, url, data, beforeSendCallback = null, successCallback = null, errorCallback = null, finalCallback = null, processData = false) {
    // Modifying the data attribute here according to the type method
    if (!["get", "post"].includes(type.toLowerCase())) {
        if (data instanceof FormData) {
            data.append("_method", type);
        } else {
            data = {...data, "_method": type};
            data = JSON.stringify(data);
        }
        type = "POST";
    }
    $.ajax({
        type: type,
        url: url,
        data: data,
        cache: false,
        processData: processData,
        contentType: data instanceof FormData ? false : "application/json",
        dataType: 'json',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        beforeSend: function () {
            if (beforeSendCallback != null) {
                beforeSendCallback();
            }
        },
        success: function (data) {
            if (!data.error || data.success === true) {
                if (successCallback != null) {
                    successCallback(data);
                }
            } else {
                if (errorCallback != null) {
                    errorCallback(data);
                }
            }

            if (finalCallback != null) {
                finalCallback(data);
            }
        }, error: function (jqXHR) {
            if (jqXHR.responseJSON) {
                showErrorToast(jqXHR.responseJSON.message);
            }
            if (finalCallback != null) {
                finalCallback();
            }
        }
    })
}

function formAjaxRequest(type, url, formElementOrData, submitButtonElement, successCallback = null, errorCallback = null, preservedFileData = null) {
    // To Remove Red Border from the Validation tag.
    // formElement.find('.has-danger').removeClass("has-danger");
    // formElement.validate();

    // Backward compatibility: Check if third parameter is FormData or formElement
    let formElement, data;
    if (formElementOrData instanceof FormData) {
        // Old signature: formAjaxRequest(type, url, data, formElement, ...)
        // In this case, formElementOrData is actually the data, and submitButtonElement is actually formElement
        data = formElementOrData;
        formElement = submitButtonElement;
        submitButtonElement = successCallback;
        successCallback = errorCallback;
        errorCallback = arguments[6] || null;
    } else {
        // New signature: formAjaxRequest(type, url, formElement, submitButtonElement, ...)
        formElement = formElementOrData;
    }

    // Preserve file input values before validation to prevent them from being cleared
    // Use provided preservedFileData if available, otherwise create new one
    let preservedFiles = preservedFileData || {};
    if (!preservedFileData) {
        const fileInputs = formElement.find('input[type="file"]');
        fileInputs.each(function() {
            const input = this;
            const inputId = $(input).attr('id') || $(input).attr('name');
            if (input.files && input.files.length > 0) {
                preservedFiles[inputId] = {
                    files: Array.from(input.files),
                    input: input
                };
            }
        });
    }

    let parsley = formElement.parsley({
        excluded: 'input[type=button], input[type=submit], input[type=reset]'
    });
    
    // Ensure file inputs are not excluded from validation even if hidden
    // This prevents Parsley from clearing them on validation failure
    formElement.find('input[type="file"]').each(function() {
        if ($(this).attr('data-parsley-required')) {
            $(this).attr('data-parsley-excluded', 'false');
        }
    });
    
    parsley.validate();
    if (parsley.isValid()) {
        // Create FormData AFTER validation passes to preserve file inputs (if not already provided)
        if (!data) {
            data = new FormData(formElement[0]);
        }
        let submitButtonText = submitButtonElement.val();

        function beforeSendCallback() {
            submitButtonElement.attr('disabled', true);
        }

        function mainSuccessCallback(response) {
            if (response.warning) {
                showWarningToast(response.message);
            } else {
                showSuccessToast(response.message);
            }

            if (successCallback != null) {
                successCallback(response);
            }
        }

        function mainErrorCallback(response) {
            // Call custom error callback if provided, otherwise use default
            if (errorCallback != null) {
                errorCallback(response);
            } else {
                // Default error handling - use SweetAlert if available, otherwise Toastify
                if (response && response.message) {
                    if (typeof showSwalErrorToast === 'function') {
                        showSwalErrorToast(null, response.message);
                    } else {
                        showErrorToast(response.message);
                    }
                }
            }
        }

        function finalCallback() {
            submitButtonElement.val(submitButtonText).attr('disabled', false);
        }


        ajaxRequest(type, url, data, beforeSendCallback, mainSuccessCallback, mainErrorCallback, finalCallback)
    } else {
        // Validation failed - restore file inputs if they were cleared
        Object.keys(preservedFiles).forEach(function(inputId) {
            const preserved = preservedFiles[inputId];
            const input = preserved.input;
            
            // Check if the file input was cleared
            if (!input.files || input.files.length === 0) {
                // Create a new FileList-like object and restore the files
                // Note: We can't directly set files, but we can use DataTransfer API
                try {
                    const dataTransfer = new DataTransfer();
                    preserved.files.forEach(function(file) {
                        dataTransfer.items.add(file);
                    });
                    input.files = dataTransfer.files;
                    
                    // Trigger change event to update preview if needed
                    $(input).trigger('change');
                } catch (e) {
                    // Fallback: If DataTransfer is not available, at least log it
                    console.warn('Could not restore file input:', inputId, e);
                }
            }
        });
    }
}

function Select2SearchDesignTemplate(repo) {
    /**
     * This function is used in Select2 Searching Functionality
     */
    if (repo.loading) {
        return repo.text;
    }
    let $container;
    if (repo.id && repo.text) {
        $container = $(
            "<div class='select2-result-repository clearfix'>" +
            "<div class='select2-result-repository__title'></div>" +
            "</div>"
        );
        $container.find(".select2-result-repository__title").text(repo.text);
    } else {
        $container = $(
            "<div class='select2-result-repository clearfix'>" +
            "<div class='row'>" +
            "<div class='col-1 select2-result-repository__avatar' style='width:20px'>" +
            "<img src='" + repo.image + "' class='w-100' alt=''/>" +
            "</div>" +
            "<div class='col-10'>" +
            "<div class='select2-result-repository__title'></div>" +
            "<div class='select2-result-repository__description'></div>" +
            "</div>" +
            "</div>"
        );

        $container.find(".select2-result-repository__title").text(repo.first_name + " " + repo.last_name);
        $container.find(".select2-result-repository__description").text(repo.email);
    }

    return $container;
}

/**
 *
 * @param searchElement
 * @param searchUrl
 * @param {Object|null} data
 * @param {number} data.total_count
 * @param {string} data.email
 * @param {number} data.page
 * @param placeHolder
 * @param templateDesignEvent
 * @param onTemplateSelectEvent
 */
function select2Search(searchElement, searchUrl, data, placeHolder, templateDesignEvent, onTemplateSelectEvent) {
    //Select2 Ajax Searching Functionality function
    if (!data) {
        data = {};
    }
    $(searchElement).select2({
        tags: true,
        ajax: {
            url: searchUrl,
            dataType: 'json',
            delay: 250,
            cache: true,
            data: function (params) {
                data.email = params.term;
                data.page = params.page;
                return data;
            },
            processResults: function (data, params) {
                params.page = params.page || 1;
                return {
                    results: data.data,
                    pagination: {
                        more: (params.page * 30) < data.total_count
                    }
                };
            }
        },
        placeholder: placeHolder,
        minimumInputLength: 1,
        templateResult: templateDesignEvent,
        templateSelection: onTemplateSelectEvent,
    });
}

/**
 * @param {string} [url] - Ajax URL that will be called when the Confirm button will be clicked
 * @param {string} [method] - GET / POST / PUT / PATCH / DELETE
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.title] - Are you sure
 * @param {string} [options.text] - You won't be able to revert this
 * @param {string} [options.icon] - 'warning'
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - '#3085d6'
 * @param {string} [options.cancelButtonColor] - '#d33'
 * @param {string} [options.confirmButtonText] - Confirm
 * @param {string} [options.cancelButtonText] - Cancel
 * @param {function} [options.successCallBack] - function()
 * @param {function} [options.errorCallBack] - function()
 * @param {function} [options.data] - FormData Object / Object
 */
function showSweetAlertConfirmPopup(url, method, options = {}) {
    let opt = {
        title: trans("Are you sure"),
        text: trans("You wont be able to revert this"),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: trans("Confirm"),
        cancelButtonText: trans("Cancel"),
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }

    Swal.fire({
        title: opt.title,
        text: opt.text,
        icon: opt.icon,
        showCancelButton: opt.showCancelButton,
        confirmButtonColor: opt.confirmButtonColor,
        cancelButtonColor: opt.cancelButtonColor,
        confirmButtonText: opt.confirmButtonText,
        cancelButtonText: opt.cancelButtonText
    }).then((result) => {
        if (result.isConfirmed) {
            function successCallback(response) {
                // Use SweetAlert toast for success messages
                if (response && response.message) {
                    showSwalSuccessToast(null, response.message);
                }
                opt.successCallBack(response);
            }

            function errorCallback(response) {
                // Use SweetAlert toast for error messages
                if (response && response.message) {
                    showSwalErrorToast(null, response.message);
                }
                opt.errorCallBack(response);
            }

            ajaxRequest(method, url, options.data || null, null, successCallback, errorCallback);
        }
    })
}

/**
 *
 * @param {string} [url] - Ajax URL that will be called when the Delete will be successfully
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.text] - "Are you sure?"
 * @param {string} [options.title] - "You won't be able to revert this!"
 * @param {string} [options.icon] - "warning"
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - "#3085d6"
 * @param {string} [options.cancelButtonColor] - "#d33"
 * @param {string} [options.confirmButtonText] - "Yes, delete it!"
 * @param {string} [options.cancelButtonText] - "Cancel"
 * @param {function} [options.successCallBack] - function()
 * @param {function} [options.errorCallBack] - function()
 * @param {function} [options.data] - FormData Object / Object
 */
function showDeletePopupModal(url, options = {}) {
    // To Preserve OLD
    let opt = {
        title: trans("Are you sure"),
        text: trans("You wont be able to revert this"),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: trans("Yes Delete"),
        cancelButtonText: trans('Cancel'),
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }
    showSweetAlertConfirmPopup(url, 'DELETE', opt);
}


/**
 *
 * @param {string} [url] - Ajax URL that will be called when the Delete will be successfully
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.text] - "Are you sure?"
 * @param {string} [options.title] - "You won't be able to revert this!"
 * @param {string} [options.icon] - "warning"
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - "#3085d6"
 * @param {string} [options.cancelButtonColor] - "#d33"
 * @param {string} [options.confirmButtonText] - "Yes, delete it!"
 * @param {string} [options.cancelButtonText] - "Cancel"
 * @param {function} [options.successCallBack]
 * @param {function} [options.errorCallBack]
 */
function showRestorePopupModal(url, options = {}) {
    // To Preserve OLD
    let opt = {
        title: trans("Are you sure"),
        text: trans("You wont be able to revert this"),
        icon: 'success',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: trans('Yes Restore it'),
        cancelButtonText: trans('Cancel'),
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }
    showSweetAlertConfirmPopup(url, 'PUT', opt);
}

/**
 *
 * @param {string} [url] - Ajax URL that will be called when the Delete will be successfully
 * @param {Object} [options] - Options to Configure SweetAlert
 * @param {string} [options.text] - "Are you sure?"
 * @param {string} [options.title] - "You won't be able to revert this!"
 * @param {string} [options.icon] - "warning"
 * @param {boolean} [options.showCancelButton] - true
 * @param {string} [options.confirmButtonColor] - "#3085d6"
 * @param {string} [options.cancelButtonColor] - "#d33"
 * @param {string} [options.confirmButtonText] - "Yes, delete it!"
 * @param {string} [options.cancelButtonText] - "Cancel"
 * @param {function} [options.successCallBack]
 * @param {function} [options.errorCallBack]
 */
function showPermanentlyDeletePopupModal(url, options = {}) {
    // To Preserve OLD
    let opt = {
        title: trans("Are you sure"),
        text: trans("You are about to Delete this data"),
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: trans("Yes Delete Permanently"),
        cancelButtonText: trans('Cancel'),
        successCallBack: function () {
        },
        errorCallBack: function (response) {
        },
        ...options,
    }
    showSweetAlertConfirmPopup(url, 'DELETE', opt);
}

/**
 * Calculate Discounted price based on the Price and Discount(%)
 * @param price
 * @param discount
 * @returns {string}
 */
function calculateDiscountedAmount(price, discount) {
    let finalPrice = price - (price * discount / 100);
    return finalPrice.toFixed(2);
}

/**
 * Calculate Discount(%)
 * @param price
 * @param discountedPrice
 * @returns {string}
 */
function calculateDiscount(price, discountedPrice) {
    let finalDiscount = 100 - discountedPrice * 100 / price;
    return finalDiscount.toFixed(2);
}

function generateSlug(text){
    if (!text) return '';
    
    // Gujarati to English transliteration mapping
    const transliterations = {
        'વેબ': 'web',
        'ડેવલપમેન્ટ': 'development',
        'વેબ ડેવલપમેન્ટ': 'web-development',
        ' ': '-',
        // Add more common translations as needed
    };
    
    // Apply transliterations
    let translatedText = text;
    for (const [gujarati, english] of Object.entries(transliterations)) {
        translatedText = translatedText.replace(new RegExp(gujarati.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), english);
    }
    
    // Generate slug from translated text
    let slug = translatedText
        .toLowerCase()
        .trim()
        .replace(/\s+/g, '-')           // Replace spaces with hyphens
        .replace(/[^\w\-]+/g, '')      // Remove non-word characters except hyphens
        .replace(/\-\-+/g, '-')        // Replace multiple hyphens with single hyphen
        .replace(/^-+/, '')            // Remove leading hyphens
        .replace(/-+$/, '');           // Remove trailing hyphens
    
    // If slug is still empty (all Unicode), create a fallback
    if (!slug || slug === '-') {
        // Use a simple hash-like approach or transliterate common patterns
        slug = 'category-' + Math.random().toString(36).substring(2, 10);
    }
    
    return slug;
}


function listingFormatter(list) {
    let html = '';
    if(list && list.length > 0){
        html = '<ul>';
        $.each(list, function (index, item) {
            html += "<li>" + item.title + "</li>";
        });
        html += '</ul>';
    } else {
        html = '<div class="text-center">-</div>';
    }
    return html;
}
