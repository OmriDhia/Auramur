<?php
/**
 * Admin class.
 * Handles the plugin settings page.
 */
class ARWP_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
	}

	/**
	 * Add admin menu item.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'AR Wallpaper Preview Settings', 'ar-wallpaper-preview' ),
			__( 'AR Wallpaper Preview', 'ar-wallpaper-preview' ),
			'manage_options',
			'ar-wallpaper-preview',
			array( $this, 'options_page' )
		);
	}

	/**
	 * Initialize settings.
	 */
	public function settings_init() {
		register_setting( 'arwp_settings_group', 'arwp_settings', array( $this, 'settings_validate' ) );

		add_settings_section(
			'arwp_main_section',
			__( 'General AR Settings', 'ar-wallpaper-preview' ),
			array( $this, 'settings_section_callback' ),
			'ar-wallpaper-preview'
		);

		// Default Wallpaper Size
		add_settings_field(
			'default_width_cm',
			__( 'Default Wallpaper Width (cm)', 'ar-wallpaper-preview' ),
			array( $this, 'text_input_callback' ),
			'ar-wallpaper-preview',
			'arwp_main_section',
                        array(
                                'id'    => 'default_width_cm',
                                'label' => __( 'Default width of the wallpaper in centimeters.', 'ar-wallpaper-preview' ),
                                'type'  => 'number',
                                'min'   => 50,
                        )
                );
                add_settings_field(
                        'default_height_cm',
                        __( 'Default Wallpaper Height (cm)', 'ar-wallpaper-preview' ),
                        array( $this, 'text_input_callback' ),
                        'ar-wallpaper-preview',
                        'arwp_main_section',
                        array(
                                'id'    => 'default_height_cm',
                                'label' => __( 'Default height of the wallpaper in centimeters.', 'ar-wallpaper-preview' ),
                                'type'  => 'number',
                                'min'   => 50,
                        )
                );

                // Default Scale
                add_settings_field(
                        'default_scale_percent',
                        __( 'Default Preview Scale (%)', 'ar-wallpaper-preview' ),
                        array( $this, 'text_input_callback' ),
                        'ar-wallpaper-preview',
                        'arwp_main_section',
                        array(
                                'id'    => 'default_scale_percent',
                                'label' => __( 'How large the wallpaper should appear by default inside the fallback viewer.', 'ar-wallpaper-preview' ),
                                'type'  => 'number',
                                'min'   => 10,
                                'max'   => 300,
                        )
                );

                // Overlay Opacity
                add_settings_field(
                        'overlay_opacity',
                        __( 'Overlay Opacity', 'ar-wallpaper-preview' ),
                        array( $this, 'text_input_callback' ),
                        'ar-wallpaper-preview',
                        'arwp_main_section',
                        array(
                                'id'    => 'overlay_opacity',
                                'label' => __( 'Transparency of the wallpaper overlay (0 = transparent, 1 = opaque).', 'ar-wallpaper-preview' ),
                                'type'  => 'number',
                                'step'  => 0.05,
                                'min'   => 0,
                                'max'   => 1,
                        )
                );

                // Snapshot toggle
                add_settings_field(
                        'enable_snapshot',
                        __( 'Enable Snapshot Button', 'ar-wallpaper-preview' ),
                        array( $this, 'checkbox_callback' ),
                        'ar-wallpaper-preview',
                        'arwp_main_section',
                        array(
                                'id'    => 'enable_snapshot',
                                'label' => __( 'Allow shoppers to capture and download a still image of the preview.', 'ar-wallpaper-preview' ),
                        )
                );
        }

	/**
	 * Settings section callback.
	 */
	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Configure the default settings for the AR Wallpaper Preview plugin.', 'ar-wallpaper-preview' ) . '</p>';
	}

	/**
	 * Text input field callback.
	 *
	 * @param array $args Field arguments.
	 */
	public function text_input_callback( $args ) {
		$options = get_option( 'arwp_settings' );
		$id      = $args['id'];
		$value   = isset( $options[ $id ] ) ? $options[ $id ] : '';
		$type    = isset( $args['type'] ) ? $args['type'] : 'text';

                $step = isset( $args['step'] ) ? ' step="' . esc_attr( $args['step'] ) . '"' : '';
                $min  = isset( $args['min'] ) ? ' min="' . esc_attr( $args['min'] ) . '"' : '';
                $max  = isset( $args['max'] ) ? ' max="' . esc_attr( $args['max'] ) . '"' : '';

                printf(
                        '<input type="%1$s" id="%2$s" name="arwp_settings[%2$s]" value="%3$s" class="regular-text"%4$s%5$s%6$s />',
                        esc_attr( $type ),
                        esc_attr( $id ),
                        esc_attr( $value ),
                        $step,
                        $min,
                        $max
                );

		if ( isset( $args['label'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['label'] ) );
		}
	}

	/**
	 * Checkbox field callback.
	 *
	 * @param array $args Field arguments.
	 */
	public function checkbox_callback( $args ) {
		$options = get_option( 'arwp_settings' );
		$id      = $args['id'];
		$checked = isset( $options[ $id ] ) && 'yes' === $options[ $id ];

		printf(
			'<input type="checkbox" id="%1$s" name="arwp_settings[%1$s]" value="yes" %2$s />',
			esc_attr( $id ),
			checked( $checked, true, false )
		);

                if ( isset( $args['label'] ) ) {
                        printf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $args['label'] ) );
                }
	}

	/**
	 * Validate settings.
	 *
	 * @param array $input Input data.
	 * @return array Validated data.
	 */
	public function settings_validate( $input ) {
                $output = get_option( 'arwp_settings' );
                if ( ! is_array( $output ) ) {
                        $output = array();
                }

		// Validate default_width_cm and default_height_cm
		$output['default_width_cm']  = isset( $input['default_width_cm'] ) ? absint( $input['default_width_cm'] ) : 300;
		$output['default_height_cm'] = isset( $input['default_height_cm'] ) ? absint( $input['default_height_cm'] ) : 250;

                // Validate default_scale_percent
                $output['default_scale_percent'] = isset( $input['default_scale_percent'] ) ? max( 10, min( 300, absint( $input['default_scale_percent'] ) ) ) : 100;

                // Validate overlay_opacity
                $opacity = isset( $input['overlay_opacity'] ) ? floatval( $input['overlay_opacity'] ) : 0.9;
                $output['overlay_opacity'] = min( 1, max( 0.1, $opacity ) );

                // Validate enable_snapshot
                $output['enable_snapshot'] = isset( $input['enable_snapshot'] ) ? 'yes' : 'no';

                return $output;
        }

	/**
	 * Options page display.
	 */
	public function options_page() {
		// Check user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show error/update messages
		settings_errors( 'arwp_settings_group' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'arwp_settings_group' );
				do_settings_sections( 'ar-wallpaper-preview' );
				submit_button( __( 'Save Settings', 'ar-wallpaper-preview' ) );
				?>
			</form>
		</div>
		<?php
	}
}
