/**
 * dotmgtagmanager — eventi GA4 che nascono nel browser:
 * add_to_cart / remove_from_cart (confronto del carrello prima e dopo ogni aggiornamento),
 * select_item (clic su un prodotto in un elenco), view_item_list per gli elenchi caricati in AJAX,
 * add_shipping_info e add_payment_info nel checkout standard.
 *
 * API per temi e moduli di checkout (es. one page checkout):
 *   dotmgtm.push('evento', { ...ecommerce }, { ...altri parametri })
 *   dotmgtm.addShippingInfo('Corriere espresso')
 *   dotmgtm.addPaymentInfo('Carta di credito')
 *   dotmgtm.syncCart(carrello)   // carrello nel formato di prestashop.cart
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
(function () {
  'use strict';

  var dl = (window.dataLayer = window.dataLayer || []);
  var log = (window.dotmgtmLog = window.dotmgtmLog || []);

  function cfg() {
    return window.dotmgtmConfig || { currency: '', withTax: true, id: { format: 'id', separator: '-', prefix: '' }, products: {}, events: [] };
  }

  function round(n) {
    return Math.round((parseFloat(n) || 0) * 100) / 100;
  }

  function storage(key, value) {
    try {
      if (value === undefined) {
        return JSON.parse(window.sessionStorage.getItem(key) || 'null');
      }
      window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      return null;
    }
    return null;
  }

  /* ---------------------------------------------------------------- push */

  function push(name, ecommerce, extra) {
    var event = { event: name };
    if (ecommerce) {
      if (!ecommerce.currency) {
        ecommerce.currency = cfg().currency;
      }
      event.ecommerce = ecommerce;
      dl.push({ ecommerce: null });
    }
    Object.keys(extra || {}).forEach(function (k) {
      event[k] = extra[k];
    });
    dl.push(event);
    log.push(event);
    debugRender();
    return event;
  }

  /* ---------------------------------------------------------- articoli */

  function formatId(p, info) {
    var id = cfg().id;
    var idProduct = parseInt(p.id_product, 10) || 0;
    var ipa = parseInt(p.id_product_attribute, 10) || 0;
    var value = String(idProduct);
    if (id.format === 'id_ipa' && ipa) {
      value = idProduct + id.separator + ipa;
    } else if (id.format === 'reference' || id.format === 'ean13' || id.format === 'upc') {
      value = p[id.format] || (info && info[id.format]) || String(idProduct);
    }
    return (id.prefix || '') + value;
  }

  function variantOf(p) {
    if (p.attributes && typeof p.attributes === 'object') {
      return Object.keys(p.attributes).map(function (k) {
        return String(p.attributes[k]).trim();
      }).filter(Boolean).join(', ');
    }
    return typeof p.attributes_small === 'string' ? p.attributes_small : '';
  }

  /**
   * Articolo GA4 da un prodotto del carrello di PrestaShop (prestashop.cart.products).
   */
  function itemFromCartProduct(p, quantity) {
    var info = cfg().products[p.id_product] || {};
    var withTax = cfg().withTax;
    var price = withTax
      ? (p.price_with_reduction !== undefined ? p.price_with_reduction : (p.price_wt !== undefined ? p.price_wt : p.price_amount))
      : (p.price_with_reduction_without_tax !== undefined ? p.price_with_reduction_without_tax : p.price);
    var item = {
      item_id: formatId(p, info),
      item_name: info.item_name || p.name || '',
      affiliation: cfg().affiliation || undefined,
      price: round(price),
      quantity: quantity
    };
    var brand = info.item_brand || p.manufacturer_name;
    if (brand) {
      item.item_brand = brand;
    }
    ['item_category', 'item_category2', 'item_category3', 'item_category4', 'item_category5'].forEach(function (k) {
      if (info[k]) {
        item[k] = info[k];
      }
    });
    if (!item.item_category && p.category_name) {
      item.item_category = p.category_name;
    }
    var variant = variantOf(p);
    if (variant) {
      item.item_variant = variant;
    }
    var list = storage('dgtm_list_' + p.id_product);
    if (list && list.id) {
      item.item_list_id = list.id;
      item.item_list_name = list.name;
    }
    item.google_business_vertical = 'retail';
    if (!item.affiliation) {
      delete item.affiliation;
    }
    return item;
  }

  /* ---------------------------------------------------------- carrello */

  var cartLines = null;

  function lineKey(p) {
    return [p.id_product, p.id_product_attribute || 0, p.id_customization || 0].join('-');
  }

  function linesOf(cart) {
    var lines = {};
    ((cart && cart.products) || []).forEach(function (p) {
      lines[lineKey(p)] = { product: p, qty: parseInt(p.quantity !== undefined ? p.quantity : p.cart_quantity, 10) || 0 };
    });
    return lines;
  }

  /**
   * Confronta il carrello con l'ultimo noto e invia add_to_cart / remove_from_cart per le differenze.
   */
  function syncCart(cart) {
    if (!cart || !cart.products) {
      return;
    }
    var next = linesOf(cart);
    if (cartLines === null) {
      cartLines = next;
      return;
    }
    var added = [];
    var removed = [];
    Object.keys(next).forEach(function (k) {
      var before = cartLines[k] ? cartLines[k].qty : 0;
      if (next[k].qty > before) {
        added.push(itemFromCartProduct(next[k].product, next[k].qty - before));
      } else if (next[k].qty < before) {
        removed.push(itemFromCartProduct(next[k].product, before - next[k].qty));
      }
    });
    Object.keys(cartLines).forEach(function (k) {
      if (!next[k] && cartLines[k].qty > 0) {
        removed.push(itemFromCartProduct(cartLines[k].product, cartLines[k].qty));
      }
    });
    cartLines = next;

    function value(items) {
      return round(items.reduce(function (sum, it) {
        return sum + it.price * it.quantity;
      }, 0));
    }
    if (added.length) {
      push('add_to_cart', { value: value(added), items: added });
    }
    if (removed.length) {
      push('remove_from_cart', { value: value(removed), items: removed });
    }
  }

  /* --------------------------------------------------- elenchi prodotti */

  function onListClick(event) {
    var link = event.target.closest ? event.target.closest('a') : null;
    var tile = link && link.closest ? link.closest('[data-id-product]') : null;
    var list = cfg().list;
    if (!tile || !list || !list.items) {
      return;
    }
    var idProduct = tile.getAttribute('data-id-product');
    var ipa = tile.getAttribute('data-id-product-attribute') || '0';
    var keys = list.keys || [];
    var pos = keys.indexOf(idProduct + '-' + ipa);
    for (var i = 0; pos === -1 && i < keys.length; i++) {
      if (keys[i].split('-')[0] === idProduct) {
        pos = i;
      }
    }
    if (pos === -1 || !list.items[pos]) {
      return;
    }
    // copia: gli oggetti già nel dataLayer non vanno modificati
    var item = JSON.parse(JSON.stringify(list.items[pos]));
    storage('dgtm_list_' + idProduct, { id: list.id, name: list.name });
    push('select_item', { item_list_id: list.id, item_list_name: list.name, items: [item] });
  }

  /**
   * Gli elenchi caricati senza ricaricare la pagina (filtri, ordinamento, pagine) vengono chiesti al modulo.
   */
  function onProductList(data) {
    var list = cfg().list;
    var products = (data && data.products) || [];
    if (!list || !products.length || !cfg().api || !window.fetch) {
      return;
    }
    var params = new URLSearchParams();
    params.append('action', 'items');
    params.append('list_id', list.id);
    params.append('list_name', list.name);
    products.forEach(function (p) {
      params.append('ids[]', (p.id_product || p.id) + '-' + (p.id_product_attribute || 0));
    });
    var url = cfg().api + (cfg().api.indexOf('?') === -1 ? '?' : '&') + params.toString();
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.items || !json.items.length) {
          return;
        }
        Object.keys(json.products || {}).forEach(function (id) {
          cfg().products[id] = json.products[id];
        });
        list.items = JSON.parse(JSON.stringify(json.items));
        list.keys = json.keys || [];
        push('view_item_list', { item_list_id: list.id, item_list_name: list.name, items: json.items });
      })
      .catch(function () {});
  }

  /* ---------------------------------------------------------- checkout */

  var shippingSent = false;
  var paymentSent = false;

  function checkoutData(extra) {
    var c = cfg().checkout;
    if (!c) {
      return null;
    }
    var data = { currency: c.currency, value: c.value, items: c.items };
    if (c.coupon) {
      data.coupon = c.coupon;
    }
    Object.keys(extra || {}).forEach(function (k) {
      data[k] = extra[k];
    });
    return data;
  }

  function addShippingInfo(tier) {
    var data = checkoutData({ shipping_tier: tier || '' });
    if (data && !shippingSent) {
      shippingSent = true;
      push('add_shipping_info', data);
    }
  }

  function addPaymentInfo(type) {
    var data = checkoutData({ payment_type: type || '' });
    if (data && !paymentSent) {
      paymentSent = true;
      push('add_payment_info', data);
    }
  }

  function textOf(el) {
    return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : '';
  }

  function onCheckoutClick(event) {
    var target = event.target;
    if (!target.closest) {
      return;
    }
    if (target.closest('[name="confirmDeliveryOption"]')) {
      var carrier = document.querySelector('.delivery-option input:checked, input[name^="delivery_option"]:checked');
      var row = carrier && carrier.closest ? carrier.closest('.delivery-option, .row') : null;
      addShippingInfo(textOf(row && row.querySelector('.carrier-name, .h6')) || (carrier ? carrier.value : ''));
    }
    if (target.closest('#payment-confirmation button, #payment-confirmation [type="submit"]')) {
      var option = document.querySelector('input[name="payment-option"]:checked');
      var label = option ? document.querySelector('label[for="' + option.id + '"]') : null;
      addPaymentInfo((option && option.getAttribute('data-module-name')) || textOf(label));
    }
  }

  /* ------------------------------------------------------------- debug */

  var panel = null;

  function debugRender() {
    if (!cfg().debug || !document.body) {
      return;
    }
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'dotmgtm-debug';
      panel.setAttribute('style', 'position:fixed;left:12px;bottom:12px;z-index:2147483647;width:380px;max-width:calc(100% - 24px);max-height:45vh;overflow:auto;padding:10px 12px;border-radius:10px;background:#1d1d1d;color:#eee;font:12px/1.45 ui-monospace,Menlo,Consolas,monospace;box-shadow:0 8px 24px rgba(0,0,0,.35)');
      document.body.appendChild(panel);
    }
    var html = '<strong style="color:#ffc8a0">dotmgtagmanager · dataLayer</strong>';
    log.slice().reverse().forEach(function (e) {
      var json = JSON.stringify(e, function (k, v) {
        return typeof v === 'function' ? undefined : v;
      }, 1);
      html += '<details style="margin-top:6px"><summary style="cursor:pointer;color:#fff">' +
        String(e.event || 'push').replace(/</g, '&lt;') + '</summary><pre style="white-space:pre-wrap;margin:4px 0 0;color:#bbb">' +
        json.replace(/</g, '&lt;') + '</pre></details>';
    });
    panel.innerHTML = html;
  }

  /* ------------------------------------------------------------ avvio */

  function start() {
    debugRender();

    var ps = window.prestashop;
    if (ps && ps.cart) {
      cartLines = linesOf(ps.cart);
    }
    if (ps && typeof ps.on === 'function') {
      ps.on('updateCart', function (e) {
        if (e && e.resp && e.resp.cart) {
          syncCart(e.resp.cart);
        }
      });
      ps.on('updatedCart', function (e) {
        if (e && e.resp && e.resp.cart) {
          syncCart(e.resp.cart);
        }
      });
      ps.on('updateProductList', onProductList);
    }
    document.addEventListener('click', onListClick, true);
    document.addEventListener('click', onCheckoutClick, true);
  }

  window.dotmgtm = {
    push: push,
    syncCart: syncCart,
    addShippingInfo: addShippingInfo,
    addPaymentInfo: addPaymentInfo,
    item: itemFromCartProduct
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
