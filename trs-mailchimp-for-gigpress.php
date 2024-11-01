<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
/*
Plugin Name: MailChimp for GigPress
Plugin URI:
Description: It is used to automate mailchimp campaigns for specified list.
Author: therightsw
Version: 1.0.1
Author URI: https://therightsw.com/plugin-development/
*/
require('mailchimp/MailChimp.php');
require('mailchimp/Batch.php');
require('mailchimp/Webhook.php');
if ( defined( 'GIGPRESS_VERSION' ) ) {
    if (!class_exists('TRS_Gigpress_Mailchimp_Automation')) {
        class TRS_Gigpress_Mailchimp_Automation
        {

            function __construct()
            {
                add_action('admin_init', array(&$this, 'admin_init'));
                add_action('init', array(&$this, 'fe_init'));
                add_action('wp_enqueue_scripts', array(&$this, 'frontend_scripts'));
                add_action('admin_menu', array(&$this, 'addAdminPage'));
            }

            function fe_init()
            {
                ob_start();
            }

            function admin_init()
            {
                /* Register our stylesheet. */
                wp_enqueue_style('report_admin_style', plugins_url('css/admin.css', __FILE__));
                if (!session_id()) {
                    session_start();
                }
                ob_start(); //holds output till full page is loaded
            }

            function addAdminPage()
            {

                add_submenu_page('gigpress', 'Mailchimp Compaigns', 'Mailchimp Compaigns', 'publish_posts', 'mailchimp-compaigns', array(&$this, 'admin_share_settings'));
            }

            function cron_job_hook_mailchimp()
            {
                $get_options = get_option('cron_setting_for_ccampaigns');

                global $wpdb;

                $mail_list_option = get_option('cron_setting_for_ccampaigns');
                $to_date = date("Y-m-d", strtotime(date("Y-m-d") . " +" . $mail_list_option['next_no_of_days'] . " day"));
                $sql = "SELECT * FROM `" . $wpdb->prefix . "gigpress_shows` WHERE `show_date` >= '" . date("Y-m-d") . "' and `show_date` <= '" . $to_date . "' and `show_status`= 'active' ORDER BY `show_date` asc";
                $events = $wpdb->get_results($sql);

                $counter = 0;
                $short_code = '';
                foreach ($events as $event) {
                    $date = date_create($event->show_date);
                    $date = date_format($date, "D d M ");
                    $time = date_create($event->show_time);
                    $time = date_format($time, "h:i A");
                    $sql1 = "SELECT * FROM `" . $wpdb->prefix . "gigpress_artists` WHERE `artist_id` >= '" . $event->show_artist_id . "'";
                    $artist = $wpdb->get_row($sql1);

                    $artist_url = $artist->artist_url;
                    if ($artist_url == '') {
                        $artist_url = site_url();
                    }

                    $sql2 = "SELECT * FROM `" . $wpdb->prefix . "gigpress_venues` WHERE `venue_id` >= '" . $event->show_venue_id . "'";
                    $venue = $wpdb->get_row($sql2);
                    if ($venue->venue_name != '') {
                        $location = $venue->venue_name;
                        if ($venue->venue_address != '') {
                            $location = $location . ',' . $venue->venue_address;
                        }
                    } else {
                        $location = $venue->venue_address;
                    }
                    $short_code .= '
                        <table align="left" border="0" cellpadding="0" cellspacing="0" width="250" class="columnWrapper">
                            <tr>
                                <td valign="top" class="columnContainer">
                                    <table border="0" cellpadding="0" cellspacing="0" width="100%" class="mcnTextBlock" style="min-width:100%;">
                                        <tbody class="mcnTextBlockOuter">
                                            <tr>
                                                <td valign="top" class="mcnTextBlockInner" style="padding-top:9px;">
                                                    <table align="left" border="0" cellpadding="0" cellspacing="0" style="max-width:100%; min-width:100%;" width="100%" class="mcnTextContentContainer">
                                                        <tbody>
                                                            <tr>
                                                                <td valign="top" class="mcnTextContent" style="padding-top:0; padding-right:18px; padding-bottom:9px; padding-left:18px;">
                                                                    <a href="' . $artist_url . '" title="" class="" target="_blank"><span style="font-size:16px"><strong>' . $artist->artist_name . '</strong></span><br>
                                                                    ' . $location . '<br>
                                                                    ' . $date . '<br>
                                                                    ' . $time . '</a>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </table>';
                }
                $short_code .= '<style type="text/css">           
                    @media only screen and (max-width: 480px){
                            .columnWrapper{
                                max-width:100% !important;
                                width:100% !important;
                            }                
                    }</style>';

                $api_for_mailchimp = get_option('api_mailchimp');
                $MailChimp = new MailChimp($api_for_mailchimp);
                $result = $MailChimp->post("campaigns", array(
                    'recipients' => array('list_id' => $mail_list_option['cron_list']),
                    'type' => 'regular',
                    'settings' => array(
                        'subject_line' => $mail_list_option['cron_subject'],
                        'title' => $mail_list_option['cron_title'] . Date('d-m-Y'),
                        'from_name' => $mail_list_option['cron_f_name'],
                        'reply_to' => $mail_list_option['cron_f_email']
                    )
                ));

                if ($MailChimp->success()) {
                    $campaigns_content = $MailChimp->put("campaigns/" . $result['id'] . "/content", array(
                        'template' => array(
                            'id' => intval($mail_list_option['cron_template']),
                            'sections' => array('std_content' => $short_code, 'title_template_mailchimp' => $mail_list_option['cron_template_header']),
                        )
                    ));
                    if ($MailChimp->success()) {
                        devprovider_mailchimp_write_log('Gigpress compaign successfully created');
                        $send = $MailChimp->post("campaigns/" . $result['id'] . "/actions/send");
                        devprovider_mailchimp_write_log('Gigpress compaign sent successfully');
                    } else {
                        devprovider_mailchimp_write_log('Gigpress compaign has some error: ' . $MailChimp->getLastError());
                        return false;
                    }
                } else {
                    devprovider_mailchimp_write_log('Gigpress compaign has some error: ' . $MailChimp->getLastError());
                    return false;
                }
                return true;
            }

            function frontend_scripts()
            {
                wp_enqueue_script('jquery');
                wp_enqueue_style('checklist_front_style', plugins_url('css/admin.css', __FILE__));
            }

            function admin_share_settings()
            {
                $api_for_mailchimp = get_option('api_mailchimp');
                try {
                    $MailChimp = new MailChimp($api_for_mailchimp);
                    $MailChimp_lists = $MailChimp->get('lists');
                    $MailChimp_templates = $MailChimp->get('templates');
                } catch (Exception $e) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . $e->getMessage() . '</p></div>';
                }

                if (isset($_POST['mandrill_settings'])) {
                    update_option('api_mailchimp', sanitize_text_field($_POST['api_mailchimp']));
                    ?>
                    <div class="notice notice-success is-dismissible"><p>Mailchimp API key settings Successfully
                            saved.</p></div>
                    <?php
                }
                $api_mailchimp = get_option('api_mailchimp', 'Mailchimp API key');

                ?>
                <div class="ju_warp">
                    <div class="ju_title">API Setting</div>
                    <hr>
                    <form action="" method="post">
                        <div class="ju_lable">Mailchimp API key:</div>
                        <div class="ju_feild"><input type="text" name="api_mailchimp"
                                                     value="<?php echo $api_mailchimp; ?>" style="width:300px"></div>
                        <hr>
                        <input type="submit" class="button button-primary" name="mandrill_settings"
                               value="Save Settings">
                    </form>
                </div>
                <?php

                if (isset($_POST['cron_setting_for_ccampaigns'])) {
                    unset($_POST['cron_setting_for_ccampaigns']);
                    $flag = true;
                    foreach ($_POST as $key => $value) {
                        $postdata[$key] = sanitize_text_field($value);
                        if (trim($value) == '')
                            $flag = false;
                    }

                    update_option('cron_setting_for_ccampaigns', $postdata);
                    if ($flag):
                        $this->cron_job_hook_mailchimp();
                        ?>
                        <div class="notice notice-success is-dismissible"><p>Mailchimp Campaign Run Successfully.</p>
                        </div>
                        <?php
                    else:
                        ?>
                        <div class="notice notice-error is-dismissible"><p>Please fill all fields before creating a
                                compaign.</p></div>
                        <?php
                    endif;
                }

                $get_options = get_option('cron_setting_for_ccampaigns');
                if (empty($get_options)) {
                    $get_options['next_no_of_days'] = 5;
                    $get_options['cron_title'] = 'GigPress Campaign';
                }
                ?>
                <div class="ju_warp">
                    <div class="ju_title">Campaigns Settings</div>
                    <hr>
                    <form action="" method="post">
                        <div class="ju_lable">Mailing List:</div>
                        <div class="ju_feild">
                            <select name="cron_list">
                                <?php
                                foreach ($MailChimp_lists['lists'] as $list) {
                                    ?>
                                    <option value="<?php echo $list['id']; ?>" <?php if ($get_options['cron_list'] == $list['id']) {
                                        echo "selected/";
                                    } ?>><?php echo $list['name'] . " (" . $list['stats']['member_count'] . ")"; ?></option>
                                    <?php
                                }
                                ?>
                            </select>
                        </div>
                        <br>
                        <div class="ju_lable">Template List:</div>
                        <div class="ju_feild">
                            <select name="cron_template">
                                <?php
                                foreach ($MailChimp_templates['templates'] as $list) {
                                    if ($list['type'] != "user") continue;
                                    ?>
                                    <option value="<?php echo $list['id']; ?>" <?php if ($get_options['cron_template'] == $list['id']) {
                                        echo "selected/";
                                    } ?>><?php echo $list['name']; ?></option>
                                    <?php
                                }
                                ?>
                            </select>
                        </div>
                        <br>

                        <div class="ju_lable">No of days :</div>
                        <div class="ju_feild">
                            <select name="next_no_of_days">
                                <?php
                                for ($x = 1; $x <= 30; $x++) {
                                    ?>
                                    <option value="<?php echo $x; ?>" <?php if ($get_options['next_no_of_days'] == $x) {
                                        echo "selected/";
                                    } ?>><?php echo $x; ?></option>
                                    <?php
                                }
                                ?>
                            </select> <i>upcoming events duration in days which are sent in email</i>
                        </div>
                        <br>

                        <div class="ju_lable">Campaign's Title:</div>
                        <div class="ju_feild">
                            <input type="text" name="cron_title" value="<?php echo $get_options['cron_title']; ?>"
                                   style="width:300px" required>
                        </div>
                        <br>
                        <div class="ju_lable">Email Subject:</div>
                        <div class="ju_feild">
                            <input type="text" name="cron_subject" value="<?php echo $get_options['cron_subject']; ?>"
                                   style="width:300px" required>
                        </div>
                        <br>
                        <div class="ju_lable">Sender's Name:</div>
                        <div class="ju_feild">
                            <input type="text" name="cron_f_name" value="<?php echo $get_options['cron_f_name']; ?>"
                                   style="width:300px" required>
                        </div>
                        <br>
                        <div class="ju_lable">Sender's Email:</div>
                        <div class="ju_feild">
                            <input type="email" name="cron_f_email" value="<?php echo $get_options['cron_f_email']; ?>"
                                   style="width:300px" required>
                        </div>
                        <br>
                        <div class="ju_lable">Template Header:</div>
                        <div class="ju_feild">
                            <input type="text" name="cron_template_header"
                                   value="<?php echo $get_options['cron_template_header']; ?>" style="width:300px"
                                   required>
                        </div>
                        <br>
                        <br><br><br>
                        <div class="ju_clear"></div>
                        <hr>
                        <input type="submit" class="button button-primary" name="cron_setting_for_ccampaigns"
                               value="Run Compaign">
                    </form>
                </div>
                <div class="" ju_wrap">
                <h2>Useage Instructions:</h2>
                <ol>
                    <li>Create a Template in mailchimp with these mc:edit sections <br/><b>std_content</b> this is used
                        for standard events information.<br/><b>title_template_mailchimp</b> this will be used to
                        display header text entered in settings
                    </li>
                    <li>select the appropriate list of clients to send this compaign</li>
                    <li>select the created template in Mailchimp using drop down</li>
                    <li>select all other relevant information and its done</li>
                </ol>
                </div>
                <?php
            }
        } //class ends
    } //class exists ends

    //log file maintenance
    if (!function_exists('devprovider_mailchimp_write_log')) {
        function devprovider_mailchimp_write_log($log)
        {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }
    $tgmpa = new TRS_Gigpress_Mailchimp_Automation();
}

?>