#!/usr/bin/env node
// PHASE-0.96 S96-6.2 — validate an extension plugin against zalo-brain-extension@1.
// [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 — checks: manifest shape, plugin header (Requires Plugins,
// Network false), no relative require into the main plugin's core, only published core hooks used, no forbidden
// folders tracked for publishing (R-PROD-REPO).
//   node bin/validate-zalo-brain-extension.mjs ../bizcity-twin-crm [--json]
import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';

// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-L2 — this file is also shipped as docs/developer/tools/validate-extension.mjs (bin/ and docs/
// never reach GitHub, R-PROD-REPO). So: ROOT = the nearest folder up that holds Zalo Brain (core/runtime/class-zalo-brain.php), whatever the folder
// name; the plugin argument resolves from the CURRENT directory first (a developer runs it from their own plugin), then from ROOT (old callers);
// published hooks come from docs/contracts/ on a developer machine, else from the shipped docs/developer/contracts/ copy (PHASE-0.103 Q103-8: one door — the root developer/ kit was folded into docs/developer/).
const HERE = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const ROOT = (() => {
	for (let d = HERE, i = 0; i < 6; i++, d = path.dirname(d)) if (fs.existsSync(path.join(d, 'core', 'runtime', 'class-zalo-brain.php'))) return d;
	return path.resolve(HERE, '..');
})();
const argDir = process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : '';
const dir = argDir && fs.existsSync(path.resolve(process.cwd(), argDir)) ? path.resolve(process.cwd(), argDir) : path.resolve(ROOT, argDir);
const CONTRACTS = ['docs/contracts', 'docs/developer/contracts', 'developer/contracts'].map((c) => path.join(ROOT, c)).find((c) => fs.existsSync(path.join(c, 'ZALO-BRAIN-HOOKS-v1.json'))) || path.join(ROOT, 'docs/contracts');
const asJson = process.argv.includes('--json');
const findings = [];
const add = (check, detail) => findings.push({ check, ...detail });
const SURFACES = ['gpt', 'gateway', 'crm', 'twinchat', 'scheduler', 'flow', 'setting', 'apps'];
// [2026-10-09 11:48 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-2b — 'apps' = slot 8 (Apps launcher, BizCity_Zalo_Brain::APPS_SURFACE); an apps entry names app_id.

if (!fs.existsSync(dir)) { console.error('plugin dir not found:', dir); process.exit(2); }
const slug = path.basename(dir);

// 1. manifest
const mf = path.join(dir, 'zalo-brain.json');
let manifest = null;
if (!fs.existsSync(mf)) add('manifest_missing', { file: 'zalo-brain.json' });
else {
	try { manifest = JSON.parse(fs.readFileSync(mf, 'utf8')); } catch (e) { add('manifest_invalid_json', { error: String(e.message) }); }
}
if (manifest) {
	if (manifest.contract !== 'zalo-brain-extension@1') add('manifest_contract', { value: manifest.contract });
	if (manifest.id !== slug) add('manifest_id_not_slug', { id: manifest.id, slug });
	for (const k of ['name', 'version']) if (!manifest[k]) add('manifest_key_missing', { key: k });
	if (!manifest.requires || !manifest.requires['zalo-brain']) add('manifest_key_missing', { key: 'requires.zalo-brain' });
	if (!Array.isArray(manifest.features) || !manifest.features.length) add('manifest_key_missing', { key: 'features[]' });
	for (const s of manifest.surfaces || []) { if (!SURFACES.includes(s.surface)) add('manifest_unknown_surface', { surface: s.surface }); else if (s.surface === 'apps' && !s.app_id) add('manifest_apps_without_app_id', {}); }
	// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-C8 — `context_producers[]` (R-ZBF-4, context-producer@1): {id, label, metrics{key: label}, roles?}.
	if (manifest.context_producers !== undefined) {
		if (!Array.isArray(manifest.context_producers)) add('context_producers_not_array', {});
		else for (const p of manifest.context_producers) {
			const id = String(p?.id || '');
			if (!/^[a-z][a-z0-9_]{1,31}$/.test(id)) add('context_producer_id', { id });
			if (!String(p?.label || '').trim()) add('context_producer_label', { id });
			const metrics = p?.metrics && typeof p.metrics === 'object' && !Array.isArray(p.metrics) ? Object.keys(p.metrics) : null;
			if (!metrics || !metrics.length) add('context_producer_metrics', { id, why: 'metrics must be an object {key: label} with at least one key' });
			else for (const k of metrics) if (!/^[a-z][a-z0-9_]{0,31}$/.test(k)) add('context_producer_metrics', { id, key: k });
			for (const r of Array.isArray(p?.roles) ? p.roles : []) if (!['owner', 'staff'].includes(r)) add('context_producer_roles', { id, role: r, why: 'only owner/staff — customers never see a data source' });
		}
	}
}

// 1b. MCP library (zalo-brain-extension@1.1, PHASE-0.101 doc 30 §2.2) — mcp_tools ↔ mcp/tools.json ↔ @mcp markers.
// [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L2 — a declared tool must be in the library and carry a code marker; a library tool
// must be declared; a write must say confirm always + preview true + required_cap (or no_standard_cap); mode is required.
const LIB_CONTRACT = 'bizcity-mcp-library@1.0.0';
const NAME_RE = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,2}$/;
const declaredTools = manifest && Array.isArray(manifest.mcp_tools) ? manifest.mcp_tools.map(String) : [];
const codeMarkers = new Set();
function validateLibTool(t) {
	if (!NAME_RE.test(String(t.name || ''))) return 'name must be <group>.<verb> or <group>.<entity>.<verb>';
	for (const k of ['title', 'description', 'mode']) if (!String(t[k] || '').trim()) return k + ' required';
	if (!t.input_schema || typeof t.input_schema !== 'object' || t.input_schema.type !== 'object') return 'input_schema must be a JSON schema object';
	if (!Array.isArray(t.scopes) || !t.scopes.length) return 'scopes[] required';
	const write = Object.prototype.hasOwnProperty.call(t, 'read_only') && t.read_only === false;
	if (write) {
		if (t.confirm !== 'always') return 'a write must declare confirm: always';
		if (t.preview !== true) return 'a write must declare preview: true';
		if (!String(t.required_cap || '').trim() && t.no_standard_cap !== true) return 'a write must declare required_cap (or no_standard_cap: true)';
	} else if (t.confirm === 'always' && t.preview !== true) return 'confirm: always needs preview: true';
	if (t.handler !== undefined) return 'handler is code, not data';
	return null;
}
// [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L1 — bizcity-mcp-library@1.1 optional keys
// (when_to_use/need/audience): a write tool missing when_to_use is a WARN in 1.1 (becomes RED in a later contract bump).
function warnLibTool(t) {
	const write = Object.prototype.hasOwnProperty.call(t, 'read_only') && t.read_only === false;
	if (write && !String(t.when_to_use || '').trim()) return 'mcp_tool_missing_when_to_use';
	return null;
}
if (manifest) {
	const libRel = manifest.mcp_library ? String(manifest.mcp_library) : '';
	let lib = null;
	if (libRel) {
		const lf = path.join(dir, libRel);
		if (!fs.existsSync(lf)) add('mcp_library_missing', { file: libRel });
		else { try { lib = JSON.parse(fs.readFileSync(lf, 'utf8')); } catch (e) { add('mcp_library_invalid_json', { file: libRel, error: String(e.message) }); } }
	} else if (declaredTools.length) add('mcp_library_required', { declared: declaredTools.length });
	if (lib) {
		if (lib.contract !== LIB_CONTRACT) add('mcp_library_contract', { value: lib.contract, expected: LIB_CONTRACT });
		if (lib.owner && lib.owner !== manifest.id) add('mcp_library_owner', { owner: lib.owner, id: manifest.id });
		const libTools = Array.isArray(lib.tools) ? lib.tools : [];
		const names = libTools.map((t) => String(t && t.name || ''));
		for (const n of declaredTools) if (!names.includes(n)) add('mcp_tool_not_in_library', { tool: n });
		for (const t of libTools) {
			if (!t || typeof t !== 'object') continue;
			if (!declaredTools.includes(String(t.name || ''))) add('mcp_library_tool_undeclared', { tool: t.name });
			const why = validateLibTool(t);
			if (why) add('mcp_library_tool_invalid', { tool: t.name, why });
			const warnWhy = warnLibTool(t);
			if (warnWhy) add(warnWhy, { tool: t.name, severity: 'warn' });
		}
	}
	for (const n of declaredTools) if (!NAME_RE.test(n)) add('mcp_tool_name', { tool: n });
}

// 2. header
const main = path.join(dir, slug + '.php');
if (!fs.existsSync(main)) add('main_file_missing', { file: slug + '.php' });
else {
	const head = fs.readFileSync(main, 'utf8').slice(0, 3000);
	// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L2 — R-ADDON-HOST v1 (PHASE-0.102): the host is recognised by the `Zalo Brain Addon:` header + BIZCITY_TWIN_AI_VERSION under any folder name; the legacy `Requires Plugins` header is still accepted.
	if (!/Zalo Brain Addon:\s*\S+/.test(head) && !/Requires Plugins:\s*bizcity-twin-ai/.test(head)) add('header_requires_plugins', { file: slug + '.php' });
	if (!/Network:\s*false/.test(head)) add('header_network_false', { file: slug + '.php' });
}

// 3. code scan
const published = new Set(JSON.parse(fs.readFileSync(path.join(CONTRACTS, 'ZALO-BRAIN-HOOKS-v1.json'), 'utf8')).hooks.filter((h) => h.status === 'published').map((h) => h.name));
const ownPrefix = new RegExp('^(' + ['bizcity_' + slug.replace(/^bizcity-/, '').replace(/-/g, '_'), 'bizcity_crm', 'bizcity_automation'].join('|') + ')');
const SKIP = /[\\/](_archived|_library|node_modules|tests|docs|vendor|dist)[\\/]/;
let files = 0;
function walk(p) {
	for (const e of fs.readdirSync(p, { withFileTypes: true })) {
		const c = path.join(p, e.name);
		if (SKIP.test(path.sep + path.relative(dir, c) + path.sep)) continue; // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L2 — relative to the plugin dir, so a fixture plugin under tests/ is scanned like a real one
		let isDir = e.isDirectory();
		if (!isDir && !e.isFile()) { try { isDir = fs.statSync(c).isDirectory(); } catch { continue; } } // OneDrive placeholders report neither
		if (isDir) { walk(c); continue; }
		if (!e.name.endsWith('.php') || /class-sprint-diagnostic/.test(e.name)) continue;
		files++;
		const rel = path.relative(dir, c).replace(/\\/g, '/');
		const src = fs.readFileSync(c, 'utf8');
		for (const m of src.matchAll(/@mcp bizcity-mcp-standard@1 tool ([a-z0-9_.]+)/g)) codeMarkers.add(m[1]); // S101-L2
		src.split('\n').forEach((line, i) => {
			if (/\b(require|include)(_once)?\b/.test(line) && /(dirname\([^)]*\)\s*\.\s*'\/(core|modules|includes)\/|bizcity-twin-ai\/(core|modules|includes)\/)/.test(line) && !/BIZCITY_TWIN_AI_DIR \. '\/core\/crm\/bootstrap\.php'/.test(line)) {
				add('relative_require_into_core', { file: rel, line: i + 1, text: line.trim().slice(0, 160) });
			}
		});
		for (const m of src.matchAll(/\badd_(?:action|filter)\(\s*'((?:bizcity|zalo_brain)_[a-z0-9_]+)'/g)) {
			const h = m[1];
			if (published.has(h) || ownPrefix.test(h)) continue;
			add('uses_internal_core_hook', { file: rel, hook: h });
		}
	}
}
walk(dir);

// 3b. [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.98 S98-L6 — zalo-brain-extension@1.1: the manifest's three doors are real.
//   mcp_tools[]        each name has a `@mcp bizcity-mcp-standard@1 tool <name>` marker in the plugin's PHP (WARN in 1.1, RED in 1.2 — 0.101 Q101-8);
//                      a write tool (read_only => false) near that marker declares required_cap or no_standard_cap (WARN).
//   automation_blocks[] each id has a block class (`function id()` returns it) whose meta() declares effect + guest_safe + required_cap (RED).
//   scenarios          relative JSON file exists and is an array of {slug, graph|graph_json} (RED).
//   mcp_library        relative JSON file exists; its tools[].name ⊆ mcp_tools (WARN).
if (manifest) {
	const phpSrc = [];
	(function collect(p) {
		for (const e of fs.readdirSync(p, { withFileTypes: true })) {
			const c = path.join(p, e.name);
			if (SKIP.test(path.sep + path.relative(dir, c) + path.sep)) continue; // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L2 — relative to the plugin dir, so a fixture plugin under tests/ is scanned like a real one
			if (e.isDirectory()) collect(c);
			else if (e.name.endsWith('.php')) phpSrc.push({ rel: path.relative(dir, c).replace(/\\/g, '/'), src: fs.readFileSync(c, 'utf8') });
		}
	})(dir);
	const all = phpSrc.map((f) => f.src).join('\n');
	// a door core keeps on the extension's behalf (AX-0: automation.* lives in core/mcp) counts as "in code" when its marker is in core/
	const coreSrc = (function collectCore(p, acc = []) {
		if (!fs.existsSync(p)) return acc;
		for (const e of fs.readdirSync(p, { withFileTypes: true })) {
			const c = path.join(p, e.name);
			if (SKIP.test(path.sep + path.relative(dir, c) + path.sep)) continue; // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L2 — relative to the plugin dir, so a fixture plugin under tests/ is scanned like a real one
			if (e.isDirectory()) collectCore(c, acc);
			else if (e.name.endsWith('.php')) acc.push(fs.readFileSync(c, 'utf8'));
		}
		return acc;
	})(path.join(ROOT, 'core', 'mcp')).join('\n');
	for (const name of Array.isArray(manifest.mcp_tools) ? manifest.mcp_tools : []) {
		const marker = new RegExp('@mcp bizcity-mcp-standard@1 tool ' + String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b');
		const at = all.search(marker);
		if (at < 0) { if (!marker.test(coreSrc)) add('mcp_tool_declared_not_in_code', { tool: name, severity: 'warn' }); continue; }
		const win = all.slice(at, at + 2500);
		if (/'read_only'\s*=>\s*false/.test(win) && !/'required_cap'\s*=>\s*'[a-z0-9_]+'/.test(win) && !/'no_standard_cap'\s*=>\s*true/.test(win)) add('mcp_write_tool_without_required_cap', { tool: name, severity: 'warn' });
	}
	for (const id of Array.isArray(manifest.automation_blocks) ? manifest.automation_blocks : []) {
		const idRe = new RegExp("function\\s+id\\s*\\(\\s*\\)\\s*(?::\\s*string\\s*)?\\{\\s*return\\s*'" + String(id).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + "'");
		const file = phpSrc.find((f) => idRe.test(f.src));
		if (!file) { add('automation_block_missing', { block: id }); continue; }
		const missing = ['effect', 'guest_safe', 'required_cap'].filter((k) => !new RegExp("'" + k + "'\\s*=>").test(file.src));
		if (missing.length) add('automation_block_meta_incomplete', { block: id, file: file.rel, missing });
	}
	for (const key of ['scenarios', 'mcp_library']) {
		if (!manifest[key]) continue;
		if (typeof manifest[key] !== 'string' || !fs.existsSync(path.join(dir, manifest[key]))) { add(key + '_file_missing', { path: String(manifest[key]) }); continue; }
		let j = null;
		try { j = JSON.parse(fs.readFileSync(path.join(dir, manifest[key]), 'utf8')); } catch (e) { add(key + '_invalid_json', { error: String(e.message) }); continue; }
		if (key === 'scenarios' && (!Array.isArray(j) || j.some((b) => !b || !b.slug || !(b.graph || b.graph_json)))) add('scenarios_shape', { hint: 'array of {slug, graph|graph_json}' });
		if (key === 'mcp_library') {
			const declared = new Set(manifest.mcp_tools || []);
			for (const t of Array.isArray(j?.tools) ? j.tools : []) if (!declared.has(t?.name)) add('mcp_library_tool_not_declared', { tool: String(t?.name), severity: 'warn' });
		}
	}
}

// 4. publish set (only when the plugin is its own git repo)
if (fs.existsSync(path.join(dir, '.git'))) {
	try {
		const tracked = execSync('git ls-files', { cwd: dir, encoding: 'utf8' }).split('\n').filter(Boolean);
		for (const t of tracked) if (/(^|\/)(src|node_modules|docs|tests|_archived|_library)\//.test(t)) { add('publishes_forbidden_path', { path: t }); break; }
	} catch { /* not a repo */ }
}

const internalHooks = [...new Set(findings.filter((f) => f.check === 'uses_internal_core_hook').map((f) => f.hook))];
const soft = findings.filter((f) => f.severity === 'warn'); // S98-L6: 1.1 warnings (RED from 1.2)
const hard = findings.filter((f) => f.check !== 'uses_internal_core_hook' && f.severity !== 'warn');
const report = { plugin: slug, files_scanned: files, status: hard.length ? 'FAIL' : internalHooks.length || soft.length ? 'WARN' : 'PASS', internal_hooks_used: internalHooks, findings: hard, warnings: soft };
if (asJson) console.log(JSON.stringify(report, null, 2));
else {
	console.log(`${report.status} ${slug}: ${files} PHP files, ${hard.length} hard finding(s), ${soft.length} warning(s), ${internalHooks.length} internal core hook(s) used`);
	hard.slice(0, 30).forEach((f) => console.log('  -', JSON.stringify(f)));
	soft.slice(0, 30).forEach((f) => console.log('  ~', JSON.stringify(f)));
	if (internalHooks.length) console.log('  internal hooks (allowed in v1 as WARN, publish or replace before v2):', internalHooks.join(' '));
}
process.exit(hard.length ? 1 : 0);
