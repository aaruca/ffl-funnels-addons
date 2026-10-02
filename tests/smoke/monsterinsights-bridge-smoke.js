'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../modules/ga4-bridge/assets/js/monsterinsights-bridge.js'),
    'utf8'
);

function runBridge(config, existingLayer) {
    const handlers = {};
    const sent = [];
    const dataLayer = existingLayer ? existingLayer.slice() : [];

    // Minimal jQuery stand-in. A target may be a plain object describing an
    // element: {data: {key: value}, form: {selector: value}, selects: [...]}.
    function jquery(target) {
        if (target && target.jquery) {
            return target;
        }
        const el = target || {};
        return {
            jquery: true,
            on: function () {
                const args = Array.prototype.slice.call(arguments);
                handlers[String(args[0]).split('.')[0]] = args[args.length - 1];
                return this;
            },
            closest: function () {
                return formMock(el.form || {});
            },
            data: function (key) { return (el.data || {})[key]; },
            find: function (selector) {
                if (selector === 'select[name^="attribute_"]') {
                    return {
                        each: function (callback) {
                            (el.selects || []).forEach(function (select) { callback.call(select); });
                        }
                    };
                }
                if (selector === 'option:selected') {
                    return {text: function () { return el.label || ''; }};
                }
                return {val: function () { return ''; }};
            },
            val: function () { return el.value; }
        };
    }
    jquery.trim = function (value) { return String(value).trim(); };

    const document = {
        body: {},
        readyState: 'complete',
        addEventListener: function () {}
    };
    const window = {
        dataLayer,
        fflaMonsterInsightsBridge: config,
        jQuery: jquery,
        mi_track_user: true,
        setTimeout: function (callback) {
            callback();
            return 1;
        },
        __gtagTracker: function () {
            const args = Array.prototype.slice.call(arguments);
            sent.push(args);
            dataLayer.push(args);
        }
    };

    vm.runInNewContext(source, {window, document});

    return {handlers, sent, dataLayer};
}

// A form.cart stand-in: find(selector).val() answers from a selector map.
function formMock(values) {
    return {
        find: function (selector) {
            return {val: function () { return values[selector] || ''; }};
        }
    };
}

const productConfig = {
    measurementId: 'G-TEST123',
    currency: 'USD',
    value: '25.00',
    items: [{item_id: '123', item_name: 'Test Product', price: 25, quantity: 1}]
};

const missingView = runBridge(productConfig);
assert.strictEqual(missingView.sent.length, 1, 'Missing view_item should receive one fallback.');
assert.strictEqual(missingView.sent[0][1], 'view_item');
assert.strictEqual(missingView.sent[0][2].send_to, 'G-TEST123');

const existingView = runBridge(productConfig, [
    ['event', 'view_item', {send_to: 'G-TEST123', items: [{item_id: '123'}]}]
]);
assert.strictEqual(existingView.sent.length, 0, 'Existing MonsterInsights view_item must not be duplicated.');

const broadcastView = runBridge(productConfig, [
    ['event', 'view_item', {items: [{item_id: '123'}]}]
]);
assert.strictEqual(broadcastView.sent.length, 0, 'A broadcast gtag view_item already reaches MonsterInsights.');

const addToCart = runBridge(productConfig);
assert.strictEqual(typeof addToCart.handlers.added_to_cart, 'function');
const button = {
    jquery: true,
    data: function (key) { return key === 'quantity' ? 2 : undefined; },
    closest: function () {
        return formMock({});
    }
};
addToCart.handlers.added_to_cart({target: {}}, {}, 'hash', button);
assert.deepStrictEqual(addToCart.sent.map(function (entry) { return entry[1]; }), ['view_item', 'add_to_cart']);
assert.strictEqual(addToCart.sent[1][2].items[0].quantity, 2);
assert.strictEqual(addToCart.sent[1][2].value, 50);

const deduplicatedAdd = runBridge(productConfig, [
    ['event', 'view_item', {send_to: 'G-TEST123', items: [{item_id: '123'}]}]
]);
deduplicatedAdd.dataLayer.push([
    'event',
    'add_to_cart',
    {send_to: 'G-TEST123', items: [{item_id: '123'}]}
]);
deduplicatedAdd.handlers.added_to_cart({target: {}}, {}, 'hash', button);
assert.strictEqual(deduplicatedAdd.sent.length, 0, 'MonsterInsights add_to_cart must not be duplicated.');

// ── Regression: add-to-cart attribution and repeated adds ────────────────
const pageConfig = Object.assign({}, productConfig, {productId: '123'});

// A related-product loop button on the product page names another product:
// no add_to_cart for the page product.
const foreign = runBridge(pageConfig);
foreign.handlers.added_to_cart({target: {}}, {}, 'hash', {
    jquery: true,
    data: function (key) { return key === 'product_id' ? 456 : (key === 'quantity' ? 1 : undefined); },
    closest: function () { return formMock({}); }
});
assert.deepStrictEqual(foreign.sent.map(function (entry) { return entry[1]; }), ['view_item'],
    'Another product\'s add-to-cart button must not be reported as the page product.');

// The page product's own form (simple product: button name="add-to-cart").
const ownForm = runBridge(pageConfig);
ownForm.handlers.added_to_cart({target: {}}, {}, 'hash', {
    jquery: true,
    data: function () { return undefined; },
    closest: function () { return formMock({'[name="add-to-cart"]': '123', 'input.qty': '3'}); }
});
assert.strictEqual(ownForm.sent.length, 2, 'The page product\'s own form is reported.');
assert.strictEqual(ownForm.sent[1][2].items[0].quantity, 3);

// A side-cart that triggers added_to_cart without a button: the page product.
const noButton = runBridge(pageConfig);
noButton.handlers.added_to_cart({target: {}}, {}, 'hash');
assert.deepStrictEqual(noButton.sent.map(function (entry) { return entry[1]; }), ['view_item', 'add_to_cart'],
    'An add without a button is attributed to the page product.');

// Two adds in a row: the module's own first fallback must not suppress the second.
const twice = runBridge(pageConfig);
twice.handlers.added_to_cart({target: {}}, {}, 'hash', button);
twice.handlers.added_to_cart({target: {}}, {}, 'hash', button);
assert.deepStrictEqual(twice.sent.map(function (entry) { return entry[1]; }), ['view_item', 'add_to_cart', 'add_to_cart'],
    'A second add to cart is not deduplicated against the module\'s own fallback.');
assert.strictEqual(twice.sent[1][2].ffla_bridge, 'monsterinsights_compatibility');

// Variations: item_id, price and item_variant follow the choice; reset restores.
const variable = runBridge(pageConfig);
const variationForm = {
    selects: [{value: 'red', label: 'Red'}, {value: '', label: 'Choose an option'}, {value: 'xl', label: 'XL'}]
};
variable.handlers.found_variation.call(variationForm, {}, {variation_id: 124, display_price: 30});
variable.handlers.added_to_cart({target: {}}, {}, 'hash', {
    jquery: true,
    data: function () { return undefined; },
    closest: function () {
        return formMock({'[name="add-to-cart"]': '123', 'input[name="variation_id"]': '124'});
    }
});
const variantItem = variable.sent[1][2].items[0];
assert.strictEqual(variantItem.item_id, '124');
assert.strictEqual(variantItem.price, 30);
assert.strictEqual(variantItem.item_variant, 'Red, XL', 'item_variant lists the chosen options.');
variable.handlers.reset_data.call(variationForm, {});
variable.handlers.added_to_cart({target: {}}, {}, 'hash', button);
const resetItem = variable.sent[2][2].items[0];
assert.strictEqual(resetItem.item_id, '123', 'Clearing the variation restores the parent product.');
assert.strictEqual(resetItem.item_variant, undefined);
assert.strictEqual(resetItem.price, 25);

console.log('MonsterInsights bridge smoke checks passed.');
