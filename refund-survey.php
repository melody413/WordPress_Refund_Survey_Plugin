<?php
/**
 * Plugin Name: Automated Refunds
 * Description: Plugin for refund process
 * Author: Georgiy Sharovarskiy, ChatGPT
 * Text Domain: refund
 */

defined( 'ABSPATH' ) || exit;



//---------------Load the style file---------------//

function refund_survey_enqueue_styles() {
    wp_enqueue_style( 'refund-survey', plugin_dir_url( __FILE__ ) . '/assets/css/refund-survey.css' );
}
add_action( 'wp_enqueue_scripts', 'refund_survey_enqueue_styles' );



//---------------functionality to (check) create a refund survey table---------------//

function check_create_refund_survey_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'wc_refund_survey'; 
  
    // Check if the table already exists
    if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
        // Table doesn't exist, so create it
        $charset_collate = $wpdb->get_charset_collate();

        // Define the SQL statement for creating the table
        $sql = "CREATE TABLE " . $table_name . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint NOT NULL,
            refund_reason int NOT NULL,
            refund_feedback text NOT NULL,
            order_id bigint NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) " . $charset_collate . ";";

        // Execute the SQL statement to create the table
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Run the table creation function on WordPress initialization
add_action('init', 'check_create_refund_survey_table');


//---------------functionality to add refund button---------------//

function add_refund_button( $actions, $order ) {
    if ( $order->get_status() != 'completed' ) return $actions; // Only show for completed orders

    $actions['refund'] = array(
        'url'  => site_url( 'refund-confirm/?order_id=' . $order->get_id()),
        'name' => __( 'Refund', 'woocommerce' )
    );
    return $actions;
}

add_filter( 'woocommerce_my_account_my_orders_actions', 'add_refund_button', 10, 2 );


//---------------refund process---------------//

function handle_refund_process() {
    if ( !isset( $_GET['refund_nonce'] ) || !wp_verify_nonce( $_GET['refund_nonce'], 'process_refund' ) || !isset( $_GET['order_id'] ) ) {
        die( 'Invalid request.' );
    }

    $order_id = intval( $_GET['order_id'] );
  
    $order = wc_get_order( $order_id );
    $payment_method = $order->get_payment_method();
    
    if ( $order && $order->get_customer_id() == get_current_user_id() ) {
        $payment_gateways = WC_Payment_Gateways::instance();

        if ($payment_method == 'stripe') {
            $payment_gateway = $payment_gateways->payment_gateways()['stripe'];
        } elseif ($payment_method == 'paypal') {
            $payment_gateway = $payment_gateways->payment_gateways()['paypal'];
        } else {
            wc_add_notice( __( 'This order was not paid through Stripe or PayPal and cannot be automatically refunded.', 'woocommerce' ), 'error' );
            header("Location: " . '/refund-survey-success/');
            exit;
        }

        // Process the refund through the payment gateway (Stripe or PayPal)
        $result = $payment_gateway->process_refund($order_id, $order->get_total(), 'Customer initiated refund from My Account page');

        if ($result) {
            // Refund the order locally
            $refund = wc_create_refund(array(
                'order_id' => $order->get_id(),
                'amount'   => $order->get_total(),
                'reason'   => 'Customer initiated refund from My Account page',
            ));

            // Check for subscriptions in the order and cancel them
            $subscriptions = wcs_get_subscriptions_for_order($order_id);
            foreach ($subscriptions as $subscription) {
                $subscription->update_status('cancelled', 'Order was refunded, subscription automatically cancelled.');
            }

            wc_add_notice( __( 'Your order and any associated subscriptions have been refunded and cancelled.', 'woocommerce' ), 'notice' );
        } else {
            wc_add_notice( __( "Error processing refund through {$payment_method}. Please contact us for assistance.", 'woocommerce' ), 'error' );
        }
    }
    wp_safe_redirect( home_url( '/refund-survey-success/' ) );
    exit;

}


add_action( 'wp_ajax_process_refund', 'handle_refund_process' );
add_action( 'wp_ajax_nopriv_process_refund', 'handle_refund_process' );







//---------------Refund confirm page---------------//
function refund_confirm() {
    ob_start();
    // Content of the form
    ?>

    <div style="text-align: center">
        <h5 class="refund-confirm-page-title">Do you want to continue with your refund, or choose another program?</h5>
    <p>We'd be happy to switch you over to another program that is a better fit for you. This is free of charge.</p>
    <?php if( isset($_GET['order_id']) ) : ?>
          <input type="hidden" name="order_id" value="<?php echo esc_attr( $_GET['order_id'] ); ?>">
        <?php endif; ?>
    
  <div class="refund-confirm-page-main-content">
    <?php $refundSurveyLink = '/refund-survey/?order_id=' . esc_attr( $_GET['order_id'] ); ?>
    <a href="/request-program" class="no-keep-learning-btn">Choose Program</a>
          <a href="<?php echo $refundSurveyLink; ?>" class="cancel-refund-btn">Cancel and Refund</a>
  </div>
    </div>

    <?php
    return ob_get_clean();
}
add_shortcode('refund_confirm', 'refund_confirm');



//---------------Survey Form page---------------//

function survey_form() {
    ob_start();

    // Content of the form
    ?>

  <form class="survey_form" method="post">
    <div class="survey_reason_title">
      What was the main reason for your refund?
    </div>
    <?php
        $showReasonError = isset($_POST['submit']) && !isset($_POST['refund_reason']);
        $errorDisplay = $showReasonError ? 'block' : 'none';
        ?>
        <span id="refundReasonError" style="color: red; margin-top: 5px; display: <?php echo $errorDisplay; ?>">*Please select the reason</span>
    
    <div id="itemContainer">
      <div class="item_list">
        <div class="item_content">
          <div style="display: flex; width: 100%;">
            <div class="No_text">A</div>
            <input id="inputA" type="radio" class="choice_input" name="refund_reason" value="0" >
            <label style="padding-left: 10px">I'm too busy to watch the content</label>
          </div>
        </div>
      </div>
      <div class="item_list">
        <div class="item_content">
          <div style="display: flex; width: 100%;">
            <div class="No_text">B</div>
            <input id="inputB" type="radio" class="choice_input" name="refund_reason" value="1" >
            <label style="padding-left: 10px">I couldn't afford the program</label>
          </div>
        </div>
      </div>
      <div class="item_list">
        <div class="item_content">
          <div style="display: flex; width: 100%;">
            <div class="No_text">C</div>
            <input id="inputC" type="radio" class="choice_input" name="refund_reason" value="2">
            <label style="padding-left: 10px">The platform isn't working for me</label>
          </div>
        </div>
      </div>
      <div class="item_list">
        <div class="item_content">
          <div style="display: flex; width: 100%;">
            <div class="No_text">D</div>
            <input type="radio" class="choice_input" name="refund_reason" value="3">
            <label style="padding-left: 10px">I never really got started</label>
          </div>
        </div>
      </div>
      <div class="item_list">
        <div class="item_content">
          <div style="display: flex; width: 100%;">
            <div class="No_text">E</div>
            <input type="radio" class="choice_input" name="refund_reason" value="4">
            <label style="padding-left: 10px">The content just isn't for me.</label>
          </div>
        </div>
      </div>       
    </div>
    
    <div class="survey-feedback-group">
      <div style="position: relative; display: inline-flex; flex-direction: column; min-width: 100%; width: 100%" >
        <textarea name="refund_feedback" class="feedback_textarea" placeholder="How can we improve? (Optional)" aria-label="How can we improve? (Optional)"></textarea>
      </div>
    </div>

    <div class="survey-button-group">
      <?php if( isset($_GET['order_id']) ) : ?>
      <input type="hidden" name="order_id" value="<?php echo esc_attr( $_GET['order_id'] ); ?>">
      <?php endif; ?>      
      <input id="keep_btn" type="button" class="no-keep-learning-btn" value="No, keep learning"/>
      <input type="submit" name="submit" class="cancel-refund-btn" value="Cancel and Refund" />
    </div>
  </form>
       
  <script>
    // Get the parent container
    const itemContainer = document.getElementById('itemContainer');
    // Add event listener to the parent container
    itemContainer.addEventListener('change', (event) => {
    // Remove the 'selected' class from all item lists
    document.querySelectorAll('.item_content').forEach((item) => {
      item.classList.remove('selected');
    });
      
    const selectedInput = event.target;
    if (selectedInput.classList.contains('choice_input')) {
      const listItem = selectedInput.closest('.item_content');
      listItem.classList.add('selected');
      document.getElementById('refundReasonError').style.display = 'none';
      }
    });
    
    //Keep button Action
    document.getElementById("keep_btn").addEventListener("click", function() {
      window.location.href = "/my-courses"; // Replace with your desired link
    });
  </script>
    <?php

    return ob_get_clean();
}

add_shortcode('survey_form', 'survey_form');





//---------------Handle form submission---------------//
function handle_form_submission() {
  
    $current_user = wp_get_current_user();
    global $wpdb;

    if (isset($_POST['submit'])) {
        $order_id        = sanitize_text_field($_POST['order_id']);
        $refund_feedback = sanitize_textarea_field($_POST['refund_feedback']);
    if (!isset($_POST['refund_reason'])) {
            ob_start();
            ?>
            <script>
                document.getElementById('refundReasonError').style.display = 'block';
            </script>
            <?php
            return ob_get_clean();
        }
        $refund_reason   = $_POST['refund_reason'];
        $table_name = $wpdb->prefix . 'wc_refund_survey';
        $data       = array(
          'user_id'     => $current_user->ID,
          'refund_reason'   => $refund_reason,
          'refund_feedback' => $refund_feedback,
          'order_id'        => $order_id,
          'created_at'      => current_time('mysql'),
        );
        
        // Prepare the SQL statement
        $prepared_statement = $wpdb->prepare(
            "INSERT INTO $table_name (user_id, refund_reason, refund_feedback, order_id, created_at) VALUES (%d, %d, %s, %d, %s)",
            $data['user_id'],
            $data['refund_reason'],
            $data['refund_feedback'],
            $data['order_id'],
            $data['created_at']
        );

        // Insert refund information into table using prepared statement
        $wpdb->query($prepared_statement);
        
        $orderID = $_GET['order_id'];
        $refundUrl = wp_nonce_url(admin_url('admin-ajax.php?action=process_refund&order_id=' . $orderID), 'process_refund', 'refund_nonce');
        $refundUrl = htmlspecialchars_decode($refundUrl);
        header("Location: " . $refundUrl);
        exit;
    }
}

add_action('init', 'handle_form_submission');

//--------------Refund success page---------------//
function refund_success_message() {
    ob_start();

    // Content of the form
    ?>
    <div class="refund-success">
       
       <div class="refund-success-content">
         <h1 class="refund-success-title">Your refund is on the way</h1>
        Your refund request has been received. Once your refund request is processed, you should receive an email with the confirmation of refund.<br>
        <br>
        If you do not receive the confirmation within 48 hours, please contact customer support at socialself.com/contact.<br>
        <br>
        The payment should show in your account in 7-14 days, but some banks take longer. If you do not see the payment, contact your bank.
       </div>
       <div class="refund-success-btn-group" >
        <button class="refund-success-btn" id="returnHomeButton">
         Return Home
        </button>
       </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
      var returnHomeButton = document.getElementById('returnHomeButton');
      returnHomeButton.addEventListener('click', function() {
        window.location.href = '/my-account';
      });
    });
    </script>
    <?php

    return ob_get_clean();
}

add_shortcode('refund_success_message', 'refund_success_message');


//---------------Custom admin page---------------//
function custom_admin_page() {
    ?>
    <div class="wrap">
        <h1>Refund Survey Page</h1>
    <style>
      table.refund-survey-table {
        border-collapse: collapse;
        width: 100%;
        margin-top: 20px;
      }
      table.refund-survey-table th, table.refund-survey-table td {
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid #ddd;
      }
    </style>
          <?php
      global $wpdb;
      $refunds = $wpdb->get_results("SELECT * FROM wp_amw_wc_refund_survey ORDER BY ID DESC;");

      echo "<div style='background: white; padding: 30px'>
              <div><button id='deleteButton' style='font-size: 17px; color: #646970; border-radius: 3px; border: 1px solid'>Delete All Data</button></div>";

      if (empty($refunds)) {
          echo "<table class='widefat fixed refund-survey-table'>";
          echo "<thead><tr>";
          echo "<th>ID</th>";
          echo "<th>User ID</th>";
          echo "<th>User Name</th>";
          echo "<th>Refund Reason</th>";
          echo "<th>Refund Feedback</th>";
          echo "<th>Order ID</th>";
    	   echo "<th>Order Name</th>";
          echo "<th>Refund At</th>";
          echo "</tr></thead>";
          echo "<tbody>";
          echo "<tr><td colspan='7' style='text-align: center; font-size: 20px; padding: 23px;'>No data available</td></tr>";
          echo "</tbody>";
          echo "</table>";
      } else {
          echo "<table class='widefat fixed refund-survey-table'>";
          echo "<thead><tr>";
          echo "<th>ID</th>";
          echo "<th>User ID</th>";
          echo "<th>User Name</th>";
          echo "<th>Refund Reason</th>";
          echo "<th>Refund Feedback</th>";
          echo "<th>Order ID</th>";
              echo "<th>Order Name</th>";
          echo "<th>Refund At</th>";
          echo "</tr></thead>";
          echo "<tbody>";
          foreach ($refunds as $refund) {
              echo "<tr>";
          echo "<td>".$refund->id."</td>";
          $CusID = $refund->id;
          $user_name = $wpdb->get_results("SELECT user_login FROM wp_amw_users WHERE ID =" . $refund->user_id .";");
          echo "<td>". $refund->user_id ."</td>";
          echo "<td>". $user_name[0]->user_login."</td>";     
          switch($refund->refund_reason){
            case '0':
                echo "<td>I'm too busy to watch the content</td>";
                break;
            case '1':
                echo "<td>I couldn't afford the program</td>";
                break;
            case '2':
                echo "<td>The platform isn't working for me</td>";
                break;
            case '3':
                echo "<td>I never really got started</td>";
                break;
            case '4':
                echo "<td>The content just isn't for me.</td>";
                break;
        }
      echo "<td>".$refund->refund_feedback."</td>";
      
      $item_name = $wpdb->get_results("SELECT order_item_name FROM wp_amw_woocommerce_order_items WHERE order_id =" . $refund->order_id .";");
      echo "<td>".$refund->order_id."</td>";
      echo "<td>".$item_name[0]->order_item_name."</td>";
      echo "<td>".$refund->created_at."</td>";
      echo "</tr>";          
}
          echo "</tbody>";
          echo "</table>";
      }

      echo "</div>";
    ?>
    </div>
  <script>
    document.getElementById('deleteButton').addEventListener('click', function() {
    if (confirm("Are you really delete the data of this table") == true) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
          alert('Data deleted successfully!');
              window.location.reload(); // Reload the page after deleting the data

        }
        };
        xhr.send('action=delete_survey_data');
      } else {
      return;
      }
    });
  </script>
    <?php
}

function delete_survey_data() {
  global $wpdb;
  $table_name = 'wp_amw_wc_refund_survey';
  $wpdb->query("TRUNCATE TABLE $table_name");

  die(); // Terminate the script execution
}


function add_custom_admin_page() {
    add_menu_page(
        'Refund Survey Table',
        'Refund Survey',
        'manage_options',
        'refund_survey_page',
        'custom_admin_page',
        'dashicons-chart-pie',
        2
    );
}
add_action('admin_menu', 'add_custom_admin_page');

add_action('wp_ajax_delete_survey_data', 'delete_survey_data');
add_action('wp_ajax_nopriv_delete_survey_data', 'delete_survey_data');

