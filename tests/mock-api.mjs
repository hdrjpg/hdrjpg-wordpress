// A stand-in for the HDRJPG API, for testing the plugin without spending conversions.
// Every upload is Ready at the first status check; the download holds one small file per variant.
import {createServer} from 'node:http';
import {crc32} from 'node:zlib';

const KEY = process.env.MOCK_KEY || 'test-key';
const conversions = new Map();
const extensions = {'jpeg-xt': 'jpg', avif: 'avif', mp4: 'mp4', 'jpeg-xl': 'jxl', jpeg: 'sdr.jpg'};

function zip(files) {
    const parts = [], central = [];
    let offset = 0;
    for (const [name, content] of files) {
        const data = Buffer.from(content), n = Buffer.from(name);
        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0); local.writeUInt32LE(crc32(data), 14);
        local.writeUInt32LE(data.length, 18); local.writeUInt32LE(data.length, 22); local.writeUInt16LE(n.length, 26);
        const c = Buffer.alloc(46);
        c.writeUInt32LE(0x02014b50, 0); c.writeUInt32LE(crc32(data), 16); c.writeUInt32LE(data.length, 20);
        c.writeUInt32LE(data.length, 24); c.writeUInt16LE(n.length, 28); c.writeUInt32LE(offset, 42);
        parts.push(local, n, data); central.push(c, n);
        offset += 30 + n.length + data.length;
    }
    const cd = Buffer.concat(central), end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(files.length, 8); end.writeUInt16LE(files.length, 10);
    end.writeUInt32LE(cd.length, 12); end.writeUInt32LE(offset, 16);
    return Buffer.concat([...parts, cd, end]);
}

createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const send = (status, body, type = 'application/json') => {
        response.writeHead(status, {'content-type': type});
        response.end(type === 'application/json' ? JSON.stringify(body) : body);
    };
    console.log(request.method, url.pathname, url.search, 'key=' + request.headers['api-key'], request.headers['user-agent']);
    if (request.headers['api-key'] !== KEY) return send(401, {errorDescription: 'Invalid API key'});

    if (url.pathname === '/convert') {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const body = Buffer.concat(chunks).toString('latin1');
        const variants = JSON.parse(body.match(/name="variant"\r\n\r\n(.*)\r\n/)[1]);
        const fileName = body.match(/filename="([^"]+)"/)[1];
        if (fileName.startsWith('flat')) {
            conversions.set('fail', {fail: true});
        }
        const uuid = fileName.startsWith('flat') ? 'fail' : 'c' + conversions.size;
        conversions.set(uuid, {variants, fail: fileName.startsWith('flat')});
        return send(200, {conversions: [{uuid, status: 'Pending', variants: []}]});
    }
    const uuid = url.searchParams.get('conversionUuid');
    const conversion = conversions.get(uuid);
    if (!conversion) return send(404, {errorDescription: 'Conversion not found'});
    const outputs = (conversion.variants || []).map((v) => ({
        format: v.format, outputImageFileName: v.fileName + '.' + extensions[v.format],
        outputImageWidth: v.width, outputImageHeight: Math.round(v.width * 2 / 3), failDescriptions: null,
    }));
    if (url.pathname === '/status') {
        if (conversion.fail) return send(200, {conversions: [{uuid, status: 'Failed', failDescriptions: ['The source image is not an HDR image'], variants: []}]});
        return send(200, {conversions: [{uuid, status: 'Ready', variants: outputs}]});
    }
    if (url.pathname === '/download') {
        return send(200, zip(outputs.map((o) => [o.outputImageFileName, 'converted ' + o.outputImageFileName])), 'application/zip');
    }
    send(404, {errorDescription: 'Not found'});
}).listen(8080, () => console.log('mock HDRJPG API on 8080'));
