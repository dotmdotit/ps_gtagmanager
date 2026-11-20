<?php
/**
 * Codice per le pagine del negozio: Consent Mode v2, informazioni su pagina e utente, contenitore GTM
 * ed eventi e-commerce della pagina (view_item, view_item_list, view_cart, begin_checkout, purchase…).
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DotmGtmDataLayer
{
    /** Spazio economico europeo, Regno Unito e Svizzera (consenso predefinito "negato" se richiesto) */
    const CONSENT_REGIONS = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT',
        'LV', 'LI', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'CH',
    ];

    const DEFAULT_SCRIPT_URL = 'https://www.googletagmanager.com/gtm.js';

    /** @var Dotmgtagmanager */
    protected $module;

    /** @var Context */
    protected $context;

    /** @var DotmGtmItemBuilder */
    protected $builder;

    /** @var int[] prodotti di cui passare i dati al JavaScript */
    protected $productIds = [];

    public function __construct(Dotmgtagmanager $module, Context $context)
    {
        $this->module = $module;
        $this->context = $context;
        $this->builder = new DotmGtmItemBuilder($module, (int) $context->language->id, (int) $context->shop->id);
    }

    public static function json($data)
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public function getPageType()
    {
        $controller = $this->context->controller;
        if (!empty($controller->php_self)) {
            return (string) $controller->php_self;
        }
        if ($controller instanceof ModuleFrontController && isset($controller->module->name)) {
            return 'module-' . $controller->module->name . '-' . Tools::strtolower((string) Tools::getValue('controller'));
        }

        return 'other';
    }

    /* ------------------------------------------------------------------
     * <head>: consenso, pagina/utente, GTM
     * ------------------------------------------------------------------ */

    public function getHeadHtml()
    {
        $js = 'window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};';
        $js .= $this->getConsentJs();
        $js .= 'dataLayer.push(' . self::json($this->getPageInfo()) . ');';

        $gtmId = $this->module->getGtmId();
        if ($gtmId !== '' && Configuration::get(Dotmgtagmanager::CFG_LOAD_SCRIPT)) {
            $js .= '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':new Date().getTime(),event:\'gtm.js\'});'
                . 'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';'
                . 'j.async=true;j.src=' . self::json($this->getScriptUrl()) . '+\'?id=\'+i+dl;f.parentNode.insertBefore(j,f);'
                . '})(window,document,\'script\',\'dataLayer\',' . self::json($gtmId) . ');';
        }

        return '<script data-dotmgtm="head">' . $js . '</script>' . "\n";
    }

    public function getNoscriptHtml()
    {
        $gtmId = $this->module->getGtmId();
        if ($gtmId === '' || !Configuration::get(Dotmgtagmanager::CFG_LOAD_SCRIPT)) {
            return '';
        }
        $url = preg_replace('/gtm\.js$/', 'ns.html', $this->getScriptUrl()) . '?id=' . rawurlencode($gtmId);

        return '<noscript><iframe src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
    }

    protected function getScriptUrl()
    {
        $url = trim((string) Configuration::get(Dotmgtagmanager::CFG_SCRIPT_URL));

        return preg_match('#^https://[^\s"\'<>]+$#', $url) ? $url : self::DEFAULT_SCRIPT_URL;
    }

    /**
     * Consent Mode v2: stato predefinito prima di GTM; gli aggiornamenti arrivano dal banner dei cookie (CMP).
     */
    protected function getConsentJs()
    {
        if (!Configuration::get(Dotmgtagmanager::CFG_CONSENT)) {
            return '';
        }
        $state = function ($key) {
            return Configuration::get($key) ? 'granted' : 'denied';
        };
        $defaults = [
            'ad_storage' => $state(Dotmgtagmanager::CFG_CONSENT_ADS),
            'ad_user_data' => $state(Dotmgtagmanager::CFG_CONSENT_ADS),
            'ad_personalization' => $state(Dotmgtagmanager::CFG_CONSENT_ADS),
            'analytics_storage' => $state(Dotmgtagmanager::CFG_CONSENT_ANALYTICS),
            'functionality_storage' => 'granted',
            'security_storage' => 'granted',
        ];
        $wait = (int) Configuration::get(Dotmgtagmanager::CFG_CONSENT_WAIT);
        if ($wait > 0) {
            $defaults['wait_for_update'] = $wait;
        }

        $js = '';
        if (Configuration::get(Dotmgtagmanager::CFG_CONSENT_REGION) === 'eea') {
            // fuori dall'area europea tutto concesso, nell'area europea i valori configurati
            $world = array_fill_keys(['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'security_storage'], 'granted');
            $js .= 'gtag("consent","default",' . self::json($world) . ');';
            $defaults['region'] = self::CONSENT_REGIONS;
        }
        $js .= 'gtag("consent","default",' . self::json($defaults) . ');';
        if (Configuration::get(Dotmgtagmanager::CFG_ADS_REDACTION)) {
            $js .= 'gtag("set","ads_data_redaction",true);';
        }
        if (Configuration::get(Dotmgtagmanager::CFG_URL_PASSTHROUGH)) {
            $js .= 'gtag("set","url_passthrough",true);';
        }

        return $js;
    }

    protected function getPageInfo()
    {
        $customer = $this->context->customer;
        $logged = Validate::isLoadedObject($customer) && $customer->isLogged();
        $info = [
            'page_type' => $this->getPageType(),
            'page_language' => (string) $this->context->language->iso_code,
            'currency' => (string) $this->context->currency->iso_code,
            'user_logged_in' => $logged,
        ];
        if ($logged) {
            $info['customer_group'] = (int) $customer->id_default_group;
            if (Configuration::get(Dotmgtagmanager::CFG_USER_ID) && !$customer->is_guest) {
                $info['user_id'] = (string) (int) $customer->id;
            }
        }

        return $this->module->alterData('page', $info);
    }

    /* ------------------------------------------------------------------
     * Fine pagina: eventi della pagina e configurazione per il JavaScript
     * ------------------------------------------------------------------ */

    public function getBottomHtml()
    {
        $events = [];
        $config = [
            'currency' => (string) $this->context->currency->iso_code,
            'withTax' => $this->builder->withTax(),
            'id' => [
                'format' => (string) Configuration::get(Dotmgtagmanager::CFG_ID_FORMAT),
                'separator' => (string) Configuration::get(Dotmgtagmanager::CFG_ID_SEPARATOR),
                'prefix' => (string) Configuration::get(Dotmgtagmanager::CFG_ID_PREFIX),
            ],
            'affiliation' => (string) Configuration::get('PS_SHOP_NAME'),
            'api' => $this->context->link->getModuleLink($this->module->name, 'api', [], true),
            'debug' => $this->module->isDebug(),
            'list' => null,
            'checkout' => null,
        ];

        switch ($this->getPageType()) {
            case 'product':
                $this->addProductEvents($events);
                break;
            case 'category':
            case 'search':
            case 'manufacturer':
            case 'supplier':
            case 'new-products':
            case 'prices-drop':
            case 'best-sales':
                $this->addListEvents($events, $config);
                break;
            case 'cart':
                $this->addCartEvents($events);
                break;
            case 'order':
            case 'checkout':
                $this->addCheckoutEvents($events, $config);
                break;
            case 'order-confirmation':
                $this->addPurchaseEvents($events);
                break;
            case 'contact':
                if (Tools::isSubmit('submitMessage') && empty($this->context->controller->errors)) {
                    $events[] = ['event' => 'generate_lead', 'lead_source' => 'contact_form'];
                }
                break;
        }

        foreach ($this->module->takeQueuedEvents() as $queued) {
            $events[] = $queued;
        }
        if (Validate::isLoadedObject($this->context->cart)) {
            foreach ($this->context->cart->getProducts() as $p) {
                $this->productIds[] = (int) $p['id_product'];
            }
        }
        $config['products'] = (object) $this->builder->productMap($this->productIds);
        $config['events'] = [];
        foreach ($events as $event) {
            $event = $this->module->alterData(isset($event['event']) ? $event['event'] : 'event', $event);
            if ($event) {
                $config['events'][] = $event;
            }
        }

        $js = '(function(){var c=window.dotmgtmConfig=' . self::json($config) . ';'
            . 'var dl=window.dataLayer=window.dataLayer||[],log=window.dotmgtmLog=window.dotmgtmLog||[];'
            . 'function seen(k){try{if(sessionStorage.getItem(k)){return true;}sessionStorage.setItem(k,"1");}catch(e){}return false;}'
            . 'c.events.forEach(function(e){'
            . 'if(e._once){if(seen("dgtm_"+e._once)){return;}}delete e._once;'
            . 'if(e._confirm){var cf=e._confirm,done=false;delete e._confirm;e.eventCallback=function(id){'
            . 'if(done||typeof id!=="string"||id.indexOf("GTM-")!==0){return;}done=true;'
            . 'var d=new FormData();d.append("action","confirm");d.append("cart",cf.cart);d.append("key",cf.key);'
            . 'if(navigator.sendBeacon){navigator.sendBeacon(cf.url,d);}else{fetch(cf.url,{method:"POST",body:d,keepalive:true});}};}'
            . 'if(e.ecommerce){dl.push({ecommerce:null});}dl.push(e);log.push(e);});})();';

        return '<script data-dotmgtm="page">' . $js . '</script>' . "\n";
    }

    protected function ecommerceEvent($name, array $ecommerce, array $extra = [])
    {
        if (!isset($ecommerce['currency'])) {
            $ecommerce = ['currency' => (string) $this->context->currency->iso_code] + $ecommerce;
        }

        return array_merge(['event' => $name, 'ecommerce' => $ecommerce], $extra);
    }

    protected function addProductEvents(array &$events)
    {
        $controller = $this->context->controller;
        $product = method_exists($controller, 'getProduct') ? $controller->getProduct() : null;
        if (!Validate::isLoadedObject($product)) {
            return;
        }
        $presented = $this->context->smarty->getTemplateVars('product');
        $ipa = 0;
        if ((is_array($presented) || $presented instanceof ArrayAccess) && isset($presented['id_product_attribute'])) {
            $ipa = (int) $presented['id_product_attribute'];
        }
        $item = $this->builder->item((int) $product->id, $ipa);
        if (!$item) {
            return;
        }
        $this->productIds[] = (int) $product->id;
        $events[] = $this->ecommerceEvent('view_item', ['value' => $item['price'], 'items' => [$item]]);
    }

    protected function addListEvents(array &$events, array &$config)
    {
        $listing = $this->context->smarty->getTemplateVars('listing');
        $products = (is_array($listing) || $listing instanceof ArrayAccess) && isset($listing['products']) ? $listing['products'] : [];
        $rows = [];
        foreach ($products as $p) {
            $rows[] = ['id_product' => (int) $p['id_product'], 'id_product_attribute' => isset($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : 0];
        }

        list($listId, $listName) = $this->getListIdentity();
        if ($this->getPageType() === 'search') {
            $term = trim((string) Tools::getValue('s', Tools::getValue('search_query', '')));
            if ($term !== '') {
                $events[] = ['event' => 'search', 'search_term' => Tools::substr(strip_tags($term), 0, 100)];
            }
        }
        $config['list'] = ['id' => $listId, 'name' => $listName];
        if (!$rows) {
            return;
        }
        $keys = [];
        $items = $this->builder->listItems($rows, $listId, $listName, (int) Configuration::get(Dotmgtagmanager::CFG_MAX_LIST_ITEMS), $keys);
        $config['list']['items'] = $items;
        $config['list']['keys'] = $keys;
        $this->productIds = array_merge($this->productIds, array_column($rows, 'id_product'));
        $events[] = $this->ecommerceEvent('view_item_list', ['item_list_id' => $listId, 'item_list_name' => $listName, 'items' => $items]);
    }

    /**
     * @return array [item_list_id, item_list_name]
     */
    public function getListIdentity()
    {
        $idLang = (int) $this->context->language->id;
        switch ($this->getPageType()) {
            case 'category':
                $id = (int) Tools::getValue('id_category');
                $category = new Category($id, $idLang);

                return ['category_' . $id, Validate::isLoadedObject($category) ? (string) $category->name : 'Category'];
            case 'manufacturer':
                $id = (int) Tools::getValue('id_manufacturer');

                return ['brand_' . $id, $id ? (string) Manufacturer::getNameById($id) : $this->module->t('list_brands')];
            case 'supplier':
                $id = (int) Tools::getValue('id_supplier');

                return ['supplier_' . $id, $id ? (string) Supplier::getNameById($id) : $this->module->t('list_suppliers')];
            case 'search':
                return ['search_results', $this->module->t('list_search')];
            case 'new-products':
                return ['new_products', $this->module->t('list_new')];
            case 'prices-drop':
                return ['prices_drop', $this->module->t('list_prices_drop')];
            case 'best-sales':
                return ['best_sales', $this->module->t('list_best_sales')];
        }

        return ['other', $this->getPageType()];
    }

    protected function addCartEvents(array &$events)
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->nbProducts()) {
            return;
        }
        DotmGtmServer::rememberCart((int) $cart->id);
        $data = $this->builder->cartData($cart);
        $events[] = $this->ecommerceEvent('view_cart', ['value' => $data['value'], 'items' => $data['items']]);
    }

    protected function addCheckoutEvents(array &$events, array &$config)
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->nbProducts()) {
            return;
        }
        DotmGtmServer::rememberCart((int) $cart->id);
        $data = $this->builder->cartData($cart);
        $ecommerce = ['value' => $data['value'], 'items' => $data['items']];
        if ($data['coupon'] !== '') {
            $ecommerce['coupon'] = $data['coupon'];
        }
        $config['checkout'] = $ecommerce + ['currency' => (string) $this->context->currency->iso_code];
        $events[] = $this->ecommerceEvent('begin_checkout', $ecommerce, ['_once' => 'bc_' . (int) $cart->id . '_' . md5(self::json($ecommerce))]);
    }

    protected function addPurchaseEvents(array &$events)
    {
        $idCart = (int) Tools::getValue('id_cart');
        $key = (string) Tools::getValue('key');
        $idOrder = (int) Order::getIdByCartId($idCart);
        $order = new Order($idOrder);
        if (!$idCart || !Validate::isLoadedObject($order) || $key === '' || !hash_equals((string) $order->secure_key, $key)) {
            return;
        }

        DotmGtmServer::rememberCart($idCart);
        $log = $this->module->getOrCreateLog($order);
        if ($log->isPurchaseSent() || $log->status === DotmGtmOrderLog::STATUS_EXCLUDED) {
            return;
        }

        $orders = $log->getOrders();
        $builder = new DotmGtmItemBuilder($this->module, (int) $order->id_lang, (int) $order->id_shop);
        $data = $builder->purchaseData($orders ?: [$order]);
        $extra = [
            '_once' => 'p_' . $order->reference,
            '_confirm' => [
                'url' => $this->context->link->getModuleLink($this->module->name, 'api', [], true),
                'cart' => $idCart,
                'key' => DotmGtmOrderLog::getConfirmKey($order->reference, $idCart),
            ],
        ];
        $userData = $this->getUserData($order);
        if ($userData) {
            $extra['user_data'] = $userData;
        }
        $customer = new Customer((int) $order->id_customer);
        if (Validate::isLoadedObject($customer)) {
            $extra['new_customer'] = Order::getCustomerNbOrders((int) $customer->id) <= count($orders ?: [$order]);
        }
        $events[] = $this->ecommerceEvent('purchase', $data, $extra);
    }

    /* ------------------------------------------------------------------
     * Conversioni avanzate (user_data)
     * ------------------------------------------------------------------ */

    /**
     * Dati del cliente per le conversioni avanzate di Google, normalizzati e (di norma) cifrati con SHA-256.
     *
     * @return array|null
     */
    public function getUserData(Order $order)
    {
        $mode = (string) Configuration::get(Dotmgtagmanager::CFG_USER_DATA);
        if ($mode !== 'hashed' && $mode !== 'plain') {
            return null;
        }
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) ($order->id_address_invoice ?: $order->id_address_delivery));
        if (!Validate::isLoadedObject($customer)) {
            return null;
        }
        $country = Validate::isLoadedObject($address) ? new Country((int) $address->id_country) : null;
        $email = self::normalizeEmail($customer->email);
        $phone = '';
        if (Validate::isLoadedObject($address)) {
            $phone = self::normalizePhone($address->phone_mobile ?: $address->phone, $country ? (string) $country->call_prefix : '');
        }
        $hashed = $mode === 'hashed';
        $value = function ($text) use ($hashed) {
            return $hashed ? hash('sha256', $text) : $text;
        };

        $data = [];
        if ($email !== '') {
            $data[$hashed ? 'sha256_email_address' : 'email'] = $value($email);
        }
        if ($phone !== '') {
            $data[$hashed ? 'sha256_phone_number' : 'phone_number'] = $value($phone);
        }
        if (Validate::isLoadedObject($address)) {
            $first = self::normalizeName($address->firstname);
            $last = self::normalizeName($address->lastname);
            $street = self::normalizeName(trim($address->address1 . ' ' . $address->address2));
            $addr = [
                $hashed ? 'sha256_first_name' : 'first_name' => $value($first),
                $hashed ? 'sha256_last_name' : 'last_name' => $value($last),
                $hashed ? 'sha256_street' : 'street' => $value($street),
                'city' => self::normalizeName($address->city),
                'postal_code' => preg_replace('/\s+/', '', (string) $address->postcode),
                'country' => $country ? (string) $country->iso_code : '',
            ];
            if ((int) $address->id_state) {
                $addr['region'] = (string) State::getNameById((int) $address->id_state);
            }
            $data['address'] = array_filter($addr, 'strlen');
        }

        return $data ?: null;
    }

    public static function normalizeEmail($email)
    {
        $email = Tools::strtolower(trim((string) $email));
        if (!Validate::isEmail($email)) {
            return '';
        }
        list($local, $domain) = explode('@', $email, 2);
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', $local);
        }

        return $local . '@' . $domain;
    }

    /**
     * Telefono in formato E.164 (+391234567890).
     */
    public static function normalizePhone($phone, $callPrefix)
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }
        $plus = strpos($phone, '+') === 0;
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return '';
        }
        if ($plus) {
            return '+' . $digits;
        }
        if (strpos($digits, '00') === 0) {
            return '+' . substr($digits, 2);
        }

        return $callPrefix !== '' ? '+' . preg_replace('/\D+/', '', $callPrefix) . $digits : '';
    }

    public static function normalizeName($text)
    {
        return Tools::strtolower(trim(preg_replace('/\s+/', ' ', (string) $text)));
    }
}
