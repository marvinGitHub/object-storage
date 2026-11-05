'use strict';
/**
 * Writes tests/fixtures/query-builder.json: random documents and filters written by the query builder, searched
 * by the PHP test QueryBuilderFiltersTest. Run after changing the builder or the generator:
 *
 *     docker run --rm -v "$PWD:/app" -w /app node:22-alpine node tests/js/generate-fixtures.js
 *
 * The JS tests fail while the file is outdated.
 */
const fs = require('fs');
const path = require('path');
const {fixture} = require('./random.js');

// a larger one for a single run: node tests/js/generate-fixtures.js <file> <seed> <documents> <filters>,
// searched with QUERY_BUILDER_FIXTURE=<file> vendor/bin/phpunit --filter QueryBuilderFiltersTest
const [target, seed, documents, filters] = process.argv.slice(2);
const file = target ?? path.join(__dirname, '..', 'fixtures', 'query-builder.json');
fs.mkdirSync(path.dirname(file), {recursive: true});
fs.writeFileSync(file, JSON.stringify(target ? fixture(Number(seed), Number(documents), Number(filters)) : fixture()) + '\n');
console.log('written', file);
