<?php

/*
+---------------------------------------------------------------------------+
| OpenX v${RELEASE_MAJOR_MINOR}                                                                |
| =======${RELEASE_MAJOR_MINOR_DOUBLE_UNDERLINE}                                                                |
|                                                                           |
| Copyright (c) 2003-2009 OpenX Limited                                     |
| For contact details, see: http://www.openx.org/                           |
|                                                                           |
| This program is free software; you can redistribute it and/or modify      |
| it under the terms of the GNU General Public License as published by      |
| the Free Software Foundation; either version 2 of the License, or         |
| (at your option) any later version.                                       |
|                                                                           |
| This program is distributed in the hope that it will be useful,           |
| but WITHOUT ANY WARRANTY; without even the implied warranty of            |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
| GNU General Public License for more details.                              |
|                                                                           |
| You should have received a copy of the GNU General Public License         |
| along with this program; if not, write to the Free Software               |
| Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA |
+---------------------------------------------------------------------------+
$Id$
*/

require_once LIB_PATH . '/Plugin/Component.php';
require_once LIB_PATH . '/Plugin/PluginManager.php';

require_once LIB_PATH . '/Admin/Redirect.php';

require_once MAX_PATH . '/lib/JSON/JSON.php';
require_once MAX_PATH . '/lib/OA/Admin/TemplatePlugin.php';
require_once MAX_PATH . '/lib/OA/Admin/UI/NotificationManager.php';
require_once MAX_PATH . '/lib/OA.php';
require_once MAX_PATH . '/lib/OX/Admin/UI/Hooks.php';

require_once dirname(__FILE__) . '/var/config.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/pcApiClient/oxPublisherConsoleMarketPluginClient.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/ZoneOptIn.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/Website.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/PreferenceVariable.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/UI/EntityFormManager.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/UI/EntityHelper.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/UI/EntityScreenManager.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/UI/CampaignsSettings.php';


define('OWNER_TYPE_AFFILIATE',  0);
define('OWNER_TYPE_CAMPAIGN',   1);
define('SETTING_TYPE_CREATIVE_TYPE',    0);
define('SETTING_TYPE_CREATIVE_ATTRIB',  1);
define('SETTING_TYPE_CREATIVE_CATEGORY', 2);

/**
 *
 * @package    openXMarket
 * @subpackage oxMarket
 * @author     Lukasz Wikierski <lukasz.wikierski@openx.org>
 * @author     Bernard Lange <bernard.lange@openx.org>
 */
class Plugins_admin_oxMarket_oxMarket extends OX_Component
{
    /** Cached plugin version string */
    private $pluginVersion;

    /**
     * @var Plugins_admin_oxMarket_PublisherConsoleMarketPluginClient
     */
    public $oMarketPublisherClient;
    
    /**
     * A manager for forms enhanced by market plugin
     *
     * @var OX_oxMarket_UI_EntityFormManager
     */
    private $oEntityFormManager;

    
    /**
     * A manager for screens enhanced by market plugin (but not forms)
     *
     * @var OX_oxMarket_UI_EntityFormManager
     */
    private $oEntityScreenManager;    
    
    
    /**
     * An instance of DAL for zone opt in
     *
     * @var OX_oxMarket_Dal_ZoneOptIn
     */
    private $zoneOptInDal;
    
    /**
     * An instance of DAL for website methods
     *
     * @var OX_oxMarket_Dal_ZoneOptIn
     */
    private $websiteDal;
    
    
    
    /**
     * An instance of DAL for market account preferences and user variables
     *
     * @var OX_oxMarket_Dal_PreferenceVariable
     */
    private $preferenceDal;
    
    

    public function __construct()
    {
        $this->oMarketPublisherClient =
            new Plugins_admin_oxMarket_PublisherConsoleMarketPluginClient(
                    $this->isMultipleAccountsMode());
                    
        $this->oFormManager = new OX_oxMarket_UI_EntityFormManager($this);
        $this->preferenceDal = new OX_oxMarket_Dal_PreferenceVariable($this);
    }
    

    public function afterLogin()
    {
        // Just unsets a cookie, so need to do it before any content is possibly output
        OX_oxMarket_UI_CampaignsSettings::removeSessionCookies($this->getCookiePath());
                
        // Try to link hosted accounts for current user
        $this->linkHostedAccounts();
        
        // If the user is manager or admin try to show him the OpenX Market Settings
        if ((OA_Permission::isAccount(OA_ACCOUNT_MANAGER) || OA_Permission::isAccount(OA_ACCOUNT_ADMIN)) 
            && $this->isRegistered() && !$this->isMarketSettingsAlreadyShown()) {
            $this->setMarketSettingsAlreadyShown();

                
            /*on upgrade, provide option to skip on quickaccess screen */    
            global $installing, $installerIsUpgrade;
            if ($installing && $installerIsUpgrade) {
                global $session;
                $session['oxMarket-quickstart-params']['showSkip'] = 1;
                phpAds_SessionDataStore();
            }
            OX_Admin_Redirect::redirect('plugins/' . $this->group . '/market-campaigns-settings.php');
            exit;
        }

        // Show only to unregistered users and... 
        if ($this->isRegistered()) {
            return;
        }
         
        if ($this->isMultipleAccountsMode()) { 
            // ... and those who are logged as manager (multiple accounts mode)
            if (OA_Permission::isUserLinkedToAdmin() || !OA_Permission::isAccount(OA_ACCOUNT_MANAGER)) {
                return;
            }
        } 
        elseif (!OA_Permission::isUserLinkedToAdmin()) {
            // ... and those who are linked to admin (normal mode)
            return;
        }

        $this->scheduleRegisterNotification();

        // Only splash if not shown already
        if (empty($GLOBALS['installing']) && !$this->isSplashAlreadyShown()) {
            OX_Admin_Redirect::redirect('plugins/' . $this->group . '/market-info.php');
            exit;
        }
    }    
    
    
    public function onEnable()
    {
        // Run autoregister method first
        try {
            require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/Installer.php';
            OX_oxMarket_Dal_Installer::autoRegisterMarketPlugin($this->getPublisherConsoleApiClient());
        } catch (Plugins_admin_oxMarket_PublisherConsoleClientException $exc) {
            OA::debug('Error during autoRegisterMarketPlugin in onEnable method: ('.$exc->getCode().')'.$exc->getMessage());
        }
        
        // Schedule Register Notification if needed
        if (!$this->isRegistered() && !$this->isMultipleAccountsMode() && OA_Permission::isUserLinkedToAdmin()) { 
            $this->scheduleRegisterNotification();
        }
        
        // Create missing market advertisers for newly added (market registered) managers
        require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/Advertiser.php';
        $oAdvertiserDal = new OX_oxMarket_Dal_Advertiser();
        $oAdvertiserDal->createMissingMarketAdvertisers($this->isMultipleAccountsMode()); 
       

        try {
            // Run registerwebsites script as background process
            $url = MAX::constructURL(MAX_URL_ADMIN, 'plugins/oxMarket/market-run-registerwebsites.php');
            $ctx = stream_context_create(array('http' => array(
                   'method' => 'POST',
                   'header' => "Cookie: sessionID=".$_COOKIE['sessionID']."\r\n")));
            $fp = @fopen($url, 'rb', false, $ctx);
            if ($fp) {
                stream_set_timeout($fp, 1); // 1s timeout
                stream_get_contents($fp); 
            } else {
                // register 10 websites if can't run background script 
                $this->getWebsiteManager()->initialUpdateWebsites();
            }
        } catch (Exception $e) {
            OA::debug('oxMarket on Enable - exception occured: [' . $e->getCode() .'] '. $e->getMessage());
        }
        return true; // we allow to enable plugin
    }


    public function onDisable()
    {
        $this->removeRegisterNotification();

        return true;
    }
    
    
    /**
     * RegisterUiHooks plugin hook
     */
    public function registerUiListeners()
    {
        if (!$this->isActive()) {
            return; //no listeners active       
        }
        
        $oViewListener = $this->getViewListener();
        
        OX_Admin_UI_Hooks::registerBeforePageHeaderListener(
            array($oViewListener,
                'beforePageHeader'
            ));
            
        OX_Admin_UI_Hooks::registerBeforePageContentListener(
            array($oViewListener,
                'beforeContent'
            ));
            
        OX_Admin_UI_Hooks::registerAfterPageContentListener(
            array($oViewListener,
                'afterContent'
            ));
    }
    
    
    /**
     * A permission hook. Used to prevent access to market entities.
     *
     * @param string $entityTable  Table name
     * @param int $entityId  Id (or empty if new is created)
     * @param int $operationAccessType Indicate the operation being accessed see OA_Permission HAS_ACCESS consts.
     * @param int $accountId  Account Id (if null account from session is taken)
     * @param string $accountType either OA_ACCOUNT_ADMIN, OA_ACCOUNT_MANAGER, OA_ACCOUNT_ADVERTISER, OA_ACCOUNT_TRAFFICKER 
     * @return boolean  True if has access
     */
    public function hasAccessToObject($entityTable, $entityId, 
                        $operationAccessType, $accountId, $accountType)
    {
            
        $hasAccess = $this->getEntityHelper()->hasAccessToObject($entityTable, $entityId, 
                        $operationAccessType, $accountId, $accountType);
            
        return $hasAccess;
    }
    
    
    public function afterPricingFormSection(&$form, $campaign, $newCampaign)
    {
        if (!$this->isActive()) {
            $this->oFormManager->buildCampaignFormPartForInactive($form, $campaign, $newCampaign);
            return;
        }

        $this->oFormManager->buildCampaignFormPart($form, $campaign, $newCampaign);
    }
    
    
    public function processCampaignForm(&$aFields)
    {
        if (!$this->isActive()) {
            return;
        }

        $this->oFormManager->processCampaignForm($aFields);
    }
    

    public function processAffiliateForm(&$aFields)
    {
        if (!$this->isActive()) {
            return;
        }

        $this->oFormManager->processWebsiteForm($aFields);
    }
    
    
    public function extendZoneForm($form, $zone, $newZone)
    {
        if (!$this->isActive()) {
            return;
        }

        $this->oFormManager->buildZoneFormPart($form, $zone, $newZone);        
    }
    
    
    public function extendZoneAdvancedForm($form, $zone)
    {
        if (!$this->isActive()) {
            return;
        }

        $this->oFormManager->buildZoneAdvancedFormPart($form, $zone);                
    }
    
    
    public function processZoneForm(&$aFields)
    {
        if (!$this->isActive()) {
            return;
        }

        $this->oFormManager->processZoneForm($aFields);
    }    
    
    
    public function getViewListener() 
    {
        if (empty($this->oEntityScreenManager)) {
            $this->oEntityScreenManager = new OX_oxMarket_UI_EntityScreenManager($this);
        }
            
        return $this->oEntityScreenManager;    
    }
    
    
    /**
     * Return DAL which allows manipulation of zone opt in status
     *
     * @return OX_oxMarket_Dal_ZoneOptIn
     */
    public function getZoneOptInManager()
    {
        if (empty($this->zoneOptInDal)) {
            $this->zoneOptInDal = new OX_oxMarket_Dal_ZoneOptIn();
        }
            
        return $this->zoneOptInDal;    
    }
    
    
    /**
     * Return DAL for market settings and preferences.
     *
     * @return OX_oxMarket_Dal_PreferenceVariable
     */
    public function getPreferenceManager()
    {
        return $this->preferenceDal;    
    }
        
    
    /**
     * Return website DAL
     *
     * @return OX_oxMarket_Dal_Website
     */
    public function getWebsiteManager()
    {
        if (empty($this->websiteDal)) {
            $this->websiteDal = new OX_oxMarket_Dal_Website($this);
        }
            
        return $this->websiteDal;    
    }
    

    /**
     * @return OX_oxMarket_UI_EntityHelper
     */
    public function getEntityHelper()
    {
        if (empty($this->entityHelper)) {
            $this->entityHelper = new OX_oxMarket_UI_EntityHelper($this);
        }
            
        return $this->entityHelper;        
    }
    

    function getAgencyDetails($agencyId = null)
    {
        if (is_null($agencyId)) {
           $agencyId = OA_Permission::getAgencyId();
        }
        $doAgency = & OA_Dal::factoryDO('agency');
        $doAgency->get($agencyId);
        $aResult = $doAgency->toArray();

        return $aResult;
    }


    function getMaxFloorPrice()
    {
        return 1000000; //hardcoded value for validation purposes, such high 
                   //CPM does not make sense anyway, but we'd like to avoid 
                   //number overflows here
    }


    /**
     * Check if plugin is registered (downloaded mode)
     * or manager account is registered (multiple accounts mode)
     *
     * @return bool
     */
    public function isRegistered()
    {
        return $this->oMarketPublisherClient->hasAssociationWithPc();
    }


    /**
     * Check if plugin is active (downloaded mode)
     * or manager account is active (multiple accounts mode)
     *
     * Account is active if is registered, has valid status and API key is set 
     * 
     * @return bool
     */
    public function isActive()
    {
        // Account is active if is registered, has valid status and API key is set
        $result = $this->isRegistered() &&
               ($this->oMarketPublisherClient->getAssociationWithPcStatus() ==
                    Plugins_admin_oxMarket_PublisherConsoleMarketPluginClient::LINK_IS_VALID_STATUS);

        if ($result && !($this->oMarketPublisherClient->hasApiKey() == true))
        {
            // If only API key is missing, try recive this key automatically
            try {
                $this->oMarketPublisherClient->getApiKeyByM2MCred();
            } catch( Exception $e)
            {
                OA::debug('openXMarket: Error during reciving automatically API key : ('
                          .$e->getCode().') '.$e->getMessage());
            }
        }
        // Check if apikey is set
        return $result && ($this->oMarketPublisherClient->hasApiKey() == true);
    }


    public function isSplashAlreadyShown()
    {
        return $this->preferenceDal->getMarketAccountPreference('splashAlreadyShown');
    }


    public function setSplashAlreadyShown()
    {
        $this->preferenceDal->setMarketAccountPreference('splashAlreadyShown', '1');
    }

    
    public function isMarketSettingsAlreadyShown()
    {
        $marketSettingsShown = $this->preferenceDal->
            getMarketUserVariable('campaign_settings_shown_to_user');
        
        return isset($marketSettingsShown) ? $marketSettingsShown : false; 
    }


    public function setMarketSettingsAlreadyShown()
    {
        $this->preferenceDal->setMarketUserVariable('campaign_settings_shown_to_user', '1');
    }
    
    
    public function isMarketInfoAdvertisersAlreadyShown()
    {
        $marketInfoShown = $this->preferenceDal->getMarketUserVariable('advertiser_index_market_info_shown_to_user');
        
        return isset($marketInfoShown) ? $marketInfoShown : false; 
    }


    public function setMarketInfoAdvertisersAlreadyShown()
    {
        $this->preferenceDal->setMarketVariable('advertiser_index_market_info_shown_to_user', '1');
    }


    public function setMarketUserVariable($name, $value)
    {
        $this->preferenceDal->setMarketUserVariable($name, $value);
    }
    
    
    public function getMarketUserVariable($name)
    {
        $value = $this->preferenceDal->getMarketUserVariable($name);
        
        return $value; 
    }
        
    
    function getInactiveStatus()
    {
        if ($this->isActive()) {
            return null;
        }

        $hasApiKey = $this->oMarketPublisherClient->hasApiKey();
        $message = "OpenX Market publisher account is not properly associated with your ad server";
        if (!$hasApiKey) {
            $status = null;
            $message .= "<br>API Key is missing, please try re-connect your Ad Server to OpenX Market using your OpenX.org account";
        } else {
            $status = $this->oMarketPublisherClient->getAssociationWithPcStatus();
        }

        return array('code' => $status, 'message' => $message);
    }


    function getConfigValue($configKey)
    {
        return $GLOBALS['_MAX']['CONF']['oxMarket'][$configKey];
    }


    function checkRegistered($desiredStatus = true)
    {
        if ($desiredStatus != $this->isRegistered()) {
            OX_Admin_Redirect::redirect('plugins/' . $this->group . '/market-index.php');
        }
    }


    function checkActive($desiredStatus = true)
    {
        if ($desiredStatus != $this->isActive()) {
            OX_Admin_Redirect::redirect('plugins/' . $this->group . '/market-index.php');
        }
    }
    
    
    /**
     * Uses permission class to enforce ADMIN or MANAGER account depending on plugin
     * mode. For multiple accounts mode it assumes manager, for standalone admin
     */
    function enforceProperAccountAccess()
    {
        if ($this->isMultipleAccountsMode()) {
            OA_Permission::enforceAccount(OA_ACCOUNT_MANAGER);
        }
        else {
            OA_Permission::enforceAccount(OA_ACCOUNT_ADMIN);
        }
    }
    


    function createMenuForPubconsolePage($sectionId)
    {
        if (!$this->isRegistered() || !$this->isActive()) {
            return null;
        }

        //contact pubconsole and get the menu items
        $aPubconsoleNav = array();
        try {
            $menuUrl = $this->buildPubconsoleApiUrl($this->getConfigValue('marketMenuUrl'));
            $oClient = $this->getHttpClient();
            $oClient->setUri($menuUrl);
            $pubAccountId = $this->oMarketPublisherClient->getPcAccountId();
            $aRequestParams =  array(
                $this->getConfigValue('marketAccountIdParamName') => $pubAccountId,
                'h' => $this->isMultipleAccountsMode()? "1" : "0"
            );
            if (!empty($sectionId)) {
                $aRequestParams["id"] = $sectionId; //no need to encode params here, client does that 
            }
            $oClient->setParameterGet($aRequestParams);

            $response = $oClient->request();
            if ($response->isSuccessful()) {
                $responseText = $response->getBody();
                $oJson = new Services_JSON();
                $aPubconsoleNav = $oJson->decode($responseText);
            }
        }
        catch(Exception $exc) {
            OA::debug('Error during retrieving menu items from pubconsole: ('.
                $exc->getCode().')'.$exc->getMessage());
        }

        //build local menu if not empty
        if (empty($aPubconsoleNav)) {
            return null;
        }

        $pageName = $aPubconsoleNav->pageName;
        $leftMenu = $aPubconsoleNav->leftMenu;
        if ($leftMenu && is_array($leftMenu)) {
            $page = 'plugins/' . $this->group . '/market-include.php';
            foreach ($leftMenu as $entry) {
                $id = $entry->id;
                $name = $entry->name;
                $url = $entry->url;
                $isActive = $entry->active;

                addLeftMenuSubItem($id, $name, "$page?p_url=$url");
                if ($isActive) {
                    setCurrentLeftMenuSubItem($id);
                }
            }
        }

        return $pageName; //might be null we do not care
    }


    //UI actions
    function indexAction()
    {
        $registered = $this->isRegistered();
        $active = $this->isActive();

        if ($registered) {
            if ($active) {
                OX_Admin_Redirect::redirect('plugins/' . $this->group . '/market-include.php');
            }
            else {
                OX_Admin_Redirect::redirect('plugins/' . $this->group . '/market-inactive.php');
            }
        }
        else {
            OX_Admin_Redirect::redirect('plugins/' . $this->group . '/market-info.php');
        }
    }
    

    /**
     * Returns Publisher Console API Client
     *
     * @return Plugins_admin_oxMarket_PublisherConsoleMarketPluginClient
     */
    public function getPublisherConsoleApiClient()
    {
        return $this->oMarketPublisherClient;
    }
    

    /**
     * Update or register all websites
     * Silent skip problems (will try again in maintenance)
     *
     * @param boolean $skip_synchronized In maintenace skip updating websites marked as url synchronized with marketplace
     *                                  other cases (e.g. reinstalling plugin and re-linking to market) should updates all websites
     * @param int $limitUpdatedWebsites Limit updated websites to this number, 0 - no limit
     */
    function updateAllWebsites($skip_synchronized = false, $limitUpdatedWebsites = 0)
    {
         $this->getWebsiteManager()->updateAllWebsites($skip_synchronized, $limitUpdatedWebsites);
    }


    protected function scheduleRegisterNotification()
    {
        $oNotificationManager = OA_Admin_UI::getInstance()->getNotificationManager();
        $oNotificationManager->removeNotifications('oxMarketRegister'); //avoid duplicates

        $url = MAX::constructURL(MAX_URL_ADMIN, 'plugins/' . $this->group . '/market-index.php');

        $aContentKeys = $this->retrieveCustomContent('market-messages');

        $registerMessage = isset($aContentKeys['register-messsage'])
            ? vsprintf($aContentKeys['register-messsage'], array($url))
            : 'Earn more revenue by activating OpenX Market for your ad server.<br>
                <a href="'.$url.'">Get started now &raquo;</a>';

        $oNotificationManager->queueNotification($registerMessage, 'info', 'oxMarketRegister');
    }


    public function updateSSLMessage()
    {
        if (!OX_oxMarket_Common_ConnectionUtils::isSSLAvailable()) {
            $this->scheduleNoSSLWarning();
        }
    }


    protected function scheduleNoSSLWarning()
    {
        $aContentKeys = $this->retrieveCustomContent('market-messages');

        $noSSLMessage = isset($aContentKeys['no-ssl-messsage'])
            ? $aContentKeys['no-ssl-messsage']
            : 'In order to secure your data, we recommend installing a PHP
                extension which supports secure connections (eg. cURL or OpenSSL).
                See <a href="http://www.openx.org/faq/market">OpenX Market FAQ</a>
                for more details.';

        OA_Admin_UI::queueMessage($noSSLMessage, 'local', 'warning', 0);
    }


    public function removeRegisterNotification()
    {
        //clean up the bugging info message
        OA_Admin_UI::getInstance()->getNotificationManager()
            ->removeNotifications('oxMarketRegister');
    }


    public function retrieveCustomContent($pageName)
    {
        require_once MAX_PATH.'/lib/Zend/Http/Client.php';

        $result = false;
        try {
            //connect to pubconsole API and get the custom content for that page
            $customContentUrl = $this->buildPubconsoleApiUrl($this->getConfigValue('marketCustomContentUrl'));
            $oClient = $this->getHttpClient();
            $oClient->setUri($customContentUrl);
            $oClient->setParameterGet(array(
                'pageName'  => urlencode($pageName),
                'adminWebUrl' => urlencode(MAX::constructURL(MAX_URL_ADMIN, '')),
                'pcWebUrl' => urlencode($this->getConfigValue('marketHost')),
                'v' => $this->getPluginVersion(),
                'h' => $this->isMultipleAccountsMode()? "1" : "0"            
            ));

            $response = $oClient->request();
            if ($response->isSuccessful()) {
                $responseText = $response->getBody();
                $result = $this->parseCustomContent($responseText);
            }
        }
        catch(Exception $exc) {
            OA::debug('Error during retrieving custom content: ('.$exc->getCode().')'.$exc->getMessage());
        }

        return $result;
    }


    /**
     * Returns an array with key to value for custom content
     *
     * @param string $responseText
     * @return an array key=>value when parsing ok, false otherwise
     */
    protected function parseCustomContent($responseText)
    {
        require_once dirname(__FILE__) . '/XMLToArray.php';

        $xml2a = new XMLToArray();
        $aRoot = $xml2a->parse($responseText);
        $aContentStrings = array_shift($aRoot["_ELEMENTS"]);

        if ($aContentStrings['_NAME'] != 'contentStrings') {
            return false;
        }

        $aKeys = array();
        foreach ($aContentStrings["_ELEMENTS"] as $aContentString) {
             $aKeys[$aContentString['_NAME']] = $aContentString['_DATA'];
        }

        return $aKeys;
    }

    
    public function getPluginVersion()
    {
        if (!isset($this->pluginVersion)) {
            $oPluginManager = new OX_PluginManager();
            $aInfo =  $oPluginManager->getPackageInfo('openXMarket', false);    
            $this->pluginVersion = strtolower($aInfo['version']);
        }
        return $this->pluginVersion;        
    }    
    
    
    /**
     * Builds an url to pubconsole either SSL or HTTP fallback, apends suffix if given
     *
     * @param string $suffix - with no leading slash
     * @return string pubconsole url with suffix if given
     */
    function buildPubconsoleApiUrl($suffix = null)
    {
        if (OX_oxMarket_Common_ConnectionUtils::isSSLAvailable()) {
            $pubconsoleLink = $this->getConfigValue('marketPcApiHost');
        }
        else {
            $pubconsoleLink = $this->getConfigValue('fallbackPcApiHost');
        }
        if (!empty($suffix)) {
            $pubconsoleLink = $pubconsoleLink .'/'. $suffix;
        }

        return $pubconsoleLink;
    }


    /**
     * Creates a client with proper attributes and settinngs (like cUrl handling etc)
     * already configured.
     * By default timeout is 30 secs and 5 redirects are allowed.
     *
     */
    function getHttpClient()
    {
        $aConf = $GLOBALS['_MAX']['CONF'];
        $curlAllowAnyCertificate = false;
        if (array_key_exists('curlAllowAnyCertificate',$aConf['oxMarket'])) {
            $curlAllowAnyCertificate = $aConf['oxMarket']['curlAllowAnyCertificate'];
        }
        $oClient = OX_oxMarket_Common_ConnectionUtils::factoryGetZendHttpClient($curlAllowAnyCertificate);
        $oClient->setConfig(array(
            'maxredirects' => 5,
            'timeout'      => 30));


        return $oClient;
    }


    /**
     * Synchronize status with market and return new status
     *
     * @return int|bool status code, or false if client isn't registered
     */
    function updateAccountStatus()
    {
        if ($this->isRegistered()) {
            return $this->oMarketPublisherClient->updateAccountStatus();
        } else {
            return false;
        }
    }
    
    
    function isMultipleAccountsMode()
    {
        return (bool) $this->getConfigValue('multipleAccountsMode');
    }
    
    
    /**
     * Set working Account Id in multiple accounts mode,
     * if it's not given or is null, client will be using
     * OA_Permission::getCurrentUser()
     *
     * @param int $accountId optional
     */
    function setWorkAsAccountId($accountId = null)
    {
        $this->oMarketPublisherClient->setWorkAsAccountId($accountId);
    }
    
    
    /**
     * Automatically link manager accounts to Publisher Console for user
     * Link only that accounts where user is first linked manager.
     *
     * @param int $user_id optional if not set current logged user is used
     * @param int $account_id optional if set only given account is linked
     * @return boolean true if linking method was succesfull, false if any checks fails
     */
    function linkHostedAccounts($user_id = null, $account_id = null)
    {
        // works only in multiple accounts mode
        if (!$this->isMultipleAccountsMode()) {
            return false;
        }
        
        // get current user if not set
        if (!isset($user_id)) {
            $user_id = OA_Permission::getUserId();
        }

        // stop if user isn't set or user is admin
        if (!isset($user_id) || OA_Permission::isUserLinkedToAdmin($user_id)) {
            return false;
        }
        
        // stop if sso_id is not set
        $doUsers = OA_Dal::staticGetDO('users', $user_id);
        if (!$doUsers || empty($doUsers->sso_user_id)) {
            return false;
        }
        $ssoId = $doUsers->sso_user_id;
        
        // Select such manager accounts where user is first linked manager
        $doAUA2 = OA_Dal::factoryDO('account_user_assoc');
        $doAUA2->user_id = $user_id;
        $doAccounts = OA_Dal::factoryDO('accounts');
        $doAccounts->account_type = OA_ACCOUNT_MANAGER;
        $doAccounts->joinAdd($doAUA2, 'LEFT', 'aua2');
        if (isset($account_id)) {
            $doAccounts->account_id = $account_id;
        }
        $doAUA1 = OA_Dal::factoryDO('account_user_assoc');
        $doAUA1->joinAdd($doAccounts);
        $doAUA1->whereAdd($doAUA1->tableName().'.linked <= aua2.linked');
        $doAUA1->groupBy($doAUA1->tableName().'.account_id');
        $doAUA1->having('count(*)=1');
        $doAUA1->selectAdd();
        $doAUA1->selectAdd($doAUA1->tableName().'.account_id');
        $doAUA1->find();
        
        $aAccountsIds = array();
        while($doAUA1->fetch()) {
            $aAccountsIds[$doAUA1->account_id] = $doAUA1->account_id;
        }
        
        // nothing to change
        if (empty($aAccountsIds)) {
            return false;
        }
        
        // select already associated accounts
        $doMarketAssoc = OA_DAL::factoryDO('ext_market_assoc_data');
        $doMarketAssoc->whereAdd('account_id IN ('.implode(",", $aAccountsIds).')');
        $doMarketAssoc->find();
        while($doMarketAssoc->fetch()) {
            unset($aAccountsIds[$doMarketAssoc->account_id]);
        }

        // nothing to change
        if (empty($aAccountsIds)) {
            return false;
        }
        
        $result = true;
        foreach ($aAccountsIds as $accountId) {
            try {
                $this->oMarketPublisherClient->linkHostedAccount((int)$ssoId, (int)$accountId, false);
            } catch (Exception $exc) {
                OA::debug('Error during auto register Market in multiple accounts mode: ('.$exc->getCode().')'.$exc->getMessage());
                $result = false;
            }
        }
        
        return $result;
    }

    
    public function getCookiePath()
    {
        require_once MAX_PATH .'/lib/Max.php';
        return parse_url(MAX::constructUrl(MAX_URL_ADMIN), PHP_URL_PATH);
    }
    
    
    /**
     * Is registration required during upgrading/install process?
     *
     * @return bool
     */
    public function isRegistrationRequired()
    {
        require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/Installer.php';
        return OX_oxMarket_Dal_Installer::isRegistrationRequired();
    }
    
    
    /**
     * Hook afterAgencyCreate
     *
     * @param int $agencyid
     */
    public function afterAgencyCreate($agencyid)
    {
        // Create market advertisers if this is not multiple accounts mode
        // and market plugin is already registered
        if (!$this->isMultipleAccountsMode() && $this->isRegistered()) {
            require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/Advertiser.php';
            $oAdvertiserDal = new OX_oxMarket_Dal_Advertiser();
            $oAdvertiserDal->createMarketAdvertiser($agencyid);
        }
    }
}

?>