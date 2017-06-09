<?php
/**
 * File Name : weeblyplan.php
 * Description : This file take the user id as post request to generate a SSO
 * link for weebly and redirect to weebly panel
 * Created By : Glowtouch
 * Created Date : 27-April-2015
 * Last Modified By : Glowtouch
 * Last Modified Date : 14-Sep-2015
 *
 */

require("init.php");
set_include_path(__DIR__.'/modules/addons/weeblycloud'.PATH_SEPARATOR.get_include_path());
require_once 'library/config.php';
require_once 'library/librarywb.php';
require_once 'model/weeblycloud_model.php';

$model = new weeblycloud_model();

// CHECK THE POST VALUES
if (isset($_POST['wb_userid'])) {
    $wb_user_id = $_POST['wb_userid'];
    $whmcs_user_id = $_POST['whmcs_userid'];
    $domain_name = $_POST['wb_domain_name'];
    $main_domain_name = $_POST['wb_main_domain_name'];
    $wb_encrypted_user_id = $_POST['wb_encrypted_user_id'];
    $wb_site_id = $_POST['wb_site_id'];

    // GET WEEBLY CONFIGURATION
    $wb_config = weeblycloud_model::getWeeblyConfigDetails(WEEBLYCLOUD_MODULE_NAME);

    // ASSIGN WEEBLY API KEY
    $contents = array();

    $contents['apiurl'] = $wb_config['wb_apiurl'];
    $contents['weeblyapisecret'] = $wb_config['wb_apisecret'];
    $contents['weeblyapikey'] = $wb_config['wb_apikey'];
    $wb_use_cpanel = $wb_config['wb_use_cpanel'];
    $wb_create_cpanel_ftp = $wb_config['wb_create_cpanel_ftp'];

    $arr_ServerCredentials = array();
    $arr_getdocrootpath = array();
    $arr_newftpcredentials = array();

    if (($wb_use_cpanel =='on')  || ($wb_create_cpanel_ftp =='on')) {
        // Get the server credentials
        $arr_ServerCredentials = explode(",", weeblycloud_getMainDomainName($model, $whmcs_user_id, $domain_name, $main_domain_name));
        $cp_url = $arr_ServerCredentials[0];
        $cp_username = $arr_ServerCredentials[1];
        $cp_password = $arr_ServerCredentials[2];
        $cp_accesshash = $arr_ServerCredentials[3];
        $cp_jsonapi_username = $arr_ServerCredentials[4];
        $weebly_main_domain_name = $arr_ServerCredentials[5];

        if (($cp_username != "") && ($cp_jsonapi_username != "") && ($weebly_main_domain_name != "")) {
            // Get the document root path
            $rel_doc_root_path = weeblycloud_getDocRootPath($cp_url, $cp_username, $cp_password, $cp_accesshash, $cp_jsonapi_username, $domain_name);

            if ($wb_use_cpanel =='on') {
                // Create whmcs.txt file
                weeblycloud_createWhmcsTxtFile($model, $domain_name, $rel_doc_root_path, $cp_url, $cp_username, $cp_password, $cp_accesshash, $cp_jsonapi_username, $wb_user_id, $wb_site_id, $wb_encrypted_user_id, $contents);
            }

            if ($wb_create_cpanel_ftp =='on') {
                // Generate Ftp Credential
                $arr_newftpcredentials = explode(",", weeblycloud_genNewFtpCredentials($domain_name));
                $ftp_user = $arr_newftpcredentials[0];
                $ftp_password = $arr_newftpcredentials[1];

                // Publish Ftp Credentials
                weeblycloud_setWeeblyPublishCredentials($model, $contents, $domain_name, $weebly_main_domain_name, $rel_doc_root_path, $ftp_user, $ftp_password, $cp_url, $cp_username, $cp_password, $cp_accesshash, $cp_jsonapi_username, $wb_user_id, $wb_site_id);
            }
        }
    }

    // GENERATE SSO LINK
    $action_method = "GET";
    $end_point = "user/".$wb_user_id."/site/".$wb_site_id."/loginLink";
    $api_val_array = array();
    $link = weeblycloud_getWeeblyApiResponse($contents, $end_point, $action_method, $api_val_array);

    if (isset($link->error)) {
        echo WEEBLYCLOUD_SSO_ERR_MSG1;
    } else {
        // REDIRECTING TO WEEBLY PAGE
        header("Location: ".$link->link);
    }
} else {
    echo WEEBLYCLOUD_SSO_ERR_MSG1;
}
