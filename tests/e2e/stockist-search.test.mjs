import test from 'node:test';
import assert from 'node:assert/strict';
import {textMatches, milesBetween, nearby} from '../../resources/js/stockists/search.js';
const rows = [{id:1,name:'Café & Grocery',address:'508 S B St, Easley, SC 29640',latitude:34.8,longitude:-82.6},{id:2,name:'Other branch',address:'Brooklyn, NY 11216',latitude:40.68,longitude:-73.94}];
test('search accepts diacritics, punctuation, city and ZIP without HTML interpretation', () => {
 assert.deepEqual(textMatches(rows,'cafe grocery').map(x=>x.id),[1]);
 assert.deepEqual(textMatches(rows,'Easley, SC').map(x=>x.id),[1]);
 assert.deepEqual(textMatches(rows,'11216').map(x=>x.id),[2]);
 assert.deepEqual(textMatches(rows,'<script>alert(1)</script>'),[]);
});
test('distance searches sort within radius and remain finite at identical or antipodal points', () => {
 assert.equal(milesBetween(rows[0],rows[0]),0);
 assert.ok(Number.isFinite(milesBetween({latitude:90,longitude:0},{latitude:-90,longitude:180})));
 assert.deepEqual(nearby(rows,rows[0]).map(x=>x.id),[1]);
 assert.deepEqual(nearby(rows,{latitude:0,longitude:0}),[]);
});
