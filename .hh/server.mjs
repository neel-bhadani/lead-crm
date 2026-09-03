import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
const root = path.join(import.meta.dirname, 'dist')
const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' }
http.createServer((req, res) => {
  const f = path.join(root, decodeURIComponent(req.url.split('?')[0]) === '/' ? 'index.html' : req.url.split('?')[0])
  fs.readFile(f, (e, d) => e ? (res.writeHead(404), res.end()) : (res.writeHead(200, { 'content-type': types[path.extname(f)] || 'text/plain' }), res.end(d)))
}).listen(8391, () => console.log('up'))
