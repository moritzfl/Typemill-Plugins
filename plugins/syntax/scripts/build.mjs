import * as esbuild from 'esbuild'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(dirname(fileURLToPath(import.meta.url)))

await esbuild.build({
    entryPoints: [join(root, 'src/client.js')],
    bundle: true,
    minify: true,
    format: 'iife',
    outfile: join(root, 'public/syntax.min.js'),
    target: 'es2020',
    logLevel: 'info',
})
