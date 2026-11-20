<?php
/**
 * Contenitore GTM pronto da importare (Amministrazione → Importa contenitore → Unisci):
 * Google tag GA4, un evento GA4 per tutti gli eventi del modulo con i dati e-commerce del dataLayer,
 * variabili, attivatori e, se configurati, linker e conversione Google Ads.
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DotmGtmContainer
{
    /** attivatori predefiniti di GTM */
    const TRIGGER_ALL_PAGES = '2147479553';
    const TRIGGER_INITIALIZATION = '2147479573';

    /** eventi inviati dal modulo */
    const EVENTS = [
        'view_item', 'view_item_list', 'select_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
        'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase', 'refund',
        'add_to_wishlist', 'search', 'login', 'sign_up', 'generate_lead',
    ];

    /** @var Dotmgtagmanager */
    protected $module;

    protected $variableId = 0;

    public function __construct(Dotmgtagmanager $module)
    {
        $this->module = $module;
    }

    public function build()
    {
        $mid = (string) Configuration::get(Dotmgtagmanager::CFG_MEASUREMENT_ID) ?: 'G-XXXXXXXXXX';
        $prefix = '[dotm] ';

        $variables = [];
        $dlv = [];
        foreach (['user_id', 'search_term', 'method', 'lead_source', 'page_type', 'ecommerce.value', 'ecommerce.currency', 'ecommerce.transaction_id', 'new_customer'] as $path) {
            $name = $prefix . 'DLV - ' . $path;
            $dlv[$path] = '{{' . $name . '}}';
            $variables[] = $this->variable($name, $path);
        }
        $variables[] = [
            'accountId' => '0',
            'containerId' => '0',
            'variableId' => (string) ++$this->variableId,
            'name' => $prefix . 'GA4 - ID di misurazione',
            'type' => 'c',
            'parameter' => [['type' => 'TEMPLATE', 'key' => 'value', 'value' => $mid]],
        ];
        $midVar = '{{' . $prefix . 'GA4 - ID di misurazione}}';

        $triggers = [
            $this->trigger('101', $prefix . 'Eventi e-commerce', 'MATCH_REGEX', '^(' . implode('|', self::EVENTS) . ')$'),
            $this->trigger('102', $prefix . 'Acquisto', 'EQUALS', 'purchase'),
        ];

        $tags = [];
        $tags[] = $this->tag('1', $prefix . 'Google tag GA4', 'googtag', [
            ['type' => 'TEMPLATE', 'key' => 'tagId', 'value' => $midVar],
            ['type' => 'LIST', 'key' => 'configSettingsTable', 'list' => [
                $this->mapRow('user_id', $dlv['user_id']),
                $this->mapRow('page_type', $dlv['page_type']),
            ]],
        ], [self::TRIGGER_INITIALIZATION]);
        $tags[] = $this->tag('2', $prefix . 'GA4 - Eventi e-commerce', 'gaawe', [
            ['type' => 'TEMPLATE', 'key' => 'eventName', 'value' => '{{Event}}'],
            ['type' => 'TEMPLATE', 'key' => 'measurementIdOverride', 'value' => $midVar],
            ['type' => 'BOOLEAN', 'key' => 'sendEcommerceData', 'value' => 'true'],
            ['type' => 'TEMPLATE', 'key' => 'getEcommerceDataFrom', 'value' => 'dataLayer'],
            ['type' => 'LIST', 'key' => 'eventSettingsTable', 'list' => [
                $this->mapRow('search_term', $dlv['search_term']),
                $this->mapRow('method', $dlv['method']),
                $this->mapRow('lead_source', $dlv['lead_source']),
                $this->mapRow('new_customer', $dlv['new_customer']),
            ]],
        ], ['101']);

        $adsId = preg_replace('/^AW-/', '', (string) Configuration::get(Dotmgtagmanager::CFG_ADS_CONVERSION_ID));
        $adsLabel = (string) Configuration::get(Dotmgtagmanager::CFG_ADS_CONVERSION_LABEL);
        if ($adsId !== '') {
            $tags[] = $this->tag('3', $prefix . 'Google Ads - Linker conversioni', 'gclidw', [
                ['type' => 'BOOLEAN', 'key' => 'enableCrossDomain', 'value' => 'false'],
                ['type' => 'BOOLEAN', 'key' => 'enableUrlPassthrough', 'value' => 'false'],
                ['type' => 'BOOLEAN', 'key' => 'enableCookieOverrides', 'value' => 'false'],
            ], [self::TRIGGER_ALL_PAGES]);
            if ($adsLabel !== '') {
                $tags[] = $this->tag('4', $prefix . 'Google Ads - Conversione acquisto', 'awct', [
                    ['type' => 'TEMPLATE', 'key' => 'conversionId', 'value' => $adsId],
                    ['type' => 'TEMPLATE', 'key' => 'conversionLabel', 'value' => $adsLabel],
                    ['type' => 'TEMPLATE', 'key' => 'conversionValue', 'value' => $dlv['ecommerce.value']],
                    ['type' => 'TEMPLATE', 'key' => 'currencyCode', 'value' => $dlv['ecommerce.currency']],
                    ['type' => 'TEMPLATE', 'key' => 'orderId', 'value' => $dlv['ecommerce.transaction_id']],
                    ['type' => 'BOOLEAN', 'key' => 'enableConversionLinker', 'value' => 'true'],
                    ['type' => 'BOOLEAN', 'key' => 'enableProductReporting', 'value' => 'false'],
                    ['type' => 'BOOLEAN', 'key' => 'rdp', 'value' => 'false'],
                ], ['102']);
            }
        }

        $gtmId = $this->module->getGtmId() ?: 'GTM-XXXXXXX';
        $export = [
            'exportFormatVersion' => 2,
            'exportTime' => date('Y-m-d H:i:s'),
            'containerVersion' => [
                'path' => 'accounts/0/containers/0/versions/0',
                'accountId' => '0',
                'containerId' => '0',
                'containerVersionId' => '0',
                'container' => [
                    'path' => 'accounts/0/containers/0',
                    'accountId' => '0',
                    'containerId' => '0',
                    'name' => 'dotmgtagmanager',
                    'publicId' => $gtmId,
                    'usageContext' => ['WEB'],
                ],
                'tag' => $tags,
                'trigger' => $triggers,
                'variable' => $variables,
                'builtInVariable' => [
                    ['accountId' => '0', 'containerId' => '0', 'type' => 'EVENT', 'name' => 'Event'],
                ],
            ],
        ];

        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function variable($name, $path)
    {
        return [
            'accountId' => '0',
            'containerId' => '0',
            'variableId' => (string) ++$this->variableId,
            'name' => $name,
            'type' => 'v',
            'parameter' => [
                ['type' => 'INTEGER', 'key' => 'dataLayerVersion', 'value' => '2'],
                ['type' => 'BOOLEAN', 'key' => 'setDefaultValue', 'value' => 'false'],
                ['type' => 'TEMPLATE', 'key' => 'name', 'value' => $path],
            ],
        ];
    }

    protected function trigger($id, $name, $match, $value)
    {
        return [
            'accountId' => '0',
            'containerId' => '0',
            'triggerId' => $id,
            'name' => $name,
            'type' => 'CUSTOM_EVENT',
            'customEventFilter' => [[
                'type' => $match,
                'parameter' => [
                    ['type' => 'TEMPLATE', 'key' => 'arg0', 'value' => '{{_event}}'],
                    ['type' => 'TEMPLATE', 'key' => 'arg1', 'value' => $value],
                ],
            ]],
        ];
    }

    protected function tag($id, $name, $type, array $parameters, array $triggers)
    {
        return [
            'accountId' => '0',
            'containerId' => '0',
            'tagId' => $id,
            'name' => $name,
            'type' => $type,
            'parameter' => $parameters,
            'firingTriggerId' => $triggers,
            'tagFiringOption' => 'ONCE_PER_EVENT',
            'monitoringMetadata' => ['type' => 'MAP'],
            'consentSettings' => ['consentStatus' => 'NOT_SET'],
        ];
    }

    protected function mapRow($parameter, $value)
    {
        return [
            'type' => 'MAP',
            'map' => [
                ['type' => 'TEMPLATE', 'key' => 'parameter', 'value' => $parameter],
                ['type' => 'TEMPLATE', 'key' => 'parameterValue', 'value' => $value],
            ],
        ];
    }
}
