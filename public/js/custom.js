/**
 *
 * You can write your JS code here, DO NOT touch the default style file
 * because it will make it harder for you to update.
 *
 */

"use strict";

/**
 * Custom JS
 */

// Delete confirmation
document.addEventListener('DOMContentLoaded', function () {
    // Add confirmation to delete buttons
    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (e) {
            if (!confirm(this.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // Initialize any custom select boxes
    document.querySelectorAll('.custom-select').forEach(function (element) {
        element.style.width = '100%';
    });

    // Add active class to current menu item
    let path = window.location.pathname;
    document.querySelectorAll('.sidebar-menu a').forEach(function (element) {
        if (element.getAttribute('href') === path) {
            element.parentElement.classList.add('active');
            let parent = element.closest('.nav-item.dropdown');
            if (parent) {
                parent.classList.add('active');
            }
        }
    });
});
$('.status-switch').on('change', function () {
    if ($(this).is(":checked")) {
        $(this).siblings('input[type="hidden"]').val(1);
    } else {
        $(this).siblings('input[type="hidden"]').val(0);
    }
})
$('input[type="radio"][name="duration_type"]').on('click', function () {
    if ($(this).hasClass('edit_duration_type')) {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#edit_limitation_for_duration').show();
                $('#edit_durationLimit').attr("required", "true").val("");
            } else {
                // Unlimited
                $('#edit_limitation_for_duration').hide();
                $('#edit_durationLimit').removeAttr("required").val("");
            }
        }
    } else {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#limitation_for_duration').show();
                $('#durationLimit').attr("required", "true").val("");
            } else {
                // Unlimited
                $('#limitation_for_duration').hide();
                $('#durationLimit').removeAttr("required").val("");
            }
        }
    }
});

$('input[type="radio"][name="item_limit_type"]').on('click', function () {
    if ($(this).hasClass('edit_item_limit_type')) {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#edit_limitation_for_limit').show();
                $('#edit_ForLimit').attr("required", "true");
            } else {
                // Unlimited
                $('#edit_limitation_for_limit').hide();
                $('#edit_ForLimit').val('');
                $('#edit_ForLimit').removeAttr("required");
            }
        }
    } else {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#limitation_for_limit').show();
                $('#durationForLimit').attr("required", "true");
            } else {
                // Unlimited
                $('#limitation_for_limit').hide();
                $('#durationForLimit').removeAttr("required");
            }
        }
    }
});

// Show file name in custom file input
$(document).ready(function() {
    $(".custom-file-input").on("change", function() {
        var fileName = $(this).val().split("\\").pop();
        $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
    });
    
    // Initialize Select2 only if library is loaded and element exists
    if (typeof $.fn.select2 !== 'undefined') {
        // Initialize Select2 on model_id when it becomes v isible
        function initModelIdSelect2() {
            var $modelId = $('#model_id');
            if ($modelId.length && !$modelId.hasClass('select2-hidden-accessible')) {
                $modelId.select2({
                    width: '100%'
                });
            }
        }
        
        // Initialize immediately if element exists
        initModelIdSelect2();
        
        // Re-initialize when model_type changes (for sliders page)
        $('#model_type').on('change', function() {
            setTimeout(initModelIdSelect2, 100);
        });
    }
});