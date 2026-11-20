<p align="center"><img src="logo.svg" width="96" alt="dotmgtagmanager"></p>

# dotmgtagmanager — Google Tag Manager e GA4 per PrestaShop

Modulo PrestaShop che inserisce **Google Tag Manager** con **Consent Mode v2** e invia al dataLayer gli **eventi e-commerce di GA4**, dalla visualizzazione di un elenco fino all'acquisto. Gli acquisti che il browser non riesce a inviare (pagamenti esterni, ad-blocker, chiusura della pagina) e i **rimborsi** partono **dal server** con il Measurement Protocol di GA4, usando l'ID GA del cliente. Include un **contenitore GTM pronto da importare**.

> *English summary: PrestaShop module for Google Tag Manager with Consent Mode v2 and the full GA4 e-commerce event set (view_item_list → purchase, refunds). Purchases the browser could not send and refunds are sent server-side through the GA4 Measurement Protocol, with the customer's own GA client ID. Enhanced conversions with SHA-256 hashed user data, a ready-to-import GTM container, a public JavaScript API for custom checkouts, an on-site debug panel and a back-office purchase log. Back office in Italian.*

## Funzionalità

- **Contenitore GTM** in `<head>` e `<noscript>` dopo `<body>`. URL dello script personalizzabile per il tracciamento server-side. Si può non caricare lo script se GTM è già inserito dal tema: il dataLayer viene popolato comunque.
- **Consent Mode v2**: il modulo invia `gtag('consent','default', …)` prima di GTM, con valori predefiniti configurabili.
  - Di norma il consenso è «negato» solo nello Spazio economico europeo, nel Regno Unito e in Svizzera, e «concesso» altrove.
  - Supporta `wait_for_update`, `ads_data_redaction` e `url_passthrough`.
  - L'aggiornamento arriva dal banner dei cookie (Cookiebot, iubenda, CookieYes…).
- **Eventi GA4**:

  | Evento | Quando |
  |---|---|
  | `view_item_list` | categorie, ricerca, marche, fornitori, novità, offerte, più venduti; anche dopo filtri e paginazione in AJAX |
  | `select_item` | clic su un prodotto in un elenco (l'elenco viene ricordato fino all'aggiunta al carrello) |
  | `view_item` | scheda prodotto, con la combinazione selezionata |
  | `add_to_cart` / `remove_from_cart` | ogni variazione del carrello, ovunque avvenga (scheda, anteprima, carrello) |
  | `view_cart`, `begin_checkout` | carrello e checkout (`begin_checkout` una volta per carrello) |
  | `add_shipping_info`, `add_payment_info` | scelta del corriere e del pagamento nel checkout standard |
  | `purchase` | pagina di conferma: valore, IVA, spedizione, coupon, articoli, `new_customer` |
  | `refund` | dal server: totale (stati configurati) e parziale (note di credito) |
  | `search`, `login`, `sign_up`, `generate_lead`, `add_to_wishlist` | ricerca, accesso, registrazione, modulo di contatto, lista desideri di PrestaShop |

- **Articoli completi e coerenti** in tutti gli eventi:
  - ID nel formato del feed di Merchant Center: ID prodotto, prodotto-combinazione, riferimento, EAN o UPC, con prefisso facoltativo;
  - nome, marca, fino a 5 livelli di categoria, variante, prezzo (IVA inclusa o esclusa), sconto, `item_list_id`/`item_list_name`;
  - `google_business_vertical` per il remarketing dinamico.
- **Pagina e utente** nel dataLayer: `page_type`, lingua, valuta, cliente loggato, gruppo e `user_id` (facoltativo).
- **Conversioni avanzate**: email, telefono (E.164) e indirizzo del cliente nel `purchase`, normalizzati e **cifrati con SHA-256** come richiesto da Google (oppure in chiaro, o disattivati).
- **Invio dal server (Measurement Protocol)**:
  - durante il checkout il modulo salva l'ID client e l'ID di sessione GA del cliente (cookie `_ga`);
  - quando GTM elabora il `purchase` nella pagina di conferma, il browser lo conferma al modulo;
  - se dopo il ritardo configurato (30 minuti) un ordine **pagato** non è confermato, il modulo lo invia dal server con l'ID del cliente, retrodatato all'ora dell'ordine;
  - niente doppioni: un acquisto inviato dal browser non viene ripetuto, nemmeno ricaricando la pagina di conferma;
  - di norma l'invio dal server avviene solo se il cliente aveva il cookie GA, cioè aveva dato il consenso alle statistiche;
  - si possono escludere metodi di pagamento.
- **Rimborsi dal server**:
  - totale quando l'ordine passa agli stati configurati (Rimborsato, Annullato);
  - parziale con i prodotti di ogni nota di credito;
  - senza doppioni e senza superare il valore dell'acquisto.
- **Contenitore GTM pronto da importare** (dalla configurazione del modulo):
  - Google tag GA4 con `user_id`;
  - un tag evento GA4 per tutti gli eventi, con i dati e-commerce del dataLayer;
  - variabili e attivatori già collegati;
  - se indicati, linker e conversione acquisto di Google Ads.
- **API JavaScript** per temi e checkout personalizzati (`window.dotmgtm`) e **hook PHP** `actionDotmgtagmanagerDataLayer` per modificare i dati inviati.
- **Debug**:
  - pannello sul sito con gli eventi inviati, visibile solo agli IP indicati;
  - verifica delle credenziali GA4 con l'endpoint di validazione di Google;
  - *Ordini → Tracciamento GA4*: stato di ogni acquisto (dal browser, dal server, in attesa, escluso, senza cookie GA), importi rimborsati, invio manuale dal server ed esportazione CSV.

## Requisiti

- PrestaShop **1.7.7** o successivo (provato su 9.1)
- PHP 7.2 o successivo, con cURL (consigliato) per l'invio dal server
- Un contenitore Google Tag Manager; per l'invio dal server, una proprietà GA4 con un «API secret» del Measurement Protocol

## Installazione

1. Scarica la repo (*Code → Download ZIP*, oppure una release).
2. La cartella del modulo **deve chiamarsi `dotmgtagmanager`**. Lo ZIP di GitHub contiene la cartella `ps_gtagmanager-main`: rinominala e ricrea lo ZIP:
   ```sh
   unzip ps_gtagmanager-main.zip && mv ps_gtagmanager-main dotmgtagmanager
   zip -r dotmgtagmanager.zip dotmgtagmanager -x '*.git*'
   ```
3. In back office: *Moduli → Gestione moduli → Carica un modulo* e scegli `dotmgtagmanager.zip`.
   In alternativa copia la cartella in `modules/dotmgtagmanager/` sul server e installa il modulo dal Gestore moduli.
4. Apri *Configura* e inserisci almeno l'**ID del contenitore GTM**: senza, il modulo non inserisce nulla nel sito.

L'installazione:
- crea le tabelle `ps_dotmgtagmanager_order` (registro degli acquisti) e `ps_dotmgtagmanager_cart` (ID GA dei carrelli);
- aggiunge la voce *Ordini → Tracciamento GA4*;
- aggancia il modulo a `displayHeader`, `displayAfterBodyOpeningTag`, `displayBeforeBodyClosingTag`, `actionFrontControllerSetMedia`, `actionValidateOrder`, `actionOrderStatusPostUpdate`, `actionOrderSlipAdd`, `actionAuthentication`, `actionCustomerAccountAdd`, `actionWishlistAddProduct`.

## Configurazione

| Impostazione | Predefinito | Note |
|---|---|---|
| ID contenitore | — | `GTM-XXXXXXX`. Obbligatorio. |
| Carica lo script GTM | sì | No solo se GTM è già inserito altrove. |
| URL dello script | `https://www.googletagmanager.com/gtm.js` | Per il server-side: `https://sgtm.tuonegozio.it/gtm.js`. |
| Consenso predefinito | sì, solo SEE/UK/CH, tutto negato | Statistiche e pubblicità «concesse in partenza» configurabili. |
| Attesa del banner | 500 ms | `wait_for_update`. |
| ID articolo | ID prodotto | Deve coincidere con il feed di Merchant Center. |
| Prezzi IVA inclusa | sì | Vale per articoli e valori. |
| Valore dell'acquisto | prodotti scontati | Oppure totale dell'ordine; `shipping` e `tax` sono sempre inviati a parte. |
| Prodotti negli elenchi | 30 | Massimo di articoli in `view_item_list`. |
| user_id | sì | Solo clienti registrati e loggati. |
| Conversioni avanzate | cifrate SHA-256 | Oppure in chiaro o disattivate. |
| ID di misurazione / API secret | — | Per l'invio dal server e per il contenitore da importare. |
| Ritardo | 30 minuti | Attesa della conferma dal browser. |
| Solo con il cookie GA del cliente | sì | Rispetta il consenso alle statistiche. |
| Stati di rimborso totale | Rimborsato, Annullato | |
| Pagamenti esclusi | nessuno | Mai inviati dal server. |
| Invio con le visite al sito | sì | Alternativa al cron, al massimo ogni 10 minuti. |
| Google Ads | — | ID e etichetta di conversione, solo per il contenitore da importare. |
| Pannello di debug | no | Limitabile a uno o più IP. |

## Dopo l'installazione

1. **Contenitore GTM**: in *Configura → Scarica il contenitore GTM (JSON)*, poi in GTM *Amministrazione → Importa contenitore*, area di lavoro esistente, opzione **Unisci**. Controlla l'anteprima e pubblica.
2. **Banner dei cookie**: deve inviare l'aggiornamento di Consent Mode v2 (`gtag('consent','update', …)`), cosa che i principali banner fanno già. Se il banner imposta da solo anche il valore predefinito, disattiva «Imposta il consenso predefinito».
3. **Invio dal server**:
   - in GA4 apri *Amministrazione → Stream di dati → il tuo stream → Measurement Protocol API secret → Crea*;
   - inserisci ID di misurazione e secret nel modulo e premi **Verifica credenziali GA4**;
   - configura il cron (ogni 15 minuti) con il comando mostrato nella configurazione.
4. **Prova**: attiva il pannello di debug per il tuo IP e fai un ordine di prova. In *Ordini → Tracciamento GA4* l'acquisto deve risultare «Dal browser». In GA4 controlla *DebugView* o i rapporti in tempo reale.

## Personalizzazione

Il modulo non contiene nulla di specifico per un negozio.

- **Checkout personalizzati** (one page checkout e simili): se il modulo di checkout non usa i pulsanti standard di PrestaShop, richiama l'API dal tema o dal modulo:
  ```js
  dotmgtm.addShippingInfo('Corriere espresso');
  dotmgtm.addPaymentInfo('Carta di credito');
  dotmgtm.syncCart(nuovoCarrello);              // carrello nel formato di prestashop.cart
  dotmgtm.push('evento', { /* ecommerce */ }, { /* altri parametri */ });
  ```
  Il carrello è seguito con gli eventi `updateCart` e `updatedCart` di PrestaShop; gli elenchi in AJAX con `updateProductList`.
- **Modificare i dati inviati**: un modulo può agganciarsi a `actionDotmgtagmanagerDataLayer`, con parametri `name` (nome dell'evento, oppure `page` per le informazioni di pagina) e `data` (per riferimento). Se `data` viene impostato a `null`, l'evento non viene inviato.
- **Testi**: i nomi degli elenchi (`item_list_name`) sono in `lang/it.php` e `lang/en.php`. Per un'altra lingua aggiungi `lang/<iso>.php`.

## Struttura

```
dotmgtagmanager.php                     classe del modulo: installazione, configurazione, hook, coda eventi
classes/DotmGtmDataLayer.php            codice nelle pagine: consenso, pagina/utente, GTM, eventi per tipo di pagina, user_data
classes/DotmGtmItemBuilder.php          articoli GA4 e dati di carrello, acquisto ed elenchi
classes/DotmGtmServer.php               Measurement Protocol: acquisti non confermati, rimborsi, ID GA del cliente
classes/DotmGtmOrderLog.php             registro degli acquisti (tabella ps_dotmgtagmanager_order)
classes/DotmGtmContainer.php            contenitore GTM da importare
controllers/front/api.php               conferma del purchase dal browser, articoli per gli elenchi in AJAX
controllers/front/cron.php              invio dal server programmato
controllers/admin/AdminDotmGtmOrderController.php   Ordini → Tracciamento GA4
views/js/front.js                       eventi del browser, API dotmgtm, pannello di debug
lang/                                   nomi degli elenchi (it, en)
upgrade/                                script di aggiornamento tra versioni
```

## Aggiornamento e disinstallazione

- Per aggiornare, sostituisci i file del modulo e avvia l'aggiornamento dal Gestore moduli: gli script in `upgrade/` aggiornano database e configurazione.
- Disinstallare il modulo cancella le impostazioni e il registro degli acquisti. Le statistiche già inviate a GA4 non vengono toccate.

## Note sulla privacy

- Con il consenso predefinito attivo, nell'area europea nulla viene memorizzato finché il cliente non accetta dal banner. I tag Google lavorano in modalità senza cookie, come previsto da Consent Mode v2.
- I dati per le conversioni avanzate sono inviati cifrati (SHA-256) e solo nell'evento `purchase`.
- L'invio dal server avviene di norma solo per i clienti che avevano il cookie GA, cioè che avevano accettato le statistiche.

Verifica comunque l'informativa privacy e la configurazione del banner con il tuo consulente.

## Licenza

Rilasciato con licenza [MIT](LICENSE).

## Autore

Sviluppato da [dotm.it](https://dotm.it).
