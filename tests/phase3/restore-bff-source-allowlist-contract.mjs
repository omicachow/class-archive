import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Public-safe static contract for the generated restore Nginx configuration.
// It opens no runtime, backup, Docker volume, private source, or network.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');
const [nginx, restoreV1, restoreV2] = await Promise.all([
  read('infra/piwigo-nginx/nginx.conf'),
  read('infra/scripts/owner-full-restore-drill.ps1'),
  read('infra/scripts/owner-independent-restore-v2.ps1'),
]);

let assertions = 0;
function check(condition, message) {
  assert.ok(condition, message);
  assertions += 1;
}

function section(text, start, end) {
  const offset = text.indexOf(start);
  if (offset < 0) return '';
  const limit = text.indexOf(end, offset + start.length);
  return limit < 0 ? text.slice(offset) : text.slice(offset, limit);
}

const mapBlock = section(
  nginx,
  'map $realip_remote_addr $class_archive_web_compat_bff_source {',
  '    }',
);
check(mapBlock.includes('default 0;'), 'unknown_bff_source_must_default_deny');

const baseEntries = [...mapBlock.matchAll(/^\s*(\d{1,3}(?:\.\d{1,3}){3})\s+1;\s*$/gm)]
  .map((match) => match[1]);
check(baseEntries.includes('172.23.0.10')
  && baseEntries.includes('172.27.0.10')
  && baseEntries.includes('10.241.0.10')
  && baseEntries.includes('10.249.0.10')
  && baseEntries.includes('10.250.0.10')
  && baseEntries.includes('10.251.0.10')
  && baseEntries.includes('10.252.0.10')
  && baseEntries.includes('10.253.0.10'), 'current_exact_bff_sources_missing');
check(baseEntries.every((entry) => /^\d{1,3}(?:\.\d{1,3}){3}$/.test(entry)), 'base_bff_source_must_not_use_cidr');
check(!baseEntries.includes('10.245.0.10') && !baseEntries.includes('10.246.0.10'), 'restore_bff_source_must_be_generated_not_global');

const inner = section(nginx, 'server {\n        listen 8088;', '    # Direct access to Piwigo');
const realIpDirectives = [...inner.matchAll(/^\s*set_real_ip_from\s+([^;\s]+);\s*$/gm)]
  .map((match) => match[1]);
check(realIpDirectives.every((entry) => /^\d{1,3}(?:\.\d{1,3}){3}\/32$/.test(entry)), 'realip_source_must_not_use_any_subnet');
const realIpEntries = realIpDirectives.map((entry) => entry.slice(0, -3));
for (const required of ['172.23.0.10', '172.27.0.10', '10.241.0.10', '10.249.0.10', '10.250.0.10', '10.251.0.10', '10.252.0.10', '10.253.0.10']) {
  check(realIpEntries.includes(required), `realip_exact_source_missing_${required.replaceAll('.', '_')}`);
}

const baseMapValue = (source) => baseEntries.includes(source) ? 1 : 0;
for (const unknown of ['10.241.0.11', '10.249.0.11', '10.249.1.10', '10.250.0.11', '10.250.1.10', '10.251.0.11', '10.251.1.10', '10.252.0.11', '10.252.1.10', '10.253.0.11', '10.253.1.10', '203.0.113.10']) {
  check(baseMapValue(unknown) === 0, `base_unknown_source_must_deny_${unknown.replaceAll('.', '_')}`);
}

function assertRestoreGenerator(script, restoreAddress, label) {
  const generator = script.match(/function New-RestoreNginxConfiguration \{[\s\S]*?\r?\n\}/)?.[0] ?? '';
  const mapLine = `        ${restoreAddress} 1;`;
  const realIpLine = `set_real_ip_from ${restoreAddress}/32;`;
  check(generator.includes("$mapAnchor = '        10.241.0.10 1;'")
    && generator.includes("$realIpAnchor = '        set_real_ip_from 10.241.0.10/32;'")
    && generator.includes(`-not $source.Contains('${mapLine}')`)
    && generator.includes(`-not $source.Contains('${realIpLine}')`), `${label}_source_anchor_or_duplicate_guard_missing`);
  check(generator.includes('$source.Replace(')
    && generator.includes('$mapAnchor')
    && generator.includes('$realIpAnchor')
    && generator.includes(mapLine)
    && generator.includes(realIpLine), `${label}_must_add_map_and_realip_entries`);
  check(generator.includes(`(^|\\n)\\s*${restoreAddress.replaceAll('.', '\\.')} 1;`)
    && generator.includes(realIpLine), `${label}_generated_exact_entry_assertion_missing`);
  const escapedPrefix = restoreAddress.replace(/\.10$/, '').replaceAll('.', '\\.');
  check(!new RegExp(`\\b${escapedPrefix}\\.\\d+/(?!32\\b)`).test(generator), `${label}_must_not_broaden_to_subnet`);

  // The Nginx map has a fail-closed default. This source-level model is
  // intentionally exact: only entries emitted by the generator can carry
  // the internal BFF marker into the private PHP listener.
  const generatedEntries = new Set([...baseEntries, restoreAddress]);
  const mapValue = (source) => generatedEntries.has(source) ? 1 : 0;
  check(mapValue(restoreAddress) === 1, `${label}_exact_restore_bff_source_must_allow`);
  for (const unknown of [
    restoreAddress.replace(/\.10$/, '.11'),
    restoreAddress.replace(/\.10$/, '.1'),
    '10.244.0.10',
    '203.0.113.10',
  ]) {
    check(mapValue(unknown) === 0, `${label}_unknown_source_must_deny_${unknown.replaceAll('.', '_')}`);
  }
}

assertRestoreGenerator(restoreV1, '10.245.0.10', 'restore_v1');
assertRestoreGenerator(restoreV2, '10.246.0.10', 'restore_v2');

process.stdout.write(`${JSON.stringify({
  suite: 'phase3-restore-bff-source-allowlist-contract',
  assertions,
  result: 'PASS',
  evidence: 'STATIC_ONLY',
})}\n`);
