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

    function jquery(target) {
        return {
            jquery: Boolean(target && target.jquery),
            on: function () {
                const args = Array.prototype.slice.call(arguments);
                handlers[String(args[0]).split('.')[0]] = args[args.length - 1];
                return this;
            },
            closest: function () {
                return {
                    find: function () {
                        return {val: function () { return ''; }};
                    }
                };
            },
            data: function () { return 1; }
        };
    }

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
    data: function () { return 2; },
    closest: function () {
        return {
            find: function () {
                return {val: function () { return ''; }};
            }
        };
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

console.log('MonsterInsights bridge smoke checks passed.');
