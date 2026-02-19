const mix = require("laravel-mix");

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

// mix.js("resources/js/app.js", "public/js").postCss(
//     "resources/css/app.css",
//     "public/css",
//     [
//         //
//     ]
// );

let plugins = [
    "bootstrap",
    "flag-icon-css",
    "jqvmap",
    "summernote",
    "owl.carousel",
    "weathericons",
    "jquery",
    "jquery-ui-dist",
    "jquery-sparkline",
    "popper.js",
    "jquery.nicescroll",
    "tooltip.js",
    "moment",
    "summernote",
    "chocolat",
    "chart.js",
    "simpleweather",
    "prismjs",
    "dropzone",
    "bootstrap-social",
    "cleave.js",
    "bootstrap-daterangepicker",
    "bootstrap-colorpicker",
    "bootstrap-timepicker",
    "bootstrap-tagsinput",
    "select2",
    "selectric",
    "codemirror",
    "fullcalendar",
    "datatables",
    "ionicons201",
    "sweetalert",
    "izitoast",
    "weathericons",
    "gmaps",
];

plugins.forEach((plugin) => {
    mix.copy("./node_modules/" + plugin, "public/library/" + plugin);
});

// BrowserSync configuration for live reloading in Docker
mix.browserSync({
    proxy: {
        target: 'nginx:80',
    },
    host: '0.0.0.0',
    port: 3000,
    ui: false,
    open: false,
    notify: false,
    ghostMode: false,
    snippetOptions: {
        rule: {
            match: /<\/body>/i,
        }
    },
    files: [
        'app/**/*.php',
        'resources/views/**/*.php',
        'resources/views/**/*.blade.php',
        'public/css/**/*.css',
        'public/js/**/*.js',
    ]
});
