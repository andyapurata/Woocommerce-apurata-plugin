<?php
/**
 * Version:           0.2.5
 * Plugin Name:       WooCommerce aCuotaz Apurata Payment Gateway
 * Plugin URI:        https://github.com/apurata/woocommerce-apurata-payment-gateway
 * Description:       Finance your purchases with a quick aCuotaz Apurata loan.
 * Requires at least: 5.3.2
 * Requires PHP:      7.0.25
 * Author:            Apurata
 * Author URI:        https://app.apurata.com/
 * License:           GPL3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       woocommerce-apurata-payment-gateway
 *
 * WC requires at least: 3.8.1
 * WC tested up to: 4.5.1
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
const APURATA_TEXT_DOMAIN = 'woocommerce-apurata-payment-gateway';
const PLUGIN_ID = 'apurata';

// DEVELOPMENT ONLY: UNCOMMENT TO DISPLAY ERRORS IN HTML
// $OLD_ERROR_REPORTING_LEVEL = error_reporting(E_ALL | E_NOTICE | -1);
// $OLD_DISPLAY_ERRORS = ini_set('display_errors', 'stdout');

// Domain to use in browser
$APURATA_DOMAIN = getenv('APURATA_DOMAIN');
// Domain to use in API calls.
// In docker, there is a special IP for the host network.
// We use this IP to access the local apurata server from inside the container.
$APURATA_API_DOMAIN = getenv('APURATA_API_DOMAIN');

if ($APURATA_DOMAIN == false) {
    $APURATA_DOMAIN = 'https://apurata.com';
}
if ($APURATA_API_DOMAIN == false) {
    $APURATA_API_DOMAIN = $APURATA_DOMAIN;
}

if (!defined('WC_APURATA_BASENAME')) {
    define('WC_APURATA_BASENAME', plugin_basename(__FILE__));
}

// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    function apurata_log($message) {
        if (getenv('APURATA_DEBUG')) {
            error_log($message);
        }
    }

    // Add settings link on plugin page.
    add_action('plugins_loaded', 'wc_apurata_init');
    function update_pos_client_context() {
        if ( is_admin() ) {
            global $wp_version;
            if( ! function_exists( 'get_plugin_data' ) ) {
                require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            }

            $apurata_gateway = new WC_Apurata_Payment_Gateway();
            $url = "/pos/client/" . $apurata_gateway->client_id . "/context";
            $apurata_gateway->make_curl_to_apurata("POST", $url, array(
                "php_version" => PHP_VERSION,
                "wordpress_version" => $wp_version,
                "woocommerce_version" => WC_VERSION,
                "wc_acuotaz_version" => get_plugin_data( __FILE__ )["Version"],
            ), TRUE);
        }
    }
    function wc_apurata_add_settings_link_on_plugin_page($links) {
        try {
            update_pos_client_context();
        } catch (SomeException $e) {}

        $plugin_links = array();
        $plugin_links[] = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=' . PLUGIN_ID) . '">' . __('Ajustes', 'woocommerce-mercadopago') . '</a>';
        return array_merge($plugin_links, $links);
    }
    function wc_apurata_init() {
        add_filter('plugin_action_links_' . WC_APURATA_BASENAME, 'wc_apurata_add_settings_link_on_plugin_page');
    }
    // End of Add settings link on plugin page.


    // Display on each shop item "Págalo en cuotas con Apurata"
    // See: https://www.businessbloomer.com/woocommerce-visual-hook-guide-single-product-page/#more-19252
    add_action( 'woocommerce_before_add_to_cart_form', 'on_every_product' );
    function on_every_product() {
        global $product;
        $apurata_gateway = new WC_Apurata_Payment_Gateway();

        if ($product->is_type('variable')) {
            // Has different prices
            $available_variations = $product->get_available_variations();
            $single_price = $product->get_variation_sale_price('min', true) == $product->get_variation_sale_price('max', true);
            if ( count($available_variations) == 1 or $single_price) {
                $apurata_gateway->gen_pay_with_apurata_html("product", $product->get_price());
            }
            else {
                $apurata_gateway->gen_pay_with_apurata_html("product", $product->get_variation_sale_price('min', true), TRUE);
            }
        } else {
            $apurata_gateway->gen_pay_with_apurata_html("product", $product->get_price());
        }
    }
    // See: https://www.businessbloomer.com/woocommerce-visual-hook-guide-cart-page/#more-19167
    add_action( 'woocommerce_proceed_to_checkout', 'on_proceed_to_checkout', 15);
    // See: https://www.businessbloomer.com/woocommerce-visual-hook-guide-checkout-page/
    add_action( 'woocommerce_review_order_before_payment', 'on_proceed_to_checkout', 15);
    function on_proceed_to_checkout() {
        $apurata_gateway = new WC_Apurata_Payment_Gateway();
        $apurata_gateway->gen_pay_with_apurata_html("cart");
    }
    // End of Display on each shop item "Págalo en cuotas con Apurata"
    add_action('rest_api_init', function () {
        register_rest_route('apurata', '/order-state/(?P<id>\d+)', array(
          'methods' => 'GET',
          'callback' => 'send_state_func'
        ) );
    } );

    function send_state_func($data) {
        $order_id = $data['id'];
        $order = wc_get_order($order_id);
        $auth = $data->get_header('Apurata-Auth');
        if (!$auth) {
            return new WP_Error('authorization_required', 'Missing authorization header', array('status' => 401));
        }
        list($auth_type, $token) = explode(' ', $auth);
        if (strtolower($auth_type) != 'bearer') {
            return new WP_Error('authorization_required', 'Invalid authorization type', array('status' => 401));
        }
        $apurata_gateway = new WC_Apurata_Payment_Gateway();
        if ($token != $apurata_gateway->secret_token) {
            return new WP_Error('authorization_required', 'Invalid authorization token', array('status' => 401 ));
        }
        if (!$order) {
            return new WP_Error('not_found', 'Order: ' . $order_id . ' not found', array('status' => 404 ));
        }
        $order_status = $order->get_status();
        switch ($order_status) {
            case 'cancelled':
            case 'failed':
            case 'refunded':
                $order_status = 'cancelled';
                break;
            case 'completed':
            case 'processing':
                $order_status = 'paid';
                break;
            default:
                $order_status = 'pending';
        }
        $order = wc_get_order($order_id);
        $items = $order->get_items();
        $data = [
            'order_status' => $order_status,
            'enough_stock' => 'TRUE',
        ];
        foreach ($items as $item) {
            $product = $item->get_product();
            $stock_quantity = $product->get_stock_quantity();
            if($stock_quantity && $stock_quantity < $item->get_quantity() || !$product->is_in_stock()) {
                $data['enough_stock'] = 'FALSE';
            }   
        }
        return new WP_REST_Response($data, 200); 
    }
    
    include_once( plugin_dir_path( __FILE__ ) . 'apurata-update.php');
    $updater = new Apurata_Update( __FILE__ );
    $updater->set_username('apurata'); 
    $updater->set_repository('woocommerce-acuotaz-payment-gateway'); 
    $updater->initialize(); 

    function init_wc_apurata_payment_gateway() {
        class WC_Apurata_Payment_Gateway extends WC_Payment_Gateway {

            public function __construct() {
                $this->id = PLUGIN_ID;

                $this->title = __('Cuotas sin tarjeta de crédito - aCuotaz', APURATA_TEXT_DOMAIN);
                // Get settings, e.g.
                $this->client_id = $this->get_option( 'client_id' );
                $this->allow_http = $this->get_option( 'allow_http' );
                $this->secret_token = $this->get_option( 'secret_token' );

                $this->description = <<<EOF
                    <div id="apurata-pos-steps"></div>
                    <script style="display:none">
                        var r = new XMLHttpRequest();
                        r.open("GET", "https://apurata.com/pos/{$this->client_id}/info-steps", true);
                        r.onreadystatechange = function () {
                          if (r.readyState != 4 || r.status != 200) return;
                          var elem = document.getElementById("apurata-pos-steps");
                          elem.innerHTML = r.responseText;
                        };
                        r.send();
                    </script>
EOF;

                # Eventually this image will change, preserving the URL
                $this->icon = 'https://static.apurata.com/img/logo-dark-aCuotaz.svg';

                $this->has_fields = FALSE;

                // Shown in the admin panel:
                $this->method_title = 'aCuotaz Apurata';
                $this->method_description = __('Evalúa a tus clientes y financia su compra con cuotas quincenales, sin tarjeta de crédito', APURATA_TEXT_DOMAIN);

                $this->init_form_fields();
                $this->init_settings();

                // Init vars used:
                $this->pay_with_apurata_addon = NULL;
                $this->landing_config = NULL;

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

                add_action( 'woocommerce_api_on_new_event_from_apurata', array($this, 'on_new_event_from_apurata') );
            }

            public function gen_pay_with_apurata_html($page, $loan_amount = NULL, $variable_price=FALSE) {
                if ($this->pay_with_apurata_addon) {
                    return;
                }

                if (!$loan_amount) {
                    $loan_amount = $this->get_order_total();
                }

                if ($this->should_hide_apurata_gateway(FALSE)) {
                    // Don't talk to apurata, the add-on endpoint will run the validations
                    return;
                }
                global $woocommerce;
                $url = "/pos/pay-with-apurata-add-on/" . $loan_amount;

                global $wp;
                $current_url = add_query_arg( $wp->query_vars, home_url( $wp->request ) );

                $number_of_items = $woocommerce->cart->cart_contents_count;
                
                $url = add_query_arg( array(
                    'page' => urlencode($page),
                    'continue_url' => urlencode($current_url),
                ), $url);
                
                if ($page =='cart' && $number_of_items > 1) {
                    $url = add_query_arg( array(
                        'multiple_products' => urlencode('TRUE'),
                    ), $url);
                }
                global $product;
                if ($product) {
                    $url = add_query_arg( array(
                        'product__id' => urlencode($product->get_id()),
                        'product__name' => urlencode($product->get_title()),
                    ), $url );
                }

                global $current_user;
                if ($current_user) {
                    $url = add_query_arg( array(
                        'user__id' => urlencode((string) $current_user->ID),
                        'user__email' => urlencode((string) $current_user->user_email),
                        'user__first_name' => urlencode((string) $current_user->first_name),
                        'user__last_name' => urlencode((string) $current_user->last_name),
                    ), $url );
                }

                $url = add_query_arg( array(
                    'variable_amount' => $variable_price,
                ), $url );

                list($resp_code, $this->pay_with_apurata_addon) = $this->make_curl_to_apurata("GET", $url);

                if ($resp_code == 200) {
                    $this->pay_with_apurata_addon = str_replace(array("\r", "\n"), '', $this->pay_with_apurata_addon);
                } else {
                    $this->pay_with_apurata_addon = '';
                }
                echo($this->pay_with_apurata_addon);
            }

            function make_curl_to_apurata($method, $path, $data=NULL, $fire_and_forget=FALSE) {
                // $method: "GET" or "POST"
                // $path: e.g. /pos/client/landing_config
                // If data is present, send it via JSON
                $ch = curl_init();

                global $APURATA_API_DOMAIN;
                $url = $APURATA_API_DOMAIN . $path;
                curl_setopt($ch, CURLOPT_URL, $url);

                // Timeouts
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);    // seconds
                curl_setopt($ch, CURLOPT_TIMEOUT, 2); // seconds

                $headers = array("Authorization: Bearer " . $this->secret_token);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

                if (strtoupper($method) == "GET") {
                    curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
                } else if (strtoupper($method) == "POST") {
                    curl_setopt($ch, CURLOPT_POST, TRUE);
                } else {
                    throw new Exception("Method not supported: " . $method);
                }

                if ($data) {
                    $payload = json_encode($data);

                    // Attach encoded JSON string to the POST fields
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

                    // Set the content type to application/json
                    array_push($headers, 'Content-Type:application/json');
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                if ($fire_and_forget) {
                    // From: https://www.xspdf.com/resolution/52447753.html
                    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                    // We don't use CURLOPT_TIMEOUT_MS because the name resolution fails and the
                    // whole request never goes out
                    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                }

                $ret = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode != 200) {
                    apurata_log("Apurata responded with http_code ". $httpCode . " on " . $method . " to " . $url);
                }
                curl_close($ch);
                return array($httpCode, $ret);
            }

            function get_landing_config() {
                if (!$this->landing_config) {
                    list ($httpCode, $landing_config) = $this->make_curl_to_apurata("GET", "/pos/client/landing_config");
                    $landing_config = json_decode($landing_config);
                    $this->landing_config = $landing_config;
                }
                return $this->landing_config;
            }

            function should_hide_apurata_gateway(bool $talkToApurata = TRUE) {
                /* Hide Apurata gateway based on some conditions. */
                $currency = get_woocommerce_currency();

                // See https://www.designcise.com/web/tutorial/how-to-check-for-https-request-in-php
                $isHttps =
                    $_SERVER['HTTPS']
                    ?? $_SERVER['REQUEST_SCHEME']
                    ?? $_SERVER['HTTP_X_FORWARDED_PROTO']
                    ?? null
                ;

                $isHttps =
                    $isHttps && (
                        strcasecmp('on', $isHttps) == 0
                        || strcasecmp('https', $isHttps) == 0
                    )
                ;

                if ($this->allow_http == "no" && !$isHttps) {
                    apurata_log('Apurata solo soporta https');
                    return TRUE;
                }
                if( $currency != 'PEN' ){
                    //disable gateway paypal if currency is ABC
                    apurata_log('Apurata sólo soporta currency=PEN. Currency actual=' . $currency);
                    return TRUE;
                }

                if (!$talkToApurata) {
                    // The following checks require to talk to Apurata
                    return FALSE;
                }

                $landing_config = $this->get_landing_config();
                if ($landing_config->min_amount > $this->get_order_total() || $landing_config->max_amount < $this->get_order_total()) {
                    global $APURATA_API_DOMAIN;
                    apurata_log('Apurata (' . $APURATA_API_DOMAIN . ') no financia el monto del carrito: ' . $this->get_order_total());
                    return TRUE;
                }

                return FALSE;
            }

            public function is_available() {
                return !$this->should_hide_apurata_gateway();
            }


            function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array
                    (
                        'title' => __('Habilitar', APURATA_TEXT_DOMAIN) . '/' . __('Deshabilitar', APURATA_TEXT_DOMAIN),
                        'type' => 'checkbox',
                        'label' => __('Habilitar aCuotaz Apurata', APURATA_TEXT_DOMAIN),
                        'default' => 'yes'
                    ),
                    'allow_http' => array
                    (
                        'title' => __('Habilitar HTTP', APURATA_TEXT_DOMAIN),
                        'type' => 'checkbox',
                        'label' => __('Habilitar HTTP (no seguro)', APURATA_TEXT_DOMAIN),
                        'default' => 'no'
                    ),
                    'client_id' => array
                    (
                        'title' => __('ID de Cliente', APURATA_TEXT_DOMAIN),
                        'type' => 'text',
                        'required' => true,
                        'description' => __('Para obtener este ID comunícate con nosotros al correo merchants@apurata.com', APURATA_TEXT_DOMAIN),
                        'default' => ''
                    ),
                    'secret_token' => array
                    (
                        'title' => __('Token Secreto', APURATA_TEXT_DOMAIN),
                        'type' => 'text',
                        'required' => true,
                        'description' => __('Para obtener este Token comunícate con nosotros al correo merchants@apurata.com', APURATA_TEXT_DOMAIN),
                        'default' => ''
                    ),
                );
            }

            function process_payment( $order_id ) {
                global $APURATA_DOMAIN;
                $order = wc_get_order( $order_id );

                $redirect_url = $APURATA_DOMAIN . '/pos/crear-orden-y-continuar';

                $redirect_url = add_query_arg( array(
                    'order_id' => urlencode($order->get_id()),
                    'pos_client_id' => urlencode($this->client_id),
                    'amount' => urlencode($order->get_total()),
                    'url_redir_on_canceled' => urlencode(wc_get_checkout_url()),
                    'url_redir_on_rejected' => urlencode(wc_get_checkout_url()),
                    'url_redir_on_success' => urlencode($this->get_return_url( $order )),
                    'customer_data__customer_id' => urlencode($order->get_customer_id()),
                    'customer_data__billing_company' => urlencode($order->get_billing_company()),
                    'customer_data__shipping_company' => urlencode($order->get_shipping_company()),
                    'customer_data__email' => urlencode($order->get_billing_email()),
                    'customer_data__phone' => urlencode($order->get_billing_phone()),
                    'customer_data__billing_address_1' => urlencode($order->get_billing_address_1()),
                    'customer_data__billing_address_2' => urlencode($order->get_billing_address_2()),
                    'customer_data__billing_first_name' => urlencode($order->get_billing_first_name()),
                    'customer_data__billing_last_name' => urlencode($order->get_billing_last_name()),
                    'customer_data__billing_city' => urlencode($order->get_billing_city()),
                    'customer_data__shipping_address_1' => urlencode($order->get_shipping_address_1()),
                    'customer_data__shipping_address_2' => urlencode($order->get_shipping_address_2()),
                    'customer_data__shipping_first_name' => urlencode($order->get_shipping_first_name()),
                    'customer_data__shipping_last_name' => urlencode($order->get_shipping_last_name()),
                    'customer_data__shipping_city' => urlencode($order->get_shipping_city()),
                ), $redirect_url);

                // Add dni if it exists
                $dni = get_post_meta($order->get_id(), "billing_dni", TRUE);
                if ($dni) {
                    $redirect_url = add_query_arg( array(
                        'customer_data__dni' => urlencode($dni),
                    ), $redirect_url);
                }

                // Add last_name_m if it exists
                $last_name_m = get_post_meta($order->get_id(), "billing_apmaterno", TRUE);
                if ($last_name_m) {
                    $redirect_url = add_query_arg( array(
                        'customer_data__billing_last_name_m' => urlencode($last_name_m),
                    ), $redirect_url);
                }

                // Return thankyou redirect
                return array(
                    'result' => 'success',
                    'redirect' => $redirect_url
                );
            }

            /* BEGIN OF HOOKS */
            function on_new_event_from_apurata() {
                global $woocommerce;

                // We need a way to debug execution in ecommerce side
                // We always must write the header Apurata-Log before return
                $log = "Start;";

                $order_id = intval($_GET["order_id"]);
                $event = $_GET["event"];

                $order = wc_get_order( $order_id );

                $conditions = array('pending', 'onhold', 'failed');

                if (!$order) {
                    apurata_log('Orden no encontrada: ' . $order_id);
                    $log = $log . "Order not found;";
                    header('Apurata-Log: ' . $log);
                    http_response_code(404);
                    return;
                }

                // Check Authorization
                $auth = getallheaders()['Apurata-Auth'];
                if (!$auth) {
                    apurata_log('Missing authorization header');
                    $log = $log . "Missing authorization header;";
                    header('Apurata-Log: ' . $log);
                    http_response_code(401);
                    return;
                }
                list($auth_type, $token) = explode(' ', $auth);
                if (strtolower($auth_type) != 'bearer'){
                    apurata_log('Invalid authorization type');
                    $log = $log . "Invalid authorization type;";
                    header('Apurata-Log: ' . $log);
                    http_response_code(401);
                    return;
                }
                if ($token != $this->secret_token) {
                    apurata_log('Invalid authorization token');
                    $log = $log . "Invalid authorization token;";
                    header('Apurata-Log: ' . $log);
                    http_response_code(401);
                    return;
                }
                if (!in_array($order->get_status(), $conditions)) {
                    apurata_log("Orden en estado {$order->get_status()} no puede ser procesada");
                    $log = $log . "Order in status {$order->get_status()} cannot be processed;";
                    header('Apurata-Log: ' . $log);
                    http_response_code(400);
                    return;
                }
                $log = $log . "Success auth;";
                switch ($event) {
                    case 'onhold':
                        // Collateral effect: empty cart and don't allow to choose a different payment method
                        $order->update_status('on-hold', __( 'aCuotaz puso la orden en onhold', APURATA_TEXT_DOMAIN ));
                        break;
                    case 'validated':
                        $order->add_order_note( __( 'aCuotaz validó identidad', APURATA_TEXT_DOMAIN ) );
                        break;
                    case 'rejected':
                        $order->update_status('failed', __( 'aCuotaz rechazó la orden', APURATA_TEXT_DOMAIN ));
                        break;
                    case 'canceled':
                        $order->update_status('failed', __( 'El financiamiento en aCuotaz fue cancelado', APURATA_TEXT_DOMAIN ));
                        break;
                    case 'funded':
                        if ($_GET["transaction_id"]) {
                            $msg = __( 'aCuotaz notifica que esta orden fue pagada y ya se puede entregar con transaction_id=' . $_GET["transaction_id"], APURATA_TEXT_DOMAIN );
                            add_post_meta($order->get_id(), 'acuotaz_transaction_id', $_GET["transaction_id"], TRUE);
                        } else {
                            $msg = __( 'aCuotaz notifica que esta orden fue pagada y ya se puede entregar', APURATA_TEXT_DOMAIN );
                        }
                        $order->update_status('processing', $msg);
                        break;
                    case 'approved':
                        $order->add_order_note( __( 'Crédito aprobado', APURATA_TEXT_DOMAIN ) );
                        apurata_log('Evento ignorado: ' . $event);
                        $log = $log . "Ignored event " . $event . ";";
                        break;
                    default:
                        $order->add_order_note( __( 'Ignorado evento enviado por aCuotaz: ' . $event, APURATA_TEXT_DOMAIN ) );
                        apurata_log('Evento ignorado: ' . $event);
                        $log = $log . "Ignored event " . $event . ";";
                        break;
                }
                $log = $log . "Done;";
                header('Apurata-Log: ' . $log);
                return;
            }
            /* END OF HOOKS */
        }
    }

    add_action( 'plugins_loaded', 'init_wc_apurata_payment_gateway' );

    function add_wc_apurata_payment_gateway( $methods ) {
        $methods[] = 'WC_Apurata_Payment_Gateway';
        return $methods;
    }

    add_filter( 'woocommerce_payment_gateways', 'add_wc_apurata_payment_gateway' );
}

?>