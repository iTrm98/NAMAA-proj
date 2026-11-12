// i18n safety check. Run by `npm run check:i18n` and (first) by `npm run build`.
// Fails when:
//  - a key exists in only one of ar.json / en.json (plural suffixes are normalised),
//  - a value is empty,
//  - the {{placeholders}} of a key differ between the two files,
//  - a key used in src/ (t('a.b'), i18n.t(...), i18nKey="a.b", t(`a.${x}`) prefixes) is missing.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const src = join(root, 'src')
const PLURAL = /_(zero|one|two|few|many|other)$/

function flatten(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object') flatten(v, key, out)
    else out[key] = String(v)
  }
  return out
}

const load = (name) => flatten(JSON.parse(readFileSync(join(src, 'i18n', `${name}.json`), 'utf8')))
const ar = load('ar')
const en = load('en')
const base = (k) => k.replace(PLURAL, '')
const placeholders = (v) => [...v.matchAll(/\{\{\s*([\w.]+)\s*(?:,[^}]*)?\}\}/g)].map((m) => m[1]).filter((p) => p !== 'count').sort().join(',')

const errors = []

// 1. parity, empty values, placeholders
const baseSet = (flat) => new Set(Object.keys(flat).map(base))
const arBase = baseSet(ar)
const enBase = baseSet(en)
for (const k of arBase) if (!enBase.has(k)) errors.push(`missing in en.json: ${k}`)
for (const k of enBase) if (!arBase.has(k)) errors.push(`missing in ar.json: ${k}`)
for (const [name, flat] of [['ar', ar], ['en', en]]) {
  for (const [k, v] of Object.entries(flat)) if (v.trim() === '') errors.push(`empty value in ${name}.json: ${k}`)
}
const phByBase = (flat) => {
  const m = {}
  for (const [k, v] of Object.entries(flat)) m[base(k)] = [...new Set([...(m[base(k)] ?? '').split(',').filter(Boolean), ...placeholders(v).split(',').filter(Boolean)])].sort().join(',')
  return m
}
const arPh = phByBase(ar)
const enPh = phByBase(en)
for (const k of arBase) if (enBase.has(k) && arPh[k] !== enPh[k]) errors.push(`placeholders differ for ${k}: ar {${arPh[k]}} en {${enPh[k]}}`)

// 2. keys used in code
function walk(dir, files = []) {
  for (const f of readdirSync(dir)) {
    const p = join(dir, f)
    if (statSync(p).isDirectory()) walk(p, files)
    else if (/\.(tsx?|jsx?)$/.test(f)) files.push(p)
  }
  return files
}

const KEY = /^[a-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/
const exact = new Map() // key -> first file
const prefixes = new Map() // "a.b." -> first file

function add(literal, file) {
  if (literal.includes('${')) {
    const prefix = literal.slice(0, literal.indexOf('${'))
    if (/^[a-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*\.$/.test(prefix)) prefixes.set(prefix, prefixes.get(prefix) ?? file)
  } else if (KEY.test(literal)) exact.set(literal, exact.get(literal) ?? file)
}

for (const file of walk(src)) {
  const text = readFileSync(file, 'utf8')
  const rel = relative(root, file)
  // t( ... ) and i18n.t( ... ): scan the first argument for string literals
  for (const m of text.matchAll(/(?<![\w.])(?:i18n\.|i18next\.)?t\(\s*/g)) {
    let i = m.index + m[0].length
    let depth = 0
    let arg = ''
    for (; i < text.length; i++) {
      const c = text[i]
      if (c === '(' || c === '[' || c === '{') depth++
      else if (c === ')' || c === ']' || c === '}') {
        if (depth === 0) break
        depth--
      } else if (c === ',' && depth === 0) break
      arg += c
    }
    for (const lit of arg.matchAll(/(['"`])((?:(?!\1).)*)\1/g)) add(lit[2], rel)
  }
  for (const m of text.matchAll(/i18nKey=["{`']+([^"}`']+)["}`']+/g)) add(m[1], rel)
}

const allKeys = [...new Set([...Object.keys(ar), ...Object.keys(en)])]
const has = (flat, key) => key in flat || Object.keys(flat).some((k) => base(k) === key)
for (const [key, file] of exact) {
  if (!has(ar, key)) errors.push(`key used in ${file} missing in ar.json: ${key}`)
  if (!has(en, key)) errors.push(`key used in ${file} missing in en.json: ${key}`)
}
for (const [prefix, file] of prefixes) {
  for (const [name, flat] of [['ar', ar], ['en', en]]) {
    if (!Object.keys(flat).some((k) => k.startsWith(prefix))) errors.push(`dynamic key prefix "${prefix}" used in ${file} has no keys in ${name}.json`)
  }
}

// 3. dynamic keys whose values come from the API: every known value needs a key.
// Keep in sync with the union types in src/types.ts and the checks in backend SystemHealth.
const DYNAMIC = {
  'impact.notCompared.': ['no_pump', 'manual_irrigation', 'no_readings', 'not_enough_data'],
  'health.check.': ['demo_mode', 'app_debug', 'mysql', 'migrations', 'queue', 'scheduler', 'reverb', 'vision', 'camera', 'real_nodes', 'bench_nodes', 'node_tokens', 'rejected', 'disk'],
}
for (const [prefix, values] of Object.entries(DYNAMIC)) {
  for (const v of values) {
    if (!has(ar, prefix + v)) errors.push(`missing in ar.json: ${prefix}${v}`)
    if (!has(en, prefix + v)) errors.push(`missing in en.json: ${prefix}${v}`)
  }
}

// 4. plural completeness: Arabic needs all six forms (a count of 2 or 11 would render the raw key), English one + other.
const FORMS = { ar: ['zero', 'one', 'two', 'few', 'many', 'other'], en: ['one', 'other'] }
for (const [name, flat] of [['ar', ar], ['en', en]]) {
  const groups = new Map()
  for (const k of Object.keys(flat)) if (PLURAL.test(k)) groups.set(base(k), [...(groups.get(base(k)) ?? []), k.match(PLURAL)[1]])
  for (const [k, forms] of groups) {
    const missing = FORMS[name].filter((f) => !forms.includes(f))
    if (missing.length) errors.push(`plural ${k} in ${name}.json lacks: ${missing.join(', ')}`)
  }
}

if (errors.length) {
  console.error(`i18n check failed (${errors.length}):\n` + errors.map((e) => `  - ${e}`).join('\n'))
  process.exit(1)
}
console.log(`i18n check passed: ${arBase.size} keys in ar and en, ${exact.size} literal and ${prefixes.size} dynamic key uses.`)
