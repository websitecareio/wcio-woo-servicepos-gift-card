<?php

/**
 * Plugin Name: Woo Gift Cards synchronize Customers 1st. 
 * Plugin URI: https://websitecare.dk/
 * Description: Synchronize WooCommerce gift cards with Customers 1st. 
 * Version: 1.4.4
 * Author: Websitecare.dk
 * Author URI: https://websitecare.dk
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

include("wooCommerceSettings.php");

/** * Override YITH Gift Card deduction on draft orders * */ 
if ( ! function_exists( 'override_yith_giftcard_deduction' ) ) {
    add_action('woocommerce_new_order_item', 'override_yith_giftcard_deduction', 999, 3); 
    function override_yith_giftcard_deduction($item_id, $item, $order_id) { 
        if ($item instanceof WC_Order_Item_Coupon) { 
            // get the order 
            $order = wc_get_order($order_id); 
            // if the order is draft, add the amount back to the gift card 
            if ($order->get_status() == 'checkout-draft') { 
                $code = $item->get_code(); 
                $gift = YITH_YWGC()->get_gift_card_by_code($code); 
                if ($gift instanceof YITH_YWGC_Gift_Card) { 
                    $discount_amount = $item->get_discount(); 
                    $gift->update_balance($gift->get_balance() + $discount_amount); 
                } 
            } 
        }
    } 
}

/** * Deduct after the order is placed */
if (!function_exists('override_yith_giftcard_deduction')) {

    add_action('woocommerce_order_payment_status_changed', 'deduct_gift_card_balance', 10, 3);

    function deduct_gift_card_balance($order_id, $new_status, $old_status)

    {

        if ( 'checkout-draft' == $old_status && ( 'processing' == $new_status || 'completed' == $new_status ) )

        // Get the order

        $order = wc_get_order($order_id);

        // If the order contains coupons, deduct the amount from the gift card

        if ($order->get_coupon_codes()) {

            foreach ($order->get_coupon_codes() as $code) {

                $gift = YITH_YWGC()->get_gift_card_by_code($code);

                if ($gift instanceof YITH_YWGC_Gift_Card) {

                    // Calculate the discount amount for this coupon

                    $discount_amount = 0;

                    foreach ($order->get_items('coupon') as $coupon_item) {

                        if ($coupon_item->get_code() === $code) {

                            $discount_amount += $coupon_item->get_discount();

                        }

                    }

                    $gift->update_balance($gift->get_balance() - $discount_amount);

                }

            }

        }

    }

}


/* Main plugin functions */
class wcioWGCSSP
{

    public $token;
    public $giftcardplugin;

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

    $url = 'https://app.deltateq.com/api' . $endpoint;
    $curl = curl_init();
    switch ($method) {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);
            if ($data) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        default:
            if ($data) {
                $url = sprintf("%s?%s", $url, http_build_query($data));
            }
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $this->token,
    ));

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($status >= 300) {

// Make an error for the shop owner

        /*$this->logging("STATUS ERROR SERVICEPOS: <br>
        'endpoint' => $url<br>
        'status' => $status<br>
        'error' => ''<br>
        'method' => $method<br>
        'result' => $result<br>
        'token' => " . $this->token . "<br>", "");*/
        exit; // just stop script, to avoid loops.
    }
    curl_close($curl);

    sleep(1); // Delay execution for X seconds

    return json_decode($result, true);
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

        // Tjek om dette er en autosave eller en revision
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Get values
        $amount_total = get_post_meta($post_id, '_ywgc_amount_total', true);
        $balance_total = get_post_meta($post_id, '_ywgc_balance_total', true);
        $giftcardNo = $post->post_title;


        // Tjek om posttypen er 'gift_card'
        if ($post->post_type === 'gift_card') {


            // Check if value is empty
            if (empty($amount_total) || empty($balance_total)) {
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

                //Count if there is one
                if(count($searchQuerydata["content"]) == 1) {

                    // Update giftcard in C1ST
                    $giftcardId = $searchQuerydata["content"][0]["id"];
                    $this->updateGiftCardC1ST($giftcardId, $amount_total, $balance_total);
                    

                } else {

                    // If none found, then we need to create this giftcard instead.
                    $this->createGiftCardC1ST($giftcardNo, $amount_total, $balance_total);

                }

            } else {
                
                // New gift card
                // Send new giftcard to C1ST
                $this->createGiftCardC1ST($giftcardNo, $amount_total, $balance_total);

            }


        }
    }


    public function findGiftCardIdC1ST($giftcardNo) {

        $searchQuery = array("giftcardno" => $giftcardNo, "scope" => "sharegiftcards"); // Start from page 1 (0)
        $searchQuerydata = $this->call("GET", "/giftcards", $searchQuery);

         //Count if there is one
         if(count($searchQuerydata["content"]) == 1) {

            // Update giftcard in C1ST
            $giftcardId = $searchQuerydata["content"][0]["id"];
            return $giftcardId;

         }

         return false; 

    }

    public function updateGiftCardC1ST($giftcardId, $amount_total, $balance_total) {
        
        // Calculated amountspent.
        $amountspent = $amount_total-$balance_total;

        // Prepare array
         $giftcardData = [
                'amountspent' => (float)$amountspent
        ];
        
        $this->call("PUT", "/giftcards/$giftcardId", ['content' => $giftcardData]);

    }

    public function createGiftCardC1ST($giftcardNo, $amount_total, $balance_total) {

        // Calculated amountspent.
        $amountspent = $amount_total-$balance_total;

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

       $this->call("POST", "/giftcards",  ['content' => $giftcard]);

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


        $this->createGiftCardC1ST($gift_card->gift_card_number, $gift_card->total_amount, $total_balance);
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


        // Tjek om meta nøglen er en af de vi er interesseret i
        if ($meta_key === '_ywgc_balance_total') {

            // Update new value in C1ST
            $giftcardNo = get_the_title( $post_id ); // 858400776131
            $amount_total = get_post_meta($post_id, '_ywgc_amount_total', true);
            $balance_total = $meta_value;

            $giftcardId = $this->findGiftCardIdC1ST($giftcardNo);
            if($giftcardId) {

                $this->updateGiftCardC1ST($giftcardId, $amount_total, $balance_total);

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
    function register_c1st_giftcard_api_endpoints()
    {
        register_rest_route('c1st/v1', '/giftcard.created', array(
            'methods' => 'POST',
            'callback' => array($this, 'c1st_giftcard_created_api_endpoint'),
        ));
        register_rest_route('c1st/v1', '/giftcard.updated', array(
            'methods' => 'POST',
            'callback' => array($this, 'c1st_giftcard_updated_api_endpoint'),
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
