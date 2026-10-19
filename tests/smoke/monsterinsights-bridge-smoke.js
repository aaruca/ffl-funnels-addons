'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../modules/ga4-bridge/assets/js/monsterinsights-bridge.js'),
    'utf8'
);

function runBridge(config, existingLayer, options) {
    const opts = options || {};
    const handlers = {};
    const sent = [];
    const dataLayer = existingLayer ? existingLayer.slice() : [];

    // A jQuery stand-in that records any event binding, so the test can prove
    // the script no longer listens for WooCommerce cart events.
    function jquery() {
        return {
            on: function () {
                const args = Array.prototype.slice.call(arguments);
                handlers[String(args[0]).split('.')[0]] = args[args.length - 1];
                return this;
            }
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
        mi_track_user: opts.trackUser === undefined ? true : opts.trackUser,
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
    items: [{item_id: '123', item_name: 'H&R Test Product', price: 25, quantity: 1}]
};

// Missing view_item: exactly one fallback, addressed to MonsterInsights.
const missingView = runBridge(productConfig);
assert.strictEqual(missingView.sent.length, 1, 'Missing view_item should receive one fallback.');
assert.strictEqual(missingView.sent[0][1], 'view_item');
assert.strictEqual(missingView.sent[0][2].send_to, 'G-TEST123');
assert.strictEqual(missingView.sent[0][2].ffla_bridge, 'monsterinsights_compatibility');
assert.strictEqual(missingView.sent[0][2].value, 25);
assert.strictEqual(missingView.sent[0][2].items[0].item_name, 'H&R Test Product');

// MonsterInsights already sent view_item to its property: no duplicate.
const existingView = runBridge(productConfig, [
    ['event', 'view_item', {send_to: 'G-TEST123', items: [{item_id: '123'}]}]
]);
assert.strictEqual(existingView.sent.length, 0, 'Existing MonsterInsights view_item must not be duplicated.');

// A broadcast view_item (no send_to) also reaches MonsterInsights: no duplicate.
const broadcastView = runBridge(productConfig, [
    ['event', 'view_item', {items: [{item_id: '123'}]}]
]);
assert.strictEqual(broadcastView.sent.length, 0, 'A broadcast gtag view_item already reaches MonsterInsights.');

// A view_item addressed to another property does not count.
const otherProperty = runBridge(productConfig, [
    ['event', 'view_item', {send_to: 'G-OTHER', items: [{item_id: '123'}]}]
]);
assert.strictEqual(otherProperty.sent.length, 1, 'A view_item for another property does not suppress the fallback.');

// Excluded MonsterInsights users get nothing.
const excluded = runBridge(productConfig, [], {trackUser: false});
assert.strictEqual(excluded.sent.length, 0, 'Users excluded by MonsterInsights are not tracked.');

// Regression: no browser add_to_cart. MonsterInsights sends add_to_cart from
// the server (Measurement Protocol) for every add, which the dataLayer cannot
// see, so a browser copy was always counted twice in GA4.
const noCartEvents = runBridge(productConfig);
['added_to_cart', 'found_variation', 'reset_data'].forEach(function (eventName) {
    assert.strictEqual(noCartEvents.handlers[eventName], undefined,
        'The script must not listen for ' + eventName + '.');
});
assert.ok(noCartEvents.sent.every(function (entry) { return entry[1] !== 'add_to_cart'; }),
    'The script must never send add_to_cart.');
assert.ok(!/add_to_cart/.test(source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')),
    'No add_to_cart code path remains in the script.');

// Invalid configuration: nothing is sent.
assert.strictEqual(runBridge(Object.assign({}, productConfig, {measurementId: 'UA-1'})).sent.length, 0);
assert.strictEqual(runBridge(Object.assign({}, productConfig, {items: []})).sent.length, 0);

console.log('MonsterInsights bridge smoke checks passed.');
