<?php
/**
 * Invio dal server con il Measurement Protocol di GA4: acquisti che il browser non ha confermato
 * (pagina di conferma mai vista, ad-blocker, pagamenti esterni) e rimborsi.
 * Usa l'ID client GA del cliente salvato durante il checkout, mai quello di un dipendente.
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DotmGtmServer
{
    /** GA4 accetta eventi retrodatati al massimo di 72 ore */
    const MAX_BACKDATE = 259200;

    /** @var Dotmgtagmanager */
    protected $module;

    public function __construct(Dotmgtagmanager $module)
    {
        $this->module = $module;
    }

    public static function isConfigured()
    {
        return preg_match('/^G-[A-Z0-9]+$/', (string) Configuration::get(Dotmgtagmanager::CFG_MEASUREMENT_ID))
            && trim((string) Configuration::get(Dotmgtagmanager::CFG_API_SECRET)) !== '';
    }

    /* ------------------------------------------------------------------
     * ID client GA (cookie _ga e _ga_<ID>)
     * ------------------------------------------------------------------ */

    /**
     * @return array [client_id, session_id] letti dai cookie della richiesta corrente
     */
    public static function readGaCookies()
    {
        $clientId = '';
        if (!empty($_COOKIE['_ga']) && preg_match('/^GA\d\.\d\.(\d+\.\d+)$/', (string) $_COOKIE['_ga'], $m)) {
            $clientId = $m[1];
        }
        $sessionId = '';
        $mid = (string) Configuration::get(Dotmgtagmanager::CFG_MEASUREMENT_ID);
        $name = '_ga_' . preg_replace('/^G-/', '', $mid);
        if ($mid !== '' && !empty($_COOKIE[$name])) {
            $value = (string) $_COOKIE[$name];
            if (preg_match('/^GS1\.\d\.(\d+)\./', $value, $m)) {
                $sessionId = $m[1];
            } elseif (preg_match('/^GS2\.\d\.s(\d+)/', $value, $m)) {
                $sessionId = $m[1];
            }
        }

        return [$clientId, $sessionId];
    }

    /**
     * Salva gli ID GA del carrello (chiamato nelle pagine di carrello e checkout).
     */
    public static function rememberCart($idCart)
    {
        list($clientId, $sessionId) = self::readGaCookies();
        if (!(int) $idCart || $clientId === '') {
            return;
        }
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'dotmgtagmanager_cart` (`id_cart`, `client_id`, `session_id`, `date_upd`)
            VALUES (' . (int) $idCart . ', \'' . pSQL($clientId) . '\', \'' . pSQL($sessionId) . '\', \'' . pSQL(date('Y-m-d H:i:s')) . '\')
            ON DUPLICATE KEY UPDATE `client_id` = VALUES(`client_id`),
                `session_id` = IF(VALUES(`session_id`) != \'\', VALUES(`session_id`), `session_id`), `date_upd` = VALUES(`date_upd`)'
        );
    }

    public static function getCartIds($idCart)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `client_id`, `session_id` FROM `' . _DB_PREFIX_ . 'dotmgtagmanager_cart` WHERE `id_cart` = ' . (int) $idCart
        );

        return $row ? [(string) $row['client_id'], (string) $row['session_id']] : ['', ''];
    }

    /* ------------------------------------------------------------------
     * Acquisti
     * ------------------------------------------------------------------ */

    /**
     * Invia gli acquisti non confermati dal browser dopo il ritardo configurato.
     *
     * @return array conteggi: sent, skipped, failed
     */
    public function runPending()
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        Configuration::updateGlobalValue(Dotmgtagmanager::CFG_LAST_RUN, date('Y-m-d H:i:s'));
        if (!self::isConfigured()) {
            return $result;
        }
        $delay = max(5, (int) Configuration::get(Dotmgtagmanager::CFG_SERVER_DELAY));
        foreach (DotmGtmOrderLog::getDue($delay) as $log) {
            $outcome = $this->sendPurchase($log, false);
            ++$result[$outcome === 'sent' ? 'sent' : ($outcome === 'failed' ? 'failed' : 'skipped')];
        }

        return $result;
    }

    /**
     * @param bool $force invio manuale dal back office (ignora pagamento non ancora confermato e consenso)
     *
     * @return string sent|skipped|failed
     */
    public function sendPurchase(DotmGtmOrderLog $log, $force)
    {
        $orders = $log->getOrders();
        if (!$orders) {
            return $this->finish($log, DotmGtmOrderLog::STATUS_EXPIRED, 'Ordine non trovato');
        }
        $first = $orders[0];
        $age = time() - strtotime($first->date_add);

        if (!$force) {
            if ($this->module->isPaymentExcluded($first->module)) {
                return $this->finish($log, DotmGtmOrderLog::STATUS_EXCLUDED, 'Metodo di pagamento escluso: ' . $first->module);
            }
            if (!$first->valid) {
                // non ancora pagato: si riprova finché l'ordine è recente
                return $age > self::MAX_BACKDATE
                    ? $this->finish($log, DotmGtmOrderLog::STATUS_EXPIRED, 'Ordine non pagato entro 72 ore')
                    : 'skipped';
            }
            if ($log->client_id === '' && Configuration::get(Dotmgtagmanager::CFG_SERVER_REQUIRE_CID)) {
                return $this->finish($log, DotmGtmOrderLog::STATUS_NO_CONSENT, 'Nessun cookie GA del cliente (consenso non dato o cookie bloccati)');
            }
        }

        $builder = new DotmGtmItemBuilder($this->module, (int) $first->id_lang, (int) $first->id_shop);
        $params = $builder->purchaseData($orders);
        if ($log->session_id !== '') {
            $params['session_id'] = $log->session_id;
        }
        $params['engagement_time_msec'] = 1;

        $payload = [
            'client_id' => $log->client_id !== '' ? $log->client_id : self::randomClientId(),
            'events' => [['name' => 'purchase', 'params' => $params]],
        ];
        if (Configuration::get(Dotmgtagmanager::CFG_USER_ID) && (int) $first->id_customer) {
            $customer = new Customer((int) $first->id_customer);
            if (Validate::isLoadedObject($customer) && !$customer->is_guest) {
                $payload['user_id'] = (string) (int) $customer->id;
            }
        }
        if ($age < self::MAX_BACKDATE - 600) {
            $payload['timestamp_micros'] = (string) (strtotime($first->date_add) * 1000000);
        }

        $response = $this->send($payload);
        $log->attempts = (int) $log->attempts + 1;
        if ($response['ok']) {
            $log->sent_at = date('Y-m-d H:i:s');

            return $this->finish($log, DotmGtmOrderLog::STATUS_SERVER, '');
        }
        $log->last_error = Tools::substr($response['error'], 0, 255);
        if ($log->attempts >= DotmGtmOrderLog::MAX_ATTEMPTS) {
            $log->status = DotmGtmOrderLog::STATUS_FAILED;
        }
        $log->update();

        return 'failed';
    }

    protected function finish(DotmGtmOrderLog $log, $status, $note)
    {
        $log->status = $status;
        $log->last_error = Tools::substr($note, 0, 255);
        $log->update();

        return $status === DotmGtmOrderLog::STATUS_SERVER ? 'sent' : 'skipped';
    }

    /* ------------------------------------------------------------------
     * Rimborsi
     * ------------------------------------------------------------------ */

    /**
     * Rimborso totale (ordine passato a uno stato di rimborso o annullamento).
     */
    public function refundFull(Order $order)
    {
        $log = DotmGtmOrderLog::getByCart((int) $order->id_cart);
        if (!$log || !$log->isPurchaseSent() || $log->refund_full) {
            return false;
        }
        $builder = new DotmGtmItemBuilder($this->module, (int) $order->id_lang, (int) $order->id_shop);
        $data = $builder->purchaseData($log->getOrders());
        $value = max(0, $data['value'] - (float) $log->refunded_amount);
        $params = [
            'transaction_id' => $data['transaction_id'],
            'currency' => $data['currency'],
            'value' => DotmGtmItemBuilder::round($value),
            'items' => $data['items'],
        ];
        if ($this->sendRefund($log, $params)) {
            $log->refund_full = 1;
            $log->refunded_amount = (float) $log->refunded_amount + $value;
            $log->update();

            return true;
        }

        return false;
    }

    /**
     * Rimborso parziale da nota di credito (prodotti e importi della nota).
     */
    public function refundSlip(Order $order, $idSlip)
    {
        $log = DotmGtmOrderLog::getByCart((int) $order->id_cart);
        if (!$log || !$log->isPurchaseSent() || $log->refund_full || $log->hasSlip($idSlip)) {
            return false;
        }
        $slip = new OrderSlip((int) $idSlip);
        if (!Validate::isLoadedObject($slip)) {
            return false;
        }
        $builder = new DotmGtmItemBuilder($this->module, (int) $order->id_lang, (int) $order->id_shop);
        $withTax = $builder->withTax();
        $lines = Db::getInstance()->executeS(
            'SELECT osd.`product_quantity`, osd.`unit_price_tax_incl`, osd.`unit_price_tax_excl`, od.`product_id`, od.`product_attribute_id`
            FROM `' . _DB_PREFIX_ . 'order_slip_detail` osd
            INNER JOIN `' . _DB_PREFIX_ . 'order_detail` od ON od.`id_order_detail` = osd.`id_order_detail`
            WHERE osd.`id_order_slip` = ' . (int) $slip->id
        );
        $items = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            $item = $builder->item(
                (int) $line['product_id'],
                (int) $line['product_attribute_id'],
                (int) $line['product_quantity'],
                $withTax ? (float) $line['unit_price_tax_incl'] : (float) $line['unit_price_tax_excl']
            );
            if ($item && (int) $line['product_quantity'] > 0) {
                $items[] = $item;
            }
        }
        $value = $withTax
            ? (float) $slip->total_products_tax_incl + (float) $slip->total_shipping_tax_incl
            : (float) $slip->total_products_tax_excl + (float) $slip->total_shipping_tax_excl;
        if ($value <= 0) {
            return false;
        }
        $params = [
            'transaction_id' => $order->reference,
            'currency' => (new Currency((int) $order->id_currency))->iso_code,
            'value' => DotmGtmItemBuilder::round($value),
        ];
        if ($items) {
            $params['items'] = $items;
        }
        if ($this->sendRefund($log, $params)) {
            $log->addSlip($slip->id);
            $log->refunded_amount = (float) $log->refunded_amount + $value;
            $log->update();

            return true;
        }

        return false;
    }

    protected function sendRefund(DotmGtmOrderLog $log, array $params)
    {
        if (!self::isConfigured()) {
            return false;
        }
        $params['engagement_time_msec'] = 1;
        $response = $this->send([
            'client_id' => $log->client_id !== '' ? $log->client_id : self::randomClientId(),
            'events' => [['name' => 'refund', 'params' => $params]],
        ]);
        if (!$response['ok']) {
            $log->last_error = Tools::substr('Rimborso: ' . $response['error'], 0, 255);
            $log->update();
        }

        return $response['ok'];
    }

    /* ------------------------------------------------------------------
     * HTTP
     * ------------------------------------------------------------------ */

    /**
     * @param bool $validate usa l'endpoint di validazione (non registra nulla in GA4)
     *
     * @return array ok, error, body
     */
    public function send(array $payload, $validate = false)
    {
        $host = Configuration::get(Dotmgtagmanager::CFG_SERVER_EU) ? 'https://region1.google-analytics.com' : 'https://www.google-analytics.com';
        $url = $host . ($validate ? '/debug/mp/collect' : '/mp/collect')
            . '?measurement_id=' . rawurlencode((string) Configuration::get(Dotmgtagmanager::CFG_MEASUREMENT_ID))
            . '&api_secret=' . rawurlencode(trim((string) Configuration::get(Dotmgtagmanager::CFG_API_SECRET)));
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $code = 0;
        $response = '';
        $error = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $response = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ]]);
            $response = (string) @file_get_contents($url, false, $context);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }

        $ok = $code >= 200 && $code < 300;
        if ($ok && $validate) {
            $decoded = json_decode($response, true);
            if (!empty($decoded['validationMessages'])) {
                $ok = false;
                $error = (string) $decoded['validationMessages'][0]['description'];
            }
        }
        if (!$ok && $error === '') {
            $error = 'HTTP ' . $code . ($response !== '' ? ': ' . Tools::substr(strip_tags($response), 0, 200) : '');
        }

        return ['ok' => $ok, 'error' => $error, 'body' => $response];
    }

    /**
     * Verifica le credenziali con l'endpoint di validazione di Google.
     *
     * @return array ok, error
     */
    public function test()
    {
        return $this->send([
            'client_id' => self::randomClientId(),
            'events' => [['name' => 'dotmgtm_test', 'params' => ['engagement_time_msec' => 1]]],
        ], true);
    }

    /**
     * ID client casuale nel formato di GA (solo se l'invio senza cookie è consentito, o per i rimborsi).
     */
    public static function randomClientId()
    {
        return random_int(100000000, 2147483647) . '.' . time();
    }
}
