=== Beplus SCSS Compiler ===
Contributors: bearsthemes, tienbeplus
Tags: scss, sass, css, compiler, preprocessor
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compiles SCSS to CSS and can enqueue the result on the frontend.

== Description ==

Declare an SCSS source directory and a CSS destination directory in the
admin (relative to your active theme). The plugin scans SCSS following a
mirror structure, recompiles when files change (auto mode) or on demand
(manual mode), and can enqueue the compiled CSS on the frontend.

* Multiple SCSS/CSS pairs, each compiled independently.
* Automatic recompilation only when files actually change (fingerprinted).
* Optional source maps and CSS minification.
* Atomic writes - a failed compile never leaves a broken CSS file behind.
* Child-theme aware: paths resolve against the active theme.
* Developer friendly: beplus_scss/* filters to swap the compiler, exclude
  files, override write paths and more.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or zip via Plugins > Add New.
2. Activate.
3. Go to Settings > Beplus SCSS Compiler, add an SCSS source directory and a
   CSS destination directory (both relative to your active theme), then Save.

== Frequently Asked Questions ==

= Where do the directories point? =

Both paths are relative to the active theme directory (child theme if
active, otherwise the parent theme). For example `assets/scss` resolves
to `/wp-content/themes/<your-theme>/assets/scss`.

= Will it enqueue CSS my theme already ships? =

No. Only files the plugin itself compiled are enqueued; pre-existing CSS
in the destination directory is left untouched.

= Does it work with child themes? =

Yes. Paths always resolve against whichever theme is currently rendering
the frontend.

= What happens on a failed compile? =

Nothing is overwritten. Writes are atomic, and the error is reported in
the admin so you can fix the source and try again.

== Changelog ==

= 1.0.0 =
Initial release.

* SCSS to CSS compilation powered by scssphp, mirroring your source
  structure: scss/modules/card.scss compiles to css/modules/card.css.
* Multiple SCSS/CSS directory pairs, each managed independently.
* Auto mode recompiles only files that actually changed (fingerprinted);
  edits to _partial imports also trigger recompilation.
* Manual mode with a Compile Now button in the admin.
* Optional source maps and CSS minification.
* Opt-in frontend enqueue of compiled files only - pre-existing CSS in
  the destination directory is never enqueued - cache-busted with the
  file modification time.
* Atomic writes through WP_Filesystem: a failed compile never leaves a
  broken or half-written CSS file behind.
* Settings screen validates every path (exists, readable/writable,
  contains compilable SCSS) and reports errors inline.
* Developer filters: beplus_scss/compiler, /import_paths, /exclude,
  /write_path, /enqueue and /error.
