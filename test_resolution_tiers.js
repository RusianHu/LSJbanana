'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

// script.js 只会注册浏览器事件；测试中不执行页面初始化回调。
global.window = {
    addEventListener() {}
};

const {classifyImageResolution} = require('./script.js');

test('rejects invalid image dimensions', () => {
    assert.equal(classifyImageResolution(0, 1024), null);
    assert.equal(classifyImageResolution(1024, -1), null);
    assert.equal(classifyImageResolution('invalid', 1024), null);
    assert.equal(classifyImageResolution(Infinity, 1024), null);
});

test('classifies SD and 1K outputs', () => {
    assert.equal(classifyImageResolution(800, 600).label, 'SD');
    assert.equal(classifyImageResolution(1024, 1024).label, '1K');
    assert.equal(classifyImageResolution(1584, 672).label, '1K');
});

test('classifies 2K outputs across supported aspect ratios', () => {
    assert.equal(classifyImageResolution(2048, 2048).label, '2K');
    assert.equal(classifyImageResolution(3168, 1344).label, '2K');
    assert.equal(classifyImageResolution(1536, 2752).label, '2K');
});

test('classifies square, landscape and portrait 4K outputs', () => {
    assert.equal(classifyImageResolution(3840, 2160).label, '4K');
    assert.equal(classifyImageResolution(4096, 4096).label, '4K');
    assert.equal(classifyImageResolution(6336, 2688).label, '4K');
    assert.equal(classifyImageResolution(3072, 5504).label, '4K');
});

test('keeps tier boundaries ordered from highest to lowest', () => {
    assert.equal(classifyImageResolution(3839, 2160).label, '2K');
    assert.equal(classifyImageResolution(3840, 2160).label, '4K');
    assert.equal(classifyImageResolution(1999, 1200).label, '1K');
    assert.equal(classifyImageResolution(2000, 1200).label, '2K');
    assert.equal(classifyImageResolution(999, 999).label, 'SD');
    assert.equal(classifyImageResolution(1000, 800).label, '1K');
});

test('returns the CSS class and translation key for the selected tier', () => {
    const result = classifyImageResolution(4096, 4096);
    assert.equal(result.className, 'resolution-4k');
    assert.equal(result.descriptionKey, 'resolution.4k');
    assert.equal(result.maxDimension, 4096);
});
