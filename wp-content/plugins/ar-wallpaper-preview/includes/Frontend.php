<?php
/**
 * Front-end helper that renders the "Preview in My Room" button and modal.
 */
class ARWP_Frontend {

        /**
         * Singleton instance.
         *
         * @var ARWP_Frontend|null
         */
        protected static $instance = null;

        /**
         * Whether assets were enqueued for the current request.
         *
         * @var bool
         */
        protected $assets_enqueued = false;

        /**
         * Whether the modal markup has already been printed.
         *
         * @var bool
         */
        protected $modal_rendered = false;

        /**
         * Retrieve the singleton.
         *
         * @return ARWP_Frontend
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }

                return self::$instance;
        }

        /**
         * Constructor.
         */
        protected function __construct() {
                add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
                add_action( 'wp_footer', array( $this, 'render_modal_template' ) );
                add_action( 'wp_body_open', array( $this, 'render_modal_template' ) );

                if ( class_exists( 'WooCommerce' ) ) {
                        add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_button' ), 35 );
                }
        }

        /**
         * Register scripts and styles so they can be enqueued on demand.
         */
        public function register_assets() {
                wp_register_style( 'arwp-frontend', ARWP_PLUGIN_URL . 'assets/css/frontend.css', array(), ARWP_VERSION );
                wp_register_script( 'arwp-frontend', ARWP_PLUGIN_URL . 'assets/js/frontend.js', array(), ARWP_VERSION, true );
        }

        /**
         * Enqueue the front-end assets and expose settings to JavaScript.
         */
        public function enqueue_assets() {
                if ( ! wp_style_is( 'arwp-frontend', 'enqueued' ) ) {
                        wp_enqueue_style( 'arwp-frontend' );
                }

                if ( ! wp_script_is( 'arwp-frontend', 'enqueued' ) ) {
                        wp_enqueue_script( 'arwp-frontend' );

                        wp_localize_script( 'arwp-frontend', 'ARWPSettings', $this->get_localized_settings() );
                }

                $this->assets_enqueued = true;
        }

        /**
         * Render the WooCommerce product button if possible.
         */
        public function render_product_button() {
                if ( ! function_exists( 'is_product' ) || ! is_product() ) {
                        return;
                }

                if ( ! class_exists( 'WC_Product' ) ) {
                        return;
                }

                global $product;

                if ( ! $product instanceof WC_Product ) {
                        return;
                }

                $should_render = apply_filters( 'arwp_show_product_button', true, $product );

                if ( ! $should_render ) {
                        return;
                }

                $image_id = $product->get_image_id();

                if ( ! $image_id ) {
                        return;
                }

                $image_url = wp_get_attachment_image_url( $image_id, 'full' );

                if ( ! $image_url ) {
                        return;
                }

                $label = apply_filters( 'arwp_product_button_label', __( 'Preview in My Room', 'ar-wallpaper-preview' ), $product );

                echo $this->get_button_html(
                        array(
                                'label'        => $label,
                                'image_url'    => $image_url,
                                'product_name' => $product->get_name(),
                                'classes'      => 'button arwp-trigger-button',
                        )
                );
        }

        /**
         * Output the modal markup in the footer when assets are enqueued.
         */
        public function render_modal_template() {
                if ( $this->modal_rendered || ! $this->assets_enqueued ) {
                        return;
                }

                $this->modal_rendered = true;

                $title = esc_html__( 'Preview in My Room', 'ar-wallpaper-preview' );
                $instructions = esc_html__( 'Point your camera at your wall to preview the wallpaper.', 'ar-wallpaper-preview' );
                $snapshot_label = esc_html__( 'Take Snapshot', 'ar-wallpaper-preview' );
                $size_label = esc_html__( 'Preview size', 'ar-wallpaper-preview' );
                $rotate_left_label = esc_html__( 'Rotate counter-clockwise', 'ar-wallpaper-preview' );
                $rotate_right_label = esc_html__( 'Rotate clockwise', 'ar-wallpaper-preview' );
                $close_label = esc_html__( 'Close preview', 'ar-wallpaper-preview' );
                $start_ar_label = esc_html__( 'Start AR Session', 'ar-wallpaper-preview' );
                ?>
                <div id="arwp-modal" class="arwp-modal" aria-hidden="true">
                        <div class="arwp-modal__overlay" data-arwp-dismiss="true"></div>
                        <div class="arwp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="arwp-modal-title" aria-live="polite">
                                <button type="button" class="arwp-modal__close" data-arwp-dismiss="true" aria-label="<?php echo esc_attr( $close_label ); ?>">
                                        <span aria-hidden="true">&times;</span>
                                </button>
                                <h2 id="arwp-modal-title" class="arwp-modal__title"><?php echo $title; ?></h2>
                                <p class="arwp-modal__instructions"><?php echo $instructions; ?></p>
                                <div class="arwp-viewer" data-arwp-viewer>
                                        <video class="arwp-viewer__video" playsinline muted></video>
                                        <canvas class="arwp-viewer__canvas" hidden></canvas>
                                        <img class="arwp-viewer__overlay" alt="" />
                                        <div class="arwp-viewer__webxr" hidden>
                                                <p class="arwp-webxr__message"><?php echo esc_html__( 'Your device supports immersive AR. Tap the button below to place the wallpaper directly on your wall.', 'ar-wallpaper-preview' ); ?></p>
                                                <button type="button" class="arwp-webxr__start"><?php echo esc_html( $start_ar_label ); ?></button>
                                        </div>
                                        <div class="arwp-viewer__fallback" hidden></div>
                                </div>
                                <div class="arwp-controls" data-arwp-controls>
                                        <label class="arwp-control arwp-control--slider">
                                                <span class="arwp-control__label"><?php echo esc_html( $size_label ); ?></span>
                                                <input type="range" class="arwp-control__scale" min="25" max="200" value="100" />
                                        </label>
                                        <div class="arwp-control arwp-control--rotate">
                                                <button type="button" class="arwp-control__rotate" data-rotate="-5" aria-label="<?php echo esc_attr( $rotate_left_label ); ?>">&#8634;</button>
                                                <button type="button" class="arwp-control__rotate" data-rotate="5" aria-label="<?php echo esc_attr( $rotate_right_label ); ?>">&#8635;</button>
                                        </div>
                                        <button type="button" class="arwp-control__snapshot"><?php echo $snapshot_label; ?></button>
                                </div>
                                <p class="arwp-modal__status" role="status"></p>
                        </div>
                </div>
                <?php
        }

        /**
         * Build the localized settings array that powers the JavaScript controller.
         *
         * @return array
         */
        protected function get_localized_settings() {
                $options = get_option( 'arwp_settings', array() );

                $width_cm  = isset( $options['default_width_cm'] ) ? (int) $options['default_width_cm'] : 300;
                $height_cm = isset( $options['default_height_cm'] ) ? (int) $options['default_height_cm'] : 250;
                $scale     = isset( $options['default_scale_percent'] ) ? (int) $options['default_scale_percent'] : 100;
                $opacity   = isset( $options['overlay_opacity'] ) ? (float) $options['overlay_opacity'] : 0.9;
                $snapshot  = isset( $options['enable_snapshot'] ) ? 'yes' === $options['enable_snapshot'] : true;

                return array(
                        'viewerScript'        => ARWP_PLUGIN_URL . 'assets/js/viewer.js',
                        'defaultWidthMeters'  => max( 0.1, $width_cm / 100 ),
                        'defaultHeightMeters' => max( 0.1, $height_cm / 100 ),
                        'defaultScalePercent' => max( 10, min( 300, $scale ) ),
                        'overlayOpacity'      => min( 1, max( 0.05, $opacity ) ),
                        'enableSnapshot'      => $snapshot,
                        'strings'             => array(
                                'loading'            => __( 'Starting camera…', 'ar-wallpaper-preview' ),
                                'permissionDenied'   => __( 'Camera access was denied. Please enable camera permissions to use the preview.', 'ar-wallpaper-preview' ),
                                'webxrUnsupported'   => __( 'This browser does not support WebXR AR sessions. Showing fallback preview.', 'ar-wallpaper-preview' ),
                                'tapToPlace'         => __( 'Move your device to detect a surface, then tap to place the wallpaper.', 'ar-wallpaper-preview' ),
                                'placed'             => __( 'Wallpaper placed. Move your device to adjust or tap another spot.', 'ar-wallpaper-preview' ),
                                'fallbackReady'      => __( 'Use the controls below to fine tune the wallpaper.', 'ar-wallpaper-preview' ),
                                'snapshotReady'      => __( 'Snapshot ready. Downloading…', 'ar-wallpaper-preview' ),
                                'snapshotDisabled'   => __( 'Snapshot capture is disabled by the site administrator.', 'ar-wallpaper-preview' ),
                        ),
                );
        }

        /**
         * Return the HTML markup for a trigger button.
         *
         * @param array $args Button parameters.
         * @return string
         */
        public function get_button_html( $args ) {
                $defaults = array(
                        'label'        => __( 'Preview in My Room', 'ar-wallpaper-preview' ),
                        'image_url'    => '',
                        'product_name' => '',
                        'classes'      => 'arwp-trigger-button',
                );

                $args = wp_parse_args( $args, $defaults );

                if ( empty( $args['image_url'] ) ) {
                        return '';
                }

                $this->enqueue_assets();

                $button  = '<button type="button" class="' . esc_attr( $args['classes'] ) . '" data-arwp-trigger="true"';
                $button .= ' data-ar-image="' . esc_url( $args['image_url'] ) . '"';
                $button .= ' data-product-name="' . esc_attr( $args['product_name'] ) . '">';
                $button .= esc_html( $args['label'] );
                $button .= '</button>';

                return $button;
        }
}
