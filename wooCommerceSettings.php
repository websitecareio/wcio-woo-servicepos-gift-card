<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_wciowgcssp {

    /*
     * Bootstraps the class and hooks required actions & filters.
     *
     */
    public static function init() {
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
        add_action( 'woocommerce_settings_tabs_wciowgcssp', __CLASS__ . '::settings_tab' );
        add_action( 'woocommerce_update_options_wciowgcssp', __CLASS__ . '::update_settings' );
    }


    /*
     * Add a new settings tab to the WooCommerce settings tabs array.
     *
     * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
     * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
     */
    public static function add_settings_tab( $settings_tabs ) {
        $settings_tabs['wciowgcssp'] = __( 'C1ST Giftcard', 'woocommerce-settings-tab-demo' );
        return $settings_tabs;
    }


    /*
     * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
     *
     * @uses woocommerce_admin_fields()
     * @uses self::get_settings()
     */
    public static function settings_tab() {
        woocommerce_admin_fields( self::get_settings() );
    }


    /*
     * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
     *
     * @uses woocommerce_update_options()
     * @uses self::get_settings()
     */
    public static function update_settings() {
        woocommerce_update_options( self::get_settings() );
    }


    /*
     * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
     *
     * @return array Array of settings for @see woocommerce_admin_fields() function.
     */
    public static function get_settings() {

        $settings = array(
            'section_title' => array( 
                'name'     => __( 'C1ST Giftcard', 'woocommerce-settings-tab-demo' ),
                'type'     => 'title',
                'desc'     => '',
                'id'       => 'wc_wciowgcssp_section_title'
            ),
            'wc_wciowgcssp_token' => array(
                'name' => __( 'C1ST Token', 'woocommerce-settings-tab-demo' ),
                'type' => 'text',
                'desc' => __( '', 'woocommerce-settings-tab-demo' ),
                'id'   => 'wc_wciowgcssp_token'
            ),
            'wc_wciowgcssp_restsecret' => array(
                'name' => __( 'Resthook secret', 'woocommerce-settings-tab-demo' ),
                'type' => 'text',
                'desc' => __( '', 'woocommerce-settings-tab-demo' ),
                'id'   => 'wc_wciowgcssp_restsecret'
            ),
            'wc_wciowgcssp_giftcardplugin' => array(
                'name' => __( 'Gift card plugin', 'woocommerce-settings-tab-demo' ),
                'type' => 'select',
                'options' => array( 
                  'yith-woocommerce-gift-cards' => __('YITH WooCommerce Gift Cards'),
                  ),      
                'desc' => __( '', 'woocommerce-settings-tab-demo' ),
                'id'   => 'wc_wciowgcssp_giftcardplugin'
            ),
            'section_end' => array(
                 'type' => 'sectionend',
                 'id' => 'wc_wciowgcssp_section_end'
            )
        );

        return apply_filters( 'wc_wciowgcssp_settings', $settings );
    }

}

WC_wciowgcssp::init();
