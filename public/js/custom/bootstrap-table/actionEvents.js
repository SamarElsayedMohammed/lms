window.languageEvents = {
    'click .edit_btn': function (e, value, row) {
        $('.filepond').filepond('removeFile')
        $("#edit_name").val(row.name);
        $("#edit_name_in_english").val(row.name_in_english);
        $("#edit_code").val(row.code);
        $("#edit_country_code").val(row.country_code);
        $("#edit_rtl_switch").prop('checked', row.rtl);
        $("#edit_rtl").val(row.rtl ? 1 : 0);
    },
    'click .language': function (e, value, row) {

    },
};


window.formFieldsEvents = {
    'click .edit_btn': function (e, value, row) {
        // Populate the ID field
        $('#edit-id').val(row.id);

        // Populate the name field
        $('#edit-name').val(row.name);

        // Populate the hidden type field
        $('#edit-type-field-value').val(row.type);

        // Set the dropdown select for the type and trigger a change event
        $('#edit-type-select').val(row.type).trigger('change').attr('disabled', true);

        // Set the required switch based on the row's 'is_required' value
        // (row.is_required) ? $('#customSwitch2').prop('checked', true).change() : $('#customSwitch2').prop('checked', false).change();
        $('#edit-required').prop('checked', row.is_required).change();

        // Handle the default values if the type is dropdown, radio, or checkbox
        if (row.type == 'dropdown' || row.type == 'radio' || row.type == 'checkbox') {
            // If there are 3 or more default values, add a new option to the list
            if (row.default_values.length >= 3) {
                $('.add-new-edit-option').click();
            }

            // Create an array of default values
            let dataArray = [];

            $.each(row.default_values, function (index, value) {
                dataArray.push({ 'option': value });
            });

            // Populate the repeater (assuming editDefaultValuesRepeater is defined and working)
            editDefaultValuesRepeater.setList(dataArray);

            // Run the function to toggle delete button access (assuming it's defined)
            $(function () {
                editToggleAccessOfDeleteButtons();
            });
        }
    }
};

// window.holidayEvents = {
//     'click .edit-data': function (e, value, row) {
//         $('#id').val(row.id);
//         $('#edit-date').val(moment(row.date, 'YYYY-MM-DD').format('DD-MM-YYYY'));
//         $('#edit-title').val(row.title);
//         $('#edit-description').val(row.description);
//     }
// };

window.courseChapterAction = {
    'click .edit-data': function (e, value, row) {
        $('#edit-id').val(row.id);
        $('#edit-course-id').val(row.course_id).trigger('change');
        $('#edit-title').val(row.title);
        $('#edit-free-preview').prop('checked', row.free_preview).change();
        $('#edit-is-active').prop('checked', row.is_active).change();
        $('#edit-description').val(row.description);
    }
};

window.staffEvents = {
    'click .edit_btn': function (e, value, row) {
        // Use role_id if available, otherwise try to get from roles array
        var roleId = row.role_id;
        if (!roleId && row.roles && row.roles.length > 0) {
            // Find custom role (excluding STAFF role)
            var customRole = row.roles.find(function(role) {
                return role.custom_role === 1 || role.custom_role === '1';
            });
            roleId = customRole ? customRole.id : (row.roles[0] ? row.roles[0].id : null);
        }
        $('#edit_role').val(roleId).trigger('change');
        $('#edit_name').val(row.name);
        $('#edit_email').val(row.email);
    },
    'click .delete-form': function (e, value, row) {
        e.preventDefault();
        e.stopPropagation();
        var url = $(e.currentTarget).attr('href');
        if (url && typeof showDeletePopupModal === 'function') {
            showDeletePopupModal(url, {
                successCallBack: function () {
                    $('#table_list').bootstrapTable('refresh');
                },
                errorCallBack: function (response) {
                    if (response && response.message) {
                        if (typeof showErrorToast === 'function') {
                            showErrorToast(response.message);
                        }
                    }
                }
            });
        }
        return false;
    }
}

window.courseLanguageEvents = {
    'click .edit_btn': function (e, value, row) {
        $('#edit_language_id').val(row.id);
        $('#edit_name').val(row.name); // fixed typo
        $('#edit_customSwitch1').prop('checked', row.is_active == 1);
    }
};

window.tagEvents = {
    'click .edit_btn': function (e, value, row) {
        $('#edit_tag_id').val(row.id);
        $('#edit_tag').val(row.tag);
        $('#edit_customSwitch1').prop('checked', row.is_active == 1);
    }
};
window.faqEvents = {
  'click .edit_btn': function (e, value, row, index) {
    e.preventDefault();
    // Set form action URL to the FAQ update route, e.g. /faqs/{id}
    $('#edit-form').attr('action', `/faqs/${row.id}`);

    // Populate the form fields with the data from the row
    $('#faq-question').val(row.question);
    $('#faq-answer').val(row.answer);
    // is_active field removed from edit modal

    // Show the edit modal
    $('#edit-faq-modal').modal('show');
  },
};
window.instructorEvents = {
    'click .change-status': function (e, value, row) {
        // Open modal directly without confirmation
        $('#edit_instructor_id').val(row.id);
        $('#instructorEditForm').attr('action', row.edit_url);
        
        // Get actual status value (not the badge HTML)
        const currentStatus = row.status_value || row.status || 'pending';
        
        // Uncheck all radio buttons first
        $('input[name="status"]').prop('checked', false);
        
        // Check the appropriate radio button based on current status
        const statusRadioId = '#status-' + currentStatus;
        if ($(statusRadioId).length) {
            $(statusRadioId).prop('checked', true);
        }
        
        // Set reason value if exists
        if (row.reason) {
            $('#reason').val(row.reason);
        } else {
            $('#reason').val('');
        }
        
        // Show/hide reason field based on current status
        if (currentStatus === 'rejected' || currentStatus === 'suspended') {
            $('#reason-field').show();
            $('#reason').attr('required', true);
        } else {
            $('#reason-field').hide();
            $('#reason').removeAttr('required');
        }
        
        $('#instructorEditModal').modal('show');
    }
};
window.taxAction = {
    'click .edit-data': function (e, value, row) {
        $('#edit_tax_id').val(row.id);
        $('#edit_name').val(row.name);
        $('#edit_percentage').val(row.percentage);
        // is_active field removed from edit modal
    }
};
window.helpdeskGroupAction = {
    'click .edit-data': function (e, value, row) {
        console.log('Edit clicked');
        console.log('This element:', row);
  
        
        // Populate form fields
        $('#edit_group_id').val(row.id);
        $('#edit_name').val(row.name);
        $('#edit_description').val(row.description);
        $('#edit_is_active').prop('checked', row.is_active == 1).change();
        
        // Set form action URL
        $('#groupEditForm').attr('action', '/helpdesk/groups/' + (row.id || ''));
        
        // Handle current image display
        if (row.image && row.image !== '') {
            $('#current_image').attr('src', row.image);
            $('#current_image_preview').show();
        } else {
            $('#current_image_preview').hide();
        }
      
    }
};
window.featureSectionAction = {
    'click .edit-data': function (e, value, row) {
        $('#edit_feature_section_id').val(row.id);
        // Ensure type value matches option values (with underscores)
        const typeValue = row.type || '';
        $('#edit_type').val(typeValue);
        $('#edit_title').val(row.title);
        $('#edit_limit').val(row.limit);
        $('#edit_is_active').prop('checked', row.is_active).change();
        if (row.type === 'offer') {
            $('#existing_offer_image').attr('src', row.images[0]);
            $('#existing_offer_image').show();
        } else {
            $('#existing_offer_image').hide();
        }
        // Trigger change event to update field visibility
        $('#edit_type').trigger('change');
    }
};

window.promoCodeAction = {
    'click .edit-data': function (e, value, row) {
        // Set values
        // Fill input fields
        $('#edit_promo_code_id').val(row.id);
        $('#edit_promo_code').val(row.promo_code);
        $('#edit_message').val(row.message);
        $('#edit_start_date').val(row.start_date);
        $('#edit_end_date').val(row.end_date);
        $('#edit_no_of_users').val(row.no_of_users);
        $('#edit_discount_type').val(row.discount_type).trigger('change');
        $('#edit_discount').val(row.discount);

        // Repeat Usage toggle
        const repeat = row.repeat_usage == 1;
        $('#edit_repeat_usage_switch').prop('checked', repeat);
        $('#edit_repeat_usage').val(repeat ? 1 : 0);
        if (repeat) {
            $('#edit_repeat_usage_count_group').removeClass('d-none');
            $('#edit_no_of_repeat_usage').val(row.no_of_repeat_usage);
        } else {
            $('#edit_repeat_usage_count_group').addClass('d-none');
            $('#edit_no_of_repeat_usage').val('');
        }

        // Status switch
        $('#edit_is_active').prop('checked', row.is_active == 1);

        // Open modal
        $('#promoCodeEditModal').modal('show');
    }
};
