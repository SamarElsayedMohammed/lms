<div class="login-brand">
    <img src="{{ (isset($settings) && !empty($settings['vertical_logo'])) ? asset($settings['vertical_logo']) : asset('images/logo.jpeg') }}"
         alt="{{ __('Logo') }}"
         width="200"
         class="shadow-light rounded-circle"
         style="max-width: 200px; height: auto; object-fit: contain;">
</div>
