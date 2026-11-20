<?php
/**
 * Articoli GA4 (items) e dati e-commerce: prodotti del catalogo, righe di carrello e righe d'ordine,
 * con lo stesso formato ovunque (ID, nome, marca, categorie, variante, prezzo).
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DotmGtmItemBuilder
{
    /** @var Dotmgtagmanager */
    protected $module;

    protected $idLang;

    protected $idShop;

    /** @var bool prezzi IVA inclusa */
    protected $withTax;

    protected $affiliation;

    /** @var array<int, array|null> */
    protected $products = [];

    /** @var array<int, array> */
    protected $categoryPaths = [];

    /** @var array<int, array> */
    protected $combinations = [];

    public function __construct(Dotmgtagmanager $module, $idLang, $idShop)
    {
        $this->module = $module;
        $this->idLang = (int) $idLang;
        $this->idShop = (int) $idShop;
        $this->withTax = (bool) Configuration::get(Dotmgtagmanager::CFG_PRICE_TAX);
        $this->affiliation = (string) Configuration::get('PS_SHOP_NAME', null, null, $this->idShop);
    }

    public function withTax()
    {
        return $this->withTax;
    }

    /**
     * Dati del prodotto che non dipendono dalla combinazione (usati anche dal JavaScript).
     *
     * @return array|null item_name, item_brand, item_category..item_category5, reference, ean13, upc
     */
    public function getProductInfo($idProduct)
    {
        $idProduct = (int) $idProduct;
        if (array_key_exists($idProduct, $this->products)) {
            return $this->products[$idProduct];
        }
        $row = Db::getInstance()->getRow(
            'SELECT p.`reference`, p.`ean13`, p.`upc`, p.`id_manufacturer`, ps.`id_category_default`, pl.`name`, m.`name` AS `brand`
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $this->idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product`
                AND pl.`id_lang` = ' . $this->idLang . ' AND pl.`id_shop` = ' . $this->idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
            WHERE p.`id_product` = ' . $idProduct
        );
        if (!$row) {
            return $this->products[$idProduct] = null;
        }
        $info = [
            'item_name' => (string) $row['name'],
            'item_brand' => (string) $row['brand'],
            'reference' => (string) $row['reference'],
            'ean13' => (string) $row['ean13'],
            'upc' => (string) $row['upc'],
        ];
        foreach ($this->getCategoryPath((int) $row['id_category_default']) as $i => $name) {
            $info[$i === 0 ? 'item_category' : 'item_category' . ($i + 1)] = $name;
        }

        return $this->products[$idProduct] = $info;
    }

    /**
     * Percorso della categoria predefinita, dalla più generale (massimo 5 livelli, senza radice e Home).
     */
    public function getCategoryPath($idCategory)
    {
        $idCategory = (int) $idCategory;
        if (isset($this->categoryPaths[$idCategory])) {
            return $this->categoryPaths[$idCategory];
        }
        $skip = [(int) Configuration::get('PS_ROOT_CATEGORY'), (int) Configuration::get('PS_HOME_CATEGORY')];
        $names = [];
        $rows = $idCategory ? Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`is_root_category`, cl.`name`
            FROM `' . _DB_PREFIX_ . 'category` t
            INNER JOIN `' . _DB_PREFIX_ . 'category` c ON c.`nleft` <= t.`nleft` AND c.`nright` >= t.`nright`
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category`
                AND cl.`id_lang` = ' . $this->idLang . ' AND cl.`id_shop` = ' . $this->idShop . '
            WHERE t.`id_category` = ' . $idCategory . '
            ORDER BY c.`level_depth` ASC'
        ) : [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!in_array((int) $row['id_category'], $skip, true) && !(int) $row['is_root_category'] && (string) $row['name'] !== '') {
                $names[] = (string) $row['name'];
            }
        }
        $names = array_slice($names, 0, 5);

        return $this->categoryPaths[$idCategory] = $names;
    }

    /**
     * @return array variant (valori degli attributi), reference, ean13, upc della combinazione
     */
    public function getCombination($idProductAttribute)
    {
        $ipa = (int) $idProductAttribute;
        if (!$ipa) {
            return ['variant' => '', 'reference' => '', 'ean13' => '', 'upc' => ''];
        }
        if (isset($this->combinations[$ipa])) {
            return $this->combinations[$ipa];
        }
        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT `reference`, `ean13`, `upc` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $ipa
        );
        $values = $db->executeS(
            'SELECT al.`name`
            FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $this->idLang . '
            WHERE pac.`id_product_attribute` = ' . $ipa . '
            ORDER BY ag.`position` ASC, a.`position` ASC'
        );

        return $this->combinations[$ipa] = [
            'variant' => implode(', ', array_filter(array_column(is_array($values) ? $values : [], 'name'), 'strlen')),
            'reference' => $row ? (string) $row['reference'] : '',
            'ean13' => $row ? (string) $row['ean13'] : '',
            'upc' => $row ? (string) $row['upc'] : '',
        ];
    }

    /**
     * ID articolo secondo la configurazione (deve coincidere con il feed di Merchant Center).
     */
    public function formatId($idProduct, $idProductAttribute, array $info = null, array $combination = null)
    {
        $format = (string) Configuration::get(Dotmgtagmanager::CFG_ID_FORMAT);
        $separator = (string) Configuration::get(Dotmgtagmanager::CFG_ID_SEPARATOR);
        $prefix = (string) Configuration::get(Dotmgtagmanager::CFG_ID_PREFIX);
        $info = $info ?: (array) $this->getProductInfo($idProduct);
        $combination = $combination ?: $this->getCombination($idProductAttribute);

        switch ($format) {
            case 'id_ipa':
                $id = (int) $idProductAttribute ? $idProduct . $separator . (int) $idProductAttribute : (string) (int) $idProduct;
                break;
            case 'reference':
            case 'ean13':
            case 'upc':
                $id = $combination[$format] !== '' ? $combination[$format] : (isset($info[$format]) ? $info[$format] : '');
                if ($id === '') {
                    $id = (string) (int) $idProduct;
                }
                break;
            default:
                $id = (string) (int) $idProduct;
        }

        return $prefix . $id;
    }

    /**
     * Prezzo unitario attuale del catalogo, nella valuta del contesto.
     *
     * @return array [prezzo scontato, prezzo pieno]
     */
    public function getCatalogPrice($idProduct, $idProductAttribute)
    {
        $price = (float) Product::getPriceStatic((int) $idProduct, $this->withTax, (int) $idProductAttribute ?: null, 6);
        $regular = (float) Product::getPriceStatic((int) $idProduct, $this->withTax, (int) $idProductAttribute ?: null, 6, null, false, false);

        return [$price, max($price, $regular)];
    }

    /**
     * Articolo GA4.
     *
     * @param float|null $price prezzo unitario (null = prezzo attuale del catalogo)
     * @param array $extra campi aggiuntivi (index, item_list_id, item_list_name, coupon…)
     *
     * @return array|null
     */
    public function item($idProduct, $idProductAttribute = 0, $quantity = 1, $price = null, array $extra = [])
    {
        $info = $this->getProductInfo($idProduct);
        if (!$info) {
            return null;
        }
        $combination = $this->getCombination($idProductAttribute);
        $regular = null;
        if ($price === null) {
            list($price, $regular) = $this->getCatalogPrice($idProduct, $idProductAttribute);
        }

        $item = [
            'item_id' => $this->formatId($idProduct, $idProductAttribute, $info, $combination),
            'item_name' => $info['item_name'],
            'affiliation' => $this->affiliation,
        ];
        if ($info['item_brand'] !== '') {
            $item['item_brand'] = $info['item_brand'];
        }
        foreach (['item_category', 'item_category2', 'item_category3', 'item_category4', 'item_category5'] as $key) {
            if (isset($info[$key])) {
                $item[$key] = $info[$key];
            }
        }
        if ($combination['variant'] !== '') {
            $item['item_variant'] = $combination['variant'];
        }
        $item['price'] = self::round($price);
        if ($regular !== null && $regular - $price >= 0.01) {
            $item['discount'] = self::round($regular - $price);
        }
        $item['quantity'] = max(1, (int) $quantity);
        $item['google_business_vertical'] = 'retail';

        return array_merge($item, $extra);
    }

    /**
     * Articoli del carrello (prezzi del carrello, già scontati).
     */
    public function cartItems(Cart $cart)
    {
        $items = [];
        foreach ($cart->getProducts() as $i => $p) {
            $price = $this->withTax
                ? (isset($p['price_with_reduction']) ? $p['price_with_reduction'] : $p['price_wt'])
                : (isset($p['price_with_reduction_without_tax']) ? $p['price_with_reduction_without_tax'] : $p['price']);
            $item = $this->item((int) $p['id_product'], (int) $p['id_product_attribute'], (int) $p['cart_quantity'], (float) $price, ['index' => $i]);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Totali del carrello: valore dei prodotti scontati, codici sconto.
     *
     * @return array value, coupon, items
     */
    public function cartData(Cart $cart)
    {
        $items = $this->cartItems($cart);
        $value = (float) $cart->getOrderTotal($this->withTax, Cart::ONLY_PRODUCTS)
            - (float) $cart->getOrderTotal($this->withTax, Cart::ONLY_DISCOUNTS);
        $coupons = [];
        foreach ($cart->getCartRules() as $rule) {
            $coupons[] = !empty($rule['code']) ? $rule['code'] : $rule['name'];
        }

        return [
            'value' => self::round(max(0, $value)),
            'coupon' => implode(',', $coupons),
            'items' => $items,
        ];
    }

    /**
     * Dati del "purchase" per gli ordini di uno stesso carrello (ordini divisi sommati).
     *
     * @param Order[] $orders
     */
    public function purchaseData(array $orders)
    {
        $first = reset($orders);
        $currency = new Currency((int) $first->id_currency);
        $productsTi = $productsTe = $discountsTi = $discountsTe = $shippingTi = $shippingTe = $paidTi = $paidTe = 0.0;
        $items = [];
        $coupons = [];
        $index = 0;

        foreach ($orders as $order) {
            $productsTi += (float) $order->total_products_wt;
            $productsTe += (float) $order->total_products;
            $discountsTi += (float) $order->total_discounts_tax_incl;
            $discountsTe += (float) $order->total_discounts_tax_excl;
            $shippingTi += (float) $order->total_shipping_tax_incl;
            $shippingTe += (float) $order->total_shipping_tax_excl;
            $paidTi += (float) $order->total_paid_tax_incl;
            $paidTe += (float) $order->total_paid_tax_excl;

            foreach ($order->getProductsDetail() as $line) {
                $price = $this->withTax ? (float) $line['unit_price_tax_incl'] : (float) $line['unit_price_tax_excl'];
                $item = $this->item((int) $line['product_id'], (int) $line['product_attribute_id'], (int) $line['product_quantity'], $price, ['index' => $index++]);
                if (!$item) {
                    // prodotto eliminato dal catalogo: dati della riga d'ordine
                    $item = [
                        'item_id' => (string) Configuration::get(Dotmgtagmanager::CFG_ID_PREFIX) . (int) $line['product_id'],
                        'item_name' => (string) $line['product_name'],
                        'price' => self::round($price),
                        'quantity' => (int) $line['product_quantity'],
                        'index' => $index - 1,
                    ];
                }
                $items[] = $item;
            }
            foreach ($order->getCartRules() as $rule) {
                $cartRule = new CartRule((int) $rule['id_cart_rule']);
                $coupons[] = Validate::isLoadedObject($cartRule) && $cartRule->code !== '' ? $cartRule->code : $rule['name'];
            }
        }

        $products = $this->withTax ? $productsTi - $discountsTi : $productsTe - $discountsTe;
        $shipping = $this->withTax ? $shippingTi : $shippingTe;
        $value = Configuration::get(Dotmgtagmanager::CFG_VALUE_MODE) === 'total'
            ? ($this->withTax ? $paidTi : $paidTe)
            : $products;

        $data = [
            'transaction_id' => $first->reference,
            'affiliation' => $this->affiliation,
            'value' => self::round(max(0, $value)),
            'tax' => self::round(max(0, $paidTi - $paidTe)),
            'shipping' => self::round($shipping),
            'currency' => $currency->iso_code,
            'items' => $items,
        ];
        $coupons = array_values(array_unique(array_filter($coupons, 'strlen')));
        if ($coupons) {
            $data['coupon'] = implode(',', $coupons);
        }

        return $data;
    }

    /**
     * Elenco di prodotti (pagine categoria, ricerca, marca…) per view_item_list.
     *
     * @param array $products righe con id_product e id_product_attribute
     * @param array|null $keys riempito con "id_prodotto-id_combinazione" di ogni articolo (per il clic in pagina)
     */
    public function listItems(array $products, $listId, $listName, $max, &$keys = null)
    {
        $items = [];
        $keys = [];
        foreach (array_slice(array_values($products), 0, max(1, (int) $max)) as $i => $p) {
            $idProduct = (int) (isset($p['id_product']) ? $p['id_product'] : (isset($p['id']) ? $p['id'] : 0));
            $ipa = (int) (isset($p['id_product_attribute']) ? $p['id_product_attribute'] : 0);
            $item = $idProduct ? $this->item($idProduct, $ipa, 1, null, ['index' => $i, 'item_list_id' => $listId, 'item_list_name' => $listName]) : null;
            if ($item) {
                $items[] = $item;
                $keys[] = $idProduct . '-' . $ipa;
            }
        }

        return $items;
    }

    /**
     * Dati di prodotto per il JavaScript, indicizzati per id_product.
     *
     * @param int[] $ids
     */
    public function productMap(array $ids)
    {
        $map = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $info = $this->getProductInfo($id);
            if ($info) {
                $map[$id] = $info;
            }
        }

        return $map;
    }

    public static function round($value)
    {
        return round((float) $value, 2);
    }
}
