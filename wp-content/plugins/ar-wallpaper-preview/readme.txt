=== AR Wallpaper Preview ===
Contributors: manus-ai
Tags: ar, webxr, augmented reality, wallpaper, preview, woocommerce, camera, shortcode
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview wallcoverings in a shopper's own space using WebXR with a camera-based fallback – all from a lightweight WooCommerce-friendly plugin.

== Description ==

The **AR Wallpaper Preview** plugin adds a "Preview in My Room" button to WooCommerce product pages (and a reusable shortcode) so customers can see how a wallpaper will look on their own wall. When the button is tapped the plugin opens an in-browser modal and automatically loads the best available experience:

* WebXR plane detection for supported mobile browsers – place the wallpaper at life-size directly on a detected wall surface.
* A camera-based fallback with draggable/rotatable overlay for browsers that do not expose WebXR or when camera permissions are denied.

Everything runs in-browser with zero third-party services. Scripts and camera access are only requested when the shopper opens the modal. Optional controls let visitors adjust scale, rotate the preview, or capture a still snapshot (if enabled in settings).

== Features ==

* **Automatic WooCommerce integration** – injects a "Preview in My Room" button on single product pages (after the Add to Cart button).
* **WebXR plane detection** – life-size placement that respects the wallpaper aspect ratio when immersive AR is supported.
* **Elegant fallback viewer** – live camera feed with manual drag, rotate, and scale controls when WebXR is unavailable.
* **Performance conscious** – viewer scripts are lazy-loaded when the modal opens, keeping product pages fast.
* **Snapshot capture** – optional button lets shoppers download a composited preview image from the fallback viewer.
* **Custom settings** – configure default wallpaper dimensions, overlay opacity, and whether the snapshot button is available.
* **Shortcode support** – drop the button anywhere with `[ar_wallpaper_preview]`.

== Installation ==

1. Upload the `ar-wallpaper-preview` folder to the `/wp-content/plugins/` directory or install via the WordPress plugin installer.
2. Activate the plugin through the **Plugins** menu.
3. Visit **Settings → AR Wallpaper Preview** to tweak defaults such as wallpaper size, overlay opacity, and snapshot availability.

== Usage ==

### Automatic WooCommerce button

The plugin automatically appends a "Preview in My Room" button to single product pages as long as the product has a featured image. No template changes are required.

To change the placement you can remove the default action and re-add it in your theme:

```
remove_action( 'woocommerce_single_product_summary', [ ARWP_Frontend::instance(), 'render_product_button' ], 35 );
add_action( 'woocommerce_single_product_summary', function () {
    echo ARWP_Frontend::instance()->get_button_html( [
        'classes' => 'button alt arwp-trigger-button',
    ] );
}, 25 );
```

### Shortcode

Use the `[ar_wallpaper_preview]` shortcode anywhere (product, page, or block editor). When no attributes are supplied the shortcode will fall back to the current post's featured image and title:

```
[ar_wallpaper_preview]
```

You can explicitly set the wallpaper image or label:

```
[ar_wallpaper_preview image="https://example.com/wp-content/uploads/wallpaper.jpg" label="Preview this wallpaper"]
```

== Settings ==

The settings screen lets you define:

* **Default wallpaper width/height (cm)** – used to size the plane in WebXR and initial overlay scale in fallback mode.
* **Default preview scale (%)** – initial size of the overlay within the fallback viewer.
* **Overlay opacity** – blend amount between camera feed and wallpaper image.
* **Enable snapshot button** – allow or disallow downloading a composed preview image.

== Compatibility ==

| Feature | Browser/OS | Notes |
| :--- | :--- | :--- |
| **WebXR preview** | Chrome for Android, Samsung Internet, Safari on iOS 17+ | Requires a device with ARCore/ARKit support and camera permissions. |
| **Fallback viewer** | All modern browsers with `getUserMedia` | Provides draggable/rotatable overlay when WebXR is unavailable or denied. |
| **Snapshot capture** | Same as fallback viewer | Requires camera permission; disabled when snapshots are turned off in settings. |

== Changelog ==

= 1.0.0 =
* Initial release with WebXR placement, camera-based fallback, WooCommerce integration, shortcode, and snapshot option.
