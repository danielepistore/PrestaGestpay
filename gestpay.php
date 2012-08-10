<?php

/**
 * GestPay main class to manage Banca Sella payment gateway, gestpay.php
 * @category payment
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public License
 * version 2.1 as published by the Free Software Foundation.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details at
 * http://www.gnu.org/copyleft/lgpl.html
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 * @author Andrea De Pirro <andrea.depirro@yameveo.com>, Enrico Aillaud <enrico.aillaud@yameveo.com>
 * @copyright Andrea De Pirro & Enrico Aillaud
 * @version 0.4.5
 *
 */
// @todo implement GestPayCryptHS when SSL is available
// @todo this class is getting too big. We must start to refactor
include_once(_PS_MODULE_DIR_ . "gestpay/lib/GestPayCrypt/GestPayCrypt.inc.php");

class gestpay extends PaymentModule
{
  private $_html = '';
  private $_postErrors = array();
  public $details;
  public $owner;
  public $address;
  public $tabClass;
  
  /**
   * Constructor for the class GestPay
   *
   */
  public function __construct()
  {
    $this->blowfish = new Blowfish(_COOKIE_KEY_, _COOKIE_IV_);
    $this->name = 'gestpay';
    $this->tab = 'payments_gateways';
    $this->tabClass = 'AdminGestpay';
    $this->version = 0.5;
    $this->author = 'Yameveo';
    $this->currencies = true;
    $this->currencies_mode = 'checkbox';

    $config = Configuration::getMultiple(
                    array(
                        'GESTPAY_LOGIN_USER',
                        'GESTPAY_PASSWORD',
                        'GESTPAY_MERCHANT_CODE',
                        'GESTPAY_LOGIN_USER_TEST',
                        'GESTPAY_PASSWORD_TEST',
                        'GESTPAY_MERCHANT_CODE_TEST',
                        'GESTPAY_ACCOUNT_TYPE',
                        'GESTPAY_CURL_PATH'
                    )
    );
    if (isset($config['GESTPAY_LOGIN_USER']))
      $this->login_user = $config['GESTPAY_LOGIN_USER'];
    if (isset($config['GESTPAY_PASSWORD']))
      $this->password = $this->blowfish->decrypt(trim($config['GESTPAY_PASSWORD']));
    if (isset($config['GESTPAY_MERCHANT_CODE']))
      $this->merchant_code = $config['GESTPAY_MERCHANT_CODE'];
    if (isset($config['GESTPAY_LOGIN_USER_TEST']))
      $this->login_user_test = $config['GESTPAY_LOGIN_USER_TEST'];
    if (isset($config['GESTPAY_PASSWORD_TEST']))
      $this->password_test = $this->blowfish->decrypt(trim($config['GESTPAY_PASSWORD_TEST']));
    if (isset($config['GESTPAY_MERCHANT_CODE_TEST']))
      $this->merchant_code_test = $config['GESTPAY_MERCHANT_CODE_TEST'];
    if (isset($config['GESTPAY_ACCOUNT_TYPE']))
      $this->account_type = $config['GESTPAY_ACCOUNT_TYPE'];
    if (isset($config['GESTPAY_CURL_PATH']))
      $this->curl_path = $config['GESTPAY_CURL_PATH'];

    parent::__construct(); /* The parent construct is required for translations */

    $this->page = basename(__FILE__, '.php');
    $this->displayName = $this->l('GestPay');
    $this->description = $this->l('Accept payments by GestPay');
    $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');
    if (!isset($this->merchant_code) && !isset($this->login_user) && !isset($this->password))
      $this->warning = $this->l('Account must be configured in order to use this module correctly');
  }

  /**
   * Function to install the module
   *
   * @return boolean true if the module has been installed correctly
   *
   */
  public function install()
  {
    
    $admin_payment_tab_id = Tab::getIdFromClassName('AdminPayment');
    if (!parent::install()
            OR !$this->installModuleTab($this->tabClass, 
                    array(1 => "GestPay", 2 => "GestPay", 3 => "GestPay", 4 => "GestPay", 5 => "GestPay"), 
                    $admin_payment_tab_id) // Insert Admin Tab
            OR !$this->installDB() // Add custom DB tables
            OR !$this->registerHook('payment')
            OR !$this->registerHook('paymentReturn')
    )

      return false;
    return true;
  }

  /**
   * Function to create tables needed by the module
   *
   * @return boolean true if queries went fine
   *
   */
  private function installDB()
  {
    
    // Map GestPay currencies IDs with Prestashop IDs
    if (
            Db::getInstance()->Execute('
          CREATE TABLE `' . _DB_PREFIX_ . 'gestpay_currencies_map` (
            `id_prestashop` INT UNSIGNED NOT NULL PRIMARY KEY,
            `id_gestpay` INT UNSIGNED NOT NULL UNIQUE,
            `currency_name` VARCHAR(10) NOT NULL
            COLLATE utf8_general_ci
          )')
            AND
            Db::getInstance()->Execute("
          INSERT INTO `" . _DB_PREFIX_ . "gestpay_currencies_map` (
            `id_prestashop`,
            `id_gestpay`,
            `currency_name`)
          VALUES
            (1, 242, 'Euro'),
            (2, 1, 'Dollars'),
            (3, 2, 'Pounds')
          ")
            AND
            // Map GestPay languages IDs with Prestashop Codes
            Db::getInstance()->Execute('
          CREATE TABLE `' . _DB_PREFIX_ . 'gestpay_languages_map` (
              `code_prestashop` CHAR(2) NOT NULL PRIMARY KEY,
              `id_gestpay` INT UNSIGNED NOT NULL UNIQUE
              COLLATE utf8_general_ci
          )')
            AND
            Db::getInstance()->Execute("
          INSERT INTO `" . _DB_PREFIX_ . "gestpay_languages_map` (
            `code_prestashop`,
            `id_gestpay`)
          VALUES
            ('it', 1),
            ('en', 2),
            ('es', 3),
            ('fr', 4),
            ('de', 5)
          "))

      return true; //endif

    return false;
  }

  private function installModuleTab($tabClass, $tabName, $idTabParent)
  {
    @copy(_PS_MODULE_DIR_ . $this->name . '/images/logo.png', _PS_IMG_DIR_ . 't/' . $tabClass . '.png');
    $tab = new Tab();
    $tab->name = $tabName;
    $tab->class_name = $tabClass;
    $tab->module = $this->name;
    $tab->id_parent = $idTabParent;
    if (!$tab->save())
      return false;
    return true;
  }

  /**
   * Removes any configuration set by the module during install, along with
   * related DB tables
   *
   * @return boolean true if unistall went fine
   *
   */
  public function uninstall()
  {
    if (!parent::uninstall()
            OR !$this->uninstallModuleTab($this->tabClass)
            OR !$this->uninstallDB()
            OR !Configuration::deleteByName('GESTPAY_LOGIN_USER')
            OR !Configuration::deleteByName('GESTPAY_PASSWORD')
            OR !Configuration::deleteByName('GESTPAY_MERCHANT_CODE')
            OR !Configuration::deleteByName('GESTPAY_LOGIN_USER_TEST')
            OR !Configuration::deleteByName('GESTPAY_PASSWORD_TEST')
            OR !Configuration::deleteByName('GESTPAY_MERCHANT_CODE_TEST')
            OR !Configuration::deleteByName('GESTPAY_TESTMODE')
            OR !Configuration::deleteByName('GESTPAY_ACCOUNT_TYPE')
            OR !Configuration::deleteByName('GESTPAY_CURL_PATH')
    )
      return false;
    
    return true;
  }

  /**
   * Support function to remove the GestPay admin tab
   *
   * @return boolean true if everything went fine
   *
   */
  private function uninstallModuleTab($tabClass)
  {
    $idTab = Tab::getIdFromClassName('AdminGestPay');
    if ($idTab != 0) {
      $tab = new Tab($idTab);
      $tab->delete();
      @unlink(_PS_IMG_DIR_ . 't/' . $tabClass . '.png');
      return true;
    }

    return false;
  }

  /**
   * Support function to remove every table and values related to the module
   * from the DB
   *
   * @return boolean true if everything went fine
   *
   */
  private function uninstallDB()
  {
    if (Db::getInstance()->Execute('DROP TABLE `' . _DB_PREFIX_ . 'gestpay_currencies_map`;')
            AND Db::getInstance()->Execute('DROP TABLE `' . _DB_PREFIX_ . 'gestpay_languages_map`;')
            AND Db::getInstance()->Execute("DELETE FROM `" . _DB_PREFIX_ . "tab` WHERE `class_name`='AdminGestPay';")
            AND Db::getInstance()->Execute("DELETE FROM `" . _DB_PREFIX_ . "tab_lang` WHERE `name`='GestPay';")) {
      return true;
    }

    return false;
  }

  /**
   * Support function to validate backend user's input after setting
   * login information
   *
   */
  private function _postValidation()
  {
    if (isset($_POST['btnSubmit'])) {

      $login_user = $_POST['login_user'];
      $password = $_POST['password'];
      $merchant_code = $_POST['merchant_code'];
      $login_user_test = $_POST['login_user_test'];
      $password_test = $_POST['password_test'];
      $merchant_code_test = $_POST['merchant_code_test'];
      $account_type = $_POST['account_type'];

      // Login User validation
      if (!preg_match('/^[a-zA-Z0-9]{3,16}$/', $login_user)) {
        if (empty($login_user))
          $this->_postErrors[] = $this->l('Login User is required.');
        else
          $this->_postErrors[] = $this->l('Login User is invalid.');
      }
      if (!preg_match('/^[a-zA-Z0-9]{0,16}$/', $login_user_test)) {
        $this->_postErrors[] = $this->l('Login User (test mode) is invalid.');
      }

      // Password validation
      if (!is_numeric($password) && strlen($password) < 3 && strlen($password) > 19) {
        if (empty($password))
          $this->_postErrors[] = $this->l('Password is required.');
        else
          $this->_postErrors[] = $this->l('Password is invalid.');
      }
      if (!empty($password_test)) {
        if ((!is_numeric($password_test) && strlen($password_test) < 3 && strlen($password_test) > 19)) {
          $this->_postErrors[] = $this->l('Password (test mode) is invalid.');
        }
      }
      // Merchant Code validation
      if (!preg_match('/^[a-zA-Z0-9]{3,16}$/', $merchant_code)) {
        if (empty($merchant_code))
          $this->_postErrors[] = $this->l('Merchant Code is required.');
        else
          $this->_postErrors[] = $this->l('Merchant Code is invalid.');
      }
      if (!preg_match('/^[a-zA-Z0-9]{0,16}$/', $merchant_code_test)) {
        $this->_postErrors[] = $this->l('Merchant Code (test mode) is invalid.');
      }

      // Account type validation
      if (!preg_match('/^[0-2]{0,1}$/', $account_type)) {
        if (empty($account_type))
          $this->_postErrors[] = $this->l('Account type is required.');
        else
          $this->_postErrors[] = $this->l('Account type is invalid.');
      }
    }
  }

  /**
   * Support function to update GestPay configuration fields
   *
   */
  private function _postProcess()
  {
    $this->blowfish = new Blowfish(_COOKIE_KEY_, _COOKIE_IV_);
    if (isset($_POST['btnSubmit'])) {
      Configuration::updateValue('GESTPAY_LOGIN_USER', $_POST['login_user']);
      Configuration::updateValue('GESTPAY_PASSWORD', $this->blowfish->encrypt(trim($_POST['password'])));
      Configuration::updateValue('GESTPAY_MERCHANT_CODE', $_POST['merchant_code']);
      Configuration::updateValue('GESTPAY_LOGIN_USER_TEST', $_POST['login_user_test']);
      Configuration::updateValue('GESTPAY_PASSWORD_TEST', $this->blowfish->encrypt(trim($_POST['password_test'])));
      Configuration::updateValue('GESTPAY_MERCHANT_CODE_TEST', $_POST['merchant_code_test']);
      Configuration::updateValue('GESTPAY_TESTMODE', $_POST['test_mode']);
      Configuration::updateValue('GESTPAY_ACCOUNT_TYPE', $_POST['account_type']);
      Configuration::updateValue('GESTPAY_CURL_PATH', $_POST['curl_path']);
    }
    $this->_html .= '<div class="conf confirm"><img src="../img/admin/ok.gif" alt="' . $this->l('ok') . '" /> ' . $this->l('Settings updated') . '</div>';
  }

  private function _displayGestPay()
  {
    // @todo better style, remove <br />
    $module_image_path = '../modules/gestpay/images';
    $this->_html .=
            '<b>' . $this->l('This module allows you to accept payments through Banca Sella GestPay.') . '</b><br />
      <img src="' . $module_image_path . '/visa.png" style="margin-right:10px" />
      <img src="' . $module_image_path . '/mastercard.png" style="margin-right:10px" />
      <img src="' . $module_image_path . '/amex.png" style="margin-right:10px" />
      <img src="' . $module_image_path . '/jcb.png" style="margin-right:10px" />
      <img src="' . $module_image_path . '/aura.png" style="margin-right:10px" />
      <img src="' . $module_image_path . '/gestpay.gif" style="margin-right:10px" />
      <br /><br />
      <b>' . $this->l('If you like this module please consider a donation') . '</b><br />
      <form action="https://www.paypal.com/cgi-bin/webscr" method="post">
        <input type="hidden" name="cmd" value="_s-xclick">
        <input type="hidden" name="encrypted" value="-----BEGIN PKCS7-----MIIHLwYJKoZIhvcNAQcEoIIHIDCCBxwCAQExggEwMI' .
            'IBLAIBADCBlDCBjjELMAkGA1UEBhMCVVMxCzAJBgNVBAgTAkNBMRYwFAYDVQQHEw1Nb3VudGFpbiBWaWV3MRQwEgYDVQQKEwtQYXlQ' .
            'YWwgSW5jLjETMBEGA1UECxQKbGl2ZV9jZXJ0czERMA8GA1UEAxQIbGl2ZV9hcGkxHDAaBgkqhkiG9w0BCQEWDXJlQHBheXBhbC5jb2' .
            '0CAQAwDQYJKoZIhvcNAQEBBQAEgYB48ZkGLt/FNYMMngNZDTiASU6gpl/n36e8hO1HF8cqfM4TdCC3jhO+3GP7hnCMt4jMxx+emMGR8' .
            'MZXy8e/q4VRlOXdrcjJISXRx5FLSiTJvTG+s8jzcqBo5FKzXKrKdQXxLUM3Xor+gtOPfzMVBTUxzsBCxBguCkWX4JMTSc76qDELMAkGB' .
            'SsOAwIaBQAwgawGCSqGSIb3DQEHATAUBggqhkiG9w0DBwQIdmbYYohhtPeAgYgUld9MB3qC30kO9RRiwWK/4ZUkCBun25KgU5IMqwfA' .
            'ahgICGuskyScMZOpC8mjtSqSJg6VQuzygpbYnrYfI2bAvnguDqZvo+zK1WQCUQn/OJOmn7tX79NgWEuzR+aQUckZ5Y1oKHIG/Qg7v9TB' .
            'JaQgJtxPG7HCaFQK78yWf7J5ICoBRwMiq5NwoIIDhzCCA4MwggLsoAMCAQICAQAwDQYJKoZIhvcNAQEFBQAwgY4xCzAJBgNVBAYTAlVTM' .
            'QswCQYDVQQIEwJDQTEWMBQGA1UEBxMNTW91bnRhaW4gVmlldzEUMBIGA1UEChMLUGF5UGFsIEluYy4xEzARBgNVBAsUCmxpdmVfY2VydHM' .
            'xETAPBgNVBAMUCGxpdmVfYXBpMRwwGgYJKoZIhvcNAQkBFg1yZUBwYXlwYWwuY29tMB4XDTA0MDIxMzEwMTMxNVoXDTM1MDIxMzEwMTMxN' .
            'VowgY4xCzAJBgNVBAYTAlVTMQswCQYDVQQIEwJDQTEWMBQGA1UEBxMNTW91bnRhaW4gVmlldzEUMBIGA1UEChMLUGF5UGFsIEluYy4xEzAR' .
            'BgNVBAsUCmxpdmVfY2VydHMxETAPBgNVBAMUCGxpdmVfYXBpMRwwGgYJKoZIhvcNAQkBFg1yZUBwYXlwYWwuY29tMIGfMA0GCSqGSIb3DQ' .
            'EBAQUAA4GNADCBiQKBgQDBR07d/ETMS1ycjtkpkvjXZe9k+6CieLuLsPumsJ7QC1odNz3sJiCbs2wC0nLE0uLGaEtXynIgRqIddYCHx88pb' .
            '5HTXv4SZeuv0Rqq4+axW9PLAAATU8w04qqjaSXgbGLP3NmohqM6bV9kZZwZLR/klDaQGo1u9uDb9lr4Yn+rBQIDAQABo4HuMIHrMB0GA' .
            '1UdDgQWBBSWn3y7xm8XvVk/UtcKG+wQ1mSUazCBuwYDVR0jBIGzMIGwgBSWn3y7xm8XvVk/UtcKG+wQ1mSUa6GBlKSBkTCBjjELMAkGA1' .
            'UEBhMCVVMxCzAJBgNVBAgTAkNBMRYwFAYDVQQHEw1Nb3VudGFpbiBWaWV3MRQwEgYDVQQKEwtQYXlQYWwgSW5jLjETMBEGA1UECxQKbG' .
            'l2ZV9jZXJ0czERMA8GA1UEAxQIbGl2ZV9hcGkxHDAaBgkqhkiG9w0BCQEWDXJlQHBheXBhbC5jb22CAQAwDAYDVR0TBAUwAwEB/zA' .
            'NBgkqhkiG9w0BAQUFAAOBgQCBXzpWmoBa5e9fo6ujionW1hUhPkOBakTr3YCDjbYfvJEiv/2P+IobhOGJr85+XHhN0v4gUkEDI8r2' .
            '/rNk1m0GA8HKddvTjyGw/XqXa+LSTlDYkqI8OwR8GEYj4efEtcRpRYBxV8KxAW93YDWzFGvruKnnLbDAF6VR5w/cCMn5hzGCAZowg' .
            'gGWAgEBMIGUMIGOMQswCQYDVQQGEwJVUzELMAkGA1UECBMCQ0ExFjAUBgNVBAcTDU1vdW50YWluIFZpZXcxFDASBgNVBAoTC1BheVB' .
            'hbCBJbmMuMRMwEQYDVQQLFApsaXZlX2NlcnRzMREwDwYDVQQDFAhsaXZlX2FwaTEcMBoGCSqGSIb3DQEJARYNcmVAcGF5cGFsLmNvb' .
            'QIBADAJBgUrDgMCGgUAoF0wGAYJKoZIhvcNAQkDMQsGCSqGSIb3DQEHATAcBgkqhkiG9w0BCQUxDxcNMDkxMjE1MTUzNjAwWjAjBgkq' .
            'hkiG9w0BCQQxFgQUmAzWH2DZgHa2DYi8c2ZJVUpIpgswDQYJKoZIhvcNAQEBBQAEgYAuYq5rEidpHJxBeIzLUl3NzRjz/p4wvN8dLef' .
            'qGvkhqT5ufivmjdD3s/CsdseY9fmlf19aU0OBehahI68mBp5anVFHy1F39ChDufWNOZJW2aSWHAijFYSgN31/j/SxmEkKe/ko9oW0GBEW' .
            '9+v8u9bSxKqOt8Q05dy/6svyNlSgMg==-----END PKCS7-----
        ">
        <input
          type="image"
          src="https://www.paypal.com/it_IT/IT/i/btn/btn_donateCC_LG.gif"
          border="0"
          name="submit"
          alt="PayPal - Il sistema di pagamento online più facile e sicuro!"
        >
        <img alt="" border="0" src="https://www.paypal.com/it_IT/i/scr/pixel.gif" width="1" height="1">
      </form>

      <br /><br />
      ' . $this->l('If the customer chooses this payment mode, the order will change its status once a positive confirmation is recieved from GestPay server') .
            '<br /><br /><br />';
  }

  private function _displayForm()
  {
    // @todo better style for fieldset.
    // @todo Should we use Smarty for templating?
    $this->_html .= '<style type="text/css">
        #gestpay_config .labels {
            width: 230px;
        }
        #gestpay_config .gestpay_input {
            width: 300px; 
            margin-bottom: 15px; 
            margin-left: 15px
        }
        </style>';
    $this->_html .=
            '<form action="' . $_SERVER['REQUEST_URI'] . '" method="POST">
        <fieldset id="gestpay_config">
          <legend><img src="../img/admin/contact.gif" />' . $this->l('Account details') . '</legend>'.
            '<h3>'. $this->l('Specify your GestPay account details') .'</h3>'.
            '<label for="login_user" class="labels">' . $this->l('Login User:') . '</label>' .
            '<input
              id="login_user"
              class="gestpay_input"
              type="text"
              name="login_user"
              value="' . htmlentities(Tools::getValue('login_user', $this->login_user), ENT_COMPAT, 'UTF-8') . '"/>
          <br />'
            .'<label for="password" class="labels">'. $this->l('Password:') . '</label>' .
            '<input
              id="password"
              class="gestpay_input"
              type="password"
              name="password"
              value="' . htmlentities(Tools::getValue('password', $this->password), ENT_COMPAT, 'UTF-8') . '" />
           <br />'
            .'<label for="merchant_code" class="labels">' . $this->l('Merchant Code:') . '</label>' .
            '<input
              id="merchant_code"
              class="gestpay_input"
              type="text"
              name="merchant_code"
              value="' . htmlentities(Tools::getValue('merchant_code', $this->merchant_code), ENT_COMPAT, 'UTF-8') . '" />
          <br />'
            .'<label for="login_user_test" class="labels">' . $this->l('Login User for Test Mode:') . '</label>' .
            '<input
              id="login_user_test"
              class="gestpay_input"
              type="text"
              name="login_user_test"
              value="' . htmlentities(Tools::getValue('login_user_test', $this->login_user_test), ENT_COMPAT, 'UTF-8') . '"
               />
          <br />'
            .'<label for="password_test" class="labels">' . $this->l('Password for Test Mode:') . '</label>' .
            '<input
              id="password_test"
              class="gestpay_input"
              type="password"
              name="password_test"
              value="' . htmlentities(Tools::getValue('password_test', $this->password_test), ENT_COMPAT, 'UTF-8') . '"
               />
           <br />'
            .'<label for="merchant_code_test" class="labels">' . $this->l('Merchant Code for Test Mode:') . '</label>' .
            '<input
              id="merchant_code_test"
              class="gestpay_input"
              type="text"
              name="merchant_code_test"
              value="' . htmlentities(Tools::getValue('merchant_code_test', $this->merchant_code_test), ENT_COMPAT, 'UTF-8') . '"
               />
          <br />';
          if(!extension_loaded("curl")) {
            $this->_html .= '<label for="curl_path" class="labels">' . $this->l('Curl bin path (usually /usr/bin/curl ):') . '</label>' .
              '<input
                id="curl_path"
                class="gestpay_input"
                type="text"
                name="curl_path"
                value="' . ($this->curl_path ? htmlentities(Tools::getValue('curl_path', $this->curl_path), ENT_COMPAT, 'UTF-8') : '/usr/bin/curl') . '"
                 />
            <br />';
          }
          $this->_html .= '<label for="test_mode" class="labels">' . $this->l('Activate Test Mode on Frontend:') . '</label>' .
            '<input
              id="test_mode"  
              type="checkbox"
              name="test_mode"
              value="1"
              style="width: 300px; margin-top: 10px; margin-left: 15px" ' .
              (Configuration::get('GESTPAY_TESTMODE') == '1' ? 'checked="checked"' : '') . '/>
          <br /><br />'
            . '<p>' . $this->l('Choose your account type:'). '</p><br />'.
          '<label for="basic" style="width: 100px; margin-top:-3px">BASIC</label><input
              id="basic"
              type="radio"
              name="account_type"
              value="0"' .
            ($this->account_type == 0 ? 'checked' : '') . '
              style="width: 20px; margin-bottom: 15px" /><br />
           <label for="advanced" style="width: 100px; margin-top:-3px">ADVANCED</label><input
              id="advanced"
              type="radio"
              name="account_type"
              value="1"' .
            ($this->account_type == 1 ? 'checked' : '') . '
              style="width: 20px; margin-bottom: 15px" /><br />
           <label for="professional" style="width: 100px; margin-top:-3px">PROFESSIONAL</label><input
              id="professional"
              type="radio"
              name="account_type"
              value="2"' .
            ($this->account_type == 2 ? 'checked' : '') . '
              style="width: 20px;  margin-bottom: 15px" />
          <br />' .
            '<input
              class="button"
              name="btnSubmit"
              value="' . $this->l('Update settings') . '"
              type="submit"
              style="margin-top: 10px" />
        </fieldset>
      </form>';
  }

  public function getContent()
  {
    $this->_html = '<h2>' . $this->displayName . '</h2>';
    if (!empty($_POST)) {
      $this->_postValidation();
      if (!sizeof($this->_postErrors))
        $this->_postProcess();
      else
        foreach ($this->_postErrors AS $err)
          $this->_html .= '<div class="alert error">' . $err . '</div>';
    } else
      $this->_html .= '<br />';

    $this->_displayGestPay();
    $this->_displayForm();

    return $this->_html;
  }

  /**
   * Crypt function based on GestPayCrypt library, needed to create 'a' and
   * 'b' parameters to create the request to GestPay gateway
   *
   * @global $cookie
   * @param Cart $cart contains transition data
   * @return array containing 'a' and 'b' parameters
   *
   */
  private function Crypt($cart)
  {
    global $cookie;

    switch (Configuration::get('GESTPAY_ACCOUNT_TYPE')) {
      case 0 :
        $account_type = 'BASIC';
        break;
      case 1 :
        $account_type = 'ADVANCED';
        break;
      case 2 :
        $account_type = 'PROFESSIONAL';
        break;
      default :
        $account_type = 'BASIC';
        break;
    }
    $gestpay_crypt = new GestPayCrypt(FALSE, Configuration::get('GESTPAY_CURL_PATH'));
    $customer = new Customer(intval($cart->id_customer));
    $del_add = new Address(intval($cart->id_address_delivery));
    $del_add_fields = $del_add->getFields();

    if (Configuration::get('GESTPAY_TESTMODE') == 1) {
      $merchant_code = Configuration::get('GESTPAY_MERCHANT_CODE_TEST');
    } else {
      $merchant_code = Configuration::get('GESTPAY_MERCHANT_CODE');
    }

    // @todo use the currencies map DB table to map PrestaShop currencies with GestPay currencies
    switch ($cookie->id_currency) {
      case 1 :
        $currency = "242"; // Euro
        break;
      case 2 :
        $currency = "1"; // Dollars
        break;
      case 3 :
        $currency = "2"; // Pounds
        break;
      default :
        $currency = "242"; // Default currency is Euro
        break;
    }

    $amount = number_format($cart->getOrderTotal(true, 3), 2, '.', ''); // Es. 1256.28
    $transaction_id = $cart->id;

    $customer_firstname = ucfirst(strtolower($del_add_fields['firstname'])) . " " . ucfirst(strtolower($del_add_fields['lastname']));
    $customer_email = $customer->email;

    // @todo use the laguages map DB table to map PrestaShop languages with GestPay languages
    switch (Language::getIsoById(intval($cookie->id_lang))) {
      case 'it' :
        $language = "1"; // Italian
        break;
      case 'en' :
        $language = "2"; // English
        break;
      case 'es' :
        $language = "3"; // Spanish
        break;
      case 'fr' :
        $language = "4"; // French
        break;
      case 'de' :
        $language = "5"; // German
        break;
      default :
        $language = "1"; // Default language is Italian
        break;
    }

    //$mycustominfo= "[PARAMETRI PERSONALIZZATI]"; //Es. "BV_CODCLIENTE=12*P1*BV_SESSIONID=398"

    if (($account_type == 'ADVANCED') or ($account_type == 'PROFESSIONAL')) {
      $gestpay_crypt->SetBuyerName($customer_firstname);
      $gestpay_crypt->SetBuyerEmail($customer_email);
      $gestpay_crypt->SetLanguage($language);
    }
    $gestpay_crypt->SetShopLogin($merchant_code);
    $gestpay_crypt->SetCurrency($currency);
    $gestpay_crypt->SetAmount($amount);
    $gestpay_crypt->SetShopTransactionID($transaction_id);
    $gestpay_crypt->SetDomainName($this->getGestPayDomainName());
    //$gestpay_crypt->SetCustomInfo($mycustominfo);

    $gestpay_crypt->Encrypt();

    $error_description = $gestpay_crypt->GetErrorDescription();
    // @todo manage error with an Exception
    if ($error_description != "") {
      echo "Encoding Error: " . $gestpay_crypt->GetErrorCode() . " " . $error_description . "<br />";
    } else {
      $a = $gestpay_crypt->GetShopLogin();
      $b = $gestpay_crypt->GetEncryptedString();
    }

    return array('a' => $a, 'b' => $b);
  }

  /**
   * Decrypts 'a' and 'b' parameters to determine if a payment went fine or
   * an hack it's been attempted
   *
   * @param string $a contains the shop login
   * @param string $b contains encrypted transaction data
   * @return GestPayCrypt contains decrypted transaction data
   *
   */
  private function deCrypt($a, $b)
  {
    $gestpay_decrypt = new GestPayCrypt(FALSE, Configuration::get('GESTPAY_CURL_PATH'));
    $gestpay_decrypt->SetShopLogin($a);
    $gestpay_decrypt->SetEncryptedString($b);
    $gestpay_decrypt->SetDomainName($this->getGestPayDomainName());
    $gestpay_decrypt->Decrypt(); // Decrypt parameters

    return $gestpay_decrypt;
  }

  /**
   * Converts GestPay code to identify currency in the correct symbol
   *
   * @param string
   * @todo use the prestashop native Currency object
   * @return string containg the html entity for the currency
   */
  private function convertToCurrencySymbol($currency_code)
  {
    switch ($currency_code) {
      case 242 :
        $symbol = "&euro;"; // Euro
        break;
      case 2 :
        $symbol = "&#36;"; // Dollars
        break;
      case 3 :
        $symbol = "&pound;"; // Pounds
        break;
      default :
        $symbol = "&euro;"; // Default currency is Euro
        break;
    }

    return $symbol;
  }

  /**
   * Validates payment decrypting 'a' and 'b' parameters and storing the
   * result of the transaction on DB
   *
   * @global Smarty $smarty
   * @global Cookie $cookie
   * @param string $a contains shop login
   * @param string $b contains encrypted transaction data
   * @return string page to be displayed after validation
   *
   */
  public function validatePayment($a, $b)
  {
    global $smarty;

    $gestpay_decrypt = $this->deCrypt($a, $b);

//    $shop_login = trim($gestpay_decrypt->GetShopLogin());
    $currency = $this->convertToCurrencySymbol($gestpay_decrypt->GetCurrency());
    $amount = floatval($gestpay_decrypt->GetAmount());
    $shop_transaction_id = trim($gestpay_decrypt->GetShopTransactionID());
    $buyer_name = preg_replace('#[\W]#', ' ', trim($gestpay_decrypt->GetBuyerName()));
//    $buyer_email = trim($gestpay_decrypt->GetBuyerEmail());
    $transaction_result = trim($gestpay_decrypt->GetTransactionResult());
//    $authorization_code = trim($gestpay_decrypt->GetAuthorizationCode());
    $error_code = trim($gestpay_decrypt->GetErrorCode());
    $error_description = trim($gestpay_decrypt->GetErrorDescription());
//    $error_bank_transaction_id = trim($gestpay_decrypt->GetBankTransactionID());
//    $alert_code = trim($gestpay_decrypt->GetAlertCode());
//    $alert_description = trim($gestpay_decrypt->GetAlertDescription());
//    $custom_info = trim($gestpay_decrypt->GetCustomInfo());

    $this->validateOrder($shop_transaction_id, ($transaction_result == 'OK' ? _PS_OS_PAYMENT_ : _PS_OS_ERROR_), $amount, 'GestPay');

    $smarty->assign(array(
        'currency' => $currency,
        'amount' => $amount,
        'shop_transaction_id' => $shop_transaction_id,
        'buyer_name' => $buyer_name,
        'transaction_result' => $transaction_result,
        'error_code' => $error_code,
        'error_description' => $error_description,
    ));

    return $this->display(__FILE__, 'payment_return.tpl');
  }

  /**
   * Builds the form to be sent to GestPay gateway
   *
   * @global <type> $smarty
   * @global <type> $cookie
   * @param Cart $cart
   * @return string page to be displayed before execution
   *
   */
  public function execPayment($cart)
  {
    global $smarty, $cookie;
    $array_crypt = $this->Crypt($cart);
    $amount = number_format($cart->getOrderTotal(true, 3), 2, '.', ''); // Ex. 1256.28
    $smarty->assign(array(
        'a' => $array_crypt['a'],
        'b' => $array_crypt['b'],
        'nbProducts' => $cart->nbProducts(),
        'cust_currency' => $cookie->id_currency,
        'currencies' => $this->getCurrency(),
        'total' => $amount,
        'gestpayURL' => $this->getGestPayUrl(),
        'isoCode' => Language::getIsoById((int) ($cookie->id_lang)),
        'this_path' => $this->_path
    ));

    return $this->display(__FILE__, 'payment_execution.tpl');
  }

  public function hookPayment($params)
  {
    if (!$this->active)
      return;

    global $smarty;
    $smarty->assign(array(
        'this_path' => $this->_path,
        'this_path_ssl' => (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') .
        htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') .
        __PS_BASE_URI__ . 'modules/' . $this->name . '/'
    ));

    return $this->display(__FILE__, 'payment.tpl');
  }

  /**
   * Returns the domain name to GestPay gateway to process the payment. If testmode
   * is set to 'test' it returns the domain name to the test payment gateway
   *
   * @return string Domain Name to GestPay payment gateway
   *
   */
  public function getGestPayDomainName()
  {
    $domain_name = Configuration::get('GESTPAY_TESTMODE') ? 'testecomm.sella.it' : 'ecomms2s.sella.it';
    return $domain_name;
  }

  /**
   * Returns the url to GestPay gateway to process the payment. If testmode
   * is set to 'test' it returns the url to the test payment gateway
   *
   * @return string Url to GestPay payment gateway
   *
   */
  public function getGestPayUrl()
  {
    $url = Configuration::get('GESTPAY_TESTMODE') ? 'https://testecomm.sella.it/Pagam/Pagam.aspx' : 'https://ecomm.sella.it/Pagam/Pagam.aspx';
    return $url;
  }

}
