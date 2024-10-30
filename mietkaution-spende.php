<?php
/*
Plugin Name: Mietkaution-Spende
Plugin URI: http://www.mietkautionsbund.de/
Description: Hiermit können Sie in Zusammenarbeit mit dem Linkwash-Plugin für jedes geworbene Mitglied automatisiert eine Spende durchführen.
Version: 0.0.3
Author: Deutscher Mietkautionsbund
Author URI: http://www.mietkautionsbund.de/
Depends: linkwash/linkwash.php
*/
class MietkautionSpendePlugin
{
    private $_accept = false;
    private $_counter = false;
    private $_amount = 0;
    private $_lastCheck = 0;
    
    const VERSION = '0.0.1';
    const LINKWASHID = '1386925598357657';
    const APIKEY = 'tV1RD1sw+pEI6ZZM3WBShp3Zj3/qMRSIAQRfumVLuzg=';
    const USERNAME = 'spenden2011@linkwash.de';
    
    /**
     * Initially read the current settings
     */
    public function __construct()
    {
        $this->_accept = get_option('accept');
        $this->_counter = get_option('counter');
        $this->_lastCheck = get_option('lastCheck');
        if ($this->_lastCheck < time()-60*60 && $this->_counter) {
            //Get current amount
            $this->_getAmount();
        }
        $this->_amount = get_option('amount');
    }
    
    /**
     * Initialize on widgets_init
     */
    public function init()
    {
        wp_register_sidebar_widget('mietkaution-spende', 'Mietkaution-Spende', array(&$this, 'getView'));
    }
    
    /**
     * Get the current 
     */
    private function _getAmount()
    {
        global $wp_version;
        
        $userAgent = 'WordPress/'.$wp_version.' | Linkwash/'.self::VERSION;
        
        $host = 'www.linkwash.de';
        $path = '/rest/getdonationamount/';
        
        if (function_exists('wp_remote_post')) {
            $request = array(
                'username' => self::USERNAME,
                'apiKey' => self::APIKEY
            );
            
            $httpArgs = array(
                'body' => $request,
                'headers' => array(
                    'Content-Type'  => 'application/x-www-form-urlencoded; ' .
                    'charset=' . get_option('blog_charset'),
                    'Host' => $host,
                    'User-Agent' => $userAgent
                ),
                'sslverify' => false,
                'httpversion' => '1.0',
                'timeout' => 15
            );
            
            $url = 'https://'.$host.$path;
            $response = wp_remote_post($url, $httpArgs);
            if (is_wp_error($response)) {
                return false;
            }
            
            if (trim($response['body']) != '') {
                update_option('lastCheck', time());
                update_option('amount', trim($response['body']));
            }
            
            return true;
        } else {
            $request = 'username='.self::USERNAME.'&apiKey='.self::APIKEY;
            $contentLength = strlen($request);
            
            $httpRequest  = "POST $path HTTP/1.0\r\n";
            $httpRequest .= "Host: $host\r\n";
            $httpRequest .= 'Content-Type: application/x-www-form-urlencoded; charset=' . get_option('blog_charset') . "\r\n";
            $httpRequest .= "Content-Length: $contentLength\r\n";
            $httpRequest .= "User-Agent: $userAgent\r\n";
            $httpRequest .= "\r\n";
            $httpRequest .= $request;
            
            $response = '';
            
            if (false != ($fs = @fsockopen('ssl://'.$host, 443, $errno, $errstr, 10))) {
                fwrite($fs, $httpRequest);
                
                while (!feof($fs)) {
                    $response .= fgets($fs, 1160); // One TCP-IP packet
                }
                fclose($fs);
                $response = explode("\r\n\r\n", $response, 2);
            }
            
            if (!is_array($response) ||
                !isset($response[1]) ||
                trim($response[1]) == ''
            ) {
                return false;
            }
            
            update_option('lastCheck', time());
            update_option('amount', trim($response[1]));
            
            return true;
        }
    }
    
    /**
     * Adds the settings link to the plugin overview
     */
    public function addSettingsLink($links, $file)
    {
        static $thisPlugin;
        if (!$thisPlugin) {
            $thisPlugin = plugin_basename(dirname(__FILE__).'/mietkaution-spende.php');
        }
        
        if ($file == $thisPlugin) {
            $settingsLink = '<a href="plugins.php?page=mietkaution-spende-key-config">'
                            .__("Einstellungen").'</a>';
            $links[] = $settingsLink;
        }
        return $links;
    }
    
    /**
     * Adds the configuration submenu item to plugin submenu
     */
    public function addMietkautionSpendeConfigMenu()
    {
        if (function_exists('add_submenu_page')) {
            add_submenu_page(
                'plugins.php',
                __('Mietkaution Spende Zustimmung'),
                __('Mietkaution Spende Zustimmung'),
                'manage_options',
                'mietkaution-spende-key-config',
                array(&$this, 'mietkautionSpendeConfig')
            );
        }
    }
    
    /**
     * Called on activation of plugin
     */
    public function activate()
    {
        //Add non accepted option
        add_option('accept', false);
        add_option('counter', false);
        add_option('amount', 0.00);
        add_option('lastCheck', 0);
    }
    
    /**
     * Called on deactivation of plugin
     */
    public function deactivate()
    {
        delete_option('accept');
        delete_option('counter');
        delete_option('amount');
        delete_option('lastCheck');
    }
    
    public function adminWarnings()
    {
        if (!$this->_accept) {
            add_action('admin_notices', array(&$this, 'getWarning'));
        }
    }
    
    /**
     * Get the warning message that acceptance is required
     */
    public function getWarning()
    {
        echo '<div class="updated fade"><p><strong>'
             .__('Spendenwidget ist fast bereit.').'</strong> '
             .sprintf(
                 __(
                     'Sie müssen Ihre <a href="%1$s">Zustimmung abgeben</a>, dass die externen Links angezeigt werden.'
                 ),
                 'plugins.php?page=mietkaution-spende-key-config'
             ).'</p></div>';
    }
    
    /**
     * Configure the acceptance and whether the counter should be shown
     */
    public function mietkautionSpendeConfig()
    {
        if (isset($_POST['save'])) {
            if (isset($_POST['accept']) && $_POST['accept'] == '1') {
                update_option('accept', true);
                $this->_accept = true;
            } else {
                update_option('accept', false);
                $this->_accept = false;
            }
            
            if (isset($_POST['counter']) && $_POST['counter'] == '1') {
                update_option('counter', true);
                $this->_counter = true;
            } else {
                update_option('counter', false);
                $this->_counter = false;
            }
            
            $this->success = __('Die Einstellungen wurden gespeichert');
        }
        
        include 'config.phtml';
    }
    
    /**
     * Get the widget for the front page
     */
    public function getView()
    {
        //Simply return html code that displays the desired widget content
        
        //Insert the js 
        echo '<script type="text/javascript">
         function __mietkautionSpendeLinkRewrite(obj)
         {
             var link = obj.href;
             var domain = document.domain;
             
             link = encodeURIComponent(encodeURIComponent(link));
             domain = encodeURIComponent(encodeURIComponent(domain));
             
             var destination = "http://www.linkwash.de/redirect/index/link/"+link+"/linkwashId/'.self::LINKWASHID.'/domain/"+domain+"/"
             
             var oWin = window.open(destination, \'_blank\');
             if (oWin) {
                 if (oWin.focus) {
                     oWin.focus();
                 }
                 return false;
             }
             oWin = null;
             return true;
         }
        </script>';
        echo '<div style="padding:5px; position:relative; padding-bottom:10px; border:1px solid #999999; border-radius:5px;"><h3 style="color:#FF7D00">'.__('Spendenaktion des').'</h3>';
        $img = WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__), "", plugin_basename(__FILE__)).'logo.png';
        $hearts = WP_PLUGIN_URL.'/'.str_replace(basename(__FILE__), "", plugin_basename(__FILE__)).'heart.png';
        echo '<img style="width:100%;" src="'.$img.'" alt="Deutscher Mietkautionsbund e.V." />';
        echo '<h3 style="padding:0px; margin:0px; margin-top:-25px; margin-bottom:20px; text-align:right; color:#FF7D00">2011/2012</h3>';
        if (!$this->_accept) {
            echo __('Für jede Integration auf einer Webseite spenden wir einmalig 10 Euro. Für jedes neu geworbene Mitglied und jede beantragte Mietkaution bzw. Mietbürgschaft, spenden wir zusätzlich jeweils 5 Euro pro Mitglied und 10 Euro pro Mietkaution.');
            //echo __('Hier können Sie sich anmelden: <a onclick="return __mietkautionSpendeLinkRewrite(this);" target="_blank" rel="noLinkwash" href="http://www.mietkautionsbund.de/spendenaktion">Mietkaution Spendenaktion</a>');
        } else {
            echo __('Für jede Integration auf einer Webseite spenden wir einmalig 10 Euro. Für jedes neu geworbene Mitglied und jede beantragte <a onclick="return __mietkautionSpendeLinkRewrite(this);" target="_blank" rel="noLinkwash" href="http://www.mietkautionsbund.de/">Mietkaution</a> bzw. <a onclick="return __mietkautionSpendeLinkRewrite(this);" target="_blank" rel="noLinkwash" href="http://www.mietkautionsbund.de/">Mietbürgschaft</a>, spenden wir zusätzlich jeweils 5 Euro pro Mitglied und 10 Euro pro Mietkaution.');
            echo __(' Hier können Sie sich anmelden: <a onclick="return __mietkautionSpendeLinkRewrite(this);" target="_blank" rel="noLinkwash" href="http://www.mietkautionsbund.de/">Mietkaution Spendenaktion</a>');
        }
        
        if ($this->_counter) {
            if ($this->_amount >= 1000) {
                echo sprintf(__('<p style="margin-top:10px;">Bereits gespendet: <br /><span style="font-size:%s; margin-left:50px; color:#FF7D00">%s &euro;</span><img style="position:absolute; top:70px; right:-30px;" src="%s" /></p>'), '130%', number_format($this->_amount, 2, ",", "."), $hearts);
            } else {
                echo sprintf(__('<p style="margin-top:10px;">Bereits gespendet: <span style="font-size:%s; color:#FF7D00">%s &euro;</span><img style="position:absolute; top:70px; right:-30px;" src="%s" /></p>'), '130%', number_format($this->_amount, 2, ",", "."), $hearts);
            }
        }
    }
}

$dmkbSpendePlugin = new MietkautionSpendePlugin();

add_action('widgets_init', array(&$dmkbSpendePlugin, 'init'));

add_action('admin_menu', array(&$dmkbSpendePlugin, 'addMietkautionSpendeConfigMenu'));
add_filter('plugin_action_links', array(&$dmkbSpendePlugin, 'addSettingsLink'), 10, 2);

register_activation_hook(__FILE__, array(&$dmkbSpendePlugin, 'activate'));
register_deactivation_hook(__FILE__, array(&$dmkbSpendePlugin, 'deactivate'));

$dmkbSpendePlugin->adminWarnings();
