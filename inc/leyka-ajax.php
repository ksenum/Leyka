<?php if( !defined('WPINC') ) die;
/** Different ajax handler functions */

function leyka_ajax_get_campaigns_list() {

    if(empty($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'leyka_get_campaigns_list_nonce')) {
        die(json_encode(array()));
    }

    $_REQUEST['term'] = empty($_REQUEST['term']) ? '' : trim($_REQUEST['term']);

    $campaigns = leyka_get_campaigns_list(array(
        'meta_query' => array(array(
            'key' => 'payment_title', 'value' => $_REQUEST['term'], 'compare' => 'LIKE', 'type' => 'CHAR',
        )),
    ), 0);

    $ids_found = array();
    $count = count($campaigns);
    for($i=0; $i<$count; $i++) {

        $ids_found[] = $campaigns[$i]->ID;
        $campaigns[$i] = array(
            'value' => $campaigns[$i]->ID,
            'label' => $campaigns[$i]->post_title,
            'payment_title' => get_post_meta($campaigns[$i]->ID, 'payment_title', true),
        );
    }

    foreach(leyka_get_campaigns_list(array('s' => $_REQUEST['term']), 0) as $campaign) { // Any criteria search - low priority
        if( !in_array($campaign->ID, $ids_found) ) {
            $campaigns[] = array(
                'value' => $campaign->ID,
                'label' => $campaign->post_title,
                'payment_title' => get_post_meta($campaign->ID, 'payment_title', true),
            );
        }
    }

    die(json_encode($campaigns));

}
add_action('wp_ajax_leyka_get_campaigns_list', 'leyka_ajax_get_campaigns_list');
add_action('wp_ajax_nopriv_leyka_get_campaigns_list', 'leyka_ajax_get_campaigns_list');

//function leyka_recalculate_total_funded_action(){
//
//    if( !wp_verify_nonce($_GET['nonce'], 'leyka_recalculate_total_funded_amount') ) {
//        wp_die(__('Error: incorrect request parameters', 'leyka'));
//    }
//
//    if(empty($_GET['campaign_id'])) {
//        wp_die(__('Error: campaign ID is missing', 'leyka'));
//    }
//
//    $campaign = new Leyka_Campaign(absint($_GET['campaign_id']));
//    $campaign->update_total_funded_amount();
//
//    wp_die($campaign->total_funded);
//
//}
//add_action('wp_ajax_leyka_recalculate_total_funded_amount', 'leyka_recalculate_total_funded_action');
//add_action('wp_ajax_nopriv_leyka_recalculate_total_funded_amount', 'leyka_recalculate_total_funded_action');


function leyka_get_gateway_redirect_data(){

    leyka()->clear_session_errors(); // Clear all previous submits errors, if there are some

    $form_errors = Leyka_Payment_Form::is_form_fields_valid();
    if(is_array($form_errors) && count($form_errors) > 0) {

        $form_errors = reset($form_errors); // Return only the first error in the list

        /** @var WP_Error $form_errors */
        die(json_encode(array('status' => 1, 'message' => $form_errors->get_error_message(),)));

    }

    $pm = leyka_pf_get_payment_method_value();

    if(empty($_POST['without_form_submission'])) { // Normal donation submit procedure

        $donation_id = leyka()->log_submission();

        if( !is_wp_error($donation_id) ) {

            leyka_remember_donation_data(array('donation_id' => $donation_id));

            do_action(
                'leyka_payment_form_submission-'.$pm['gateway_id'],
                $pm['gateway_id'],
                implode('-', array_slice($pm, 1)),
                $donation_id,
                $_POST
            );

        }

        $payment_vars = array(
            'status' => 0,
            'payment_url' => apply_filters('leyka_submission_redirect_url-'.$pm['gateway_id'], '', $pm['payment_method_id']),
            'submission_redirect_type' => apply_filters(
                'leyka_submission_redirect_type-'.$pm['gateway_id'],
                'auto', $pm['payment_method_id'], $donation_id
            ),
        );

        if(is_wp_error($donation_id)) {

            $payment_vars['errors'] = $donation_id;
            $payment_vars['message'] = $donation_id->get_error_message();
            $payment_vars['status'] = 1;

        } else if(leyka()->payment_form_has_errors()) {

            $error = leyka()->get_payment_form_errors();
            $error = reset($error);

            $payment_vars['errors'] = $error;
            $payment_vars['message'] = $error->get_error_message();
            $payment_vars['status'] = 1;

        } else { // Donation created successfully

            $payment_vars['donation_id'] = $donation_id;
            $donation = new Leyka_Donation($donation_id);

            if( // Direct integration with GUA - checkout event:
                leyka_options()->opt('use_gtm_ua_integration') === 'enchanced_ua_only'
                && leyka_options()->opt('gtm_ua_tracking_id')
            ) {

                require_once LEYKA_PLUGIN_DIR.'vendor/autoload.php';

                $analytics = new TheIconic\Tracking\GoogleAnalytics\Analytics(true);
                $analytics // Main params:
                    ->setProtocolVersion('1')
                    ->setTrackingId(leyka_options()->opt('gtm_ua_tracking_id'))
                    ->setClientId($donation->ga_client_id ? $donation->ga_client_id : leyka_gua_get_client_id())
                    ->setDocumentLocationUrl(get_permalink($donation->campaign_id))
                    // Transaction params:
                    ->setTransactionId($donation_id)
                    ->setAffiliation(get_bloginfo('name'))
                    ->setRevenue($donation->amount)
                    ->addProduct(array( // Donation params
                        'name' => $donation->payment_title,
                        'price' => $donation->amount,
                        'brand' => get_bloginfo('name'), // Mb, it won't work with it
                        'category' => $donation->type_label, // Mb, it won't work with it
                        'quantity' => 1,
                    ))
                    ->setProductActionToCheckout()
                    ->setCheckoutStep(1)
                    ->setCheckoutStepOption($donation->pm_label)
                    ->setEventCategory('Checkout')
                    ->setEventAction('Checkout') // 'Purchase'
                    ->sendEvent();

            } // Direct integration with GUA - checkout event END

        }

        $payment_vars = array_merge(
            apply_filters('leyka_submission_form_data-'.$pm['gateway_id'], $_POST, $pm['payment_method_id'], $donation_id),
            $payment_vars
        );

    } else { // Get payment vars without donation submit
        $payment_vars = array_merge(
            apply_filters('leyka_submission_form_data-'.$pm['gateway_id'], $_POST, $pm['payment_method_id'], false),
            array(
                'status' => 0,
                'payment_url' => apply_filters('leyka_submission_redirect_url-'.$pm['gateway_id'], '', $pm['payment_method_id']),
            )
        );
    }

    die(json_encode($payment_vars));

}
add_action('wp_ajax_leyka_ajax_get_gateway_redirect_data', 'leyka_get_gateway_redirect_data');
add_action('wp_ajax_nopriv_leyka_ajax_get_gateway_redirect_data', 'leyka_get_gateway_redirect_data');

function leyka_process_success_form(){

    if(empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'leyka_donor_subscription')) {
        die(json_encode(array(
            'status' => 1,
            'message' => __('Wrong nonce in the submitted data', 'leyka'),
        )));
    } else if(empty($_POST['leyka_donation_id'])) {
        die(json_encode(array('status' => 1, 'message' => __('No donation ID found in the submitted data', 'leyka'),)));
    }

    $donation = new Leyka_Donation(absint($_POST['leyka_donation_id']));
    if( !$donation ) {
        die(json_encode(array('status' => 1, 'message' => __('Wrong donation ID in the submitted data', 'leyka'),)));
    }

    if(isset($_POST['leyka_donor_name']) && leyka_validate_donor_name($_POST['leyka_donor_name'])) {
        $donation->donor_name = $_POST['leyka_donor_name'];
    }

    if(isset($_POST['leyka_donor_email']) && leyka_validate_email($_POST['leyka_donor_email'])) {

        $donation->donor_email = $donation->donor_email ? $donation->donor_email : $_POST['leyka_donor_email'];
        $donation->donor_subscription_email = $_POST['leyka_donor_email'];
        $donation->donor_subscribed = $donation->campaign_id;

    }

    leyka_remembered_data('donation_id', false, true); // Delete the donor data cookie

    die(json_encode(array('status' => 0,)));

}
add_action('wp_ajax_leyka_donor_subscription', 'leyka_process_success_form');
add_action('wp_ajax_nopriv_leyka_donor_subscription', 'leyka_process_success_form');

function leyka_set_campaign_photo(){

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'set-campaign-photo')) {
        die(json_encode(array(
            'status' => 'error',
            'message' => __('Wrong nonce in the submitted data', 'leyka'),
        )));
    } else if(empty($_POST['campaign_id'])) {
        die(json_encode(array(
            'status' => 'error',
            'message' => __('Error: campaign ID is missing', 'leyka'),
        )));
    }

    $attachment_id = absint($_POST['attachment_id']);
    $campaign_id = absint($_POST['campaign_id']);

    update_post_meta($campaign_id, '_thumbnail_id', $attachment_id);
    sleep(1);

    die(json_encode(array('status' => 'ok', 'post' => $_POST,)));

}
add_action('wp_ajax_leyka_set_campaign_photo', 'leyka_set_campaign_photo');

function leyka_set_campaign_attachment(){

    $_POST['campaign_id'] = empty($_POST['campaign_id']) ? false : absint($_POST['campaign_id']);
    $_POST['attachment_id'] = empty($_POST['attachment_id']) ? false : absint($_POST['attachment_id']);

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'set-campaign-attachment')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    } else if(empty($_POST['campaign_id'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: campaign ID is missing', 'leyka'),)));
    } else if(empty($_POST['field_name'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: field name is missing', 'leyka'),)));
    }

    update_post_meta($_POST['campaign_id'], esc_attr($_POST['field_name']), $_POST['attachment_id']);
    sleep(1);

    die(json_encode(array(
        'status' => 'ok',
        'post' => $_POST,
        'img_url' => wp_get_attachment_image_url(absint($_POST['attachment_id']), 'thumbnail'),
    )));

}
add_action('wp_ajax_leyka_set_campaign_attachment', 'leyka_set_campaign_attachment');

function leyka_set_campaign_template(){

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'set-campaign-template')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    } else if(empty($_POST['campaign_id'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: campaign ID is missing', 'leyka'),)));
    }

    update_post_meta(absint($_POST['campaign_id']), 'campaign_template', $_POST['template']);

    die(json_encode(array('status' => 'ok', 'post' => $_POST,)));

}
add_action('wp_ajax_leyka_set_campaign_template', 'leyka_set_campaign_template');

function leyka_edit_campaign_slug(){

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka-edit-campaign-slug')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    } else if(empty($_POST['campaign_id']) || empty($_POST['slug'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: the campaign data needed are missing', 'leyka'),)));
    }

    $campaign = get_post($_POST['campaign_id']);
    if( !$campaign || $campaign->post_type !== Leyka_Campaign_Management::$post_type ) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: wrong campaign ID given', 'leyka'),)));
    }

    $_POST['slug'] = wp_unique_post_slug(sanitize_title($_POST['slug']), $_POST['campaign_id'], $campaign->post_status, $campaign->post_type, null);

    $res = wp_update_post(array(
        'ID' => absint($_POST['campaign_id']),
        'post_name' => $_POST['slug'],
    ));

    if($res) {
        die(json_encode(array('status' => 'ok', 'slug' => $_POST['slug'],)));
    } else {
        die(json_encode(array('status' => 'error', 'message' => __("Error: the campaign slug wasn't updated", 'leyka'),)));
    }

}
add_action('wp_ajax_leyka_edit_campaign_slug', 'leyka_edit_campaign_slug');

function leyka_update_pm_list(){

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka-update-pm-order')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    } else if(empty($_POST['pm_order'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: PM order value is missing', 'leyka'),)));
    }

    leyka_options()->opt('pm_order', $_POST['pm_order']);
    leyka_options()->opt('pm_available', explode('&', str_replace('pm_order[]=', '', $_POST['pm_order'])));

    if( !empty($_POST['pm_labels']) && is_array($_POST['pm_labels']) ) {
        foreach($_POST['pm_labels'] as $pm_full_id => $pm_label) {
            leyka_options()->opt($pm_full_id, $pm_label);
        }
    }

    die(json_encode(array('status' => 'ok',)));

}
add_action('wp_ajax_leyka_update_pm_list', 'leyka_update_pm_list');

function leyka_upload_l10n(){

    $url = 'https://translate.wordpress.org/projects/wp-plugins/leyka/stable/ru/default/export-translations?format=mo';
    $file = download_url($url, 60);

    $res = null;

    if(is_wp_error($file)) {
        $res = array(
            'status' => 'error',
            'message' => "Ошибка! Не удалось скачать файл локализации. ".$file->get_error_message()
        );
    } else {

        if( !is_dir(WP_CONTENT_DIR."/languages") ) {
            $res = array('status' => 'error', 'message' => sprintf("Ошибка! Папка локализации не найдена: %s", WP_CONTENT_DIR . "/languages"));
        } elseif( !is_dir(WP_CONTENT_DIR.'/languages/plugins') ) {
            $res = array(
                'status' => 'error',
                'message' => sprintf("Ошибка! Папка локализации плагинов не найдена: %s", WP_CONTENT_DIR.'/languages/plugins')
            );
        } else {

            try {
                if(copy($file, WP_CONTENT_DIR.'/languages/plugins/leyka-ru_RU.mo')) {
                    unlink($file);
                } else {
                    $res = array(
                        'status' => 'error',
                        'message' => sprintf("Ошибка! Нет прав для записи в папку %s", WP_CONTENT_DIR . "/languages/plugins")
                    );
                }
            } catch(Exception $ex) {
                $res = array(
                    'status' => 'error',
                    'message' => "Ошибка! Не удалось установить файл локализации! ".$ex->getMessage()
                );
            }
        }

    }

    if( !$res ) {
        $res = array('status' => 'ok', 'message' => 'Перевод успешно загружен');
    }

    die(json_encode($res));

}
add_action('wp_ajax_leyka_upload_l10n', 'leyka_upload_l10n');

function leyka_ajax_get_env_and_options(){
    die('<pre>'.format_debug_data(humanaize_debug_data(leyka_get_env_and_options())).'</pre>');
}
add_action('wp_ajax_leyka_get_env_and_options', 'leyka_ajax_get_env_and_options');

function leyka_setup_donor_password(){

    $res = array('status' => 'ok', 'message' => __('The password is set. Welcome to your personal account!', 'leyka'));

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_account_password_setup')) {
        $res = array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_get_website_tech_support_email())
        );
    } else if(
        empty($_POST['leyka_donor_pass'])
        || empty($_POST['leyka_donor_pass2'])
        || $_POST['leyka_donor_pass2'] !== $_POST['leyka_donor_pass2']
        || empty($_POST['donor_account_id'])
        || absint($_POST['donor_account_id']) <= 0
    ) {
        $res = array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request data. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_get_website_tech_support_email())
        );
    } else {

        try {
            $donor = new Leyka_Donor(absint($_POST['donor_account_id']));
        } catch(Exception $e) {
            die(json_encode(array('status' => 'error', 'message' => __('Wrong Donor ID given', 'leyka'))));
        }

        $donor->password = $_POST['leyka_donor_pass'];
        $donor->account_activation_code = false;

        if( !empty($_POST['auto-login']) ) { // Password initial setup (account activation)

            $donor_logged_in = $donor->login($_POST['leyka_donor_pass'], true);

            if(is_wp_error($donor_logged_in)) { /** @var $donor_logged_in WP_Error */
                $res = array('status' => 'error', 'message' => strip_tags($donor_logged_in->get_error_message()),);
            }

        } else { // Password resetting
            $res = array('status' => 'ok', 'message' => sprintf(__('The password is changed. You may <a href="%s">log in</a>', 'leyka'), home_url('/donor-account/login/')));
        }

    }

    die(json_encode($res));

}
add_action('wp_ajax_leyka_setup_donor_password', 'leyka_setup_donor_password');
add_action('wp_ajax_nopriv_leyka_setup_donor_password', 'leyka_setup_donor_password');

function leyka_donor_login(){

    $res = array('status' => 'ok', 'message' => __('You are logged in and will be redirected in a moment. Welcome to your personal account :)', 'leyka'));

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_donor_login')) {
        $res = array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_get_website_tech_support_email())
        );
    } else if(
        empty($_POST['leyka_donor_email'])
        || !is_email($_POST['leyka_donor_email'])
        || empty($_POST['leyka_donor_pass'])
    ) {
        $res = array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request data. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_get_website_tech_support_email())
        );
    } else {

        try {
            $donor = new Leyka_Donor($_POST['leyka_donor_email']);
        } catch(Exception $e) {
            $donor = false;
        }

        if( !$donor ) {
            $res = array('status' => 'error', 'message' => __('Incorrect email or password.', 'leyka'),);
        } else if( !$donor->has_account_access ) {
            $res = array('status' => 'error', 'message' => __("You don't have an access for the donor account yet.", 'leyka'),);
        } else {

            $donor_logged_in = $donor->login($_POST['leyka_donor_pass'], true);

            if(is_wp_error($donor_logged_in)) {
                $res = array('status' => 'error', 'message' => strip_tags($donor_logged_in->get_error_message()),);
            }

        }

    }

    die(json_encode($res));

}
add_action('wp_ajax_leyka_donor_login', 'leyka_donor_login');
add_action('wp_ajax_nopriv_leyka_donor_login', 'leyka_donor_login');

function leyka_donor_password_reset_request(){

    $res = array('status' => 'ok', 'message' => __('Your password is ready to reset! Check your email for the confirmation link.', 'leyka'));

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_donor_password_reset')) {
        $res = array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_get_website_tech_support_email())
        );
    } else if(empty($_POST['leyka_donor_email']) || !is_email($_POST['leyka_donor_email'])) {
        $res = array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request data. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_get_website_tech_support_email())
        );
    } else {

        try {
            $donor = new Leyka_Donor($_POST['leyka_donor_email']);
        } catch(Exception $e) {
            $donor = false;
        }

        if( !$donor ) {
            $res = array('status' => 'error', 'message' => __('Incorrect email.', 'leyka'),);
        } else {

            $pass_reset_key = $donor->get_password_reset_key();

            if($pass_reset_key && !is_wp_error($pass_reset_key)) {

                $email_text = sprintf(
                    __("Hello, %s!\n\nYou received this email because someone asked to reset your email on the <a href='%s'>%s</a> website.\n\nIf it was not you, just ignore this email and nothing will happen.\n\n If you really wish to reset your password, click <a href='%s' target='_blank'>here</a>.\n\nGood day to you!", 'leyka'),
                    $donor->display_name,
                    home_url(),
                    get_bloginfo('name'),
                    home_url('/donor-account/reset-password/?code='.$pass_reset_key.'&donor=' . urlencode($_POST['leyka_donor_email']))
                );

                add_filter('wp_mail_content_type', 'leyka_set_html_content_type');

                $email_sent = wp_mail(
                    $_POST['leyka_donor_email'],
                    apply_filters('leyka_email_donor_password_reset_title', __('Your account access resetting', 'leyka'), $donor),
                    wpautop(apply_filters('leyka_email_donor_password_reset_text', $email_text, $donor)),
                    array('From: '.apply_filters(
                            'leyka_email_from_name',
                            leyka_options()->opt_safe('email_from_name'),
                            $donor
                        ).' <'.leyka_options()->opt_safe('email_from').'>',)
                );
                if( !$email_sent ) {
                    $res = array(
                        'status' => 'error',
                        'message' => sprintf(__('Sorry, we could not send the password resetting email to you. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_get_website_tech_support_email())
                    );
                }

                remove_filter('wp_mail_content_type', 'leyka_set_html_content_type');

            } else {
                $res = array('status' => 'error', 'message' => __("Can't get the password reset key.", 'leyka'),);
            }

        }

    }

    die(json_encode($res));

}
add_action('wp_ajax_leyka_donor_password_reset_request', 'leyka_donor_password_reset_request');
add_action('wp_ajax_nopriv_leyka_donor_password_reset_request', 'leyka_donor_password_reset_request');

function leyka_get_donations_history_page() {

    $res = array('status' => 'ok', 'items_html' => '');

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_get_donor_donations_history')) {
        die(json_encode(array('status' => 'error',)));
    }

    if(empty($_POST['donor_id']) || empty($_POST['page'])) {
        die(json_encode(array('status' => 'error',)));
    }

    try {
    	$donor = new Leyka_Donor(absint($_POST['donor_id']));
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error',)));
    }

    foreach($donor->get_donations(absint($_POST['page'])) as $donation) {
        $res['items_html'] .= leyka_get_donor_account_donations_list_item_html(false, $donation)."\n";
    }

    die(json_encode($res));

}
add_action('wp_ajax_leyka_get_donations_history_page', 'leyka_get_donations_history_page');
add_action('wp_ajax_nopriv_leyka_get_donations_history_page', 'leyka_get_donations_history_page');

function leyka_cancel_recurring_subscription(){

    if(empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'leyka_cancel_subscription')) {
        die(json_encode(array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_options()->opt('tech_support_email'))
        )));
    } else if(empty($_POST['leyka_campaign_id']) || empty($_POST['leyka_donation_id'])) {
        die(json_encode(array(
            'status' => 'error',
            'message' => sprintf(__('Wrong request data. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka_options()->opt('tech_support_email'))
        )));
    }

    $campaign_id = absint($_POST['leyka_campaign_id']);
    $donation_id = absint($_POST['leyka_donation_id']);

    try {
        $donor = new Leyka_Donor(get_current_user_id());
    } catch(Exception $e) {
        $donor = false;
    }

    $campaign = new Leyka_Campaign($campaign_id);
    $init_recurring_donation = new Leyka_Donation($donation_id);

    $cancel_reasons = is_array($_POST['leyka_cancel_subscription_reason']) ?
        $_POST['leyka_cancel_subscription_reason'] : array($_POST['leyka_cancel_subscription_reason']);

    if($cancel_reasons) {

        $leyka_possible_reasons = leyka_get_cancel_subscription_reasons();
        $reason_text_lines = array();

        foreach($cancel_reasons as $reason) {

            if($reason === 'other') {
                $line = sprintf(__('Other reason: %s', 'leyka'), isset($_POST['leyka_donor_custom_reason']) ? $_POST['leyka_donor_custom_reason'] : '');
            } else {
                $line = $leyka_possible_reasons[$reason];
            }

            $reason_text_lines[] = $line;

        }

        $reason_text = implode("\n", $reason_text_lines);

    } else {
        $reason_text = '';
    }

    if( !$donor || !$donor->has_account_access ) {
        die(json_encode(array(
            'status' => 'error',
            'message' => __('This operation is allowed only for registered donors.', 'leyka'),
        )));
    } else if( !$campaign ) {
        die(json_encode(array(
            'status' => 'error',
            'message' => sprintf(__('The campaign #%s is not found. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), $campaign_id, leyka()->opt('tech_support_email'))
        )));
    } else if( !$init_recurring_donation ) {
        die(json_encode(array(
            'status' => 'error',
            'message' => sprintf(__('Donation #%s is not found. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), $donation_id, leyka()->opt('tech_support_email'))
        )));
    }

    $res = array('status' => 'ok', 'message' => __('Your request to unsubscribe accepted.', 'leyka'));

    $donation_gateway = leyka_get_gateway_by_id($init_recurring_donation->gateway_id);
    if($donation_gateway->has_recurring_auto_cancelling_support) { // Recurring auto-cancelling supported - do it

        $cancelling_result = $donation_gateway->cancel_recurring_subscription($init_recurring_donation);

        if(is_wp_error($cancelling_result)) { /** @var $cancelling_result WP_Error */
            $res = array('status' => 'error', 'message' => $cancelling_result->get_error_message());
        } else if( !$cancelling_result ) {
            $res = array(
                'status' => 'error',
                'message' => sprintf(__('Error while trying to cancel the recurring subscription.<br><br>Please, email abount this to the <a href="%s" target="_blank">website tech. support</a>.<br>Also you may <a href="%s">cancel your recurring donations manually</a>.<br><br>We are very sorry for inconvenience.', 'leyka'), leyka_get_website_tech_support_email())
            );
        }

        $res['message'] = __('Your recurring subscription cancelled.', 'leyka');

    } else { // We can only "request to cancel a recurring subscription", so the website admin could cancel it manually

        $init_recurring_donation->cancel_recurring_requested = true; // Save unsubscribe request flag

        $email_text = sprintf(
            __("Hello!\n\nDonor %s with email %s and ID %s would like to unsubscribe from campaign <a href='%s'>%s</a> with ID %s on the <a href='%s'>%s</a> website.\n\nLink to subscription: %s\n\nThe reasons are:\n%s", 'leyka'),
            $donor->name,
            $donor->email,
            $donor->id,
            $campaign->permalink,
            $campaign->title,
            $campaign->ID,
            home_url(),
            get_bloginfo('name'),
            admin_url('/post.php?post='.$init_recurring_donation->id.'&action=edit'),
            $reason_text
        );
        add_filter('wp_mail_content_type', 'leyka_set_html_content_type');

        $email_sent = wp_mail(
            leyka_get_dm_list_or_alternatives(),
            apply_filters('leyka_email_manager_cancel_subscription_title', __('New cancel campaign subscription request', 'leyka'), $donor, $campaign),
            wpautop(apply_filters('leyka_email_manager_cancel_subscription_text', $email_text, $donor, $campaign)),
            array(
                'From: '.apply_filters('leyka_email_from_name', leyka_options()->opt_safe('email_from_name'), $donor)
                .' <'.leyka_options()->opt_safe('email_from').'>',
            )
        );

        if( !$email_sent ) {
            $res = array(
                'status' => 'error',
                'message' => sprintf(__('Sorry, we could not send unsubscription request. Please, <a href="mailto:%s" target="_blank">contact the website tech. support</a> about it.', 'leyka'), leyka()->opt('tech_support_email'))
            );
        }

        remove_filter('wp_mail_content_type', 'leyka_set_html_content_type');

    }

    if(in_array('uncomfortable_pm', $cancel_reasons) || in_array('too_much', $cancel_reasons)) {
        $res['redirect_to'] = $campaign->url;
    }

    die(json_encode($res));

}
add_action('wp_ajax_leyka_cancel_recurring', 'leyka_cancel_recurring_subscription'); // leyka_unsubscribe_persistent_campaign
add_action('wp_ajax_nopriv_leyka_cancel_recurring', 'leyka_cancel_recurring_subscription');

function leyka_reset_campaign_attachment(){

    $_POST['campaign_id'] = empty($_POST['campaign_id']) ? false : absint($_POST['campaign_id']);
    $_POST['attachment_id'] = empty($_POST['attachment_id']) ? false : absint($_POST['attachment_id']);

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reset-campaign-attachment')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    } else if(empty($_POST['campaign_id'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: campaign ID is missing', 'leyka'),)));
    } else if(empty($_POST['img_mission'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: field name is missing', 'leyka'),)));
    }

    delete_post_meta($_POST['campaign_id'], 'campaign_'.esc_attr(sanitize_text_field($_POST['img_mission'])));

    die(json_encode(array('status' => 'ok',)));

}
add_action('wp_ajax_leyka_reset_campaign_attachment', 'leyka_reset_campaign_attachment');

function leyka_usage_stats_y(){
    
    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'usage_stats_y')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }
    
    update_option('leyka_plugin_stats_option_needs_sync', time());
    $stats_option_synch_res = leyka_sync_plugin_stats_option();
    
    if(is_wp_error($stats_option_synch_res)) {
        die(json_encode(array(
            'status' => 'error',
            'message' => __('Connection to leyka statistics server failed!', 'leyka'),
        )));
    } else {

        delete_option('leyka_plugin_stats_option_needs_sync');
        update_option('leyka_plugin_stats_option_sync_done', time());

        leyka()->opt('send_plugin_stats', 'y');

    }
    
    die(json_encode(array('status' => 'ok', 'message' => __('Thank you!', 'leyka'),)));
    
}
add_action('wp_ajax_leyka_usage_stats_y', 'leyka_usage_stats_y');

function leyka_donors_autocomplete(){

    $search_query = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
    $res = array();

    if( !$search_query ) {
        die(json_encode($res));
    }

    if(isset($_GET['type']) && $_GET['type'] === 'users') { // Search for donors in user accounts

        $donors = get_users(array(
            'role__in' => array(Leyka_Donor::DONOR_USER_ROLE,),
            'number' => 10,
            'search' => '*'.str_replace('*', '', $search_query).'*',
            'search_columns' => array('login', 'nicename', 'email'),
        ));

        foreach($donors as $donor) {
            $res[] = array('label' => sprintf('%s (%s)', $donor->display_name, $donor->user_email), 'value' => $donor->user_email);
        }

    } else { // Search for donors in Donations fields values

        $donations = get_posts(array(
            'post_type' => Leyka_Donation_Management::$post_type,
            'post_status' => 'funded',
            'posts_per_page' => 10,
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => 'leyka_donor_name', 'value' => $search_query, 'compare' => 'LIKE',),
                array('key' => 'leyka_donor_email', 'value' => $search_query, 'compare' => 'LIKE',),
            )
        ));

        $tmp_res = array();
        foreach($donations as $donation) {

            $donation = new Leyka_Donation($donation);
            if( !array_key_exists($donation->donor_email, $tmp_res) ) {
                $tmp_res[$donation->donor_email] = $donation->donor_name;
            }

        }
        foreach($tmp_res as $email => $name) {
            $res[] = array('label' => sprintf('%s (%s)', $name, $email), 'value' => $email);
        }

    }

    die(json_encode($res));

}
add_action('wp_ajax_leyka_donors_autocomplete', 'leyka_donors_autocomplete');

function leyka_gateways_autocomplete(){
    
    $res = array();

    $pm_list = leyka_get_pm_list();
    foreach($pm_list as $pm) {
        $res[] = array('label' => sprintf("%s (%s)", $pm->title, $pm->gateway->title), 'value' => $pm->full_id);
    }
    
    die(json_encode($res));

}
add_action('wp_ajax_leyka_gateways_autocomplete', 'leyka_gateways_autocomplete');

function leyka_campaigns_autocomplete(){

    $filter = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
    $res = array();

    if($filter) {
        $campaigns = leyka_get_campaigns_list(array('s' => $filter));
    }
    else {
        $campaigns = leyka_get_campaigns_list(array());
    }

    foreach($campaigns as $campaign_id => $campaign_title) {
        $res[] = array('label' => $campaign_title, 'value' => $campaign_id);
    }
    
    die(json_encode($res));

}
add_action('wp_ajax_leyka_campaigns_autocomplete', 'leyka_campaigns_autocomplete');

function leyka_donors_tags_autocomplete(){

    $filter = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
    
    $res = array();
    
    if($filter) {
        $donors_tags = get_terms(
            Leyka_Donor::DONORS_TAGS_TAXONOMY_NAME,
            array('hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'search' => $filter,)
        );
    } else {
        $donors_tags = get_terms(
            Leyka_Donor::DONORS_TAGS_TAXONOMY_NAME,
            array('hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC', 'count' => 10,)
        );
    }
    
    foreach($donors_tags as $tag) {
        $res[] = array('label' => $tag->name, 'value' => $tag->term_id);
    }
    
    die(json_encode($res));

}
add_action('wp_ajax_leyka_donors_tags_autocomplete', 'leyka_donors_tags_autocomplete');

function leyka_add_donor_comment(){
    
    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_add_donor_comment')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }
    
    try {
        $donor = new Leyka_Donor(absint($_POST['donor']));
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: donor not found', 'leyka'),)));
    }
    
    if(empty($_POST['comment'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: empty comment', 'leyka'),)));
    }
    
    $comment_text = sanitize_text_field($_POST['comment']);
    $donor->add_comment($comment_text);
    
    $comments = $donor->get_comments();
    $comment_id = 0;
    $comment = array();
    
    foreach($comments as $donor_comment_id => $donor_comment) {
        $comment_id = $donor_comment_id;
        $comment = $donor_comment;
    }
    
    $comment = array(
        'id' => $comment_id,
        'text' => stripslashes(esc_html($comment['text'])),
        'date' => time(),
        'author_name' => $comment['author_name'],
    );
    
    $comment_table_row_html = leyka_admin_get_donor_comment_table_row($comment_id, $comment);
    
    die(json_encode(array('status' => 'ok', 'comment_html' => $comment_table_row_html)));

}
add_action('wp_ajax_leyka_add_donor_comment', 'leyka_add_donor_comment');

function leyka_delete_donor_comment(){

    if(empty($_POST['comment_id'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: undefined comment id', 'leyka'),)));
    }

    $_POST['comment_id'] = absint($_POST['comment_id']);
    
    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_delete_donor_comment')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }
    
    try {
        $donor = new Leyka_Donor(absint($_POST['donor']));
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: donor not found', 'leyka'),)));
    }
    
    try {
        $donor->delete_comment($_POST['comment_id']);
    } catch(Exception $ex) {
        die(json_encode(array(
            'status' => 'error',
            'message' => $ex->getMessage()
        )));
    }
    
    die(json_encode(array('status' => 'ok')));

}
add_action('wp_ajax_leyka_delete_donor_comment', 'leyka_delete_donor_comment');

function leyka_save_editable_comment(){
    
    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_save_editable_str')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }
    
    try {
        $donor = new Leyka_Donor(absint($_POST['donor']));
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: donor not found', 'leyka'),)));
    }
    
    if(empty($_POST['text_item_id'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: empty comment id', 'leyka'),)));
    }
    
    if(empty($_POST['text'])) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: empty text', 'leyka'),)));
    }

    $comment_text = sanitize_text_field($_POST['text']);
    $donor->update_comment(absint($_POST['text_item_id']), $comment_text);
    
    die(json_encode(array(
        'status' => 'ok',
        'saved_text' => stripcslashes(stripcslashes(htmlspecialchars_decode($comment_text))),
    )));

}
add_action('wp_ajax_leyka_save_editable_comment', 'leyka_save_editable_comment');


function leyka_save_donor_description(){
    
    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_save_editable_str')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }
    
    try {
        $donor = new Leyka_Donor(absint($_POST['donor']));
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: donor not found', 'leyka'),)));
    }
    
    $donor->description = !empty($_POST['text']) ? sanitize_text_field($_POST['text']) : "";
    
    die(json_encode(array(
        'status' => 'ok', 
        'saved_text' => stripcslashes(stripcslashes(htmlspecialchars_decode($donor->description))),
    )));

}
add_action('wp_ajax_leyka_save_donor_description', 'leyka_save_donor_description');

function leyka_save_donor_name(){
    
    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_save_editable_str')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }
    
    try {
        $donor = new Leyka_Donor(absint($_POST['donor']));
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: donor not found', 'leyka'),)));
    }
    
    $donor->name = !empty($_POST['text']) ? sanitize_text_field($_POST['text']) : '';
    
    die(json_encode(array(
        'status' => 'ok',
        'saved_text' => stripcslashes(stripcslashes(htmlspecialchars_decode($donor->name))),
    )));

}
add_action('wp_ajax_leyka_save_donor_name', 'leyka_save_donor_name');

function leyka_save_donor_tags(){

    if(empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'leyka_save_donor_tags')) {
        die(json_encode(array('status' => 'error', 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }

    try {
        $donor = new Leyka_Donor(absint($_POST['donor']));
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error', 'message' => __('Error: donor not found', 'leyka'),)));
    }
    
    $tags = !empty($_POST['tags']) ? explode(',', sanitize_text_field($_POST['tags'])) : '';
    wp_set_object_terms($donor->id, $tags, Leyka_Donor::DONORS_TAGS_TAXONOMY_NAME);
    
    die(json_encode(array('status' => 'ok',)));

}
add_action('wp_ajax_leyka_save_donor_tags', 'leyka_save_donor_tags');

function leyka_close_dashboard_banner(){

    try {
        update_user_meta(get_current_user_id(), 'leyka_dashboard_banner_closed', 'y');
    } catch(Exception $e) {
        die(json_encode(array('status' => 'error')));
    }

    die(json_encode(array('status' => 'ok',)));

}
add_action('wp_ajax_leyka_close_dashboard_banner', 'leyka_close_dashboard_banner');

// Ajax files uploading handler (admin only):
function leyka_files_upload(){

    $data = array_merge(isset($_POST) ? $_POST : array(), isset($_FILES) ? $_FILES : array());

    if( !wp_verify_nonce($data['nonce'], 'leyka-upload-'.$data['option_id']) ) {
        die(json_encode(array('status' => -1, 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }

    $uploaded_file = wp_handle_upload($data['files'], array('test_form' => false,));

    if($uploaded_file && !isset($uploaded_file['error'])) {

        $upload_dir_base_path = wp_upload_dir();
        $upload_dir_base_path = $upload_dir_base_path['basedir'];
        $filename = basename($uploaded_file['url']);

        $response = array(
            'status' => 0,
            'filename' => $filename,
            'url' => $uploaded_file['url'],
            'path' => str_replace($upload_dir_base_path, '', $uploaded_file['file']),
            'type' => $uploaded_file['type'],
        );

    } else {
        $response = array('status' => -1, 'error' => $uploaded_file['error'],);
    }

    die(json_encode($response));

}
add_action('wp_ajax_leyka_files_upload', 'leyka_files_upload');

// Ajax handler for Donors bulk edit (admin only):
function leyka_bulk_edit_donors(){

    if( !wp_verify_nonce($_POST['nonce'], 'leyka-bulk-edit-donors') ) {
        die(json_encode(array('status' => -1, 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }

    $_POST['bulk-edit-action'] = empty($_POST['bulk-edit-action'])
        || !in_array($_POST['bulk-edit-action'], array('add', 'remove', 'replace')) ?
        'add' : trim($_POST['bulk-edit-action']);

    if( !empty($_POST['donors']) && !empty($_POST['donors-bulk-tags']) ) {
        foreach((array)$_POST['donors'] as $donor_user_id) {

            array_walk($_POST['donors-bulk-tags'], function( &$value ){
                $value = absint($value);
            });

            if($_POST['bulk-edit-action'] === 'add' || $_POST['bulk-edit-action'] === 'replace') {
                $result = wp_set_object_terms($donor_user_id, $_POST['donors-bulk-tags'], Leyka_Donor::DONORS_TAGS_TAXONOMY_NAME, $_POST['bulk-edit-action'] === 'add');
            } else if($_POST['bulk-edit-action'] === 'remove') {
                $result = wp_remove_object_terms($donor_user_id, $_POST['donors-bulk-tags'], Leyka_Donor::DONORS_TAGS_TAXONOMY_NAME);
            }

            if( !empty($result) && is_wp_error($result)) {

                $response = array('status' => -1, 'error' => $result->get_error_message(),);
                break;

            }

        }
    }

    die(json_encode(array('status' => 'ok',)));

}
add_action('wp_ajax_leyka_bulk_edit_donors', 'leyka_bulk_edit_donors');

function leyka_delete_extension(){

    if(empty($_POST['extension_id']) || !Leyka_Extension::get_by_id($_POST['extension_id'])) {
        die(json_encode(array('status' => -1, 'message' => __('Cannot found given extension', 'leyka'),)));
    } else if( !wp_verify_nonce($_POST['nonce'], 'leyka_delete_extension_'.$_POST['extension_id']) ) {
        die(json_encode(array('status' => -1, 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }

    $extension = Leyka_Extension::get_by_id($_POST['extension_id']);

    if( !$extension->folder || !file_exists($extension->folder) || !is_dir($extension->folder) ) {
        die(json_encode(array('status' => -1, 'message' => __('Cannot found the extension folder', 'leyka'),)));
    }

    if( !leyka_delete_dir($extension->folder) ) {
        die(json_encode(array(
            'status' => -1,
            'message' => sprintf(__('Cannot delete the extension. Please report this problem to the <a href="mailto:%s" target="_blank">Leyka technical support</a>.', 'leyka'), leyka_get_website_tech_support_email())
        )));
    }

    die(json_encode(array('status' => 0,)));

}
add_action('wp_ajax_leyka_delete_extension', 'leyka_delete_extension');

function leyka_support_packages_set_no_campaign_behavior(){

    if( !wp_verify_nonce($_POST['nonce'], 'support-packages-no-campaign-behavior') ) {
        die(json_encode(array('status' => -1, 'message' => __('Wrong nonce in the submitted data', 'leyka'),)));
    }

    if(empty($_POST['behavior'])) {
        die(json_encode(array('status' => -1, 'message' => __('No new behavior is given for the Support packages', 'leyka'),)));
    } else if( !in_array($_POST['behavior'], array('another-campaign', 'content-open', 'content-closed',))) {
        die(json_encode(array('status' => -1, 'message' => __('Incorrect behavior is given for the Support packages', 'leyka'),)));
    }

    if($_POST['behavior'] === 'another-campaign') {

        if( !absint($_POST['campaign_id']) ) {
            die(json_encode(array('status' => -1, 'message' => __('No new campaign is given for the Support packages', 'leyka'),)));
        }

        leyka_options()->opt('support_packages_campaign', absint($_POST['campaign_id']));
        delete_option('leyka_support_packages_no_campaign_behavior');

    } else if($_POST['behavior'] === 'content-open') {
        update_option('leyka_support_packages_no_campaign_behavior', 'content-open');
    } else if($_POST['behavior'] === 'content-closed') {
        update_option('leyka_support_packages_no_campaign_behavior', 'content-closed');
    }

    die(json_encode(array('status' => 0,)));

}
add_action('wp_ajax_leyka_support_packages_set_no_campaign_behavior', 'leyka_support_packages_set_no_campaign_behavior');
