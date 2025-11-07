<?php
/**
 * Shortcode class.
 * Provides the [ar_wallpaper_preview] shortcode for manual placement of the trigger button.
 */
class ARWP_Shortcode {

        /**
         * Front-end helper instance.
         *
         * @var ARWP_Frontend
         */
        protected $frontend;

        /**
         * Constructor.
         *
         * @param ARWP_Frontend|null $frontend Front-end helper.
         */
        public function __construct( ?ARWP_Frontend $frontend = null ) {
                $this->frontend = $frontend ? $frontend : ARWP_Frontend::instance();

                add_shortcode( 'ar_wallpaper_preview', array( $this, 'render_shortcode' ) );
        }

        /**
         * Render the shortcode output.
         *
         * @param array       $atts    Attributes.
         * @param string|null $content Optional content for the button label.
         * @return string
         */
        public function render_shortcode( $atts, $content = null ) {
                $atts = shortcode_atts(
                        array(
                                'image'       => '',
                                'product_id'  => 0,
                                'label'       => __( 'Preview in My Room', 'ar-wallpaper-preview' ),
                                'class'       => '',
                                'product_name'=> '',
                        ),
                        $atts,
                        'ar_wallpaper_preview'
                );

                $image_url = esc_url_raw( $atts['image'] );
                $product_id = absint( $atts['product_id'] );

                if ( ! $image_url ) {
                        $post_id = $product_id ? $product_id : get_the_ID();

                        if ( $post_id ) {
                                $thumb_id = get_post_thumbnail_id( $post_id );
                                if ( $thumb_id ) {
                                        $image_url = wp_get_attachment_image_url( $thumb_id, 'full' );
                                }

                                if ( ! $atts['product_name'] ) {
                                        $atts['product_name'] = get_the_title( $post_id );
                                }
                        }
                }

                if ( ! $image_url ) {
                        return '';
                }

                $label = trim( (string) $content ) !== '' ? wp_strip_all_tags( $content ) : $atts['label'];

                return $this->frontend->get_button_html(
                        array(
                                'label'        => $label,
                                'image_url'    => $image_url,
                                'product_name' => $atts['product_name'],
                                'classes'      => trim( 'arwp-trigger-button ' . $atts['class'] ),
                        )
                );
        }
}
