<?php

/**
 * Plugin Name: Woo Gift Cards synchronize Customers 1st. 
 * Plugin URI: https://websitecare.dk/
 * Description: Synchronize WooCommerce gift cards with Customers 1st. 
 * Version: 1.5.4
 * Author: Websitecare.dk
 * Author URI: https://websitecare.dk
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

include("wooCommerceSettings.php");

/**
 * YITH can reserve/deduct a gift card while Nexi creates a checkout-draft.
 * Restore that reservation once per coupon item and deduct it again only when
 * the draft actually becomes a paid order.
 */
if ( ! function_exists( 'wcio_restore_yith_giftcard_on_checkout_draft' ) ) {
    add_action( 'woocommerce_new_order_item', 'wcio_restore_yith_giftcard_on_checkout_draft', 999, 3 );

    function wcio_restore_yith_giftcard_on_checkout_draft( $item_id, $item, $order_id ) {
        if ( ! $item instanceof WC_Order_Item_Coupon || ! function_exists( 'YITH_YWGC' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || 'checkout-draft' !== $order->get_status() ) {
            return;
        }

        if ( wc_get_order_item_meta( $item_id, '_wcio_ywgc_draft_restored', true ) ) {
            return;
        }

        $gift = YITH_YWGC()->get_gift_card_by_code( $item->get_code() );
        if ( $gift instanceof YITH_YWGC_Gift_Card ) {
            $gift->update_balance( (float) $gift->get_balance() + (float) $item->get_discount() );
            wc_add_order_item_meta( $item_id, '_wcio_ywgc_draft_restored', 1, true );
        }
    }
}

if ( ! function_exists( 'wcio_deduct_yith_giftcard_after_draft_payment' ) ) {
    add_action( 'woocommerce_order_status_changed', 'wcio_deduct_yith_giftcard_after_draft_payment', 10, 4 );

    function wcio_deduct_yith_giftcard_after_draft_payment( $order_id, $old_status, $new_status, $order ) {
        if (
            'checkout-draft' !== $old_status
            || ! in_array( $new_status, array( 'processing', 'completed' ), true )
            || ! function_exists( 'YITH_YWGC' )
        ) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items( 'coupon' ) as $item_id => $coupon_item ) {
            if ( ! wc_get_order_item_meta( $item_id, '_wcio_ywgc_draft_restored', true ) ) {
                continue;
            }

            $gift = YITH_YWGC()->get_gift_card_by_code( $coupon_item->get_code() );
            if ( $gift instanceof YITH_YWGC_Gift_Card ) {
                $new_balance = max( 0, (float) $gift->get_balance() - (float) $coupon_item->get_discount() );
                $gift->update_balance( $new_balance );
                wc_delete_order_item_meta( $item_id, '_wcio_ywgc_draft_restored' );
            }
        }
    }
}


/* Main plugin functions */
class wcioWGCSSP
{

    public $token;
    public $giftcardplugin;
    private $restsecret;
    private $processing_inbound_webhook = false;
    private $allowed_balance_restores = array();

    public function __construct()
    {

        $this->token = get_option("wc_wciowgcssp_token"); // ServicePOS token
        $this->restsecret = get_option("wc_wciowgcssp_restsecret"); // Resthook secret 

        // Updates and settings

        require dirname(__FILE__) . "/plugin-update-checker/plugin-update-checker.php";

        $myUpdateChecker = PucFactory::buildUpdateChecker(
            'https://github.com/websitecareio/wcio-woo-servicepos-gift-card/',
            __FILE__,
            'wcio-woo-servicepos-gift-card'
        );

        //Set the branch that contains the stable release.
        $myUpdateChecker->setBranch('main');

        // Hook for at registrere API-endepunktet ved init
        add_action('rest_api_init', array($this, 'register_c1st_giftcard_api_endpoints'));



        // Hook til 'updated_postmeta' for at logge opdateringer af relevante meta nøgler
       add_action('updated_postmeta', array($this, 'wooGiftCardUpdate'), 10, 4);
        // Hook til 'added_postmeta' for at logge tilføjelser af relevante meta nøgler
      //  add_action('added_postmeta', array($this, 'wooGiftCardUpdate'), 10, 4);


        // Hook til 'save_post' for at logge oprettelse eller ændring af gift_card posts
        add_action('wp_after_insert_post', array($this, 'wooGiftCardCreateUpdate'), 10, 3);

        // Specific for WooCommerce orders. When an order is saved a code will run and end with this hook. yith_ywgc_after_gift_card_generation_save with the data: $gift_card
        add_action('yith_ywgc_after_gift_card_generation_save', array($this, 'yith_ywgc_after_gift_card_generation_save'), 10, 1);

        add_action('woocommerce_order_status_changed', array($this, 'prepareGiftCardBalanceRestore'), 1, 4);
        add_action('woocommerce_order_status_changed', array($this, 'clearGiftCardBalanceRestore'), 100, 4);


        
    
    }


    
/**
 * Call a REST API endpoint using cURL and handle response.
 *
 * This function performs HTTP requests to a specified API endpoint using cURL.
 * It supports POST, PUT, and GET methods. It sets appropriate headers including
 * JSON content type and authorization token. It logs any HTTP status errors and
 * delays execution for 1 second after each request.
 *
 * @param string $method The HTTP method (POST, PUT, GET) for the request.
 * @param string $endpoint The API endpoint to call, appended to the base URL.
 * @param mixed $data Optional. Data payload for POST or PUT requests.
 * @return array|null Returns decoded JSON response from the API or null on failure.
 */
function call($method, $endpoint, $data = false)
{
    if ( empty( $this->token ) ) {
        error_log( 'wcioWGCSSP: C1ST API token mangler. Kald blev ikke sendt.' );
        return null;
    }

    $method = strtoupper( $method );
    $url    = 'https://app.deltateq.com/api' . $endpoint;

    if ( 'GET' === $method && $data ) {
        $url = add_query_arg( $data, $url );
    }

    $args = array(
        'method'      => $method,
        'timeout'     => 15,
        'redirection' => 3,
        'headers'     => array(
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ),
    );

    if ( in_array( $method, array( 'POST', 'PUT' ), true ) && false !== $data ) {
        $args['body'] = wp_json_encode( $data );
    }

    $response = wp_remote_request( $url, $args );
    if ( is_wp_error( $response ) ) {
        error_log( sprintf( 'wcioWGCSSP: C1ST %s %s fejlede: %s', $method, $endpoint, $response->get_error_message() ) );
        return null;
    }

    $status = (int) wp_remote_retrieve_response_code( $response );
    $body   = wp_remote_retrieve_body( $response );

    if ( $status < 200 || $status >= 300 ) {
        error_log( sprintf( 'wcioWGCSSP: C1ST %s %s returnerede HTTP %d. Svar: %s', $method, $endpoint, $status, substr( $body, 0, 1000 ) ) );
        return null;
    }

    $decoded = json_decode( $body, true );
    if ( JSON_ERROR_NONE !== json_last_error() ) {
        error_log( sprintf( 'wcioWGCSSP: C1ST %s %s returnerede ugyldig JSON.', $method, $endpoint ) );
        return null;
    }

    return $decoded;
}





/**
 * Custom method to log actions related to gift card posts.
 *
 * This method logs actions such as creation or update of gift card posts 
 * and logs the current values of specific meta keys related to gift cards.
 *
 * @param int $post_id The ID of the post being saved.
 * @param WP_Post $post The post object.
 * @param bool $update Whether this is an existing post being updated or not.
 */


 // IN USE ON CUSTOM CREATION
 // IN USE ON CUSTOM UPDATE

    public function wooGiftCardCreateUpdate($post_id, $post, $update)
    {

        if ( $this->processing_inbound_webhook ) {
            return;
        }

        // Tjek om dette er en autosave eller en revision
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Get values
        $amount_total = get_post_meta($post_id, '_ywgc_amount_total', true);
        $balance_total = get_post_meta($post_id, '_ywgc_balance_total', true);
        $giftcardNo = $post->post_title;
        // Udløbsdato gemt af YITH som Unix timestamp (0 = ingen udløbsdato)
        $expiration = get_post_meta($post_id, '_ywgc_expiration', true);


        // Tjek om posttypen er 'gift_card'
        if ($post->post_type === 'gift_card') {


            // Check if value is empty
            if ($amount_total === '' || $balance_total === '') {
               return; // No amount or balance
            }
            
            
            if ($update) {

                // Update giftcard
                // Search for this card in C1ST
                $searchQuery = array("giftcardno" => $giftcardNo, "scope" => "sharegiftcards"); // Start from page 1 (0)
                $searchQuerydata = $this->call("GET", "/giftcards", $searchQuery);

                /*
                Array ( 
                    [content] => Array ( 
                        [0] => Array ( 
                            [id] => 147681 
                            [giftcardno] => Test123 
                            [amount] => 99 
                            [amountspent] => 99 
                            [createddate] => 2023-01-19 14:34:58 
                            [expirationdate] => 2026-01-19 14:34:58 
                            [paymentid] => 
                            [type] => giftcard 
                            [vat] => 
                            [productid] => 
                            [productno] => 
                            [expired] => 
                            [deleted_at] => 
                            [deleted] => 
                            [store] => Array ( 
                                [id] => 3241 ) 
                                [customer] => ) ) 
                                [count] => 1 
                                [hasMore] => ) 
                */ 

                if ( null === $searchQuerydata ) {
                    return;
                }

                // Count if there is exactly one.
                if ( ! empty( $searchQuerydata['content'] ) && count( $searchQuerydata['content'] ) === 1 ) {

                    // Update giftcard in C1ST
                    $giftcardId = $searchQuerydata["content"][0]["id"];
                    // Nuværende amountspent i C1ST, bruges til at sikre at saldoen aldrig kan gå OP
                    $currentAmountSpent = isset($searchQuerydata["content"][0]["amountspent"]) ? $searchQuerydata["content"][0]["amountspent"] : null;
                    $this->updateGiftCardC1ST($giftcardId, $amount_total, $balance_total, $currentAmountSpent, $giftcardNo);
                    

                } elseif ( empty( $searchQuerydata['content'] ) ) {

                    // If none found, then we need to create this giftcard instead.
                    $this->createGiftCardC1ST($giftcardNo, $amount_total, $balance_total, $expiration);

                } else {
                    error_log( sprintf( 'wcioWGCSSP: Flere C1ST-gavekort matcher koden %s. Ingen opdatering blev sendt.', $giftcardNo ) );
                }

            } else {
                
                // New gift card
                // Send new giftcard to C1ST
                $this->createGiftCardC1ST($giftcardNo, $amount_total, $balance_total, $expiration);

            }


        }
    }


    /**
     * Find hele gavekort-recorden i C1ST via gavekortnummer.
     * Bruges bl.a. til at hente den nuværende amountspent-værdi,
     * så vi kan sikre at saldoen aldrig sendes OP fra WooCommerce.
     *
     * @param string $giftcardNo
     * @return array|false Gavekort-record fra C1ST, eller false hvis ikke fundet.
     */
    public function findGiftCardC1ST($giftcardNo) {

        $searchQuery = array("giftcardno" => $giftcardNo, "scope" => "sharegiftcards"); // Start from page 1 (0)
        $searchQuerydata = $this->call("GET", "/giftcards", $searchQuery);

         //Count if there is one
         if(!empty($searchQuerydata["content"]) && count($searchQuerydata["content"]) == 1) {

            return $searchQuerydata["content"][0];

         }

         return false;

    }

    public function findGiftCardIdC1ST($giftcardNo) {

        $giftcard = $this->findGiftCardC1ST($giftcardNo);

        if ($giftcard && isset($giftcard["id"])) {
            return $giftcard["id"];
        }

         return false; 

    }

    /**
     * Opdaterer et gavekorts amountspent i C1ST.
     *
     * VIGTIGT: WooCommerce må ALDRIG kunne hæve gavekortets saldo i C1ST.
     * Derfor tjekkes den nuværende amountspent-værdi (hvis den er kendt)
     * før opdateringen sendes. Hvis den nye amountspent er LAVERE end den
     * nuværende (dvs. saldoen ville stige), blokeres opdateringen.
     * Saldoen kan altså kun forblive uændret eller falde - aldrig stige.
     *
     * @param int $giftcardId C1ST gavekort-ID
     * @param float $amount_total Gavekortets oprindelige totalbeløb (fra WooCommerce)
     * @param float $balance_total Gavekortets nuværende saldo (fra WooCommerce)
     * @param float|null $currentAmountSpent Den nuværende amountspent-værdi i C1ST, hvis kendt
     * @return bool true hvis opdateringen blev sendt, false hvis den blev blokeret
     */
    public function updateGiftCardC1ST($giftcardId, $amount_total, $balance_total, $currentAmountSpent = null, $giftcardNo = '') {

        // Calculated amountspent.
        $amountspent = max( 0, (float) $amount_total - (float) $balance_total );

        // Do not echo an inbound C1ST update straight back to C1ST.
        if ( $currentAmountSpent !== null && abs( $amountspent - (float) $currentAmountSpent ) < 0.005 ) {
            return true;
        }

        // Sikkerhedstjek: bloker opdateringer der ville hæve saldoen (dvs. sænke amountspent)
        $normalized_code = wc_format_coupon_code((string) $giftcardNo);
        $increase        = $currentAmountSpent !== null ? (float) $currentAmountSpent - $amountspent : 0.0;
        $allowed         = isset($this->allowed_balance_restores[$normalized_code])
            ? (float) $this->allowed_balance_restores[$normalized_code]
            : 0.0;

        // A logged-in WooCommerce administrator may manually restore a balance
        // up to the gift card's original amount. Frontend requests, cron jobs,
        // webhooks and increases above the original value remain blocked.
        $manual_admin_restore = is_admin()
            && current_user_can('manage_woocommerce')
            && !wp_doing_cron()
            && (float) $balance_total <= (float) $amount_total + 0.01;

        if ($currentAmountSpent !== null && $amountspent < (float) $currentAmountSpent && !$manual_admin_restore && ($allowed <= 0 || $increase > $allowed + 0.01)) {
            error_log("wcioWGCSSP: Blokerede forsøg på at hæve gavekort-saldo i C1ST (giftcard ID $giftcardId). Nyt amountspent ($amountspent) er lavere end nuværende amountspent i C1ST ($currentAmountSpent). Opdatering IKKE sendt.");
            return false;
        }

        if ($currentAmountSpent !== null && $amountspent < (float) $currentAmountSpent && $manual_admin_restore) {
            error_log("wcioWGCSSP: Administrator genopfyldte gavekort $giftcardNo i C1ST med $increase.");
        }

        // Prepare array
         $giftcardData = [
                'amountspent' => (float)$amountspent
        ];
        
        return null !== $this->call("PUT", "/giftcards/$giftcardId", ['content' => $giftcardData]);

    }

    /**
     * Opretter et gavekort i C1ST.
     *
     * @param string $giftcardNo Gavekortnummer
     * @param float $amount_total Totalbeløb
     * @param float $balance_total Nuværende saldo
     * @param int|string $expiration Udløbsdato. Kan være et Unix timestamp (som YITH bruger) eller en dato-streng.
     *                               0, tom streng eller null betyder ingen udløbsdato.
     */
    public function createGiftCardC1ST($giftcardNo, $amount_total, $balance_total, $expiration = 0) {

        // Calculated amountspent.
        $amountspent = max(0, (float) $amount_total - (float) $balance_total);

        // Prepare array                
        $giftcard = [
            "giftcardno" => $giftcardNo,
            "amount" => (float)$amount_total,
            "amountspent" => (float)$amountspent,
            "type" => "giftcard",
            "customer" => array(
                  "name" => "",
                  "email" => "",
            )
      ];

        // Tilføj udløbsdato, hvis der er sat en (YITH gemmer 0 = ingen udløbsdato)
        $expirationDateString = $this->formatExpirationForC1ST($expiration);
        if ($expirationDateString) {
            $giftcard["expirationdate"] = $expirationDateString;
        }

       return null !== $this->call("POST", "/giftcards",  ['content' => $giftcard]);

    }

    /**
     * Konverterer en udløbsdato til det format C1ST forventer ("Y-m-d H:i:s").
     * Accepterer enten et Unix timestamp (int, som YITH bruger internt) eller
     * en allerede formateret dato-streng.
     *
     * @param int|string $expiration
     * @return string|false Formateret dato-streng, eller false hvis ingen udløbsdato.
     */
    private function formatExpirationForC1ST($expiration) {

        if (empty($expiration) || $expiration === '0') {
            return false; // Ingen udløbsdato sat
        }

        // Håndter MySQL "zero-date" artefakter (fx "0000-00-00 00:00:00"),
        // som nogle systemer bruger som sentinel-værdi for "ingen dato".
        if (is_string($expiration) && preg_match('/^0{4}-0{2}-0{2}/', trim($expiration))) {
            return false;
        }

        // Find timestamp - enten direkte (allerede et Unix timestamp) eller via parsing af dato-streng
        if (is_numeric($expiration)) {
            $timestamp = (int) $expiration;
        } else {
            $timestamp = strtotime($expiration);
        }

        // Ugyldig eller urealistisk værdi (fx parsing-fejl eller dato omkring/før 1970)
        if ($timestamp === false || $timestamp <= 0) {
            return false;
        }

        return date("Y-m-d H:i:s", $timestamp);
    }

    /**
     * Parser en dato-streng fra C1ST (fx "2027-07-08 21:59:59") til et sikkert
     * Unix timestamp, klar til at blive gemt i YITH's "_ywgc_expiration" meta-felt.
     *
     * Beskytter mod ugyldige værdier og MySQL "zero-date" artefakter
     * (fx "0000-00-00 00:00:00"), som ellers ville blive tolket som en
     * urealistisk dato langt tilbage i tiden - hvilket ville få YITH til
     * fejlagtigt at markere et gyldigt gavekort som udløbet.
     *
     * @param string $dateString
     * @return int|false Unix timestamp, eller false hvis ugyldig/ingen dato.
     */
    private function parseC1STExpirationDate($dateString) {

        if (empty($dateString) || !is_string($dateString)) {
            return false;
        }

        if (preg_match('/^0{4}-0{2}-0{2}/', trim($dateString))) {
            return false;
        }

        $timestamp = strtotime($dateString);

        if ($timestamp === false || $timestamp <= 0) {
            return false;
        }

        return $timestamp;
    }

    /**
     * Allow balance increases only while YITH restores a redeemed amount from
     * an order that changes to cancelled, refunded or failed.
     */
    public function prepareGiftCardBalanceRestore($order_id, $from_status, $to_status, $order = false) {
        $this->allowed_balance_restores = array();

        if (!in_array($to_status, array('cancelled', 'refunded', 'failed'), true)) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($order->get_items('coupon') as $coupon_item) {
            $code = wc_format_coupon_code($coupon_item->get_code());
            if ($code === '') {
                continue;
            }

            $amount = (float) $coupon_item->get_discount() + (float) $coupon_item->get_discount_tax();
            if ($amount > 0) {
                $this->allowed_balance_restores[$code] = ($this->allowed_balance_restores[$code] ?? 0.0) + $amount;
            }
        }

        $applied_gift_cards = $order->get_meta('_ywgc_applied_gift_cards');
        if (is_array($applied_gift_cards)) {
            foreach ($applied_gift_cards as $code => $amount) {
                $code = wc_format_coupon_code((string) $code);
                if ($code !== '' && (float) $amount > 0) {
                    $this->allowed_balance_restores[$code] = max(
                        (float) ($this->allowed_balance_restores[$code] ?? 0.0),
                        (float) $amount
                    );
                }
            }
        }
    }

    public function clearGiftCardBalanceRestore($order_id, $from_status, $to_status, $order = false) {
        $this->allowed_balance_restores = array();
    }

    //Run when giftcards are made from WooCommerce
    public function yith_ywgc_after_gift_card_generation_save($gift_card) {
         
        /*
        gift_card data:
        YITH_YWGC_Gift_Card Object ( [ID] => 3349 [product_id] => 3341 [order_id] => 3347 [gift_card_number] => 8B9A25125643 [total_amount] => 1 [total_balance:protected] => 1 [status] => publish [recipient] => kim@dicm.dk [customer_id] => 0 [delivery_date] => [delivery_send_date] => [sender_name] => 132456 [recipient_name] => 123 [message] => [has_custom_design] => 0 [design_type] => default [design] => [currency] => DKK [version] => 4.14.0 [is_digital] => 1 [expiration] => 0 [internal_notes] => )
        */

        // First, try using a get method if available
        if (method_exists($gift_card, 'get_balance')) {
            $total_balance = $gift_card->get_balance();
        } else {
            // If no getter method exists, use Reflection
            $reflection = new ReflectionClass($gift_card);
            $property = $reflection->getProperty('total_balance');
            $property->setAccessible(true);

            $total_balance = $property->getValue($gift_card);
        }

        // Udløbsdato (Unix timestamp fra YITH, 0 = ingen udløbsdato)
        $expiration = isset($gift_card->expiration) ? $gift_card->expiration : 0;

        $existing = $this->findGiftCardC1ST( $gift_card->gift_card_number );
        if ( $existing && isset( $existing['id'] ) ) {
            $current_amount_spent = isset( $existing['amountspent'] ) ? $existing['amountspent'] : null;
            $this->updateGiftCardC1ST( $existing['id'], $gift_card->total_amount, $total_balance, $current_amount_spent, $gift_card->gift_card_number );
            return;
        }

        $this->createGiftCardC1ST( $gift_card->gift_card_number, $gift_card->total_amount, $total_balance, $expiration );
    }

/**
 * Custom function to log updates of specific meta keys for gift card posts.
 *
 * This function logs updates to meta keys '_ywgc_amount_total' and '_ywgc_balance_total' 
 * for gift card posts to a specified log file.
 *
 * @param int $meta_id The ID of the meta data.
 * @param int $post_id The ID of the post.
 * @param string $meta_key The meta key that was updated.
 * @param mixed $meta_value The new value of the meta key.
 */

// IN USE FOR CUSTOM UPDATE

    function wooGiftCardUpdate($meta_id, $post_id, $meta_key, $meta_value)
    {
        if ( $this->processing_inbound_webhook ) {
            return;
        }

        // Tjek om meta nøglen er en af de vi er interesseret i
        if ($meta_key === '_ywgc_balance_total') {

            // Update new value in C1ST
            $giftcardNo = get_the_title( $post_id ); // 858400776131
            $amount_total = get_post_meta($post_id, '_ywgc_amount_total', true);
            $balance_total = $meta_value;

            $giftcard = $this->findGiftCardC1ST($giftcardNo);
            if($giftcard && isset($giftcard["id"])) {

                $currentAmountSpent = isset($giftcard["amountspent"]) ? $giftcard["amountspent"] : null;
                $this->updateGiftCardC1ST($giftcard["id"], $amount_total, $balance_total, $currentAmountSpent, $giftcardNo);

            } else {

               error_log("Giftcard was not found in C1ST , unable to update balance.  $giftcardNo", 0);
               
            }    

        }

    }




/**
 * Custom function to log messages to a file.
 *
 * This function appends a timestamped message to a specified log file located at ABSPATH/gift_card_log.txt.
 *
 * @param string $message The message to be logged.
 */
/*
    function custom_log_to_file($message)
    {
        $log_file = ABSPATH . 'gift_card_log.txt';
        $current_time = date('Y-m-d H:i:s');
        $log_message = '[' . $current_time . '] ' . $message . PHP_EOL;
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
*/

/**
 * Handle requests to create giftcard via custom API endpoint.
 *
 * This function is a callback handler for the custom REST API endpoint '/giftcard.created'.
 * It verifies the webhook signature, processes the giftcard creation logic,
 * and returns a response indicating the success or failure of the creation operation.
 *
 * @param WP_REST_Request $request The REST API request object.
 * @return WP_REST_Response Returns a REST API response object with status and message.
 */
    function c1st_giftcard_created_api_endpoint($request)
    {
        // Logik for hvad der skal ske ved oprettelse af giftcard
        $signature = $request->get_header('X-C1st-Webhook-Signature');

        $secret = $this->restsecret;

        $payload = $request->get_body();
        $payload_decode = json_decode($payload);
        $calculated_hmac = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if ($signature != $calculated_hmac) {
            header("HTTP/1.0 401 Webhook failed signature check");
            die("401, Webhook failed signature check");
            return;
        }

        // All good. Now create the giftcard with the data
        /*
       Example data:
       {"content":{"id":317501,"giftcardno":"953462762930","amount":1,"amountspent":0,"createddate":"2024-07-08 19:34:06","expirationdate":"2027-07-08 21:59:59","paymentid":16593923,"type":"giftcard","vat":25,"productid":7987676,"productno":"giftcard","expired":false,"deleted_at":null,"deleted":false,"store":{"id":3241},"customer":null},"event":"giftcard.created","logid":12723294,"resthookid":5598,"storeid":3241,"initiated":"2024-07-08T19:34:09+00:00","metadata":[]}
       */
    
       // Gem værdier i variabler
        $content = $payload_decode->content;
        $id = $content->id; // 317501
        $giftcardno = $content->giftcardno; // 953462762930
        $amount = $content->amount; // 1
        $amountspent = $content->amountspent; // 0
        $createddate = $content->createddate; // 2024-07-08 19:34:06
        $expirationdate = $content->expirationdate; // 2027-07-08 21:59:59
        $paymentid = $content->paymentid; // 16593923
        $type = $content->type; // giftcard
        $vat = $content->vat; // 25
        $productid = $content->productid; // 7987676
        $productno = $content->productno; // giftcard
        $expired = $content->expired; // false
        $deleted_at = $content->deleted_at; // null
        $deleted = $content->deleted; // false
        $store_id = $content->store->id; // 3241
        $customer = $content->customer; // null

        // Check if this gift card exists before we create it
        $args = array(
            'post_type' => 'gift_card',
            'title'     => wp_strip_all_tags($giftcardno),
            'post_status' => 'publish',
            'posts_per_page' => 1
        );

        $existing_giftcards = get_posts($args);

        if ($existing_giftcards) {
            return new WP_REST_Response('Gift Card Created!', 409); // Duplicated data
        }

        // Create the giftcard
        $newGiftCard = array(
            'post_title'    => wp_strip_all_tags($giftcardno),
            'post_content'  => "",
            'post_status'   => 'publish',
            'post_author'   => 1,
            'post_type'     => "gift_card"
        );

        // Insert the post into the database
        $postID = wp_insert_post($newGiftCard);

        update_post_meta($postID, "_ywgc_amount_total", $amount);  // The gift card amount
        update_post_meta($postID, "_ywgc_balance_total", $amount); // The current amount available for the customer
        update_post_meta($postID, "_ywgc_internal_notes", "".date("d-m-Y h:i:s").": Created using Websitcare.dk´s giftcard plugin."); // Just internal notes

        // Gem udløbsdato fra C1ST i det format YITH forventer (Unix timestamp)
        $expirationTimestamp = $this->parseC1STExpirationDate($expirationdate);
        if ($expirationTimestamp !== false) {
            update_post_meta($postID, "_ywgc_expiration", $expirationTimestamp);
            update_post_meta($postID, "_ywgc_expiration_date_formatted", date_i18n(apply_filters('yith_wcgc_date_format', 'Y-m-d'), $expirationTimestamp));
        }

        return new WP_REST_Response('Gift Card Created!', 200);

    }

/**
 * Handle requests to update giftcard via custom API endpoint.
 *
 * This function is a callback handler for the custom REST API endpoint '/giftcard.updated'.
 * It verifies the webhook signature, processes the giftcard update logic, and returns
 * a response indicating the success or failure of the update operation.
 *
 * @param WP_REST_Request $request The REST API request object.
 * @return WP_REST_Response Returns a REST API response object with status and message.
 */
    function c1st_giftcard_updated_api_endpoint($request)
    {
        // Logik for hvad der skal ske ved opdatering af giftcard
        // 
        $signature = $request->get_header('X-C1st-Webhook-Signature');

        $secret = $this->restsecret;

        $payload = $request->get_body();
        $payload_decode = json_decode($payload);
        $calculated_hmac = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if ($signature != $calculated_hmac) {
            header("HTTP/1.0 401 Webhook failed signature check");
            die("401, Webhook failed signature check");
            return;
        }

        // Signature success, now we want to setup variables
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $WooCommerceGiftCardTableName = "posts";

        /*
        Example data:
        {"content":{"id":317501,"giftcardno":"953462762930","amount":1,"amountspent":0,"createddate":"2024-07-08 19:34:06","expirationdate":"2027-07-08 21:59:59","paymentid":16593923,"type":"giftcard","vat":25,"productid":7987676,"productno":"giftcard","expired":false,"deleted_at":null,"deleted":false,"store":{"id":3241},"customer":null},"event":"giftcard.created","logid":12723294,"resthookid":5598,"storeid":3241,"initiated":"2024-07-08T19:34:09+00:00","metadata":[]}
        */
     
        // Gem værdier i variabler
         $content = $payload_decode->content;
         $id = $content->id; // 317501
         $giftcardno = $content->giftcardno; // 953462762930
         $amount = $content->amount; // 1
         $amountspent = $content->amountspent; // 0
         $createddate = $content->createddate; // 2024-07-08 19:34:06
         $expirationdate = $content->expirationdate; // 2027-07-08 21:59:59
         $paymentid = $content->paymentid; // 16593923
         $type = $content->type; // giftcard
         $vat = $content->vat; // 25
         $productid = $content->productid; // 7987676
         $productno = $content->productno; // giftcard
         $expired = $content->expired; // false
         $deleted_at = $content->deleted_at; // null
         $deleted = $content->deleted; // false
         $store_id = $content->store->id; // 3241
         $customer = $content->customer; // null

        // Check if this giftcard exists and get its data.
        $wooGiftCard = $wpdb->get_results("SELECT * FROM $table_prefix$WooCommerceGiftCardTableName WHERE post_type = 'gift_card' AND post_title = '$giftcardno' LIMIT 1");
        $postID = $wooGiftCard["0"]->ID;

        // If there is no post id, fail this.
        if(!$postID) {
            return new WP_REST_Response('Gift Card not found in WooCommerce!', 412 ); // 412 Precondition Failed. A precondition in the request is not met, that prevents it from executing.
        }

        // Remaining from C1ST
        $remaining = $amount-$amountspent; // Calculated value

        // Update remaning value
        update_post_meta($postID, "_ywgc_balance_total", $remaining); // This is remaining

        //Update notes
        $current_notes = get_post_meta($postID, "_ywgc_internal_notes", true);
        $updated_notes = $current_notes . "\r\n".date("d-m-Y h:i:s").": _ywgc_balance_total update from C1ST REST hook giftcard.updated : amount - amountspent ($amount-$amountspent)";
        update_post_meta($postID, "_ywgc_internal_notes", $updated_notes); // Just internal notes   

        // Hold udløbsdato synkroniseret, hvis den er ændret i C1ST
        $expirationTimestamp = $this->parseC1STExpirationDate($expirationdate);
        if ($expirationTimestamp !== false) {
            update_post_meta($postID, "_ywgc_expiration", $expirationTimestamp);
            update_post_meta($postID, "_ywgc_expiration_date_formatted", date_i18n(apply_filters('yith_wcgc_date_format', 'Y-m-d'), $expirationTimestamp));
        }
                            

        return new WP_REST_Response('Gift Card Updated!', 200);
    }

/**
 * Register custom API endpoints for handling giftcard creation and update.
 *
 * This function registers two custom REST API endpoints under the namespace 'c1st/v1':
 * - '/giftcard.created' for handling POST requests related to giftcard creation.
 * - '/giftcard.updated' for handling PUT requests related to giftcard updates.
 * Each endpoint specifies a callback function within the current class instance.
 *
 * @link https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
 */
    private function validateC1STWebhookRequest($request) {
        if (empty($this->restsecret)) {
            error_log('C1ST webhook rejected: REST hook secret is not configured.');
            return new WP_Error('c1st_secret_missing', 'Webhook secret is not configured.', array('status' => 503));
        }

        $signature = (string) $request->get_header('X-C1st-Webhook-Signature');
        $payload = (string) $request->get_body();
        $calculated_hmac = base64_encode(hash_hmac('sha256', $payload, $this->restsecret, true));

        if ($signature === '' || !hash_equals($calculated_hmac, $signature)) {
            return new WP_Error('c1st_invalid_signature', 'Webhook failed signature check.', array('status' => 401));
        }

        $decoded = json_decode($payload);
        if (!is_object($decoded) || !isset($decoded->content) || !is_object($decoded->content)) {
            return new WP_Error('c1st_invalid_payload', 'Webhook payload is not valid JSON.', array('status' => 400));
        }

        return $decoded;
    }

    private function findWooGiftCardPostId($giftcardno) {
        $query = new WP_Query(array(
            'post_type'              => 'gift_card',
            'post_status'            => 'any',
            'title'                  => sanitize_text_field($giftcardno),
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        return !empty($query->posts) ? (int) $query->posts[0] : 0;
    }

    private function updateWooGiftCardFromC1ST($postID, $content, $created = false) {
        $amount = isset($content->amount) ? max(0, (float) $content->amount) : 0;
        $amountspent = isset($content->amountspent) ? max(0, (float) $content->amountspent) : 0;
        $remaining = max(0, $amount - $amountspent);
        $expired = !empty($content->expired);
        $deleted = !empty($content->deleted) || !empty($content->deleted_at);
        $expirationdate = isset($content->expirationdate) ? $content->expirationdate : '';

        $this->processing_inbound_webhook = true;
        try {
            update_post_meta($postID, '_ywgc_amount_total', $amount);
            update_post_meta($postID, '_ywgc_balance_total', $remaining);

            $current_notes = (string) get_post_meta($postID, '_ywgc_internal_notes', true);
            $action = $created ? 'created' : 'updated';
            $note = date_i18n('d-m-Y H:i:s') . ": Gift card {$action} from C1ST; balance {$remaining}.";
            update_post_meta($postID, '_ywgc_internal_notes', trim($current_notes . "\r\n" . $note));

            $expirationTimestamp = $this->parseC1STExpirationDate($expirationdate);
            if ($expirationTimestamp !== false) {
                update_post_meta($postID, '_ywgc_expiration', $expirationTimestamp);
                update_post_meta($postID, '_ywgc_expiration_date_formatted', date_i18n(apply_filters('yith_wcgc_date_format', 'Y-m-d'), $expirationTimestamp));
            }

            $target_status = ($deleted || $expired) ? 'ywgc-disabled' : 'publish';
            if (get_post_status($postID) !== $target_status) {
                wp_update_post(array('ID' => $postID, 'post_status' => $target_status));
            }
        } finally {
            $this->processing_inbound_webhook = false;
        }
    }

    public function secure_c1st_giftcard_created_api_endpoint($request) {
        $payload = $this->validateC1STWebhookRequest($request);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $content = $payload->content;
        $giftcardno = isset($content->giftcardno) ? sanitize_text_field($content->giftcardno) : '';
        if ($giftcardno === '' || !isset($content->amount)) {
            return new WP_Error('c1st_missing_fields', 'Gift card number or amount is missing.', array('status' => 400));
        }

        $postID = $this->findWooGiftCardPostId($giftcardno);
        if ($postID) {
            $this->updateWooGiftCardFromC1ST($postID, $content, false);
            return new WP_REST_Response(array('message' => 'Gift card already existed and was synchronized.', 'id' => $postID), 200);
        }

        $this->processing_inbound_webhook = true;
        try {
            $postID = wp_insert_post(array(
                'post_title'   => $giftcardno,
                'post_content' => '',
                'post_status'  => (!empty($content->expired) || !empty($content->deleted) || !empty($content->deleted_at)) ? 'ywgc-disabled' : 'publish',
                'post_author'  => get_current_user_id() ?: 1,
                'post_type'    => 'gift_card',
            ), true);
        } finally {
            $this->processing_inbound_webhook = false;
        }

        if (is_wp_error($postID)) {
            error_log('C1ST gift card creation failed: ' . $postID->get_error_message());
            return new WP_Error('c1st_create_failed', 'Gift card could not be created.', array('status' => 500));
        }

        $this->updateWooGiftCardFromC1ST((int) $postID, $content, true);
        return new WP_REST_Response(array('message' => 'Gift card created.', 'id' => (int) $postID), 201);
    }

    public function secure_c1st_giftcard_updated_api_endpoint($request) {
        $payload = $this->validateC1STWebhookRequest($request);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $content = $payload->content;
        $giftcardno = isset($content->giftcardno) ? sanitize_text_field($content->giftcardno) : '';
        if ($giftcardno === '' || !isset($content->amount) || !isset($content->amountspent)) {
            return new WP_Error('c1st_missing_fields', 'Gift card number, amount or amount spent is missing.', array('status' => 400));
        }

        $postID = $this->findWooGiftCardPostId($giftcardno);
        if (!$postID) {
            return new WP_Error('c1st_giftcard_not_found', 'Gift card not found in WooCommerce.', array('status' => 404));
        }

        $this->updateWooGiftCardFromC1ST($postID, $content, false);
        return new WP_REST_Response(array('message' => 'Gift card updated.', 'id' => $postID), 200);
    }

    function register_c1st_giftcard_api_endpoints()
    {
        register_rest_route('c1st/v1', '/giftcard.created', array(
            'methods' => 'POST',
            'callback' => array($this, 'secure_c1st_giftcard_created_api_endpoint'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('c1st/v1', '/giftcard.updated', array(
            'methods' => 'POST',
            'callback' => array($this, 'secure_c1st_giftcard_updated_api_endpoint'),
            'permission_callback' => '__return_true',
        ));
    }



/**
 * Recursively search for all occurrences of a key-value pair in a multidimensional array.
 *
 * This function recursively searches through a multidimensional array to find all occurrences
 * where a specific key has a given value. It returns an array of all matching subarrays.
 *
 * @param array $array The array to search through.
 * @param string|int $key The key to search for.
 * @param mixed $value The value of the key to match.
 * @return array An array of subarrays where the key matches the given value.
 */
  /*  function search($array, $key, $value)
    {
        $results = array();

        if (is_array($array)) {
            if (isset($array[$key]) && $array[$key] == $value) {
                $results[] = $array;
            }

            foreach ($array as $subarray) {
                $results = array_merge($results, $this->search($subarray, $key, $value));
            }
        }

        return $results;
    }
    */

}



$wcioWGCSSP = new wcioWGCSSP();
//$giftcardPlugin = $wcioWGCSSP->giftcardplugin;
//include(dirname(__FILE__) . "/includes/yith-woocommerce-gift-cards.php");
//$service = new wcioWGCSSPservice();
