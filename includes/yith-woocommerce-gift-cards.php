<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
      exit;
}


// Extend the class with our serviceclass
class wcioWGCSSPservice extends wcioWGCSSP
{

      public  $token;

      public function __construct()
      {

            $this->token = get_option("wc_wciowgcssp_token"); // Customers 1st. token

            // Run this code if the gift card plugin is Woo Gift Card
           add_action('admin_init',  array($this, 'ywgc_code_pattern'));
		  
	
      }
	
	
      function ywgc_code_pattern()
      {

            $word = "-";

            // Test if string contains the word 
            if (strpos(get_option("ywgc_code_pattern"), $word) !== false) {

                  update_option('ywgc_code_pattern', '************');
            }
      }

      //  Tjekker WooCommerce Gift Cards og opretter dem i Customers 1st. hvis de ikke allerede findes. Hvis de findes i Customers 1st. gør den ikke mere.
      function wcio_wgcssp_cron_sync_woo_service_pos()
      {

            global $wpdb;
            $table_prefix = $wpdb->prefix;
            $WooCommerceGiftCardTableName = "posts";

            // If its less than 5 minutes ago since last action, then dont? allow this ro run again.
            $wcio_wgcssp_last_action = get_option('wcio_wgcssp_last_action');
            if ($wcio_wgcssp_last_action > (time() - 300)) {
                  return;
            }

            // Start run
            $this->logging("Started <strong>wcio_wgcssp_cron_sync_woo_service_pos</strong> function.", "");

            // Update last action
            update_option('wcio_wgcssp_last_action', time());

            // Get Gift cards from database
            $wooGiftCards = $wpdb->get_results("SELECT * FROM $table_prefix$WooCommerceGiftCardTableName WHERE post_type = 'gift_card' ORDER BY ID DESC");

           
            // Get ServicePOS giftcards
            // Sets the amount of gift cards per page.
            $paginationPageLength = 249; // 250 is new Customers 1st. limit in future releases

            // Make the full list of giftcards from Customers 1st.
            $servicePOSGiftcards = array();
            $x = 0;
            while (true) {

                  // We need to loop all pages
                  // Now we do the query with the paging.
                  $paginationStart = $paginationPageLength * $x;
                  $query = array("paginationPageLength" => $paginationPageLength, "paginationStart" => $paginationStart, "scope" => "sharegiftcards"); // Start from page 1 (0)
                  $queryGiftcards = $this->call("GET", "/giftcards", $query);

                  // Log the data. We cannot use $giftcards in the log data. For some reason it cannot save it.. DB character limit possible. Using MEDIUMTEXT or LONGTEXT might fix it.
                  $this->logging("Called GET /giftcards with parameters <strong>\"paginationPageLength\" => $paginationPageLength, \"paginationStart\" => $paginationStart</strong><br><strong>Count:</strong> " . count($queryGiftcards["content"]) . "", "");

                                    // Check for errors
                if($queryGiftcards == null || $queryGiftcards == "error") {
              
                        // Log the data. We cannot use $giftcards in the log data. For some reason it cannot save it.. DB character limit possible. Using MEDIUMTEXT or LONGTEXT might fix it.
                        $this->logging("No response from API or error with authentication.", "");
                        break;    

                  }
                  
                  // Merge all giftcards from Customers 1st. into one array.
                  $servicePOSGiftcards = array_merge($servicePOSGiftcards, $queryGiftcards["content"]);

                  // If hasMore is false, break
                  if ($queryGiftcards["hasMore"] == false || $queryGiftcards == null) {
                        break;
                  }
                  $x++;
            }


            // Loop all WooCommerce giftcards then in each giftcard we loop Customers 1st. to find it.
            foreach ($wooGiftCards as $card) {

                  // Update last action
                  update_option('wcio_wgcssp_last_action', time());

                  $code = $card->post_title; // YITH
                  //$balance = floatval(get_post_meta($card->ID, "_ywgc_amount_total", true));  // This is initial balance
                  //$remaining = floatval(get_post_meta($card->ID, "_ywgc_balance_total", true)); // This is remaining

		   // Check if "_ywgc_amount_total" exists, if not continue to the next iteration.
               $balance_meta = get_post_meta($card->ID, "_ywgc_amount_total", true);
               if ( $balance_meta === false ) {
                   continue; // Skip to the next card if meta doesn't exist.
               }
               $balance = floatval($balance_meta);
           
               // Check if "_ywgc_balance_total" exists, if not continue to the next iteration.
               $remaining_meta = get_post_meta($card->ID, "_ywgc_balance_total", true);
               if ( $remaining_meta === false ) {
                   continue; // Skip to the next card if meta doesn't exist.
               }
               $remaining = floatval($remaining_meta);

               
                 $balance = floatval(get_post_meta($card->ID, "_ywgc_amount_total", true));  // This is initial balance
                 $remaining = floatval(get_post_meta($card->ID, "_ywgc_balance_total", true)); // This is remaining


                  $searchGiftCardRaw = $this->search($servicePOSGiftcards, 'giftcardno', $this->codeToServicePos($code));
                  $searchGiftCardCount = count($searchGiftCardRaw);

                  if ($searchGiftCardCount > 0) {

                        $giftcard = $searchGiftCardRaw[0];

                        // First check if the giftcard is available in Customers 1st. variable
                        // We cannot break this loop until we auctually find it, because we have to check all cards
                        if ($this->codeToServicePos($code) == $giftcard["giftcardno"] && $giftcard["giftcardno"] != "") {

                              // If gift card was found at ServicePOS.
                              // Match values to make sure this isnt an outdated card.
                              //$servicePOSAmount = $queryGiftcards["content"]["0"]["amount"]; // Overwridden to fix error.
                              $servicePOSAmountRemaining = floatval($giftcard["amount"]) - floatval($giftcard["amountspent"]); // Full amount minus amount spent gives remaining

                              // The amounts wasnt the same, and they should be. Find the card with most spent and update the other.
                              // If the card in WooCommerce have been used more then the one in Customers 1st., then update Customers 1st.
                              if ($remaining < $servicePOSAmountRemaining) {

                                    // If WooCommerce gift card ave more spent on it, then we need to update Customers 1st.
                                    // Now updat the amount spent.
                                    $servicePOSAmountSpent = floatval($giftcard["amount"]) - $remaining; // Full amount minus the remaining from wooCommerce gives the amount spent
                                    $giftcardData = [
                                          'amountspent' => (float)$servicePOSAmountSpent
                                    ];

                                    if ($remaining != $servicePOSAmountRemaining) {

                                          // Update giftcard in Customers 1st.
                                          $updateServicePOSGiftcard = $this->call("PUT", "/giftcards/" . $giftcard["id"] . "", ['content' => $giftcardData]);

                                          // Update giftcard in Customers 1st.
                                          $this->logging("Called PUT /giftcards/" . $giftcard["id"] . " with content <strong>" . json_encode($giftcardData) . "</strong><br>
                                                      Giftcard: " . $giftcard["id"] . " will be updated in ServicePOS with this new value for AMOUNTSPENT: <strong>$servicePOSAmountSpent</strong>.<br>
                                                      Because remaining ($remaining) is LOWER than servicePOSAmountRemaining (" . $giftcard["amount"] . " - " . $giftcard["amountspent"] . ").<br>
                                                      Old value in ServicePOS for AMOUNTSPENT: <strong>$servicePOSAmountRemaining (" . $giftcard["amount"] . " - " . $giftcard["amountspent"] . ")</strong>", $code);
                                          continue;
                                    } else {

                                          // This code does nothing other than updating something with the axact same value...
                                          // Customers 1st. have most spent, then update WooCommerce
                                          // CodeToWoo Not needed, this stems from Woo.
                                          $updateWooGiftCard = $wpdb->query($wpdb->prepare("UPDATE $table_prefix$WooCommerceGiftCardTableName SET remaining='$servicePOSAmountRemaining' WHERE code='$code'"));
                                          // Update woo
                                          $this->logging("Giftcard: " . $code . " will be updated in WooCommerce with this new remaining value: $servicePOSAmountRemaining<br>
                                                      This happens because the remaning value WooCommerce value: $remaining is equal to ServicePOS value: $servicePOSAmountRemaining and needs to be updated", $code);
                                          continue;
                                    }
                              }
                        }
                  } else if ($searchGiftCardCount == 0) { // IF card wasnt found in Customers 1st. query

                        // It wasnt dead, now create it in Customers 1st. since its not there. 
                        // First check what the balance auctually is.
                        if ($balance == 0) {

                              // The balance was 0, most likely due to the card wasnt created with a balance, then we need to use remaining as balance and fix the card.
                              $giftcardAmount = $remaining;
                        } else {

                              $giftcardAmount = $balance;
                        }

                        // Make sure we dont send empty cards
                        if ($this->codeToServicePos($code) == "") {
                              continue;
                        }

                        $giftcard = [
                              "giftcardno" => $this->codeToServicePos($code),
                              "amount" => (float)$giftcardAmount,
                              "type" => "giftcard",
                              "customer" => array(
                                    "name" => "",
                                    "email" => "",
                              )
                        ];

                        $createServicePOSGiftcard = $this->call("POST", "/giftcards",  ['content' => $giftcard]);
                        $this->logging("Giftcard: " . $this->codeToServicePos($code) . " will be created in ServicePOS with this value: <strong>$giftcardAmount</strong>. Content: <strong>" . json_encode($giftcard) . "</strong>.<br>", $this->codeToServicePos($code));
                        continue;
                  }
            }

            $this->logging("Stopped <strong>wcio_wgcssp_cron_sync_woo_service_pos</strong> function.", "");
      }

   

      // Skal bruges for alle kort der stammer fra Woo og som skal til Customers 1st.
      // This can only be removed in 22-03-2026 due to giftcard expire dates. 
       function codeToServicePos($code)
{
      $word = "-";
      $sectionLength = 4;

      // Test if string contains the word "-" and if each section has 4 characters
      $sections = explode('-', $code);
      $allSectionsValid = true;
      foreach ($sections as $section) {
            if (strlen($section) != $sectionLength || !preg_match('/^[a-zA-Z0-9]+$/', $section)) {
                  $allSectionsValid = false;
                  break;
            }
      }

      if (strpos($code, $word) !== false && $allSectionsValid) {
            // Input: XXXX-XXXX-XXXX-XXXX
            // Output: 724503989151  (12 char)
            $code = str_replace("XXXX", "", $code); // Remove X's that have been added to match format
            $code = str_replace("-", "", $code); // Remove hyphens
            return $code; // Output the original code without X's and hyphens
      } else {
            return str_replace("--", "", str_replace("XXXX", "", $code)); // If the code doesn't meet the criteria, remove X's and return it as is
      }
}
      

      // Skal bruges for alle kort der stammer fra Customers 1st. og som skal til Woo
 function codeToWoo($code)
{
      $word = "-";
      $sectionLength = 4;

      // Test if string contains the word "-" and if each section has 4 characters
      $sections = explode('-', $code);
      $allSectionsValid = true;
      foreach ($sections as $section) {
            if (strlen($section) != $sectionLength || !preg_match('/^[a-zA-Z0-9]+$/', $section)) {
                  $allSectionsValid = false;
                  break;
            }
      }

      if (strpos($code, $word) !== false && $allSectionsValid) {
            // Input: 7245-0398-9151  (12 char)
            // Output XXXX-XXXX-XXXX-XXXX
            $number = str_pad($code, 16, "X", STR_PAD_RIGHT); // Pad the code to 16 characters with X
            $str = chunk_split($number, 4, '-'); // Split the code into groups of 4 characters separated by -
            $str = substr($str, 0, -1); // Remove the last hyphen
            
            // Fix for double hyphens after change
            $str = str_replace("--", "-", $str); // Replace any double hyphens with single hyphens
            return $str; // Return the transformed code
      } else {
            return $code; // If the code doesn't meet the criteria, return it as is
      }
}
}
