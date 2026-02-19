$('#category_name').on('input', function () {
    let slug = generateSlug($(this).val())
    $('#category_slug').val(slug);
});

$(document).on('click', '.toggle-subcategories', function() {

    let categoryId = $(this).data('id');
    let categoryRow = $(this).closest('tr');
    let currentLevel = categoryRow.data('level') || 0;

    if (currentLevel >= 3) {
        // Toastify({
        //     text: "You can create subcategories up to only 3 levels.",
        //     duration: 3000,
        //     gravity: "top", // `top` or `bottom`
        //     position: "right", // `left`, `center` or `right`
        //     backgroundColor: "#ff6b6b",
        //     stopOnFocus: true,
        // }).showToast();
        return;
    }

    if ($(this).hasClass('expanded')) {
        $(this).removeClass('expanded').html('<i class="fa fa-plus"></i>');
        categoryRow.nextAll().filter(function() {
            return $(this).data('level') > currentLevel;
        }).remove();
    } else {
        $(this).addClass('expanded').html('<i class="fa fa-minus"></i>');
        let url = `/category/${categoryId}/subcategories`;

        ajaxRequest('GET', url, null, null, function(data) {
            if (!Array.isArray(data)) {
                console.error('Expected an array but got:', data);
                return;
            }
            let nextLevel = currentLevel + 1;
            let subcategoryRows = '';
            data.forEach(subcategory => {
                subcategoryRows += `
                    <tr class="subcategory-row" data-level="${nextLevel}">
                        <td class="text-center">${subcategory.id}</td>
                        <td>${subCategoryNameFormatter(subcategory.name , subcategory ,nextLevel)}</td>
                        <td class="text-center">${imageFormatter(subcategory.image, subcategory.name)}</td>
                        <td class="text-center">${subCategoryFormatter(subcategory.subcategories_count,subcategory)}</td>
                        <td class="text-center">${statusFormatter(subcategory.status, subcategory)}</td>
                        <td class="">${subcategory.operate}</td>
                    </tr>
                `;
            });
            categoryRow.after(subcategoryRows);
        });
    }
});


$(".checkbox-toggle-switch").on('change', function () {
    let inputValue = $(this).is(':checked') ? 1 : 0;
    $(this).siblings(".checkbox-toggle-switch-input").val(inputValue);
});

$('.toggle-button').on('click', function (e) {
    e.preventDefault();
    $(this).closest('.category-header').next('.subcategories').slideToggle();
});

let subCategoryCount1 = $('#sub_category_count').val();

for (let i = 1; i <= subCategoryCount1; i++) {
    $('.child_category_list' + i).hide();
    $('#sub_category' + i).change(function () {
        $('#child_category' + i).prop("checked", $(this).is(":checked"));
    });

    $('#category_arrow' + i).on('click', function () {
        $('.child_category_list' + i).toggle();
    });
}
//magnific popup
$(document).on('click', '.image-popup-no-margins', function () {
    $(this).magnificPopup({
        type: 'image',
        closeOnContentClick: true,
        closeBtnInside: false,
        fixedContentPos: true,
        image: {
            verticalFit: true
        },
        zoom: {
            enabled: true,
            duration: 300 // don't forget to change the duration also in CSS
        },
        gallery: {
            enabled: true
        },
    }).magnificPopup('open');
    return false;
});

$('.image').on('change', function () {
    const allowedExtensions = /(\.jpg|\.jpeg|\.png|\.gif|\.svg)$/i;
    const fileInput = this;
    const [file] = fileInput.files;
    
    // Clear previous errors first
    $(this).closest('.cs_field_img').siblings('.img_error').text('');
    
    if (!file) {
        return; // No file selected
    }

    if (!allowedExtensions.exec(file.name)) {
        $(this).closest('.cs_field_img').siblings('.img_error').text('Invalid file type. Please choose an image file (jpg, jpeg, png, svg).');
        fileInput.value = '';
        return;
    }

    const maxFileSize = 7 * 1024 * 1024; // 7MB
    if (file.size > maxFileSize) {
        $(this).closest('.cs_field_img').siblings('.img_error').text('File size exceeds the maximum allowed size (7MB).');
        fileInput.value = '';
        return;
    }
    
    // If we reach here, file is valid - show preview
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $(fileInput).siblings('.preview-image').attr('src', e.target.result);
        };
        reader.readAsDataURL(file);
    }
});

$('.img_input').on('click', function () {
    $(this).siblings('.image').click();
});


// Function to make remove button accessible on the basis of Option Section Length
// For radio and dropdown types, ensure minimum 2 options
let toggleAccessOfDeleteButtons = () => {
    let $options = $('.default-values-section [data-repeater-item]');
    let minOptions = 2; // Minimum 2 options required for radio and dropdown
    
    if ($options.length <= minOptions) {
        $('.remove-default-option').attr('disabled', true);
    } else {
        $('.remove-default-option').removeAttr('disabled');
    }
}

// Function to make remove button accessible on the basis of Option Section Length
// For radio and dropdown types, ensure minimum 2 options
let editToggleAccessOfDeleteButtons = () => {
    let $editOptions = $('.edit-default-values-section [data-repeater-item]');
    let minOptions = 2; // Minimum 2 options required for radio and dropdown
    
    if ($editOptions.length <= minOptions) {
        $('.remove-edit-default-option').attr('disabled', true);
    } else {
        $('.remove-edit-default-option').removeAttr('disabled');
    }
}

$('.type-field').on('change', function (e) {
    e.preventDefault();

    const inputValue = $(this).val();
    const optionSection = $('.default-values-section');

    // Show/hide the "default-values-section" based on the selected value using a switch statement
    switch (inputValue) {
        case 'dropdown':
        case 'radio':
        case 'checkbox':
            optionSection.show(500).find('input').attr('required', true).removeAttr('data-parsley-required');
            // For radio and dropdown, ensure at least 2 options exist
            if (inputValue === 'dropdown' || inputValue === 'radio') {
                setTimeout(function() {
                    let $options = $('.default-values-section [data-repeater-item]');
                    // If less than 2 options, add more
                    while ($options.length < 2) {
                        $('.add-new-option').click();
                        $options = $('.default-values-section [data-repeater-item]');
                    }
                    toggleAccessOfDeleteButtons();
                }, 600);
            } else {
                toggleAccessOfDeleteButtons();
            }
            break;
        default:
            // Remove required and parsley validation from hidden fields
            optionSection.hide(500).find('input').removeAttr('required').removeAttr('data-parsley-required');
            // Also remove parsley error classes
            optionSection.find('input').removeClass('parsley-error');
            optionSection.find('.parsley-errors-list').remove();
            break;
    }

});

$('.edit-type-field').on('change', function (e) {
    e.preventDefault();

    const inputValue = $(this).val();
    const optionSection = $('.edit-default-values-section');

    // Show/hide the "edit-default-values-section" based on the selected value using a switch statement
    switch (inputValue) {
        case 'dropdown':
        case 'radio':
        case 'checkbox':
            optionSection.show(500).find('input').attr('required', true);
            $('.extra-edit-option-section').remove();
            // To Add Second Option
            $('.add-new-edit-option').click();
            break;
        default:
            optionSection.hide(500).find('input').removeAttr('required');
            break;
    }

});

// Repeater On Default Values section's Option Section
var defaultValuesRepeater = $('.default-values-section').repeater({
    show: function () {
        let optionNumber = parseInt($('.option-section:nth-last-child(2)').find('.option-number').text()) + 1;

        if (!optionNumber) {
            optionNumber = 1;
        }

        $(this).find('.option-number').text(optionNumber);

        $(this).slideDown();

        toggleAccessOfDeleteButtons();

    },
    hide: function (deleteElement) {
        // Prevent deletion if only 2 options remain (minimum required for radio/dropdown)
        let $allOptions = $('.default-values-section [data-repeater-item]');
        if ($allOptions.length <= 2) {
            if (typeof showErrorToast === 'function') {
                showErrorToast('At least 2 options are required for radio button and dropdown types.');
            } else {
                alert('At least 2 options are required for radio button and dropdown types.');
            }
            return false; // Prevent deletion
        }
        
        $(this).slideUp(deleteElement);
        $(function () {
            toggleAccessOfDeleteButtons();
        });
    }
});

// Repeater On Default Values section's Option Section
var editDefaultValuesRepeater = $('.edit-default-values-section').repeater({
    show: function () {
        let optionNumber = parseInt($('.edit-option-section:nth-last-child(2)').find('.edit-option-number').text()) + 1;

        if (!optionNumber) {
            optionNumber = 1;
        }

        $(this).find('.edit-option-number').text(optionNumber);

        $(this).slideDown();
        $(this).addClass('extra-edit-option-section');

        editToggleAccessOfDeleteButtons();

    },
    hide: function (deleteElement) {
        // Prevent deletion if only 2 options remain (minimum required for radio/dropdown)
        let $allOptions = $('.edit-default-values-section [data-repeater-item]');
        if ($allOptions.length <= 2) {
            if (typeof showErrorToast === 'function') {
                showErrorToast('At least 2 options are required for radio button and dropdown types.');
            } else {
                alert('At least 2 options are required for radio button and dropdown types.');
            }
            return false; // Prevent deletion
        }

        let default_value_id = $(this).find('.default_value_id').val();

        if (default_value_id) {
            let url = baseurl + 'custom-form/default-value/' + default_value_id;

            if (typeof showDeletePopupModal !== 'function') {
                // Check again before allowing deletion
                if ($allOptions.length <= 2) {
                    if (typeof showErrorToast === 'function') {
                        showErrorToast('At least 2 options are required for radio button and dropdown types.');
                    } else {
                        alert('At least 2 options are required for radio button and dropdown types.');
                    }
                    return false;
                }
                $(this).slideUp(deleteElement);
                editToggleAccessOfDeleteButtons();
                return;
            }

            showDeletePopupModal(url, {
                successCallBack: function () {
                    $(this).slideUp(deleteElement);
                    editToggleAccessOfDeleteButtons();
                }.bind(this)
            });
        } else {
            // Check again before allowing deletion
            if ($allOptions.length <= 2) {
                if (typeof showErrorToast === 'function') {
                    showErrorToast('At least 2 options are required for radio button and dropdown types.');
                } else {
                    alert('At least 2 options are required for radio button and dropdown types.');
                }
                return false;
            }
            $(this).slideUp(deleteElement);
            editToggleAccessOfDeleteButtons();
        }
    }




});

$(function () {
    // Initialize sortable with mobile/touch support
    $(".sortable").sortable({
        revert: true,
        items: "li",
        tolerance: "pointer",
        cursor: "move",
        cursorAt: { top: 20, left: 20 },
        // Enable touch support for mobile devices
        start: function(event, ui) {
            ui.placeholder.height(ui.item.height());
            ui.placeholder.css('visibility', 'visible');
        },
        // Better helper for touch devices
        helper: function(e, item) {
            if (!item.hasClass('ui-sortable-helper')) {
                item.addClass('ui-sortable-helper');
            }
            var helper = item.clone().appendTo('body');
            helper.css({
                width: item.outerWidth(),
                opacity: 0.8,
                position: 'absolute',
                zIndex: 1000,
                pointerEvents: 'none'
            });
            return helper;
        }
    });
    
    // Enhanced touch support for mobile devices - jQuery UI Touch Punch alternative
    if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
        var touchData = {
            startY: 0,
            startX: 0,
            isDragging: false,
            element: null,
            startTime: 0
        };
        
        // Touch event handlers for mobile devices
        $(document).on('touchstart', '.sortable li', function(e) {
            if (e.touches.length === 1) {
                var touch = e.originalEvent.touches[0];
                touchData.startY = touch.clientY;
                touchData.startX = touch.clientX;
                touchData.element = $(this);
                touchData.isDragging = false;
                touchData.startTime = Date.now();
            }
        });
        
        $(document).on('touchmove', '.sortable li', function(e) {
            if (e.touches.length === 1 && touchData.element && touchData.element[0] === this) {
                var touch = e.originalEvent.touches[0];
                var deltaY = Math.abs(touch.clientY - touchData.startY);
                var deltaX = Math.abs(touch.clientX - touchData.startX);
                
                // If moved more than 5px, start dragging
                if ((deltaY > 5 || deltaX > 5) && !touchData.isDragging) {
                    touchData.isDragging = true;
                    
                    // Create synthetic mouse events for jQuery UI sortable
                    var syntheticEvent = document.createEvent('MouseEvents');
                    syntheticEvent.initMouseEvent('mousedown', true, true, window, 1,
                        touch.screenX, touch.screenY, touch.clientX, touch.clientY,
                        false, false, false, false, 0, null);
                    this.dispatchEvent(syntheticEvent);
                }
                
                if (touchData.isDragging) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Create synthetic mousemove event
                    var moveEvent = document.createEvent('MouseEvents');
                    moveEvent.initMouseEvent('mousemove', true, true, window, 1,
                        touch.screenX, touch.screenY, touch.clientX, touch.clientY,
                        false, false, false, false, 0, null);
                    document.dispatchEvent(moveEvent);
                }
            }
        });
        
        $(document).on('touchend touchcancel', '.sortable li', function(e) {
            if (touchData.isDragging) {
                // Create synthetic mouseup event
                var upEvent = document.createEvent('MouseEvents');
                upEvent.initMouseEvent('mouseup', true, true, window, 1,
                    0, 0, 0, 0, false, false, false, false, 0, null);
                document.dispatchEvent(upEvent);
            }
            touchData.isDragging = false;
            touchData.element = null;
        });
        
        // Also add CSS to make items more touch-friendly
        $(".sortable li").css({
            'touch-action': 'none',
            '-webkit-touch-callout': 'none',
            '-webkit-user-select': 'none',
            'user-select': 'none'
        });
    }
    
    // $("#draggable").draggable({
    //     connectToSortable: "#sortable",
    //     helper: "clone",
    //     revert: "invalid"
    // });
    $("ul, li").disableSelection();
});

$("#update-team-member-rank-form").on("submit", function (e) {
    e.preventDefault();

    let sortableElement = $(".sortable");
    
    // Check if sortable is initialized
    if (!sortableElement.length || !sortableElement.data('ui-sortable')) {
        console.error("Sortable is not initialized");
        showErrorToast("Sortable functionality is not initialized. Please refresh the page.");
        return;
    }
    
    let userOrder = sortableElement.sortable("toArray"); // Get the new order of items
    
    // Debug: Check if sortable is initialized and order is captured
    console.log("Category order:", userOrder);
    
    if (!userOrder || userOrder.length === 0) {
        console.error("Sortable order is empty. Make sure sortable is initialized.");
        showErrorToast("Unable to get category order. Please refresh the page and try again.");
        return;
    }
    
    let formElement = $(this);
    let submitButtonElement = $(this).find(":submit");
    let url = $(this).attr("action");

    // Create FormData from form (includes CSRF token)
    let data = new FormData(this);
    data.append("order", JSON.stringify(userOrder)); // Append order as JSON

    function successCallback(response) {
        setTimeout(function () {
            window.location.reload();
        }, 1000);
    }

    // Use ajaxRequest directly with FormData
    // processData: false is required for FormData
    ajaxRequest(
        "POST",
        url,
        data,
        function() {
            submitButtonElement.attr('disabled', true);
        },
        function(response) {
            if (!response.error || response.success === true) {
                showSuccessToast(response.message || "Order Updated Successfully");
                successCallback(response);
            } else {
                showErrorToast(response.message || "Failed to update order");
            }
            submitButtonElement.attr('disabled', false);
        },
        function(response) {
            showErrorToast(response.message || "Failed to update order");
            submitButtonElement.attr('disabled', false);
        },
        function() {
            submitButtonElement.attr('disabled', false);
        },
        false // processData = false for FormData
    );
});
// Change the order of Form fields Data
// $('#change-order-form-field').click(async function () {
//     const ids = await $('#table_list').bootstrapTable('getData').map(function (row) {
//         return row.id;
//     });
//     $.ajax({
//         type: "PUT",
//         url: window.baseurl + "custom-form-fields/update-rank",
//         data: {
//             ids: ids
//         },
//         dataType: "json",
//         success: function (data) {
//             $('#table_list').bootstrapTable('refresh');
//             if (!data.error) {
//                 showSuccessToast(data.message);
//                 setTimeout(() => {
//                     window.location.reload();
//                 }, 1000);
//             } else {
//                 showErrorToast(data.message);
//             }
//         }
//     });
// })

// Change the order of Form fields Data
$('#change-order-form-field').click(async function () {
    const ids = await $('#table_list').bootstrapTable('getData').map(function (row) {
        return row.id;
    });
    
    // Determine the correct URL based on current page
    let updateUrl = window.baseurl + "custom-form-fields/update-rank";
    if (window.location.pathname.includes('/sliders')) {
        updateUrl = window.baseurl + "sliders/update-rank";
    } else if (window.location.pathname.includes('/feature-sections')) {
        updateUrl = window.baseurl + "feature-sections/update-rank";
    } else if (window.location.pathname.includes('/course-chapters') && window.location.pathname.includes('/curriculum')) {
        // Extract chapter ID from URL for curriculum reordering
        const pathParts = window.location.pathname.split('/');
        const chapterIndex = pathParts.indexOf('course-chapters');
        if (chapterIndex !== -1 && pathParts[chapterIndex + 1]) {
            const chapterId = pathParts[chapterIndex + 1];
            updateUrl = window.baseurl + "course-chapters/" + chapterId + "/curriculum/update-rank";
        }
    }
    
    $.ajax({
        type: "PUT",
        url: updateUrl,
        data: {
            ids: ids,
        },
        dataType: "json",
        success: function (data) {
            $('#table_list').bootstrapTable('refresh');
            if (!data.error) {
                showSuccessToast(data.message);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showErrorToast(data.message);
            }
        }
    });
})

// Change the order of Featured Section Data
$('#change-order-feature-section').click(async function () {
    const ids = await $('#table_list').bootstrapTable('getData').map(function (row) {
        return row.id;
    });
    $.ajax({
        type: "PUT",
        url: window.baseurl + "feature-sections/update-rank",
        data: {
            ids: ids,
        },
        dataType: "json",
        success: function (data) {
            console.log(data);
            $('#table_list').bootstrapTable('refresh');
            if (!data.error) {
                showSuccessToast(data.message);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showErrorToast(data.message);
            }
        }
    });
})


// Change the order of Helpdesk Groups Data
$('#change-order-helpdesk-groups').click(async function () {
    const ids = await $('#table_list').bootstrapTable('getData').map(function (row) {
        return row.id;
    });
    $.ajax({
        type: "PUT",
        url: window.baseurl + "helpdesk/groups/update-rank",
        data: {
            ids: ids,
        },
        dataType: "json",
        success: function (data) {
            console.log(data);
            $('#table_list').bootstrapTable('refresh');
            if (!data.error) {
                showSuccessToast(data.message);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showErrorToast(data.message);
            }
        }
    });
})


$('.course-chapter-title').on('input', function () {
    let slug = generateSlug($(this).val())
    $('.course-chapter-slug').val(slug);
});

$('.edit-course-chapter-title').on('input', function () {
    let slug = generateSlug($(this).val())
    $('.edit-course-chapter-slug').val(slug);
});

var courseLearningsRepeater = $('.course-learnings-section').repeater({
    initEmpty: true,
    show: function () {
        // Find all existing requirement numbers and extract their numeric values
        let existingNumbers = $('.learning-number').map(function () {
            let num = parseInt($(this).text());
            return isNaN(num) ? 0 : num;
        }).get();

        let maxOptionNumber = existingNumbers.length ? Math.max(...existingNumbers) : 0;
        let newOptionNumber = maxOptionNumber + 1;

        $(this).find('.learning-number').text(newOptionNumber);

        // Slide down the learning section
        $(this).slideDown();
    },
    hide: function (deleteElement) {
        let idExists = $(this).find('.id').val();
        if(idExists){
            let url = baseurl + 'courses/' + idExists + '/learnings';
            showDeletePopupModal(url, {
                successCallBack: function () {
                    window.location.reload();
                }, errorCallBack: function (response) {
                    showErrorToast(response.message);
                }
            })
        }else{
            // Slide up the learning section
            $(this).slideUp(deleteElement);
        }
    }
});

var courseRequirementsRepeater = $('.course-requirements-section').repeater({
    initEmpty: true,
    show: function () {
        // Find all existing requirement numbers and extract their numeric values
        let existingNumbers = $('.requirement-number').map(function () {
            let num = parseInt($(this).text());
            return isNaN(num) ? 0 : num;
        }).get();

        let maxOptionNumber = existingNumbers.length ? Math.max(...existingNumbers) : 0;
        let newOptionNumber = maxOptionNumber + 1;

        // Update the label number in the new item
        $(this).find('.requirement-number').text(newOptionNumber);

        // Slide down the requirement section
        $(this).slideDown();
    },
    hide: function (deleteElement) {
        let idExists = $(this).find('.id').val();
        if(idExists){
            let url = baseurl + 'courses/' + idExists + '/requirements';
            showDeletePopupModal(url, {
                successCallBack: function () {
                    window.location.reload();
                }, errorCallBack: function (response) {
                    showErrorToast(response.message);
                }
            })
        }else{
            // Slide up the learning section
            $(this).slideUp(deleteElement);
        }
    }
});

$(document).ready(function () {
    $('#course_tags').select2({
        tags: true,
        tokenSeparators: [','],
        placeholder: trans("Select or type tags"),
        createTag: function(params) {
            if(params.term != ""){
                return {
                    id: 'new__' + params.term,
                    text: params.term,
                    newTag: true
                };
            }
        }
    });

    $(document).find('.tags-without-new-tag').select2({
        tags: true,
        tokenSeparators: [','],
        placeholder: "Select Options",
    });
});


/** Get Child Select Options */
$(document).ready(function () {
    $('.child-select-options').find('option').hide();
    $('.child-select-options').find('option[data-value="data-not-found"]').attr('selected',true).attr('disabled',true).show();
});

$('.parent-select-options').on('change', function(){
    let parentId = $(this).val();
    let childElement = $('.child-select-options');
    $(childElement).find("option").hide();
    
    let selectedOption = $(this).val();
    if(selectedOption == ''){
        $(childElement).find("option").hide();
        $(childElement).find('option[data-value="data-not-found"]').attr('disabled',true).attr('selected',true).show();
    }else{
        $(childElement).find('option[data-value="data-not-found"]').hide();
        $(childElement).find('option[value=""]').attr('selected',true).show();
        $(childElement).find('option[data-parent-id="'+parentId+'"]').show();
    }
});
/********************************************************** */

$('#course-chapter-type').on('change', function(){
    let chapterType = $(this).val();
    switch (chapterType) {
        case 'document':
            $('.document-container').show(500);  // Show
            $('.lecture-container').hide();  // Hide
            $('.quiz-container').hide(); // Hide
            $('.assignment-container').hide(); // Hide
            $(document).find('.document-type:checked').trigger('change'); // Trigger document-type change to set correct initial state
            $('.resource-toggle-section').removeAttr('checked').hide(); // Hide Resource Toggle Section
            break;
        case 'lecture':
            $('.lecture-container').show(500);  // Show
            $('.document-container').hide();  // Hide
            $('.quiz-container').hide(); // Hide
            $('.assignment-container').hide(); // Hide
            $(document).find('.lecture-type:checked').trigger('change'); // Trigger video-type change to set correct initial state
            $('.resource-toggle-section').show(); // Show Resource Toggle Section
            break;
        case 'quiz':
            $('.quiz-container').show(500); // Show
            $('.lecture-container').hide(); // Hide
            $('.document-container').hide(); // Hide
            $('.assignment-container').hide(); // Hide
            $('.resource-toggle-section').removeAttr('checked').hide();  // Show Resource Toggle Section
            break;
        case 'assignment':
            $('.assignment-container').show(500); // Show
            $('.lecture-container').hide(); // Hide
            $('.document-container').hide(); // Hide
            $('.quiz-container').hide(); // Hide
            $('.resource-toggle-section').removeAttr('checked').hide();  // Show Resource Toggle Section
            break;
        default:
            $('.lecture-container').hide();  // Hide 
            $('.document-container').hide(); // Hide
            $('.quiz-container').hide(); // Hide
            $('.assignment-container').hide(); // Hide
            break;
    }
});
/*************************************************************** */
// Make video type handlers more specific to avoid conflicts
$('.lecture-type').on('change', function(){
    let videoType = $(this).val();
    switch (videoType) {
        case 'url':
            $('.lecture-file-input').removeAttr('required');
            $('.lecture-file').hide();
            $('.lecture-youtube-url-input').removeAttr('required');
            $('.lecture-youtube-url').hide();
            $('.lecture-url').show();
            $('.lecture-url-input').attr('required', true);
            break;
        case 'file':
            $('.lecture-youtube-url-input').removeAttr('required');
            $('.lecture-youtube-url').hide();
            $('.lecture-url-input').removeAttr('required');
            $('.lecture-url').hide();
            $('.lecture-file').show();
            $('.lecture-file-input').attr('required', true);
            break;
        case 'youtube_url':
            $('.lecture-url-input').removeAttr('required');
            $('.lecture-url').hide();
            $('.lecture-file-input').removeAttr('required');
            $('.lecture-file').hide();
            $('.lecture-youtube-url').show();
            $('.lecture-youtube-url-input').attr('required', true);
            break;
        default:
            $('.lecture-file').hide();
            $('.lecture-file-input').removeAttr('required');
            $('.lecture-url').hide();
            $('.lecture-url-input').removeAttr('required');
            $('.lecture-youtube-url').hide();
            $('.lecture-youtube-url-input').removeAttr('required');
            break;
    }
});

/*************************************************************** */
// Document type change event
$('.document-type').on('change', function(){
    let documentType = $(this).val();
    
    switch (documentType) {
        case 'url':
            $('.document-file-input').removeAttr('required');
            $('.document-file').hide();
            $('.document-url').show();
            $('.document-url-input').attr('required', true);
            break;
        case 'file':
            $('.document-url').hide();
            $('.document-url-input').removeAttr('required');
            $('.document-file').show();
            $('.document-file-input').attr('required', true);
            break;
        default:
            $('.document-file').hide();
            $('.document-file-input').removeAttr('required');
            $('.document-url').hide();
            $('.document-url-input').removeAttr('required');
            break;
    }
});

/*************************************************************** */
// Quiz Options Repeater
$('.quiz-questions-section').repeater({
    initEmpty: false,
    show: function () {
        // Ensure new questions have 2 default options
        var newQuestion = $(this);
        setTimeout(function() {
            updateQuestionLabels(newQuestion);
            updateOptionLabels(newQuestion.find('.quiz-options-section'));
            manageOptionButtons(newQuestion.find('.quiz-options-section'));
            manageAnswerSwitch(newQuestion.find('.quiz-options-section'));
        }, 100);
        $(this).slideDown();
        checkQuestionSectionLength();
    },
    hide: function (deleteElement) {
        $(this).slideUp(function(){
            deleteElement();
            checkQuestionSectionLength();
        });
    },
    repeaters: [{
        selector: '.quiz-options-section',
        initEmpty: false,
        show: function () {
            var currentElement = $(this);
            var optionsSection = currentElement.closest('.quiz-options-section');
            
            // Ensure answer-switch class is added to newly created option
            currentElement.find('.custom-toggle-switch').addClass('answer-switch');
            currentElement.find('.custom-toggle-switch-value').addClass('is-answer');
            
            updateOptionLabels(optionsSection);
            manageOptionButtons(optionsSection);
            manageAnswerSwitch(optionsSection);
            $(this).slideDown();
        },
        hide: function (deleteElement) {
            var optionsSection = $(this).closest('.quiz-options-section');
            var currentElement = $(this);
            $(this).slideUp(function() {
                deleteElement();
                updateOptionLabels(optionsSection);
                manageOptionButtons(optionsSection);
                manageAnswerSwitch(optionsSection);
            });
        }
    }]
});

// Helper functionsAdd commentMore actions
function updateQuestionLabels(questionSection) {
    questionSection.find('[data-repeater-item]').each(function(index) {
        var questionNumber = index + 1
        $(this).find('.quiz-question-label').text('Question ' + questionNumber);
        $(this).find('.quiz-question-input').attr('placeholder', 'Question ' + questionNumber);
        // Add ID and For Attribute unique
        $(this).find('.quiz-question-label').attr('for', 'question-' + questionNumber);
        $(this).find('.quiz-question-input').attr('id', 'question-' + questionNumber);
    });
}
// Update Option Labels
function updateOptionLabels(optionsSection) {
    optionsSection.find('[data-repeater-item]').each(function(index) {
        var optionNumber = index + 1
        $(this).find('.option-label').text('Option ' + optionNumber);
        $(this).find('.option-input').attr('placeholder', 'Option ' + optionNumber);
        // Add ID and For Attribute unique
        $(this).find('.option-label').attr('for', 'option-' + optionNumber);
        $(this).find('.option-input').attr('id', 'option-' + optionNumber);
    });
}
// Check Question Section LengthAdd commentMore actions
function checkQuestionSectionLength(){
    if($('.quiz-question-input-section').length >= 2){
        $('.remove-question').removeAttr('disabled');
    }else{
        $('.remove-question').attr('disabled', true);
    }
}
// Manage Option Buttons
function manageOptionButtons(optionsSection) {
    var $items = optionsSection.find('[data-repeater-item]'); 
    
    // Always ensure minimum 2 options
    if ($items.length <= 2) {
        $items.find('[data-repeater-delete]').attr('disabled', true);
    }else{
        $items.find('[data-repeater-delete]').removeAttr('disabled');
    }

    // Always ensure maximum 6 options
    if($items.length >= 6){
        optionsSection.find('.add-new-option').attr('disabled', true);
    }else{
        optionsSection.find('.add-new-option').removeAttr('disabled');
    }
}

// Initialize on page load
$(document).ready(function() {
    $('.quiz-options-section').each(function() {
        updateOptionLabels($(this));
        manageOptionButtons($(this));
    });
});

$(document).on('change', '.answer-switch', function(){
    let $currentSwitch = $(this);
    let isChecked = $currentSwitch.is(':checked');
    
    // Find the question section (parent quiz-question-input-section)
    let $questionSection = $currentSwitch.closest('.quiz-question-input-section');
    
    if (isChecked) {
        // Uncheck all other answer switches in the same question
        $questionSection.find('.answer-switch').not($currentSwitch).prop('checked', false);
        $questionSection.find('.answer-switch').not($currentSwitch).each(function() {
            $(this).closest('.quiz-option-input-section').find('.is-answer').val(0);
        });
        
        // Set current switch value to 1
        $currentSwitch.closest('.quiz-option-input-section').find('.is-answer').val(1);
    } else {
        // If unchecking, set value to 0
        $currentSwitch.closest('.quiz-option-input-section').find('.is-answer').val(0);
    }
});

function manageAnswerSwitch(optionsSection) {
    // Find the question section
    let $questionSection = optionsSection.closest('.quiz-question-input-section');
    if ($questionSection.length === 0) {
        $questionSection = optionsSection.closest('[data-repeater-item]').closest('.quiz-question-input-section');
    }
    
    // Find checked answer switch
    let $checkedSwitch = $questionSection.find('.answer-switch:checked').first();
    
    if ($checkedSwitch.length > 0) {
        // Uncheck all others and set their values to 0
        $questionSection.find('.answer-switch').not($checkedSwitch).prop('checked', false);
        $questionSection.find('.answer-switch').not($checkedSwitch).each(function() {
            $(this).closest('.quiz-option-input-section').find('.is-answer').val(0);
        });
        
        // Set checked switch value to 1
        $checkedSwitch.closest('.quiz-option-input-section').find('.is-answer').val(1);
    } else {
        // No answer selected, set all to 0
        $questionSection.find('.is-answer').val(0);
    }
}


/*************************************************************** */

// Resource Toggle
$('#resource-toggle').on('change', function(){
    let resourceToggle = $(this).is(':checked') ? 1 : 0;
    let resourceContainer = $('.resource-container');
    if(resourceToggle == 1){
        resourceContainer.show();
    }else{
        resourceContainer.hide();  
    }
});

$(document).on('change', '.course-chapter-resource-type', function(){
    let resourceTypeElement = $(this);
    let resourceType = resourceTypeElement.val();
    
    if(resourceType == 'url'){
        resourceTypeElement.parent().parent().find('.resource-url').attr('required', true).show();
        resourceTypeElement.parent().parent().find('.resource-file').removeAttr('required').hide();
    }else if(resourceType == 'file'){
        resourceTypeElement.parent().parent().find('.resource-url').removeAttr('required').hide();
        resourceTypeElement.parent().parent().find('.resource-file').attr('required', true).show();
    }else{
        resourceTypeElement.parent().parent().find('.resource-url').removeAttr('required').hide();
        resourceTypeElement.parent().parent().find('.resource-file').removeAttr('required').hide();
    }
});

// Resource Section Repeater
let resourceSectionRepeater = $('.resource-section').repeater({
    initEmpty: false,
    show: function () {
        updateResourceTypeLabels($(this));
        $(this).slideDown();
    },
    hide: function (deleteElement) {
        $(this).slideUp(deleteElement);
    }
});

function updateResourceTypeLabels(resourceSection) {
    resourceSection.find('[data-repeater-item]').each(function(index) {
        var resourceTypeNumber = index + 1
        $(this).find('.resource-type-url-label').text('Resource Type ' + resourceTypeNumber);
        $(this).find('.resource-type-url').attr('placeholder', 'Resource Type ' + resourceTypeNumber);
        // Add ID and For Attribute unique
        $(this).find('.resource-type-url-label').attr('for', 'resource-type-url-' + resourceTypeNumber);
        $(this).find('.resource-type-url').attr('id', 'resource-type-url-' + resourceTypeNumber);
    });
}

/*************************************************************** */

/** Custom Toggle Switch */
$(document).on('change', '.custom-toggle-switch', function(){
    let isChecked = $(this).is(':checked') ? 1 : 0;
    $(this).siblings('.custom-toggle-switch-value').val(isChecked);
});
/*************************************************************** */
$(document).ready(function () {
    /** Select2 Instructors */
    $('#instructors').select2({
        placeholder: trans("Select Instructors"),
        allowClear: true,
        width: '100%',
        language: {
            noResults: function() {
                return trans("No instructors found");
            }
        }
    }); 
});
/*************************************************************** */
var socialMediaRepeater = $('.social-media-section').repeater({
    initEmpty: true,
    show: function () {
        // Find all existing requirement numbers and extract their numeric values
        let existingNumbers = $('.sr-number').map(function () {
            let num = parseInt($(this).text());
            return isNaN(num) ? 0 : num;
        }).get();

        let maxOptionNumber = existingNumbers.length ? Math.max(...existingNumbers) : 0;
        let newOptionNumber = maxOptionNumber + 1;

        $(this).find('.sr-number').text(newOptionNumber);
        $(this).find('.social-media-icon-input').attr('id', 'social-media-icon-input-' + newOptionNumber);
        $(this).find('.social-media-icon-preview').attr('id', 'social-media-icon-preview-' + newOptionNumber);

        // Slide down the learning section
        $(this).slideDown();
    },
    hide: function (deleteElement) {
        let idExists = $(this).find('.id').val();
        if(idExists){
            let url = baseurl + 'settings/social-medias/' + idExists;
            showDeletePopupModal(url, {
                successCallBack: function () {
                    window.location.reload();
                }, errorCallBack: function (response) {
                    showErrorToast(response.message);
                }
            })
        }else{
            // Slide up the learning section
            $(this).slideUp(deleteElement);
        }
    }
});

// Initialize TinyMCE - Wait for DOM to be ready
// Exclude specific editors that are initialized elsewhere
$(document).ready(function() {
    if (typeof tinymce !== 'undefined') {
        // Check document mode
        var compatMode = document.compatMode;
        if (compatMode !== 'CSS1Compat') {
            console.warn('TinyMCE: Document is not in standards mode. Current mode: ' + compatMode);
        }
        
        // Initialize only editors that don't have specific IDs (to avoid conflicts)
        // Exclude tinymce-individual and tinymce-team as they're handled in instructor-terms-settings page
        tinymce.init({
            selector: '.tinymce-editor:not(#tinymce-individual):not(#tinymce-team)',
            height: 400,
            menubar: false,
            plugins: [
                'advlist autolink lists link image charmap print preview anchor',
                'searchreplace visualblocks code fullscreen',
                'insertdatetime media table paste code help wordcount'
            ],
            toolbar: 'undo redo | formatselect | bold italic backcolor | \
            alignleft aligncenter alignright alignjustify | \
            bullist numlist outdent indent | removeformat | help',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
            schema: 'html5',
            doctype: '<!DOCTYPE html>',
            forced_root_block: 'p',
            setup: function (editor) {
                editor.on('change', function () {
                    editor.save();
                });
            }
        });
    }
}); 

// Fill Currency Symbol in Symbol input based on country
$("#currency-code").on("change", function(e){
    let value = $(this).val();
    if(value){
        let url = $("#url-for-currency-symbol").val()

        axios.get(url, {
            params: {
              country_code: value
            }
        }).then(function (response) {
            if(response.data.error == false){
                $("#currency-symbol").val(response.data.data)
            }else{
                console.log(response);
            }
        }).catch(function (error) {
            console.log(error);
        });
    }
})

// Refund Request Action Handler
function submitRefundAction(action) {
    const form = document.querySelector('.create-form');
    if (!form) return;
    
    const actionInput = document.getElementById('refund_action');
    if (!actionInput) return;
    
    // Get translations from form data attributes
    const translations = {
        approveTitle: form.dataset.approveTitle || 'Approve Refund Request?',
        rejectTitle: form.dataset.rejectTitle || 'Reject Refund Request?',
        approveText: form.dataset.approveText || 'Are you sure you want to approve this refund? The amount will be credited to user wallet and course access will be removed.',
        rejectText: form.dataset.rejectText || 'Are you sure you want to reject this refund request?',
        yesApprove: form.dataset.yesApprove || 'Yes, Approve',
        yesReject: form.dataset.yesReject || 'Yes, Reject',
        cancel: form.dataset.cancel || 'Cancel'
    };
    
    // Set action
    actionInput.value = action;
    
    // Show SweetAlert2 confirmation dialog
    const isApprove = action === 'approve';
    const title = isApprove ? translations.approveTitle : translations.rejectTitle;
    const text = isApprove ? translations.approveText : translations.rejectText;
    const icon = isApprove ? 'question' : 'warning';
    const confirmButtonText = isApprove ? translations.yesApprove : translations.yesReject;
    const confirmButtonColor = isApprove ? '#28a745' : '#dc3545';
    
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: confirmButtonColor,
            cancelButtonColor: '#6c757d',
            confirmButtonText: confirmButtonText,
            cancelButtonText: translations.cancel,
            reverseButtons: true,
            focusCancel: false,
            focusConfirm: true,
            buttonsStyling: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    } else {
        // Fallback to browser confirm if SweetAlert2 is not available
        if (confirm(text)) {
            form.submit();
        }
    }
}