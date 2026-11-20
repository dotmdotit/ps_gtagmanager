<?php
/**
 * Endpoint per il JavaScript del modulo:
 * - action=confirm (POST): il browser conferma che GTM ha elaborato il "purchase";
 * - action=items (GET): articoli GA4 per un elenco caricato in AJAX (filtri, paginazione).
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DotmgtagmanagerApiModuleFrontController extends ModuleFrontController
{
    /** @var Dotmgtagmanager */
    public $module;

    /** articoli richiedibili in una volta */
    const MAX_ITEMS = 60;

    public function init()
    {
        parent::init();

        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');

        switch ((string) Tools::getValue('action')) {
            case 'confirm':
                $this->confirm();
                break;
            case 'items':
                $this->items();
                break;
            default:
                http_response_code(400);
                echo json_encode(['ok' => false]);
        }
        exit;
    }

    protected function confirm()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false]);

            return;
        }
        $log = DotmGtmOrderLog::getByCart((int) Tools::getValue('cart'));
        if (!$log || !hash_equals(DotmGtmOrderLog::getConfirmKey($log->reference, (int) $log->id_cart), (string) Tools::getValue('key'))) {
            http_response_code(404);
            echo json_encode(['ok' => false]);

            return;
        }
        if (in_array($log->status, [DotmGtmOrderLog::STATUS_PENDING, DotmGtmOrderLog::STATUS_NO_CONSENT, DotmGtmOrderLog::STATUS_FAILED], true)) {
            $log->status = DotmGtmOrderLog::STATUS_BROWSER;
            $log->sent_at = date('Y-m-d H:i:s');
            $log->last_error = '';
            $log->update();
        }
        echo json_encode(['ok' => true]);
    }

    protected function items()
    {
        $rows = [];
        foreach (array_slice((array) Tools::getValue('ids', []), 0, self::MAX_ITEMS) as $key) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', (string) $key, $m)) {
                $rows[] = ['id_product' => (int) $m[1], 'id_product_attribute' => isset($m[2]) ? (int) $m[2] : 0];
            }
        }
        $listId = Tools::substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('list_id')), 0, 64);
        $listName = Tools::substr(strip_tags((string) Tools::getValue('list_name')), 0, 100);
        $builder = new DotmGtmItemBuilder($this->module, (int) $this->context->language->id, (int) $this->context->shop->id);
        $max = (int) Configuration::get(Dotmgtagmanager::CFG_MAX_LIST_ITEMS);

        // solo prodotti attivi e visibili
        $visible = [];
        if ($rows) {
            $ids = implode(',', array_unique(array_column($rows, 'id_product')));
            $found = Db::getInstance()->executeS(
                'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop`
                WHERE `id_shop` = ' . (int) $this->context->shop->id . ' AND `active` = 1 AND `visibility` != \'none\'
                AND `id_product` IN (' . $ids . ')'
            );
            $visible = array_map('intval', array_column(is_array($found) ? $found : [], 'id_product'));
        }
        $rows = array_values(array_filter($rows, function ($r) use ($visible) {
            return in_array($r['id_product'], $visible, true);
        }));

        $keys = [];
        $items = $builder->listItems($rows, $listId, $listName, $max, $keys);
        echo DotmGtmDataLayer::json([
            'ok' => true,
            'items' => $items,
            'keys' => $keys,
            'products' => $builder->productMap(array_column($rows, 'id_product')),
        ]);
    }
}
