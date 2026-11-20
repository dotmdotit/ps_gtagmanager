<?php
/**
 * Ordini → Tracciamento GA4: stato del "purchase" di ogni ordine (browser, server, escluso…),
 * rimborsi inviati, invio manuale dal server.
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'dotmgtagmanager/dotmgtagmanager.php';

class AdminDotmGtmOrderController extends ModuleAdminController
{
    /** @var Dotmgtagmanager */
    public $module;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'dotmgtagmanager_order';
        $this->className = 'DotmGtmOrderLog';
        $this->identifier = 'id_dotmgtagmanager_order';
        $this->lang = false;
        $this->allow_export = true;
        $this->list_no_link = true;
        $this->_defaultOrderBy = 'id_dotmgtagmanager_order';
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();

        $this->_select = 'o.`date_add` AS `order_date`, o.`payment`, o.`total_paid_tax_incl`, o.`id_currency`, o.`id_order` AS `order_id`,
            IF(a.`client_id` != \'\', 1, 0) AS `has_client`';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = a.`id_order`';

        $this->addRowAction('send');
        $this->addRowAction('exclude');
        $this->bulk_actions = [
            'send' => ['text' => 'Invia dal server', 'icon' => 'icon-cloud-upload', 'confirm' => 'Inviare a GA4 dal server gli acquisti selezionati?'],
        ];

        $statuses = [];
        foreach (DotmGtmOrderLog::getStatuses() as $status) {
            $statuses[$status] = $this->statusLabel($status);
        }
        $this->fields_list = [
            'reference' => ['title' => 'Ordine', 'callback' => 'renderReference', 'filter_key' => 'a!reference'],
            'order_date' => ['title' => 'Data', 'type' => 'datetime', 'filter_key' => 'o!date_add'],
            'payment' => ['title' => 'Pagamento', 'filter_key' => 'o!payment'],
            'total_paid_tax_incl' => ['title' => 'Totale', 'callback' => 'renderTotal', 'align' => 'text-right', 'search' => false],
            'status' => ['title' => 'Purchase', 'type' => 'select', 'list' => $statuses, 'filter_key' => 'a!status', 'callback' => 'renderStatus'],
            'sent_at' => ['title' => 'Inviato il', 'type' => 'datetime'],
            'has_client' => ['title' => 'Cookie GA', 'type' => 'bool', 'align' => 'center', 'havingFilter' => true, 'class' => 'fixed-width-sm'],
            'refunded_amount' => ['title' => 'Rimborsato', 'callback' => 'renderRefund', 'align' => 'text-right', 'search' => false],
            'last_error' => ['title' => 'Note', 'callback' => 'renderNote', 'search' => false, 'orderby' => false],
        ];
    }

    protected function statusLabel($status)
    {
        $labels = [
            DotmGtmOrderLog::STATUS_PENDING => 'In attesa',
            DotmGtmOrderLog::STATUS_BROWSER => 'Dal browser',
            DotmGtmOrderLog::STATUS_SERVER => 'Dal server',
            DotmGtmOrderLog::STATUS_EXCLUDED => 'Escluso',
            DotmGtmOrderLog::STATUS_NO_CONSENT => 'Senza cookie GA',
            DotmGtmOrderLog::STATUS_EXPIRED => 'Non inviato',
            DotmGtmOrderLog::STATUS_FAILED => 'Errore',
        ];

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    public function renderReference($value, $row)
    {
        $href = $this->context->link->getAdminLink('AdminOrders', true, ['orderId' => (int) $row['order_id'], 'route' => 'admin_orders_view'], ['id_order' => (int) $row['order_id'], 'vieworder' => 1]);

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    public function renderTotal($value, $row)
    {
        $currency = new Currency((int) $row['id_currency']);

        return htmlspecialchars(DotmGtmItemBuilder::round($value) . ' ' . $currency->iso_code, ENT_QUOTES, 'UTF-8');
    }

    public function renderRefund($value, $row)
    {
        if ((float) $value <= 0) {
            return '—';
        }

        return $this->renderTotal($value, $row) . ((int) $row['refund_full'] ? ' <span class="label label-default">totale</span>' : '');
    }

    public function renderStatus($value)
    {
        $classes = [
            DotmGtmOrderLog::STATUS_PENDING => 'warning',
            DotmGtmOrderLog::STATUS_BROWSER => 'success',
            DotmGtmOrderLog::STATUS_SERVER => 'info',
            DotmGtmOrderLog::STATUS_FAILED => 'danger',
        ];

        return '<span class="label label-' . (isset($classes[$value]) ? $classes[$value] : 'default') . '">'
            . htmlspecialchars($this->statusLabel($value), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public function renderNote($value)
    {
        return $value !== '' ? '<small>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</small>' : '';
    }

    public function displaySendLink($token, $id)
    {
        $log = new DotmGtmOrderLog((int) $id);
        if (!Validate::isLoadedObject($log) || !DotmGtmServer::isConfigured()) {
            return '';
        }
        $label = $log->isPurchaseSent() ? 'Reinvia dal server' : 'Invia dal server';
        $confirm = $log->isPurchaseSent() ? ' onclick="return confirm(\'Il purchase risulta già inviato: reinviarlo può duplicare il ricavo in GA4. Continuare?\');"' : '';

        return '<a href="' . htmlspecialchars(self::$currentIndex . '&token=' . $this->token . '&dgtm_send=' . (int) $id, ENT_QUOTES, 'UTF-8') . '"' . $confirm . '><i class="icon-cloud-upload"></i> ' . $label . '</a>';
    }

    public function displayExcludeLink($token, $id)
    {
        $log = new DotmGtmOrderLog((int) $id);
        if (!Validate::isLoadedObject($log) || $log->status !== DotmGtmOrderLog::STATUS_PENDING) {
            return '';
        }

        return '<a href="' . htmlspecialchars(self::$currentIndex . '&token=' . $this->token . '&dgtm_exclude=' . (int) $id, ENT_QUOTES, 'UTF-8') . '"><i class="icon-ban"></i> Escludi</a>';
    }

    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_btn['config'] = [
            'href' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->module->name]),
            'desc' => 'Impostazioni',
            'icon' => 'process-icon-cogs',
        ];
        parent::initPageHeaderToolbar();
    }

    public function renderList()
    {
        $info = '<div class="alert alert-info">Ogni riga è un acquisto. <strong>Dal browser</strong>: GTM ha elaborato il purchase nella pagina di conferma. '
            . '<strong>In attesa</strong>: se l\'ordine è pagato, dopo il ritardo configurato viene inviato <strong>dal server</strong> (Measurement Protocol) con l\'ID GA del cliente. '
            . '<strong>Senza cookie GA</strong>: il cliente non aveva dato il consenso alle statistiche, quindi non viene inviato.</div>';
        if (!DotmGtmServer::isConfigured()) {
            $info .= '<div class="alert alert-warning">Invio dal server non configurato: inserisci ID di misurazione e API secret nelle impostazioni del modulo.</div>';
        }

        return $info . parent::renderList();
    }

    public function postProcess()
    {
        $server = new DotmGtmServer($this->module);
        if ($id = (int) Tools::getValue('dgtm_send')) {
            $this->sendOne($server, $id);
        } elseif ($id = (int) Tools::getValue('dgtm_exclude')) {
            $log = new DotmGtmOrderLog($id);
            if (Validate::isLoadedObject($log) && $log->status === DotmGtmOrderLog::STATUS_PENDING) {
                $log->status = DotmGtmOrderLog::STATUS_EXCLUDED;
                $log->last_error = 'Escluso a mano';
                $log->update();
                $this->confirmations[] = 'Acquisto escluso dall\'invio.';
            }
        }

        return parent::postProcess();
    }

    protected function processBulkSend()
    {
        $server = new DotmGtmServer($this->module);
        foreach ((array) $this->boxes as $id) {
            $this->sendOne($server, (int) $id);
        }
    }

    protected function sendOne(DotmGtmServer $server, $id)
    {
        $log = new DotmGtmOrderLog((int) $id);
        if (!Validate::isLoadedObject($log)) {
            return;
        }
        if (!DotmGtmServer::isConfigured()) {
            $this->errors[] = 'Invio dal server non configurato.';

            return;
        }
        $outcome = $server->sendPurchase($log, true);
        if ($outcome === 'sent') {
            $this->confirmations[] = 'Purchase ' . $log->reference . ' inviato a GA4 dal server.';
        } else {
            $log = new DotmGtmOrderLog((int) $id);
            $this->errors[] = 'Purchase ' . $log->reference . ' non inviato: ' . htmlspecialchars($log->last_error, ENT_QUOTES, 'UTF-8');
        }
    }
}
