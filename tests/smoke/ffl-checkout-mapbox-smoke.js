'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../modules/ffl-checkout/assets/js/ffl-checkout-mapbox.js'),
    'utf8'
);

class TestEvent {
    constructor(type, options) {
        this.type = type;
        this.bubbles = Boolean(options && options.bubbles);
        this.defaultPrevented = false;
        this.target = null;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

class TestElement {
    constructor(tagName) {
        this.tagName = String(tagName || 'div').toUpperCase();
        this.children = [];
        this.parentElement = null;
        this.dataset = {};
        this.style = {};
        this.attributes = {};
        this.listeners = {};
        this.options = [];
        this.value = '';
        this.id = '';
        this.className = '';
        this.textContent = '';
    }

    addEventListener(type, callback) {
        this.listeners[type] = this.listeners[type] || [];
        this.listeners[type].push(callback);
    }

    dispatchEvent(event) {
        event.target = this;
        (this.listeners[event.type] || []).slice().forEach((callback) => callback(event));
        return !event.defaultPrevented;
    }

    appendChild(child) {
        child.parentElement = this;
        this.children.push(child);
        return child;
    }

    remove() {
        if (!this.parentElement) return;
        this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
        this.parentElement = null;
    }

    contains(candidate) {
        return this === candidate || this.children.some((child) => child.contains(candidate));
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }
}

const body = new TestElement('body');
const wrapper = body.appendChild(new TestElement('div'));
const billingAddress = wrapper.appendChild(new TestElement('input'));
const fields = {
    '#billing_address_1': billingAddress,
    '#billing_address_2': body.appendChild(new TestElement('input')),
    '#billing_city': body.appendChild(new TestElement('input')),
    '#billing_state': body.appendChild(new TestElement('select')),
    '#billing_postcode': body.appendChild(new TestElement('input')),
    '#billing_country': body.appendChild(new TestElement('select')),
};
fields['#billing_state'].options = [{value: 'GA'}];
fields['#billing_country'].options = [{value: 'US'}];

const documentListeners = {};
const document = {
    body,
    readyState: 'complete',
    querySelector(selector) {
        return fields[selector] || null;
    },
    createElement(tagName) {
        return new TestElement(tagName);
    },
    getElementById(id) {
        function find(element) {
            if (element.id === id) return element;
            for (const child of element.children) {
                const match = find(child);
                if (match) return match;
            }
            return null;
        }
        return find(body);
    },
    addEventListener(type, callback) {
        documentListeners[type] = documentListeners[type] || [];
        documentListeners[type].push(callback);
    },
    removeEventListener(type, callback) {
        documentListeners[type] = (documentListeners[type] || []).filter((item) => item !== callback);
    },
};

let nextTimerId = 1;
let timers = [];
function setTimeoutStub(callback) {
    const timer = {id: nextTimerId++, callback, cancelled: false};
    timers.push(timer);
    return timer.id;
}
function clearTimeoutStub(id) {
    timers.forEach((timer) => {
        if (timer.id === id) timer.cancelled = true;
    });
}
async function flushAsyncWork() {
    for (let pass = 0; pass < 20; pass++) {
        const pending = timers;
        timers = [];
        pending.forEach((timer) => {
            if (!timer.cancelled) timer.callback();
        });
        await Promise.resolve();
        await Promise.resolve();
        if (!timers.length) {
            await Promise.resolve();
            if (!timers.length) return;
        }
    }
    throw new Error('Async test work did not settle.');
}

let suggestCalls = 0;
let retrieveCalls = 0;
function fetchStub(url) {
    if (url.indexOf('/suggest') !== -1) {
        suggestCalls++;
        return Promise.resolve({
            json: () => Promise.resolve({
                suggestions: [{
                    name: '145 Main St',
                    place_formatted: 'Atlanta, Georgia 30303',
                    mapbox_id: 'test-address',
                }],
            }),
        });
    }

    retrieveCalls++;
    return Promise.resolve({
        json: () => Promise.resolve({
            features: [{
                properties: {
                    address_line1: '145 Main Street',
                    context: {
                        place: {name: 'Atlanta'},
                        region: {region_code: 'GA'},
                        postcode: {name: '30303'},
                        country: {country_code: 'us'},
                    },
                },
            }],
        }),
    });
}

vm.runInNewContext(source, {
    document,
    Event: TestEvent,
    fetch: fetchStub,
    setTimeout: setTimeoutStub,
    clearTimeout: clearTimeoutStub,
    encodeURIComponent,
    console,
    fflCheckoutMapbox: {accessToken: 'pk.test'},
});

(async () => {
    billingAddress.value = '145 Mai';
    billingAddress.dispatchEvent(new TestEvent('input', {bubbles: true}));
    await flushAsyncWork();

    const dropdown = document.getElementById('ffl-mbx-billing');
    assert(dropdown, 'Typing should open the Mapbox suggestion dropdown.');
    assert.strictEqual(suggestCalls, 1, 'Typing should make one suggestion request.');

    dropdown.children[0].dispatchEvent(new TestEvent('mousedown'));
    assert.strictEqual(
        document.getElementById('ffl-mbx-billing'),
        null,
        'Selecting a suggestion should close the dropdown immediately.'
    );

    await flushAsyncWork();

    assert.strictEqual(
        document.getElementById('ffl-mbx-billing'),
        null,
        'Programmatic field updates must not reopen the dropdown.'
    );
    assert.strictEqual(suggestCalls, 1, 'Selection must not trigger another suggestion request.');
    assert.strictEqual(retrieveCalls, 1, 'Selection should retrieve the complete address once.');
    assert.strictEqual(fields['#billing_address_1'].value, '145 Main Street');
    assert.strictEqual(fields['#billing_city'].value, 'Atlanta');
    assert.strictEqual(fields['#billing_state'].value, 'GA');
    assert.strictEqual(fields['#billing_postcode'].value, '30303');
    assert.strictEqual(fields['#billing_country'].value, 'US');

    billingAddress.value = '200 Pea';
    billingAddress.dispatchEvent(new TestEvent('input', {bubbles: true}));
    await flushAsyncWork();

    assert(
        document.getElementById('ffl-mbx-billing'),
        'Typing again after a selection should reopen autocomplete normally.'
    );
    assert.strictEqual(suggestCalls, 2, 'A later manual edit should make a new suggestion request.');

    console.log('FFL checkout Mapbox autocomplete smoke checks passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
