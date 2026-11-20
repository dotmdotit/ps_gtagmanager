<?php
/**
 * Google Tag Manager e GA4 per PrestaShop: contenitore GTM, Consent Mode v2, eventi e-commerce GA4,
 * conversioni avanzate, invio dal server di acquisti persi e rimborsi (Measurement Protocol),
 * contenitore GTM pronto da importare.
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/DotmGtmOrderLog.php';
require_once __DIR__ . '/classes/DotmGtmItemBuilder.php';
require_once __DIR__ . '/classes/DotmGtmDataLayer.php';
require_once __DIR__ . '/classes/DotmGtmServer.php';
require_once __DIR__ . '/classes/DotmGtmContainer.php';

class Dotmgtagmanager extends Module
{
    // contenitore
    const CFG_GTM_ID = 'DOTMGTAGMANAGER_GTM_ID';
    const CFG_LOAD_SCRIPT = 'DOTMGTAGMANAGER_LOAD_SCRIPT';
    const CFG_SCRIPT_URL = 'DOTMGTAGMANAGER_SCRIPT_URL';
    // consenso
    const CFG_CONSENT = 'DOTMGTAGMANAGER_CONSENT';
    const CFG_CONSENT_REGION = 'DOTMGTAGMANAGER_CONSENT_REGION';
    const CFG_CONSENT_ANALYTICS = 'DOTMGTAGMANAGER_CONSENT_ANALYTICS';
    const CFG_CONSENT_ADS = 'DOTMGTAGMANAGER_CONSENT_ADS';
    const CFG_CONSENT_WAIT = 'DOTMGTAGMANAGER_CONSENT_WAIT';
    const CFG_ADS_REDACTION = 'DOTMGTAGMANAGER_ADS_REDACTION';
    const CFG_URL_PASSTHROUGH = 'DOTMGTAGMANAGER_URL_PASSTHROUGH';
    // dati
    const CFG_ID_FORMAT = 'DOTMGTAGMANAGER_ID_FORMAT';
    const CFG_ID_SEPARATOR = 'DOTMGTAGMANAGER_ID_SEPARATOR';
    const CFG_ID_PREFIX = 'DOTMGTAGMANAGER_ID_PREFIX';
    const CFG_PRICE_TAX = 'DOTMGTAGMANAGER_PRICE_TAX';
    const CFG_VALUE_MODE = 'DOTMGTAGMANAGER_VALUE_MODE';
    const CFG_MAX_LIST_ITEMS = 'DOTMGTAGMANAGER_MAX_LIST_ITEMS';
    const CFG_USER_ID = 'DOTMGTAGMANAGER_USER_ID';
    const CFG_USER_DATA = 'DOTMGTAGMANAGER_USER_DATA';
    // server
    const CFG_MEASUREMENT_ID = 'DOTMGTAGMANAGER_MEASUREMENT_ID';
    const CFG_API_SECRET = 'DOTMGTAGMANAGER_API_SECRET';
    const CFG_SERVER_EU = 'DOTMGTAGMANAGER_SERVER_EU';
    const CFG_SERVER_DELAY = 'DOTMGTAGMANAGER_SERVER_DELAY';
    const CFG_SERVER_REQUIRE_CID = 'DOTMGTAGMANAGER_SERVER_REQUIRE_CID';
    const CFG_REFUND_STATES = 'DOTMGTAGMANAGER_REFUND_STATES';
    const CFG_EXCLUDE_PAYMENTS = 'DOTMGTAGMANAGER_EXCLUDE_PAYMENTS';
    const CFG_CRON_TOKEN = 'DOTMGTAGMANAGER_CRON_TOKEN';
    const CFG_VISIT_TRIGGER = 'DOTMGTAGMANAGER_VISIT_TRIGGER';
    const CFG_LAST_RUN = 'DOTMGTAGMANAGER_LAST_RUN';
    // Google Ads (solo per il contenitore da importare)
    const CFG_ADS_CONVERSION_ID = 'DOTMGTAGMANAGER_ADS_CONVERSION_ID';
    const CFG_ADS_CONVERSION_LABEL = 'DOTMGTAGMANAGER_ADS_CONVERSION_LABEL';
    // debug
    const CFG_DEBUG = 'DOTMGTAGMANAGER_DEBUG';
    const CFG_DEBUG_IPS = 'DOTMGTAGMANAGER_DEBUG_IPS';

    /** eventi in coda (login, registrazione, lista desideri) per la pagina successiva */
    const COOKIE_QUEUE = 'dotmgtm_queue';

    /** @var array<string, array> */
    protected $strings = [];

    public function __construct()
    {
        $this->name = 'dotmgtagmanager';
        $this->tab = 'analytics_stats';
        $this->version = '1.0.0';
        $this->author = 'dotm.it';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = 'Google Tag Manager e GA4';
        $this->description = 'Contenitore Google Tag Manager con Consent Mode v2, eventi e-commerce GA4, conversioni avanzate, invio dal server di acquisti persi e rimborsi, contenitore GTM pronto da importare.';
        $this->confirmUninstall = 'Disinstallando il modulo verranno cancellati impostazioni e registro degli acquisti inviati a GA4. Continuare?';
    }

    /* ------------------------------------------------------------------
     * Installazione
     * ------------------------------------------------------------------ */

    protected function getDefaults()
    {
        return [
            self::CFG_GTM_ID => '',
            self::CFG_LOAD_SCRIPT => 1,
            self::CFG_SCRIPT_URL => '',
            self::CFG_CONSENT => 1,
            self::CFG_CONSENT_REGION => 'eea',
            self::CFG_CONSENT_ANALYTICS => 0,
            self::CFG_CONSENT_ADS => 0,
            self::CFG_CONSENT_WAIT => 500,
            self::CFG_ADS_REDACTION => 1,
            self::CFG_URL_PASSTHROUGH => 0,
            self::CFG_ID_FORMAT => 'id',
            self::CFG_ID_SEPARATOR => '-',
            self::CFG_ID_PREFIX => '',
            self::CFG_PRICE_TAX => 1,
            self::CFG_VALUE_MODE => 'products',
            self::CFG_MAX_LIST_ITEMS => 30,
            self::CFG_USER_ID => 1,
            self::CFG_USER_DATA => 'hashed',
            self::CFG_MEASUREMENT_ID => '',
            self::CFG_API_SECRET => '',
            self::CFG_SERVER_EU => 0,
            self::CFG_SERVER_DELAY => 30,
            self::CFG_SERVER_REQUIRE_CID => 1,
            self::CFG_REFUND_STATES => implode(',', array_filter([
                (int) Configuration::get('PS_OS_REFUND'),
                (int) Configuration::get('PS_OS_CANCELED'),
            ])),
            self::CFG_EXCLUDE_PAYMENTS => '',
            self::CFG_VISIT_TRIGGER => 1,
            self::CFG_ADS_CONVERSION_ID => '',
            self::CFG_ADS_CONVERSION_LABEL => '',
            self::CFG_DEBUG => 0,
            self::CFG_DEBUG_IPS => '',
        ];
    }

    public function install()
    {
        if (!parent::install() || !$this->installDb() || !$this->installTab()) {
            return false;
        }
        foreach ($this->getDefaults() as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        Configuration::updateGlobalValue(self::CFG_CRON_TOKEN, Tools::passwdGen(24, 'ALPHANUMERIC'));

        return $this->registerHook([
            'displayHeader',
            'displayAfterBodyOpeningTag',
            'displayBeforeBodyClosingTag',
            'actionFrontControllerSetMedia',
            'actionValidateOrder',
            'actionOrderStatusPostUpdate',
            'actionOrderSlipAdd',
            'actionAuthentication',
            'actionCustomerAccountAdd',
            'actionWishlistAddProduct',
        ]);
    }

    public function uninstall()
    {
        foreach (array_merge(array_keys($this->getDefaults()), [self::CFG_CRON_TOKEN, self::CFG_LAST_RUN]) as $key) {
            Configuration::deleteByName($key);
        }
        foreach (['order', 'cart'] as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'dotmgtagmanager_' . $table . '`');
        }

        return $this->uninstallTab() && parent::uninstall();
    }

    protected function installDb()
    {
        $engine = _MYSQL_ENGINE_;
        $p = _DB_PREFIX_ . 'dotmgtagmanager_';

        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . $p . 'order` (
                `id_dotmgtagmanager_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_cart` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `reference` VARCHAR(64) NOT NULL DEFAULT \'\',
                `client_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                `session_id` VARCHAR(32) NOT NULL DEFAULT \'\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `sent_at` DATETIME NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `refunded_amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `refund_full` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `refund_slips` VARCHAR(255) NOT NULL DEFAULT \'\',
                `last_error` VARCHAR(255) NOT NULL DEFAULT \'\',
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_dotmgtagmanager_order`),
                UNIQUE KEY `id_cart` (`id_cart`),
                KEY `reference` (`reference`),
                KEY `status` (`status`, `date_add`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        ) && Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . $p . 'cart` (
                `id_cart` INT UNSIGNED NOT NULL,
                `client_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                `session_id` VARCHAR(32) NOT NULL DEFAULT \'\',
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cart`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    protected function installTab()
    {
        if (Tab::getIdFromClassName('AdminDotmGtmOrder')) {
            return true;
        }
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminDotmGtmOrder';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = $lang['iso_code'] === 'it' ? 'Tracciamento GA4' : 'GA4 tracking';
        }

        return (bool) $tab->add();
    }

    protected function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminDotmGtmOrder');

        return !$idTab || (new Tab($idTab))->delete();
    }

    /* ------------------------------------------------------------------
     * Testi (lang/<iso>.php; per le lingue senza file: inglese, poi italiano)
     * ------------------------------------------------------------------ */

    public function getStrings($idLang = null)
    {
        $iso = $idLang ? Language::getIsoById((int) $idLang) : $this->context->language->iso_code;
        $iso = preg_replace('/[^a-z]/', '', strtolower((string) $iso));
        if (!isset($this->strings[$iso])) {
            $strings = include __DIR__ . '/lang/it.php';
            if ($iso !== 'it') {
                $file = __DIR__ . '/lang/' . $iso . '.php';
                foreach (array_unique([__DIR__ . '/lang/en.php', is_file($file) ? $file : __DIR__ . '/lang/en.php']) as $path) {
                    $local = include $path;
                    $strings = array_merge($strings, is_array($local) ? $local : []);
                }
            }
            $this->strings[$iso] = $strings;
        }

        return $this->strings[$iso];
    }

    public function t($key, array $params = [], $idLang = null)
    {
        $strings = $this->getStrings($idLang);
        $text = isset($strings[$key]) ? $strings[$key] : $key;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', $v, $text);
        }

        return $text;
    }

    /* ------------------------------------------------------------------
     * Utilità
     * ------------------------------------------------------------------ */

    public function getGtmId()
    {
        $id = strtoupper(trim((string) Configuration::get(self::CFG_GTM_ID)));

        return preg_match('/^GTM-[A-Z0-9]+$/', $id) ? $id : '';
    }

    /**
     * Pannello di debug sul sito: attivo in configurazione e (se indicati) solo per gli IP autorizzati.
     */
    public function isDebug()
    {
        if (!Configuration::get(self::CFG_DEBUG)) {
            return false;
        }
        $ips = array_filter(array_map('trim', explode(',', (string) Configuration::get(self::CFG_DEBUG_IPS))));

        return !$ips || in_array((string) Tools::getRemoteAddr(), $ips, true);
    }

    public function getExcludedPayments()
    {
        return array_filter(array_map('trim', explode(',', (string) Configuration::get(self::CFG_EXCLUDE_PAYMENTS))));
    }

    public function isPaymentExcluded($moduleName)
    {
        return in_array((string) $moduleName, $this->getExcludedPayments(), true);
    }

    public function getRefundStates()
    {
        return array_filter(array_map('intval', explode(',', (string) Configuration::get(self::CFG_REFUND_STATES))));
    }

    /**
     * Permette ad altri moduli di modificare i dati inviati: hook actionDotmgtagmanagerDataLayer
     * con parametri name (nome evento o "page") e data (per riferimento).
     *
     * @return array|null null per non inviare l'evento
     */
    public function alterData($name, array $data)
    {
        Hook::exec('actionDotmgtagmanagerDataLayer', ['name' => $name, 'data' => &$data], null, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Registro dell'acquisto per il carrello dell'ordine (creato se manca).
     */
    public function getOrCreateLog(Order $order)
    {
        $log = DotmGtmOrderLog::getByCart((int) $order->id_cart);
        if ($log) {
            if ($log->client_id === '') {
                list($clientId, $sessionId) = DotmGtmServer::getCartIds((int) $order->id_cart);
                if ($clientId !== '') {
                    $log->client_id = $clientId;
                    $log->session_id = $sessionId;
                    $log->update();
                }
            }

            return $log;
        }
        list($clientId, $sessionId) = DotmGtmServer::getCartIds((int) $order->id_cart);
        $log = new DotmGtmOrderLog();
        $log->id_shop = (int) $order->id_shop;
        $log->id_cart = (int) $order->id_cart;
        $log->id_order = (int) $order->id;
        $log->reference = (string) $order->reference;
        $log->client_id = $clientId;
        $log->session_id = $sessionId;
        $log->status = DotmGtmOrderLog::STATUS_PENDING;
        $log->sent_at = null;
        try {
            $log->add();
        } catch (Throwable $e) {
            // creato nel frattempo da un'altra richiesta (ordini divisi)
            $existing = DotmGtmOrderLog::getByCart((int) $order->id_cart);
            if ($existing) {
                return $existing;
            }
        }

        return $log;
    }

    /* ------------------------------------------------------------------
     * Coda di eventi per la pagina successiva (cookie del negozio)
     * ------------------------------------------------------------------ */

    public function queueEvent(array $event)
    {
        $cookie = $this->context->cookie;
        if (!$cookie) {
            return;
        }
        $key = self::COOKIE_QUEUE;
        $queue = json_decode((string) $cookie->$key, true);
        $queue = is_array($queue) ? $queue : [];
        $queue[] = $event;
        $cookie->$key = json_encode(array_slice($queue, -5));
    }

    public function takeQueuedEvents()
    {
        $cookie = $this->context->cookie;
        $key = self::COOKIE_QUEUE;
        if (!$cookie || empty($cookie->$key)) {
            return [];
        }
        $queue = json_decode((string) $cookie->$key, true);
        unset($cookie->$key);

        return is_array($queue) ? $queue : [];
    }

    /* ------------------------------------------------------------------
     * Hook: pagine del negozio
     * ------------------------------------------------------------------ */

    protected function isActiveOnFront()
    {
        return $this->getGtmId() !== '' && !Tools::getValue('ajax');
    }

    public function hookDisplayHeader()
    {
        if (!$this->isActiveOnFront()) {
            return '';
        }
        try {
            return (new DotmGtmDataLayer($this, $this->context))->getHeadHtml();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('dotmgtagmanager: ' . $e->getMessage(), 2);

            return '';
        }
    }

    public function hookDisplayAfterBodyOpeningTag()
    {
        if (!$this->isActiveOnFront()) {
            return '';
        }

        return (new DotmGtmDataLayer($this, $this->context))->getNoscriptHtml();
    }

    public function hookDisplayBeforeBodyClosingTag()
    {
        if (!$this->isActiveOnFront()) {
            return '';
        }
        try {
            return (new DotmGtmDataLayer($this, $this->context))->getBottomHtml();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('dotmgtagmanager: ' . $e->getMessage(), 2);

            return '';
        }
    }

    public function hookActionFrontControllerSetMedia()
    {
        if ($this->getGtmId() === '') {
            return;
        }
        $this->context->controller->registerJavascript(
            'dotmgtagmanager',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 300]
        );

        // invio dal server con le visite al sito (se il cron non è configurato): al massimo ogni 10 minuti
        if (Configuration::get(self::CFG_VISIT_TRIGGER) && DotmGtmServer::isConfigured()) {
            $last = (string) Configuration::getGlobalValue(self::CFG_LAST_RUN);
            if (!$last || strtotime($last) < time() - 600) {
                Configuration::updateGlobalValue(self::CFG_LAST_RUN, date('Y-m-d H:i:s'));
                try {
                    (new DotmGtmServer($this))->runPending();
                } catch (Throwable $e) {
                    PrestaShopLogger::addLog('dotmgtagmanager: invio dal server - ' . $e->getMessage(), 2);
                }
            }
        }
    }

    public function hookActionAuthentication($params)
    {
        $this->queueEvent(['event' => 'login', 'method' => 'email']);
    }

    public function hookActionCustomerAccountAdd($params)
    {
        $this->queueEvent(['event' => 'sign_up', 'method' => 'email']);
    }

    public function hookActionWishlistAddProduct($params)
    {
        try {
            $this->queueWishlist($params);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('dotmgtagmanager: ' . $e->getMessage(), 2);
        }
    }

    protected function queueWishlist($params)
    {
        $idProduct = isset($params['idProduct']) ? (int) $params['idProduct'] : 0;
        if (!$idProduct) {
            return;
        }
        $builder = new DotmGtmItemBuilder($this, (int) $this->context->language->id, (int) $this->context->shop->id);
        $item = $builder->item($idProduct, isset($params['idProductAttribute']) ? (int) $params['idProductAttribute'] : 0);
        if ($item) {
            $this->queueEvent([
                'event' => 'add_to_wishlist',
                'ecommerce' => ['currency' => (string) $this->context->currency->iso_code, 'value' => $item['price'], 'items' => [$item]],
            ]);
        }
    }

    /* ------------------------------------------------------------------
     * Hook: ordini
     * ------------------------------------------------------------------ */

    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }
        try {
            $log = $this->getOrCreateLog($params['order']);
            if ($log->status === DotmGtmOrderLog::STATUS_PENDING && $this->isPaymentExcluded($params['order']->module)) {
                $log->status = DotmGtmOrderLog::STATUS_EXCLUDED;
                $log->last_error = 'Metodo di pagamento escluso: ' . $params['order']->module;
                $log->update();
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('dotmgtagmanager: ' . $e->getMessage(), 2, null, 'Order', (int) $params['order']->id);
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $state = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if (!$idOrder || !Validate::isLoadedObject($state) || !in_array((int) $state->id, $this->getRefundStates(), true)) {
            return;
        }
        $order = new Order($idOrder);
        if (Validate::isLoadedObject($order)) {
            try {
                (new DotmGtmServer($this))->refundFull($order);
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('dotmgtagmanager: rimborso - ' . $e->getMessage(), 2, null, 'Order', $idOrder);
            }
        }
    }

    public function hookActionOrderSlipAdd($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $idSlip = (int) Db::getInstance()->getValue(
            'SELECT MAX(`id_order_slip`) FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` = ' . (int) $order->id
        );
        if ($idSlip) {
            try {
                (new DotmGtmServer($this))->refundSlip($order, $idSlip);
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('dotmgtagmanager: rimborso parziale - ' . $e->getMessage(), 2, null, 'Order', (int) $order->id);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Configurazione
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        if (Tools::getValue('dgtmExport')) {
            $this->downloadContainer();
        }
        $output = '';
        if (Tools::isSubmit('submitDotmgtagmanager')) {
            $output .= $this->saveConfig();
        } elseif (Tools::isSubmit('dgtmTest')) {
            $result = (new DotmGtmServer($this))->test();
            $output .= $result['ok']
                ? $this->displayConfirmation('Credenziali GA4 valide: Google ha accettato l\'evento di prova (endpoint di validazione, nulla viene registrato).')
                : $this->displayError('Verifica non riuscita: ' . htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8'));
        } elseif (Tools::isSubmit('dgtmRun')) {
            $r = (new DotmGtmServer($this))->runPending();
            $output .= $this->displayConfirmation('Invio dal server eseguito: ' . $r['sent'] . ' inviati, ' . $r['skipped'] . ' saltati, ' . $r['failed'] . ' con errori.');
        } elseif (Tools::isSubmit('dgtmNewToken')) {
            Configuration::updateGlobalValue(self::CFG_CRON_TOKEN, Tools::passwdGen(24, 'ALPHANUMERIC'));
            $output .= $this->displayConfirmation('Nuovo token generato: aggiorna il comando del cron.');
        }

        return $output . $this->renderInfo() . $this->renderForm();
    }

    protected function renderInfo()
    {
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $link = $this->context->link;
        $self = $link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $cron = $link->getModuleLink($this->name, 'cron', ['token' => Configuration::getGlobalValue(self::CFG_CRON_TOKEN)], true);
        $last = (string) Configuration::getGlobalValue(self::CFG_LAST_RUN);
        $gtm = $this->getGtmId();
        $server = DotmGtmServer::isConfigured();

        $html = '<div class="panel"><h3><i class="icon-bar-chart"></i> Google Tag Manager e GA4</h3>';
        $html .= '<p><strong>Contenitore:</strong> ' . ($gtm !== '' ? '<code>' . $e($gtm) . '</code> attivo' : '<span class="text-danger">inserisci l\'ID del contenitore GTM qui sotto</span>') . '. '
            . '<strong>Invio dal server:</strong> ' . ($server ? 'configurato' : '<span class="text-warning">non configurato (servono ID di misurazione e API secret)</span>') . '.</p>';
        $html .= '<p><strong>Contenitore pronto da importare:</strong> contiene Google tag GA4, l\'evento GA4 per tutti gli eventi e-commerce'
            . ' e, se indicati, linker e conversione Google Ads. In GTM: <em>Amministrazione → Importa contenitore</em>, scegli «Unisci».</p>'
            . '<p><a class="btn btn-default" href="' . $e($self . '&dgtmExport=1') . '"><i class="icon-download"></i> Scarica il contenitore GTM (JSON)</a> '
            . '<a class="btn btn-default" href="' . $e($link->getAdminLink('AdminDotmGtmOrder')) . '"><i class="icon-list"></i> Ordini → Tracciamento GA4</a></p>';
        $html .= '<hr><p><strong>Invio dal server</strong> (acquisti non confermati dal browser dopo il ritardo configurato). Cron consigliato, ogni 15 minuti:</p>'
            . '<pre>*/15 * * * * curl -s "' . $e($cron) . '" &gt;/dev/null</pre>'
            . '<p class="help-block">Ultima esecuzione: ' . ($last ? $e(Tools::displayDate($last, true)) : 'mai') . '.</p>'
            . '<form method="post" action="' . $e($self) . '" style="display:inline-block;margin-right:8px;"><button type="submit" name="dgtmTest" class="btn btn-default"' . ($server ? '' : ' disabled') . '><i class="icon-check"></i> Verifica credenziali GA4</button></form>'
            . '<form method="post" action="' . $e($self) . '" style="display:inline-block;margin-right:8px;"><button type="submit" name="dgtmRun" class="btn btn-default"' . ($server ? '' : ' disabled') . '><i class="icon-cloud-upload"></i> Esegui ora l\'invio</button></form>'
            . '<form method="post" action="' . $e($self) . '" style="display:inline-block;"><button type="submit" name="dgtmNewToken" class="btn btn-default" onclick="return confirm(\'Il comando del cron attuale smetterà di funzionare. Continuare?\');"><i class="icon-refresh"></i> Nuovo token</button></form>';

        return $html . '</div>';
    }

    protected function downloadContainer()
    {
        $json = (new DotmGtmContainer($this))->build();
        $name = 'dotmgtagmanager-' . ($this->getGtmId() ?: 'container') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    protected function saveConfig()
    {
        $errors = [];
        $gtm = strtoupper(trim((string) Tools::getValue(self::CFG_GTM_ID)));
        if ($gtm !== '' && !preg_match('/^GTM-[A-Z0-9]+$/', $gtm)) {
            $errors[] = 'ID contenitore non valido (formato GTM-XXXXXXX).';
        }
        $scriptUrl = trim((string) Tools::getValue(self::CFG_SCRIPT_URL));
        if ($scriptUrl !== '' && !preg_match('#^https://[^\s"\'<>]+/gtm\.js$#', $scriptUrl)) {
            $errors[] = 'URL dello script non valido: deve iniziare con https:// e terminare con /gtm.js.';
        }
        $mid = strtoupper(trim((string) Tools::getValue(self::CFG_MEASUREMENT_ID)));
        if ($mid !== '' && !preg_match('/^G-[A-Z0-9]+$/', $mid)) {
            $errors[] = 'ID di misurazione GA4 non valido (formato G-XXXXXXXXXX).';
        }
        $format = (string) Tools::getValue(self::CFG_ID_FORMAT);
        if (!in_array($format, ['id', 'id_ipa', 'reference', 'ean13', 'upc'], true)) {
            $format = 'id';
        }
        $separator = (string) Tools::getValue(self::CFG_ID_SEPARATOR);
        if (!preg_match('/^[-_.:|]{0,3}$/', $separator)) {
            $errors[] = 'Separatore non valido: usa - _ . : oppure |.';
        }
        $prefix = trim((string) Tools::getValue(self::CFG_ID_PREFIX));
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_\-.]{1,20}$/', $prefix)) {
            $errors[] = 'Prefisso ID non valido.';
        }
        $adsId = strtoupper(trim((string) Tools::getValue(self::CFG_ADS_CONVERSION_ID)));
        if ($adsId !== '' && !preg_match('/^(AW-)?\d+$/', $adsId)) {
            $errors[] = 'ID di conversione Google Ads non valido (formato AW-123456789).';
        }
        $ips = array_filter(array_map('trim', explode(',', (string) Tools::getValue(self::CFG_DEBUG_IPS))));
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors[] = 'Indirizzo IP non valido: ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
            }
        }
        if ($errors) {
            return $this->displayError(implode('<br>', $errors));
        }

        $bool = function ($key) {
            Configuration::updateValue($key, (int) (bool) Tools::getValue($key));
        };
        Configuration::updateValue(self::CFG_GTM_ID, $gtm);
        Configuration::updateValue(self::CFG_SCRIPT_URL, $scriptUrl);
        foreach ([self::CFG_LOAD_SCRIPT, self::CFG_CONSENT, self::CFG_CONSENT_ANALYTICS, self::CFG_CONSENT_ADS, self::CFG_ADS_REDACTION,
            self::CFG_URL_PASSTHROUGH, self::CFG_PRICE_TAX, self::CFG_USER_ID, self::CFG_SERVER_EU, self::CFG_SERVER_REQUIRE_CID,
            self::CFG_VISIT_TRIGGER, self::CFG_DEBUG, ] as $key) {
            $bool($key);
        }
        Configuration::updateValue(self::CFG_CONSENT_REGION, Tools::getValue(self::CFG_CONSENT_REGION) === 'all' ? 'all' : 'eea');
        Configuration::updateValue(self::CFG_CONSENT_WAIT, max(0, min(5000, (int) Tools::getValue(self::CFG_CONSENT_WAIT))));
        Configuration::updateValue(self::CFG_ID_FORMAT, $format);
        Configuration::updateValue(self::CFG_ID_SEPARATOR, $separator);
        Configuration::updateValue(self::CFG_ID_PREFIX, $prefix);
        Configuration::updateValue(self::CFG_VALUE_MODE, Tools::getValue(self::CFG_VALUE_MODE) === 'total' ? 'total' : 'products');
        Configuration::updateValue(self::CFG_MAX_LIST_ITEMS, max(1, min(200, (int) Tools::getValue(self::CFG_MAX_LIST_ITEMS))));
        $userData = (string) Tools::getValue(self::CFG_USER_DATA);
        Configuration::updateValue(self::CFG_USER_DATA, in_array($userData, ['off', 'hashed', 'plain'], true) ? $userData : 'hashed');
        Configuration::updateValue(self::CFG_MEASUREMENT_ID, $mid);
        $secret = trim((string) Tools::getValue(self::CFG_API_SECRET));
        if ($secret !== '' || Tools::getValue('dgtm_clear_secret')) {
            Configuration::updateValue(self::CFG_API_SECRET, $secret);
        }
        Configuration::updateValue(self::CFG_SERVER_DELAY, max(5, min(1440, (int) Tools::getValue(self::CFG_SERVER_DELAY))));
        Configuration::updateValue(self::CFG_REFUND_STATES, implode(',', array_filter(array_map('intval', (array) Tools::getValue(self::CFG_REFUND_STATES, [])))));
        $payments = array_filter(array_map(function ($name) {
            return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $name);
        }, (array) Tools::getValue(self::CFG_EXCLUDE_PAYMENTS, [])));
        Configuration::updateValue(self::CFG_EXCLUDE_PAYMENTS, implode(',', $payments));
        Configuration::updateValue(self::CFG_ADS_CONVERSION_ID, $adsId !== '' && strpos($adsId, 'AW-') !== 0 ? 'AW-' . $adsId : $adsId);
        Configuration::updateValue(self::CFG_ADS_CONVERSION_LABEL, preg_replace('/[^A-Za-z0-9_\-]/', '', (string) Tools::getValue(self::CFG_ADS_CONVERSION_LABEL)));
        Configuration::updateValue(self::CFG_DEBUG_IPS, implode(',', $ips));

        return $this->displayConfirmation('Impostazioni salvate.');
    }

    protected function renderForm()
    {
        $switch = function ($name, $label, $desc = '') {
            return [
                'type' => 'switch', 'label' => $label, 'name' => $name, 'is_bool' => true, 'desc' => $desc,
                'values' => [['id' => $name . '_on', 'value' => 1, 'label' => 'Sì'], ['id' => $name . '_off', 'value' => 0, 'label' => 'No']],
            ];
        };
        $select = function ($name, $label, array $options, $desc = '') {
            $query = [];
            foreach ($options as $id => $text) {
                $query[] = ['id' => $id, 'name' => $text];
            }

            return ['type' => 'select', 'label' => $label, 'name' => $name, 'desc' => $desc, 'options' => ['query' => $query, 'id' => 'id', 'name' => 'name']];
        };
        $payments = [];
        foreach (PaymentModule::getInstalledPaymentModules() as $row) {
            $instance = Module::getInstanceByName($row['name']);
            $payments[] = ['id' => $row['name'], 'name' => ($instance ? $instance->displayName : $row['name']) . ' (' . $row['name'] . ')'];
        }
        $secretSet = trim((string) Configuration::get(self::CFG_API_SECRET)) !== '';

        $forms = [
            ['form' => [
                'legend' => ['title' => 'Contenitore Google Tag Manager', 'icon' => 'icon-code'],
                'input' => [
                    ['type' => 'text', 'label' => 'ID contenitore', 'name' => self::CFG_GTM_ID, 'class' => 'fixed-width-xl', 'desc' => 'Formato GTM-XXXXXXX. Senza ID il modulo non inserisce nulla nel sito.'],
                    $switch(self::CFG_LOAD_SCRIPT, 'Carica lo script GTM', 'Disattiva solo se GTM è già inserito dal tema o da un altro modulo: il dataLayer continua a essere popolato.'),
                    ['type' => 'text', 'label' => 'URL dello script', 'name' => self::CFG_SCRIPT_URL, 'desc' => 'Vuoto = ' . DotmGtmDataLayer::DEFAULT_SCRIPT_URL . '. Per il tracciamento server-side indica il tuo dominio, ad esempio https://sgtm.tuonegozio.it/gtm.js.'],
                ],
                'submit' => ['title' => 'Salva'],
            ]],
            ['form' => [
                'legend' => ['title' => 'Consent Mode v2', 'icon' => 'icon-shield'],
                'input' => [
                    $switch(self::CFG_CONSENT, 'Imposta il consenso predefinito', 'Invia gtag("consent","default",…) prima di GTM. Il banner dei cookie (Cookiebot, iubenda, CookieYes…) invia poi l\'aggiornamento. Disattiva solo se il banner imposta già anche il valore predefinito.'),
                    $select(self::CFG_CONSENT_REGION, 'Dove si applica', ['eea' => 'Solo Spazio economico europeo, Regno Unito e Svizzera (altrove tutto concesso)', 'all' => 'In tutto il mondo']),
                    $switch(self::CFG_CONSENT_ANALYTICS, 'Statistiche concesse in partenza', 'analytics_storage. Nell\'UE normalmente No (serve il consenso).'),
                    $switch(self::CFG_CONSENT_ADS, 'Pubblicità concessa in partenza', 'ad_storage, ad_user_data, ad_personalization. Nell\'UE normalmente No.'),
                    ['type' => 'text', 'label' => 'Attesa del banner (ms)', 'name' => self::CFG_CONSENT_WAIT, 'class' => 'fixed-width-sm', 'desc' => 'wait_for_update: per quanto tempo i tag attendono la scelta salvata dal banner. 0 = nessuna attesa.'],
                    $switch(self::CFG_ADS_REDACTION, 'Oscura i dati pubblicitari senza consenso', 'ads_data_redaction.'),
                    $switch(self::CFG_URL_PASSTHROUGH, 'Passa i parametri dei clic negli URL', 'url_passthrough: mantiene gclid e simili tra le pagine quando i cookie pubblicitari non sono consentiti.'),
                ],
                'submit' => ['title' => 'Salva'],
            ]],
            ['form' => [
                'legend' => ['title' => 'Dati e-commerce', 'icon' => 'icon-shopping-cart'],
                'input' => [
                    $select(self::CFG_ID_FORMAT, 'ID articolo', [
                        'id' => 'ID prodotto (12)',
                        'id_ipa' => 'ID prodotto e combinazione (12-45)',
                        'reference' => 'Riferimento',
                        'ean13' => 'EAN13',
                        'upc' => 'UPC',
                    ], 'Deve coincidere con l\'ID del feed di Google Merchant Center, per il remarketing dinamico. Riferimento, EAN e UPC usano quelli della combinazione se presenti, altrimenti del prodotto, altrimenti l\'ID.'),
                    ['type' => 'text', 'label' => 'Separatore combinazione', 'name' => self::CFG_ID_SEPARATOR, 'class' => 'fixed-width-sm', 'desc' => 'Per «ID prodotto e combinazione».'],
                    ['type' => 'text', 'label' => 'Prefisso ID', 'name' => self::CFG_ID_PREFIX, 'class' => 'fixed-width-md', 'desc' => 'Facoltativo, se il feed usa ID con prefisso (es. it_).'],
                    $switch(self::CFG_PRICE_TAX, 'Prezzi IVA inclusa', 'Prezzi degli articoli e valori degli eventi con IVA (Sì) o senza (No).'),
                    $select(self::CFG_VALUE_MODE, 'Valore dell\'acquisto', ['products' => 'Prodotti al netto degli sconti (senza spedizione)', 'total' => 'Totale dell\'ordine (con spedizione)'], 'Spedizione e IVA sono comunque inviate a parte (shipping, tax).'),
                    ['type' => 'text', 'label' => 'Prodotti negli elenchi', 'name' => self::CFG_MAX_LIST_ITEMS, 'class' => 'fixed-width-sm', 'desc' => 'Massimo di articoli in view_item_list (1–200).'],
                    $switch(self::CFG_USER_ID, 'Invia l\'ID cliente (user_id)', 'Per i clienti registrati e loggati: abilita il tracciamento multi-dispositivo di GA4.'),
                    $select(self::CFG_USER_DATA, 'Conversioni avanzate (user_data)', [
                        'hashed' => 'Sì, cifrati con SHA-256 (consigliato)',
                        'plain' => 'Sì, in chiaro (li cifra GTM)',
                        'off' => 'No',
                    ], 'Email, telefono e indirizzo del cliente nell\'evento purchase, normalizzati secondo le specifiche di Google.'),
                ],
                'submit' => ['title' => 'Salva'],
            ]],
            ['form' => [
                'legend' => ['title' => 'Invio dal server (Measurement Protocol GA4)', 'icon' => 'icon-cloud-upload'],
                'description' => 'Recupera gli acquisti la cui pagina di conferma non è stata vista (pagamenti esterni, ad-blocker, chiusura del browser) e invia i rimborsi. In GA4: Amministrazione → Stream di dati → il tuo stream → Measurement Protocol API secret → Crea.',
                'input' => [
                    ['type' => 'text', 'label' => 'ID di misurazione GA4', 'name' => self::CFG_MEASUREMENT_ID, 'class' => 'fixed-width-xl', 'desc' => 'Formato G-XXXXXXXXXX. Serve anche per il contenitore da importare.'],
                    ['type' => 'text', 'label' => 'API secret', 'name' => self::CFG_API_SECRET, 'desc' => $secretSet ? 'Già impostato: lascia vuoto per non cambiarlo.' : 'Senza, l\'invio dal server è disattivato.'],
                    $switch(self::CFG_SERVER_EU, 'Endpoint europeo', 'Invia a region1.google-analytics.com (dati raccolti nell\'UE).'),
                    ['type' => 'text', 'label' => 'Ritardo (minuti)', 'name' => self::CFG_SERVER_DELAY, 'class' => 'fixed-width-sm', 'desc' => 'Dopo quanto tempo un ordine pagato non confermato dal browser viene inviato dal server (5–1440).'],
                    $switch(self::CFG_SERVER_REQUIRE_CID, 'Solo con il cookie GA del cliente', 'Sì: invia dal server solo se durante il checkout il cliente aveva il cookie di Google Analytics (consenso alle statistiche dato). Consigliato.'),
                    [
                        'type' => 'select', 'label' => 'Stati di rimborso totale', 'name' => self::CFG_REFUND_STATES . '[]', 'multiple' => true, 'size' => 8,
                        'options' => ['query' => OrderState::getOrderStates((int) $this->context->language->id), 'id' => 'id_order_state', 'name' => 'name'],
                        'desc' => 'Quando un ordine già tracciato passa a uno di questi stati viene inviato un refund totale. Le note di credito inviano un refund parziale.',
                    ],
                    [
                        'type' => 'select', 'label' => 'Pagamenti esclusi', 'name' => self::CFG_EXCLUDE_PAYMENTS . '[]', 'multiple' => true, 'size' => 6,
                        'options' => ['query' => $payments, 'id' => 'id', 'name' => 'name'],
                        'desc' => 'Gli ordini con questi metodi non vengono mai inviati dal server (es. pagamenti in negozio).',
                    ],
                    $switch(self::CFG_VISIT_TRIGGER, 'Invio con le visite al sito', 'Se il cron non è configurato, l\'invio parte al massimo ogni 10 minuti con le visite. Con il cron puoi disattivarlo.'),
                ],
                'submit' => ['title' => 'Salva'],
            ]],
            ['form' => [
                'legend' => ['title' => 'Google Ads (contenitore da importare) e debug', 'icon' => 'icon-wrench'],
                'input' => [
                    ['type' => 'text', 'label' => 'ID conversione Google Ads', 'name' => self::CFG_ADS_CONVERSION_ID, 'class' => 'fixed-width-xl', 'desc' => 'Facoltativo, formato AW-123456789: aggiunge al contenitore il linker e la conversione sull\'acquisto.'],
                    ['type' => 'text', 'label' => 'Etichetta conversione', 'name' => self::CFG_ADS_CONVERSION_LABEL, 'class' => 'fixed-width-xl'],
                    $switch(self::CFG_DEBUG, 'Pannello di debug sul sito', 'Mostra in basso nelle pagine del negozio gli eventi inviati al dataLayer.'),
                    ['type' => 'text', 'label' => 'Solo per questi IP', 'name' => self::CFG_DEBUG_IPS, 'desc' => 'Separati da virgola. Il tuo IP: ' . htmlspecialchars((string) Tools::getRemoteAddr(), ENT_QUOTES, 'UTF-8') . '. Vuoto = tutti i visitatori (sconsigliato).'],
                ],
                'submit' => ['title' => 'Salva'],
            ]],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitDotmgtagmanager';
        $values = [];
        foreach (array_keys($this->getDefaults()) as $key) {
            $values[$key] = Configuration::get($key);
        }
        $values[self::CFG_API_SECRET] = '';
        $values[self::CFG_REFUND_STATES . '[]'] = $this->getRefundStates();
        $values[self::CFG_EXCLUDE_PAYMENTS . '[]'] = $this->getExcludedPayments();
        $helper->fields_value = $values;

        return $helper->generateForm($forms);
    }
}
