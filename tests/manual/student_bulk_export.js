const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function main() {
    const template = fs.readFileSync(path.join(__dirname, '../../templates/Users/bulkstudentaccess.php'), 'utf8');
    const script = template.match(/<script>([\s\S]*?)<\/script>/)[1];
    const handlers = {};
    let download;
    let blob;
    let printCount = 0;
    const inputRows = [
        ['Student', 'Student ID', 'Single-use setup code', 'Expires (server time)'],
        ['=HYPERLINK("https://example.invalid")', '7K4M9R', 'ABCD2345EFGH6789', '2026-11-19 10:00:00'],
        ['  +Formula', '8K4M9R', 'BBCD2345EFGH6789', '2026-11-19 10:00:00'],
        ['-Formula', '9K4M9R', 'CBCD2345EFGH6789', '2026-11-19 10:00:00'],
        ['@Formula', 'AK4M9R', 'DBCD2345EFGH6789', '2026-11-19 10:00:00'],
        ['Zoë, "Student"\nSecond line', 'BK4M9R', 'EBCD2345EFGH6789', '2026-11-19 10:00:00'],
    ];
    const rows = inputRows.map(row => ({cells: row.map(textContent => ({textContent}))}));
    vm.runInNewContext(script, {
        Blob,
        URL: {
            createObjectURL(value) { blob = value; return 'blob:fixture'; },
            revokeObjectURL(value) { assert.equal(value, 'blob:fixture'); },
        },
        window: {print() { printCount++; }},
        setTimeout(callback) { callback(); },
        document: {
            getElementById(id) { return {addEventListener(event, callback) { handlers[id] = callback; }}; },
            querySelectorAll(selector) { assert.equal(selector, '#student-setup-codes tr'); return rows; },
            createElement(tag) {
                assert.equal(tag, 'a');
                return {click() { download = {href: this.href, filename: this.download}; }, remove() {}};
            },
            body: {appendChild() {}},
        },
    });
    handlers['print-student-codes']();
    assert.equal(printCount, 1);
    handlers['download-student-codes']();
    assert.deepEqual(download, {href: 'blob:fixture', filename: 'student-setup-codes.csv'});
    assert.equal(blob.type, 'text/csv;charset=utf-8');
    const bytes = Buffer.from(await blob.arrayBuffer());
    assert.equal(bytes.subarray(0, 3).toString('hex'), 'efbbbf');
    const csv = bytes.subarray(3).toString('utf8');
    assert.ok(csv.startsWith('"Student","Student ID","Single-use setup code","Expires (server time)"\r\n'));
    for (const prefix of ['=', '+', '-', '@']) {
        assert.ok(csv.includes(`"'${prefix}`), 'Formula-like names must be neutralized.');
    }
    assert.ok(csv.includes('"Zoë, ""Student""\nSecond line","BK4M9R","EBCD2345EFGH6789","2026-11-19 10:00:00"'));
    assert.equal((csv.match(/2026-11-19 10:00:00/g) || []).length, 5);
    console.log('PASS: CSV headers, Unicode/BOM, quoting, formula safety, exact rows, local download and print action.');
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
