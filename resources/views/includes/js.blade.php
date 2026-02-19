<!-- General JS Scripts -->
<script src="{{ asset('js/jquery.min.js') }}"></script>
<script src="{{ asset('js/popper.min.js') }}"></script>
<script src="{{ asset('js/bootstrap.min.js') }}"></script>
<script src="{{ asset('js/jquery.nicescroll.min.js') }}"></script>
<script src="{{ asset('js/moment.min.js') }}"></script>
<script src="{{ asset('js/stisla.js') }}"></script>
<script type="text/javascript" src="{{ url('js/js-color.min.js') }}"></script>


<!-- Repeter -->
<script src="{{ asset('extensions/jquery-repeater/jquery.repeater.js') }}"></script>
<!-- SweetAlert2 JS -->
<script src="{{ asset('js/sweetalert2.js') }}"></script>

<!-- Parsley -->
<script type="text/javascript" src="{{ asset('js/parsley.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/page/parsley.js') }}"></script>

<!-- Jquery UI -->
<script type="text/javascript" src="{{ asset('extensions/jquery-ui/jquery-ui.min.js') }}"></script>

<!-- Toastify JS -->
<script type="text/javascript" src="{{ asset('extensions/toastify-js/toastify.js') }}"></script>

<!-- Magnific Popup JS -->
<script src="{{ asset('js/jquery.magnific-popup.min.js') }}"></script>

<!-- Bootstrap Table and Export Dependencies -->
<script src="{{ asset('js/bootstrap-table/bootstrap-table.min.js') }}"></script>
<script src="{{ asset('js/bootstrap-table/jspdf.min.js') }}"></script>
<script src="{{ asset('js/bootstrap-table/jspdf.plugin.autotable.js') }}"></script>

<!-- TinyMCE -->
<script src="{{ asset('library/tinymce/tinymce.min.js') }}"></script>

<!-- Bootstrap Table Export --> 
<script type="text/javascript" src="{{ asset('js/bootstrap-table/bootstrap-table-export.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/bootstrap-table/tableExport.min.js') }}"></script>

<!-- Reorder Rows -->
<script src="{{ asset('js/bootstrap-table/reorder-rows.min.js') }}"></script>

<!-- For Drag & Drop Rows -->
<script src="{{ asset('js/bootstrap-table/jquery.tablednd.min.js') }}"></script>

<!-- Template JS Files -->
<script src="{{ asset('js/scripts.js') }}"></script>
<script src="{{ asset('js/custom/bootstrap-table/queryParams.js') }}"></script>
<script src="{{ asset('js/custom/bootstrap-table/actionEvents.js') }}"></script>
<script src="{{ asset('js/custom/bootstrap-table/formatter.js') }}"></script>
<script src="{{ asset('js/custom.js') }}"></script>
<script src="{{ asset('js/custom/function.js') }}"></script>
<script src="{{ asset('js/custom/common.js') }}"></script>
<script src="{{ asset('js/custom/custom.js') }}"></script>


<!-- Language Script -->
<script src="{{ route('common.language.read') }}"></script>

{{-- Filepond --}}
<script type="text/javascript" src="{{ asset('extensions/filepond/filepond.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('extensions/filepond/filepond.jquery.js') }}"></script>
<script type="text/javascript" src="{{ asset('extensions/filepond/filepond-plugin-image-preview.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('extensions/filepond/filepond-plugin-pdf-preview.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('extensions/filepond/filepond-plugin-file-validate-size.min.js') }}">
</script>
<script type="text/javascript" src="{{ asset('extensions/filepond/filepond-plugin-file-validate-type.min.js') }}">
</script>
<script type="text/javascript" src="{{ asset('extensions/filepond/filepond-plugin-image-validate-size.min.js') }}">
</script>

{{--JS Tree--}}
<script src="{{asset("extensions/jstree/jstree.min.js")}}"></script>

{{-- Axios --}}
<script src="{{ asset('js/axios.min.js') }}"></script>

{{-- Custom JS --}}
{{-- <script type="text/javascript" src="{{ asset('js/custom/common.js') }}"></script> --}}
{{-- <script type="text/javascript" src="{{ asset('js/custom/custom.js') }}"></script> --}}
<script type="text/javascript" src="{{ asset('js/custom/function.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/custom/bootstrap-table/formatter.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/custom/bootstrap-table/queryParams.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/custom/bootstrap-table/actionEvents.js') }}"></script>



{{-- Select2 --}}
<script type="text/javascript" src="{{ asset('extensions/select2/select2.min.js') }}"></script>
<!-- Base URL -->

<script>
   window.baseurl = "{{ url('/') }}/";

    @if (session()->has('success'))
    showSuccessToast(@json(session('success')));
    @endif

    @if (isset($errors) && is_object($errors) && $errors->any())
        @foreach ($errors->all() as $error)
            showErrorToast("{!! $error !!}");
        @endforeach
    @endif

    @if (session()->has('error'))
        showErrorToast("{!! session('error') !!}");
    @endif

    @if (session()->has('errors') && is_string(session('errors')))
        showErrorToast("{!! session('errors') !!}");
    @endif

   
</script>
