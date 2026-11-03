// Builds the schedule PDF on the server with the same code as the "Archi – გრაფიკის PDF" browser extension
// (vendor/jsPDF + autotable, fonts/*.js, pdf.js copied unchanged from archi_calc_print/extension).
// Input: payload JSON on stdin (same shape as the extension's scrape.js output). Output: PDF as base64 on stdout.
// Written for the server's Node v8: plain ES2017, no globalThis/atob/btoa.
'use strict';
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var dir = __dirname;
global.window = global;
global.self = global;
if (typeof global.navigator === 'undefined') {
  global.navigator = { userAgent: 'node' }; // jsPDF's bundled FileSaver reads it at load time
}
if (typeof global.atob !== 'function') {
  global.atob = function (s) { return Buffer.from(String(s), 'base64').toString('binary'); };
}
if (typeof global.btoa !== 'function') {
  global.btoa = function (s) { return Buffer.from(String(s), 'binary').toString('base64'); };
}

function load(rel) {
  vm.runInThisContext(fs.readFileSync(path.join(dir, rel), 'utf8'), { filename: rel });
}

var chunks = [];
process.stdin.on('data', function (c) { chunks.push(c); });
process.stdin.on('end', function () {
  try {
    var input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    load('vendor/jspdf.umd.min.js');
    load('vendor/jspdf.plugin.autotable.min.js');
    load('fonts/NotoSansGeorgian-Regular.js');
    load('fonts/NotoSansGeorgian-Bold.js');
    load('pdf.js');
    var doc = global.ArchiPdf.buildPdf(input.payload, { now: input.now });
    process.stdout.write(Buffer.from(doc.output('arraybuffer')).toString('base64'));
  } catch (e) {
    process.stderr.write(String(e && e.stack || e));
    process.exit(1);
  }
});
