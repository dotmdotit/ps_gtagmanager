<?php
/**
 * Registro degli acquisti per GA4: un record per carrello (gli ordini divisi hanno lo stesso riferimento).
 * Traccia se il "purchase" è partito dal browser o dal server, gli ID GA del cliente e i rimborsi inviati.
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DotmGtmOrderLog extends ObjectModel
{
    const STATUS_PENDING = 'pending';       // in attesa della conferma dal browser
    const STATUS_BROWSER = 'browser';       // inviato dal browser (GTM ha elaborato l'evento)
    const STATUS_SERVER = 'server';         // inviato dal server (Measurement Protocol)
    const STATUS_EXCLUDED = 'excluded';     // metodo di pagamento escluso o escluso a mano
    const STATUS_NO_CONSENT = 'no_consent'; // nessun ID GA del cliente: non inviato
    const STATUS_EXPIRED = 'expired';       // non pagato o troppo vecchio per l'invio dal server
    const STATUS_FAILED = 'failed';         // errori ripetuti di invio

    /** tentativi di invio dal server prima di arrendersi */
    const MAX_ATTEMPTS = 5;

    public $id_shop;
    public $id_cart;
    public $id_order;
    public $reference;
    public $client_id = '';
    public $session_id = '';
    public $status = self::STATUS_PENDING;
    public $sent_at;
    public $attempts = 0;
    public $refunded_amount = 0;
    public $refund_full = 0;
    /** @var string id delle note di credito già inviate, separati da virgola */
    public $refund_slips = '';
    public $last_error = '';
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'dotmgtagmanager_order',
        'primary' => 'id_dotmgtagmanager_order',
        'fields' => [
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_cart' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'reference' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'client_id' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'session_id' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16],
            'sent_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull', 'allow_null' => true],
            'attempts' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'refunded_amount' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'refund_full' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'refund_slips' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255],
            'last_error' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public static function getStatuses()
    {
        return [
            self::STATUS_PENDING, self::STATUS_BROWSER, self::STATUS_SERVER, self::STATUS_EXCLUDED,
            self::STATUS_NO_CONSENT, self::STATUS_EXPIRED, self::STATUS_FAILED,
        ];
    }

    /**
     * @return self|null
     */
    public static function getByCart($idCart)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_dotmgtagmanager_order` FROM `' . _DB_PREFIX_ . 'dotmgtagmanager_order` WHERE `id_cart` = ' . (int) $idCart
        );

        return $id ? new self($id) : null;
    }

    /**
     * @return self|null
     */
    public static function getByReference($reference)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_dotmgtagmanager_order` FROM `' . _DB_PREFIX_ . 'dotmgtagmanager_order`
            WHERE `reference` = \'' . pSQL($reference) . '\' ORDER BY `id_dotmgtagmanager_order` DESC'
        );

        return $id ? new self($id) : null;
    }

    /**
     * Acquisti in attesa da più di $minutes minuti (i più vecchi di 72 ore vengono chiusi da DotmGtmServer).
     *
     * @return self[]
     */
    public static function getDue($minutes, $limit = 50)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_dotmgtagmanager_order` FROM `' . _DB_PREFIX_ . 'dotmgtagmanager_order`
            WHERE `status` = \'' . pSQL(self::STATUS_PENDING) . '\'
            AND `date_add` <= \'' . pSQL(date('Y-m-d H:i:s', time() - 60 * max(1, (int) $minutes))) . '\'
            ORDER BY `date_add` ASC
            LIMIT ' . (int) $limit
        );
        $logs = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $logs[] = new self((int) $row['id_dotmgtagmanager_order']);
        }

        return $logs;
    }

    public function isPurchaseSent()
    {
        return in_array($this->status, [self::STATUS_BROWSER, self::STATUS_SERVER], true);
    }

    /**
     * Ordini PrestaShop dello stesso carrello (ordini divisi per corriere o magazzino).
     *
     * @return Order[]
     */
    public function getOrders()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int) $this->id_cart . ' ORDER BY `id_order` ASC'
        );
        $orders = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $order = new Order((int) $row['id_order']);
            if (Validate::isLoadedObject($order)) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    public function hasSlip($idSlip)
    {
        return in_array((string) (int) $idSlip, array_filter(explode(',', (string) $this->refund_slips)), true);
    }

    public function addSlip($idSlip)
    {
        $slips = array_filter(explode(',', (string) $this->refund_slips));
        $slips[] = (string) (int) $idSlip;
        $this->refund_slips = Tools::substr(implode(',', array_unique($slips)), 0, 255);
    }

    /**
     * Chiave per la conferma dal browser (impedisce di segnare come inviati ordini altrui).
     */
    public static function getConfirmKey($reference, $idCart)
    {
        return substr(hash('sha256', $reference . '|' . (int) $idCart . '|' . _COOKIE_KEY_), 0, 20);
    }
}
