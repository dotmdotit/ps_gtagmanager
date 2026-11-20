<?php
/**
 * Invio dal server degli acquisti non confermati dal browser: da chiamare ogni 15 minuti con il token.
 * Esempio: *\/15 * * * * curl -s "https://negozio.it/module/dotmgtagmanager/cron?token=..."
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DotmgtagmanagerCronModuleFrontController extends ModuleFrontController
{
    /** @var Dotmgtagmanager */
    public $module;

    public function init()
    {
        parent::init();

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        $token = (string) Configuration::getGlobalValue(Dotmgtagmanager::CFG_CRON_TOKEN);
        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            http_response_code(403);
            echo "invalid token\n";
            exit;
        }
        if (!DotmGtmServer::isConfigured()) {
            echo "server sending not configured\n";
            exit;
        }
        $r = (new DotmGtmServer($this->module))->runPending();
        echo 'ok: ' . $r['sent'] . ' sent, ' . $r['skipped'] . ' skipped, ' . $r['failed'] . " failed\n";
        exit;
    }
}
