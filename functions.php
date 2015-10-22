<?php
/**
 * Schema example functions
 */

/**
 * Schema credentials
 * Find yours in Admin > Settings > API Keys
 */
$GLOBALS['schema_client_id'] = '';
$GLOBALS['schema_client_key'] = '';

/**
 * Connect to Schema API
 *
 * @return \Schema\Client
 */
function schema_client() {

    static $client;

    if (!isset($client)) {
        if (empty($GLOBALS['schema_client_id']) || empty($GLOBALS['schema_client_key'])) {
            echo 'Missing `client_id` or `client_key` in '.__DIR__.'/functions.php';
            exit;
        }
        if (!is_writable(__DIR__.'/cache')) {
            echo 'Cache directory is not writable: '.__DIR__.'/cache/';
            exit;
        }
        require_once('inc/schema-php-client/lib/Schema.php');
        $client = new \Schema\Client($GLOBALS['schema_client_id'], $GLOBALS['schema_client_key'], array(
            'cache' => array('path' => __DIR__.'/cache'),
            'session' => true
        ));
    }

    return $client;
}

/**
 * Schema GET request
 */
function schema_get($url, $data = null) {

    return schema_client()->get($url, $data);
}

/**
 * Schema PUT request
 */
function schema_put($url, $data = null) {

    return schema_client()->put($url, $data);
}

/**
 * Schema POST request
 */
function schema_post($url, $data = null) {

    return schema_client()->post($url, $data);
}

/**
 * Schema DELETE request
 */
function schema_delete($url, $data = null) {

    return schema_client()->delete($url, $data);
}

/**
 * Get current user session
 */
function schema_get_session() {

    if (isset($GLOBALS['schema_session'])) {
        return $GLOBALS['schema_session'];
    }
    return schema_get('/:sessions/:current') ?: array();
}

/**
 * Update current user session
 */
function schema_put_session($data = array()) {

    $GLOBALS['schema_session'] = schema_put('/:sessions/:current', $data);
    return $GLOBALS['schema_session'];
}

/**
 * Get a product by id
 *
 * @param  string $id_or_slug
 */
function schema_get_product($id_or_slug) {

    return schema_get('/products/{id}', array(
        'id' => $id_or_slug,
        'active' => true
    ));
}

/**
 * Get related products
 *
 * @param  string $product_id
 * @param  string $category_id
 * @return \Schema\Collection
 */
function schema_get_related_products($product_id, $category_id = null, $limit = 5) {

    $query = array(
        'id' => array('$ne' => $product_id),
        'slug' => array('$ne' => $product_id),
        'active' => true,
        'limit' => $limit
    );
    // Filter products by category
    if ($category_id) {
        $category = schema_get('/categories/{id}', array('id' => $category_id));
        $query['category_index.id'] = $category['id'];
        $query['sort'] = 'category_index.sort.'.$category['id'].' ASC';
    }
    return schema_get('/products', $query);
}

/**
 * Get a product by id
 *
 * @param  string $id
 */
function schema_get_category_products($id) {

    return schema_get('/categories/{id}/products', array(
        'id' => $id,
        'expand' => 'product'
    ));
}

/**
 * Get cart from session
 *
 * @param  \Schema\Record $session
 * @param  bool $create_if_none_found
 * @return \Schema\Record
 */
function schema_get_cart($create_if_none_found = null) {

    $session = schema_get_session();
    $account_id = isset($session['account_id']) ? $session['account_id'] : null;

    $cart = array();

    // Create cart if it doesn't exist, save id to session
    if (!isset($session['cart_id']) && $create_if_none_found) {
        $cart = schema_post('/carts', array(
            'account_id' => $account_id
        ));
        $session = schema_put_session(array('cart_id' => $cart['id']));
    } else if (isset($session['cart_id'])) {
        // Get existing cart
        $cart = schema_get('/carts/{id}', array('id' => $session['cart_id']));
        if (!$cart && $create_if_none_found) {
            $cart = schema_post('/carts', array(
                'id' => $cart['id'],
                'account_id' => $account_id
            ));
            $session = schema_put_session(array('cart_id' => $cart['id']));
        }
        // Update cart with account after login
        if ($cart && !isset($cart['account_id']) && $account_id) {
            $cart = schema_put($cart, array('account_id' => $account_id));
        }
    }

    return $cart;
}

/**
 * Add an item to a cart
 *
 * @param  \Schema\Record $cart
 * @param  array $item
 * @return \Schema\Record
 */
function schema_post_cart_item($cart, $item) {

    return $cart->post('items', array(
        'product_id' => isset($item['product_id']) ? $item['product_id'] : null,
        'variant_id' => isset($item['variant_id']) ? $item['variant_id'] : null,
        'quantity' => isset($item['quantity']) ? $item['quantity'] : null,
        'options' => isset($item['options']) ? $item['options'] : null
    ));
}

/**
 * Handle checkout steps
 *
 * @param  \Schema\Record $cart
 * @param  array $data
 * @return array
 */
function schema_checkout($cart, $data) {

    // Step 1) Shipping
    if (isset($_POST['shipping'])) {

        $result = schema_update_cart_shipping($cart, $_POST);
        $result['redirect'] = '/checkout/billing';
    }

    // Step 2) Billing
    if (isset($_POST['billing'])) {

        $result = schema_update_cart_billing($cart, $_POST);

        if (!isset($result['errors'])) {

            // Convert cart to order on final step
            $result = schema_convert_cart($cart);

            if (!isset($result['errors'])) {
                $result = array('redirect' => '/receipt/'.$result['number']);
            }
        }
    }

    return $result;
}

/**
 * Update cart shipping details
 *
 * @param  \Schema\Record $cart
 * @param  array $data
 * @return array
 */
function schema_update_cart_shipping($cart, $data) {

    $update = array();

    // Validate certain fields as required
    $errors = schema_validate_fields($data, array(
        'shipping' => array(
            'name', 'address1', 'city', 'zip', 'country'
        ),
        'account' => array(
            'email'
        )
    ));
    if ($errors) {
        return array('errors' => $errors);
    }

    // Update shipping info
    if (isset($data['shipping'])) {
        $shipping = $data['shipping'];
        $shipping_fields = array(
            'name', 'address1', 'address2',
            'city', 'state', 'zip', 'country', 'phone',
            'account_address_id'
        );
        foreach ($shipping_fields as $field) {
            if (isset($shipping[$field])) {
                $update['shipping'][$field] = $shipping[$field];
            }
        }
        // Update shipping service
        if (isset($shipping['service'])) {
            // Set shipping price from cart shipment rating
            $shipping_service = schema_get_shipping_service($cart, $shipping['service']);
            if ($shipping_service['price']) {
                $update['shipping']['service'] = $shipping_service['id'];
                $update['shipping']['price'] = $shipping_service['price'];
            } else {
                $update['shipping']['service'] = null;
                $update['shipping']['price'] = null;
            }
        }
    }

    // Update account info
    if (isset($data['account'])) {
        $account = $data['account'];
        // Find existing or create new account
        if (isset($account['email'])) {
            $ex_account = schema_get('/accounts/:first', array(
                'email' => $account['email']
            ));
            if ($ex_account) {
                // Existing account found by email
                if ($cart['account_id'] && $cart['account_id'] !== $ex_account['id']) {
                    // Clear shipping/billing info when switching accounts
                    $cart = $cart->put(array('shipping' => null, 'billing' => null));
                }
                $update['account_id'] = $ex_account['id'];
                unset($account['email']);
            } else {
                // New account
                $new_account = schema_post('/accounts', array(
                    'email' => $account['email'],
                    'phone' => isset($data['shipping']['phone']) ? $data['shipping']['phone'] : null
                ));
                $update['account_id'] = $new_account['id'];
            }
        }
    }

    // Update other details
    if (isset($data['coupon_code'])) {
        $update['coupon_code'] = $data['coupon_code'];
    }
    if (isset($data['comments'])) {
        $update['comments'] = $data['comments'];
    }

    // Update cart record
    $result = $cart->put($update);
    if (isset($result['errors'])) {
        return $result;
    }

    // Update default account shipping info if stored
    if (isset($cart['shipping']['account_address_id'])) {
        $cart_address_id = $cart['shipping']['account_address_id'];
        $account = $cart['account'];
        $account_address_id = (
            isset($account['shipping']['account_address_id'])
            ? $account['shipping']['account_address_id']
            : null
        );
        if (!$account_address_id || $account_address_id !== $cart_address_id) {
            $cart['account'] = schema_put($account, array(
                'shipping' =>  array(
                    'account_address_id' => $cart_address_id
                )
            ));
        }
    }

    return array('ok' => true);
}

/**
 * Update cart billing details
 *
 * @param  \Schema\Record $cart
 * @param  array $data
 * @return array
 */
function schema_update_cart_billing($cart, $data) {

    $update = array();

    // Validate certain fields as required
    $errors = schema_validate_fields($data, array(
        'billing' => array(
            'name', 'address1', 'city', 'zip', 'country',
            'method'
        )
    ));
    if ($errors) {
        return array('errors' => $errors);
    }

    // Update billing info
    if (isset($data['billing'])) {
        $billing = $data['billing'];
        $billing_fields = array(
            'name', 'address1', 'address2',
            'city', 'state', 'zip', 'country', 'phone',
            'method', 'card'
        );
        foreach ($billing_fields as $field) {
            if (isset($billing[$field])) {
                $update['billing'][$field] = $billing[$field];
            }
        }
    }

    // Update cart record
    $result = $cart->put($update);
    if (isset($result['errors'])) {
        return $result;
    }

    // Update default account billing info if stored
    if (isset($cart['billing']['account_card_id'])) {
        $cart_card_id = $cart['billing']['account_card_id'];
        $account = $cart['account'];
        $account_card_id = (
            isset($account['billing']['account_card_id'])
            ? $account['billing']['account_card_id']
            : null
        );
        if (!$account_card_id || $account_card_id !== $cart_card_id) {
            $cart['account'] = schema_put($account, array(
                'billing' =>  array(
                    'account_card_id' => $cart_card_id
                )
            ));
        }
    }

    return array('ok' => true);
}

/**
 * Convert cart to order and update session accordingly
 *
 * @param  \Schema\Record $cart
 * @return \Schema\Record
 */
function schema_convert_cart($cart) {

    $order = schema_post('/orders', array('cart_id' => $cart['id']));

    if (!isset($order['errors'])) {
        // Update session cart and login account
        schema_put_session(array(
            'cart_id' => null,
            'account_id' => $order['account_id']
        ));
    }

    return $order;
}

/**
 * Get shipping service details from shipment_rating (if applicable)
 *
 * @param  array $cart
 * @param  string $service_id
 * @param  float $service_price
 * @return array
 */
function schema_get_shipping_service($cart, $service_id, $service_price = null) {

    $shipping_service = null;
    foreach ($cart['shipment_rating']['services'] as $service) {
        if ($service['id'] === $service_id) {
            $shipping_service = $service;
            break;
        }
    }
    return array(
        'id' => $service_id,
        'name' => $shipping_service ? $shipping_service['name'] : $service_id,
        'price' => $shipping_service ? $shipping_service['price'] : $service_price
    );
}

/**
 * Get credit card gateway settings
 *
 * @return array
 */
function schema_get_card_gateway() {

    $gateway_settings = array();

    $payment_settings = schema_get('/settings/payments');
    $card_method = null;
    foreach ($payment_settings['methods'] as $method) {
        if ($method['id'] === 'card') {
            $card_method = $method;
            break;
        }
    }
    if ($card_method) {
        $card_gateway = null;
        foreach ($payment_settings['gateways'] as $gateway) {
            if ($gateway['id'] === $card_method['gateway']) {
                $card_gateway = $gateway;
                break;
            }
        }
        if ($card_gateway) {
            $gateway_settings = $card_gateway;
            // TODO: normalize key resolution for multiple gateways
            if ($card_gateway['id'] === 'stripe') {
                if ($card_gateway['mode'] === 'live') {
                    $gateway_settings['publishable_key'] = $card_gateway['live_publishable_key'];
                } else {
                    $gateway_settings['publishable_key'] = $card_gateway['test_publishable_key'];
                }
            }
        }
    }

    return $gateway_settings;
}

/**
 * Create a new account
 *
 * @param  array $data
 * @return \Schema\Record
 */
function schema_create_account($data) {

    // Validate certain fields as required
    $required_fields = array(
        'first_name', 'last_name', 'email', 'password'
    );

    // Business accounts added to 'Wholesale' group, others are individual 'Customers'
    if ($data['type'] === 'business') {
        $data['group'] = 'wholesale';
        $data['contacts'] = array(
            array(
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email']
            )
        );
        $required_fields[] = 'name';
        $required_fields[] = 'phone';
    } else {
        $data['type'] = 'individual';
        $data['group'] = 'customers';
    }

    $errors = schema_validate_fields($data, $required_fields);
    if ($errors) {
        return array('errors' => $errors);
    }

    $create_update = array(
        'type' => isset($data['type']) ? $data['type'] : null,
        'group' => isset($data['group']) ? $data['group'] : null,
        'first_name' => isset($data['first_name']) ? $data['first_name'] : null,
        'last_name' => isset($data['last_name']) ? $data['last_name'] : null,
        'name' => isset($data['name']) ? $data['name'] : null,
        'email' => isset($data['email']) ? $data['email'] : null,
        'password' => isset($data['password']) ? $data['password'] : null,
        'phone' => isset($data['phone']) ? $data['phone'] : null,
        'contacts' => isset($data['contacts']) ? $data['contacts'] : null
    );

    // Try to update existing account if password was not previously set
    $ex_account = schema_get('/accounts/{email}', array('email' => $create_update['email']));
    if ($ex_account && !isset($ex_account['password'])) {
        $account = schema_put($ex_account, $create_update);
    } else {
        $account = schema_post('/accounts', $create_update);
    }

    schema_put_session(array('account_id' => $account['id']));

    return $account;
}

/**
 * Update currently logged in account account
 *
 * @param  array $data
 * @return \Schema\Record
 */
function schema_update_current_account($data) {

    $session = schema_get_session();

    if (isset($data['new_password'])) {
        $errors = schema_validate_fields($data, array(
            'new_password', 'confirm_password'
        ));
        if ($errors) {
            return array('errors' => $errors);
        }
        if ($data['new_password'] !== $data['confirm_password']) {
            return array('errors' => array(
                'confirm_password' => array(
                    'message' => 'Must match password',
                    'code' => 'CONFIRM'
                )
            ));
        }
        return schema_put('/accounts/{id}', array(
            'id' => $session['account_id'],
            'password' => $data['new_password']
        ));
    } else {
        $update = array(
            'id' => $session['account_id'],
            'first_name' => isset($data['first_name']) ? $data['first_name'] : null,
            'last_name' => isset($data['last_name']) ? $data['last_name'] : null,
            'name' => isset($data['name']) ? $data['name'] : null,
            'email' => isset($data['email']) ? $data['email'] : null,
            'phone' => isset($data['phone']) ? $data['phone'] : null
        );
        if ($data['type'] === 'business') {
            $update['contacts'] = isset($data['contacts']) ? $data['contacts'] : null;
            $required_fields = array(
                'name', 'phone',
                'contacts' => array(
                    '0' => array('first_name', 'last_name')
                )
            );
        } else {
            $required_fields = array(
                'first_name', 'last_name', 'email'
            );
        }
        $errors = schema_validate_fields($data, $required_fields);
        if ($errors) {
            return array('errors' => $errors);
        }
        return schema_put('/accounts/{id}', $update);
    }
}


/**
 * Get current logged in account
 *
 * @return \Schema\Record
 */
function schema_get_account() {

    $session = schema_get_session();

    if (isset($session['account_id'])) {
        return schema_get('/accounts/{id}', array(
            'id' => $session['account_id']
        ));
    }
}

/**
 * Get orders placed by the current logged in account
 *
 * @param  array $data
 * @return \Schema\Record
 */
function schema_get_account_orders($data) {

    $session = schema_get_session();

    if (isset($session['account_id'])) {
        return schema_get('/orders', array(
            'account_id' => $session['account_id'],
            'page' => isset($data['page']) ? $data['page'] : 1,
            'limit' => isset($data['limit']) ? $data['limit'] : 25
        ));
    }
}

/**
 * Get addresses for current account session, if logged in
 *
 * @return \Schema\Collection
 */
function schema_get_account_addresses() {

    $session = schema_get_session();

    if (isset($session['account_id'])) {
        return schema_get('/accounts/{id}/addresses', array(
            'id' => $session['account_id']
        ));
    }
}

/**
 * Get cards for current account session, if logged in
 *
 * @return \Schema\Collection
 */
function schema_get_account_cards() {

    $session = schema_get_session();

    if (isset($session['account_id'])) {
        return schema_get('/accounts/{id}/cards', array(
            'id' => $session['account_id']
        ));
    }
}

/**
 * Login to an account
 *
 * @param  array $data
 * @return \Schema\Record
 */
function schema_login($data) {

    if (!isset($data['email']) || !isset($data['password'])) {
        return null;
    }

    $account = schema_get('/accounts/:login', array(
        'email' => $data['email'],
        'password' => $data['password']
    ));

    if (isset($account['id'])) {
        schema_put_session(array('account_id' => $account['id']));
        return true;
    }

    return false;
}

/**
 * Logout of an account
 *
 * @return void
 */
function schema_logout() {

    $session = schema_get_session();
    if ($session['account_id'] !== null) {
        schema_put_session(array('account_id' => null));
    }
}

/**
 * Check session and redirect if not logged in
 *
 * @return void
 */
function schema_require_login() {

    $session = schema_get_session();
    if (!isset($session['account_id'])) {
        header('Location: /account-login');
        exit;
    }
}

/**
 * Validate fields that are considered as required
 * Returns an array of errors similar to API request errors
 *
 * @param  array $data
 * @param  array $fields
 * @return array
 */
function schema_validate_fields($data, $fields) {

    $errors = array();
    foreach ($fields as $key => $field) {
        if (is_string($field)) {
            // Field must be empty string to trigger required error
            if (isset($data[$field]) && empty($data[$field])) {
                $errors[$field] = array(
                    'message' => 'Required',
                    'code' => 'REQUIRED'
                );
            }
        }
        else if (is_array($field)) {
            // Recursively validate nested fields
            $field_errors = schema_validate_fields($data[$key], $field);
            if ($field_errors) {
                foreach ($field_errors as $field_key => $field_error) {
                    $errors["{$key}.{$field_key}"] = $field_error;
                }
            }
        }
    }
    return $errors;
}

/**
 * Submit a lead from a contact form
 *
 * @param  array $lead
 */
function schema_post_lead($lead) {

    $result = schema_post('/leads', array(
        'first_name' => $lead['first_name'],
        'last_name' => $lead['last_name'],
        'email' => $lead['email'],
        'subject' => $lead['subject'],
        'message' => $lead['message'],
        'status' => 'new'
    ));
}

/**
 * Escape a record value for template output
 *
 * @param  string $value
 * @return string
 */
function schema_escape($value) {

    return htmlspecialchars($value);
}

/**
 * Format a currency value
 * Accepts record and field path in order to format with record currency code
 *
 * @param  \Schema\Record $record
 * @param  string $path (dot notation)
 * @return string
 */
function schema_currency($record, $path) {

    $value = null;

    $path_parts = explode('.', $path);
    $record_pointer = $record;
    while ($path_parts) {
        $part = array_shift($path_parts);
        if ($record_pointer[$part] !== null) {
            if (count($path_parts) > 0) {
                $record_pointer = $record_pointer[$part];
            } else {
                $value = $record_pointer[$part];
            }
        } else {
            break;
        }
    }

    // TODO: render value based on $record['currency'];
    return '$'.number_format($value, 2);
}

/**
 * Format a date value
 *
 * @param  string $value
 * @return string
 */
function schema_date($value) {

    // TODO: better
    return date('Y-m-d', strtotime($value));
}

