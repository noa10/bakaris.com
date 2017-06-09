<?php
/**
 * File Name : weebly_billing.php
 * Description : This file is used to catch URL response from Weebly and check
 * the user existence. If exist then redirect to the upgrade product page
 * otherwise to the initial checkout page.
 * Created By : Glowtouch
 * Created Date : 27-Apr-2015
 * Last Modified By : Glowtouch
 * Last Modified Date : 13-Oct-2015
 *
 */

require("init.php");
set_include_path(__DIR__.'/modules/addons/weeblycloud'.PATH_SEPARATOR.get_include_path());
require_once 'library/config.php';
require_once 'library/librarywb.php';
require_once 'model/weeblycloud_model.php';

$model = new weeblycloud_model();

// GET WEEBLY CONFIGURATION
$result = weeblycloud_model::getWeeblyConfigDetails(WEEBLYCLOUD_MODULE_NAME);
$wb_upgradehash = $result['wb_upgradehash'];
$autoauthkey = $result['whmcs_autoauthkey'];

//CHECK BILLING OR UPGRADE TYPE
if (isset($_GET["type"]) && $_GET["type"]!="" && $_GET["type"]=="billing") {
    if (((isset($_GET["domain"]) && $_GET["domain"]!="")||(isset($_GET["user_id"]) && $_GET["user_id"]!="")||(isset($_GET["main_domain"]) && $_GET["main_domain"]!=""))) {
        $type = $_GET["type"];
        $domain = $_GET["domain"];
        $user_id = $_GET["user_id"];
        $main_domain = $_GET["main_domain"];

        // GET WEEBLY CONFIGURATION
        $wb_config = weeblycloud_model::getWeeblyConfigDetails(WEEBLYCLOUD_MODULE_NAME);

        // ASSIGN WEEBLY API KEY
        $contents = array();

        $contents['apiurl'] = $wb_config['wb_apiurl'];
        $contents['weeblyapisecret'] = $wb_config['wb_apisecret'];
        $contents['weeblyapikey'] = $wb_config['wb_apikey'];

        // FETCH EMAIL ID
        $table = "tblclients";
        $fields = "tblclients.email";
        $join = "tblhosting ON tblclients.id=tblhosting.userid";
        $where = "tblhosting.domain='".$main_domain."' and tblhosting.server!=0 and tblhosting.domainstatus='Active'";
        $host_email = $model->sqlSelect($table, $fields, $where, '', '', '', $join);

        $whmcs_email_id = '';
        if (count($host_email)>0) {
            $whmcs_email_id = $host_email[0]['email'];

            $table = "mod_weeblycloud_orders";
            $fields = "*";
            $where = "user_email='".$whmcs_email_id."'";
            $result = $model->sqlSelect($table, $fields, $where);

            if (count($result)>0) {
                $user_id = $result[0]['wb_user_id'];
                $wb_encrypted_user_id = $result[0]['wb_encrypted_user_id'];
            } else {
                // GET ENCRYPTED USERID
                $action_method = "POST";
                $end_point = "user/".$user_id."/encryptedId";
                $api_val_array = array("source"=>"cpanel");
                $api_response = weeblycloud_getWeeblyApiResponse($contents, $end_point, $action_method, $api_val_array, true);
                weeblycloud_LogModuleCall('Get Encrypted UserId', $end_point, print_r($api_response, true), '');

                if (isset($api_response->error)) {
                    $wb_encrypted_user_id = '';
                } else {
                    $wb_encrypted_user_id = $api_response['user_id'];
                }
            }
        }

        //CHECK USER IS EXIST OR NOT. IF NOT ADD THE USER ID TO THE WHMCS TABLE
        $table = "mod_weeblycloud_orders";
        $fields = "*";
        $where = "domain_name='".$domain."' and terminate_date is NULL";
        $result = $model->sqlSelect($table, $fields, $where);

        if (count($result)==0) {
            $table = "mod_weeblycloud_orders";
            $values = array(
                "wb_user_id" => $user_id,
                "wb_encrypted_user_id" => $wb_encrypted_user_id,
                "domain_name"=> $domain,
                "main_domain_name"=> $main_domain
            );
            $model->addDbDataWhmcsApi($table, $values);
        }

        // FETCH BILLING OPTION
        $table = "mod_weeblycloud_accountinfo";
        $fields = "*";
        $where = array();

        $acc_info = $model->sqlSelect($table, $fields, $where);

        $op = $acc_info[0]['billing_option'];

        // GET WHMCS URL
        $tmp = explode('/', $_SERVER['PHP_SELF']);
        $scriptname=end($tmp);
        $scriptpath=str_replace($scriptname, '', $_SERVER['PHP_SELF']);
        $whmcsurl   = 'http';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            $whmcsurl .= 's';
        }
        $whmcsurl .= '://'.$_SERVER['SERVER_NAME'].$scriptpath;

        if ($op=="1") {
            // GETTING THE GROUP ID
            $table = "tblproducts";
            $fields = "gid";
            $where = "id in (select whmcs_product_id from ";
            $where .= "mod_weeblycloud_plans where ";
            $where .= "ifnull(whmcs_product_id,'') != '')";
            $sort = "";
            $sortorder = "";
            $limits = "1";
            $join = "";
            $result = $model->sqlSelect($table, $fields, $where, $sort, $sortorder, $limits, $join);

            $gid = $result[0]['gid'];

            //Save domain name in session variable
            //This is utilized to inject into domain custom field while ordering Weebly plan
            $_SESSION['weeblycloud_domain_from_cPanel'] = $domain;

            $timestamp = time(); # Get current timestamp
            $email = $whmcs_email_id; # Clients Email Address to Login
            $goto = "cart.php?gid=".$gid;

            $hash = sha1($email.$timestamp.$autoauthkey); # Generate Hash

            # Generate AutoAuth URL & Redirect
            $url = $whmcsurl."dologin.php?email=".urlencode($email);
            $url .= "&timestamp=".$timestamp."&hash=".$hash."&goto=";
            $url .= urlencode($goto);

            header("Location: $url");
        } else {
            if ($op=="2") {
                // GETTING THE PRODUCT AND CUSTOM ID
                $table = "tblcustomfields";
                $fields = "id,relid";
                $where = "`relid` in (select `whmcs_product_id` from ";
                $where .= "`mod_weeblycloud_plans` where `wb_plan_id` in ";
                $where .= "(select `wb_plan_id` from ";
                $where .= "`mod_weeblycloud_accountinfo` where ";
                $where .= "`billing_option`=".$op."))";

                $result = $model->sqlSelect($table, $fields, $where);

                $cid = $result[0]['id'];
                $pid = $result[0]['relid'];

                $timestamp = time(); # Get current timestamp
                $email = $whmcs_email_id; # Clients Email Address to Login
                $goto = "cart.php?a=add&pid=".$pid;
                $goto .= "&customfield[".$cid."]=".$domain;

                $hash = sha1($email.$timestamp.$autoauthkey); # Generate Hash

                # Generate AutoAuth URL & Redirect
                $url = $whmcsurl."dologin.php?email=".urlencode($email);
                $url .= "&timestamp=".$timestamp."&hash=".$hash."&goto=";
                $url .= urlencode($goto);

                header("Location: $url");
            } else {
                echo WEEBLYCLOUD_BILLING_URL_ERR_MSG;
                exit;
            }
        }
    } else {
        echo WEEBLYCLOUD_BILLING_URL_ERR_MSG;
        exit;
    }
} else {
    //CHECK BILLING OR UPGRADE TYPE
    if (isset($_GET["type"]) && $_GET["type"]!="" && $_GET["type"]=="upgrade") {
        if ((isset($_GET["site"]) && $_GET["site"]!="")||(isset($_GET["user_id"]) && $_GET["user_id"]!="")||(isset($_GET["plan"]) && $_GET["plan"]!="")||(isset($_GET["upgrade_type"]) && $_GET["upgrade_type"]!="")||(isset($_GET["upgrade_id"]) && $_GET["upgrade_id"]!="")||(isset($_GET["plan_ids"]) && $_GET["plan_ids"]!="")||(isset($_GET["hash"]) && $_GET["hash"]!="")) {

            $site_id = htmlspecialchars($_GET['site']);
            $user_id = htmlspecialchars($_GET['user_id']);
            $current_plan = htmlspecialchars($_GET['plan']);
            $upgrade_type = htmlspecialchars($_GET['upgrade_type']);
            $upgrade_id = htmlspecialchars($_GET['upgrade_id']);
            $plan_ids = htmlspecialchars($_GET['plan_ids']);
            $hash = htmlspecialchars($_GET['hash']);

            $gethash = weeblycloud_getHashKey($user_id, $site_id, $wb_upgradehash);

            if ($gethash==$hash) {
                // GET WHMCS URL
                $tmp = explode('/', $_SERVER['PHP_SELF']);
                $scriptname=end($tmp);
                $scriptpath=str_replace($scriptname, '', $_SERVER['PHP_SELF']);
                $whmcsurl   = 'http';
                if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on') {
                    $whmcsurl .= 's';
                }
                $whmcsurl .= '://'.$_SERVER['SERVER_NAME'].$scriptpath;

                //GET THE WHMCS PLAN ID IN REFERENCE TO WEEBLY CLOUD PLAN ID
                $table = "mod_weeblycloud_plans";
                $fields = "whmcs_product_id";
                $where = "wb_plan_id in (".$plan_ids.")";
                $rows = $model->sqlSelect($table, $fields, $where);

                foreach ($rows as $value) {
                    $whmcsids[] = $value['whmcs_product_id'];
                }

                //GET THE PRESENT WEEBLY PLAN ID OF THE USER
                $table = "mod_weeblycloud_orders";
                $fields = "*";
                $where = array(
                    "wb_user_id" => $user_id,
                    "wb_site_id" => $site_id
                );

                $result = $model->sqlSelect($table, $fields, $where);

                $whmcs_package_id  = $whmcs_rel_id = $whmcs_email_id = "";
                if (count($result)) {
                    $whmcs_package_id = $result[0]['whmcs_package_id'];
                    $whmcs_rel_id = $result[0]['whmcs_rel_id'];
                    $whmcs_order_id = $result[0]['whmcs_order_id'];
                    $whmcs_email_id = $result[0]['user_email'];
                }

                //GET THE ID FOR UPGRADING THE PLAN
                $table = "tblhosting";
                $fields = "max(`id`)";
                $where = array(
                    "packageid" => $whmcs_package_id,
                    "userid" => $whmcs_rel_id,
                    "orderid" => $whmcs_order_id,
                );
                $result = $model->sqlSelect($table, $fields, $where);

                if (count($result)) {
                    $id = $result[0]['max(`id`)'];
                }

                if ($id == '') {
                    //GET THE ID FOR UPGRADING THE PLAN FROM UPGRADE TABLE
                    $table = "tblupgrades";
                    $fields = "max(`relid`)";
                    $where = array(
                        "orderid" => $whmcs_order_id,
                    );
                    $result = $model->sqlSelect($table, $fields, $where);

                    if (count($result)) {
                        $id = $result[0]['max(`relid`)'];
                    }
                }

                //TO CHECK THE TABLE IS EXIST OR NOT
                $table = "tblproduct_upgrade_products";
                $fields = "*";
                $where = array();

                $result = $model->sqlSelect($table, $fields, $where);

                if (count($result)==0) {
                    //version 5
                    //UPDATE THE PRODUCTS TABLE WITH THE PLANS TO WHICH IT CAN UPGRADE
                    $table = "tblproducts";
                    $update = array(
                        "upgradepackages" => serialize($whmcsids),
                        "configoptionsupgrade"=>"on"
                    );
                    $where = array("id" => $whmcs_package_id);
                    $model->updateDbDataWhmcsApi($table, $update, $where);
                } else {
                    //version 6
                    //UPDATE THE UPGARDE PRODUCTS TABLE WITH THE PLANS TO WHICH IT CAN UPGRADE
                    $table = "tblproduct_upgrade_products";
                    $where = array("product_id"=>$whmcs_package_id);
                    $model->deleteDbDataWhmcsApi($table, $where);

                    foreach ($whmcsids as $wid) {
                        $table = "tblproduct_upgrade_products";
                        $values = array(
                            "product_id"=>$whmcs_package_id,
                            "upgrade_product_id"=>$wid
                        );
                        $model->addDbDataWhmcsApi($table, $values);
                    }
                }

                $timestamp = time(); # Get current timestamp
                $email = $whmcs_email_id; # Clients Email Address to Login
                $goto = "upgrade.php?type=package&id=".$id;

                $hash = sha1($email.$timestamp.$autoauthkey); # Generate Hash

                # Generate AutoAuth URL & Redirect
                $url = $whmcsurl."dologin.php?email=".urlencode($email);
                $url .= "&timestamp=".$timestamp."&hash=".$hash."&goto=";
                $url .= urlencode($goto);

                echo "<iframe name=\"whmcscart\" src=\"$url\" frameborder=\"0\" width=\"1000\" height=\"1000\" id=\"iframe\"> </iframe>";

            } else {
                echo WEEBLYCLOUD_BILLING_URL_ERR_MSG;
                exit;
            }
        } else {
            echo WEEBLYCLOUD_BILLING_URL_ERR_MSG;
            exit;
        }
    } else {
        echo WEEBLYCLOUD_BILLING_URL_ERR_MSG;
        exit;
    }
}

/**
 * GENERATE THE WEEBLY HASH KEY FOR WHMCS LOGIN
 *
 */
function weeblycloud_getHashKey($wb_user_id, $wb_site_id, $wb_upgradehash)
{
    $hash = sha1($wb_user_id . "|" . $wb_site_id . "|" . $wb_upgradehash);
    return $hash;
}
