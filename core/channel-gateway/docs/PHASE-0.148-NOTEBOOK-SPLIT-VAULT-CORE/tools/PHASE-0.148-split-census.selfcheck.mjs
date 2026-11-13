#!/usr/bin/env node
// [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — census tách Notebook (chỉ đọc, chạy trên máy dev, R-DIAG-LOCAL).
// [2026-11-12 08:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 vòng 1 — Vault = core/skills. [2026-11-12 09:17 PM] vòng 2 (doc 60) — ba module core/vault (đổi tên) · core/journal · core/sheet;
//   core/skills không tính vào V (gọi nội bộ của Vault) nhưng VẪN bị quét K/T (Vault không được dính KG / TwinChat).
// Đếm chỗ NGOÀI kg-hub / twinchat / twinkg gọi thẳng:
//   V = lớp Vault / Journal (chỉ cần đổi đường nạp ở W1)   K = lớp KG (phải qua BizCity_Notebook_Port ở W2)   T = TwinChat (W2)
// Và kiểm vị trí file Vault / Journal (W1), sự vắng mặt của bản cũ (W4).
// Chạy (từ bizcity-twin-ai/):
//   node core/channel-gateway/docs/PHASE-0.148-NOTEBOOK-SPLIT-VAULT-CORE/tools/PHASE-0.148-split-census.selfcheck.mjs            báo cáo, luôn exit 0
//   … --check=W1 | --check=W2 | --check=W4                                                                                   đỏ nếu đợt chưa đạt
//   … --list                                                                                                                in từng file:dòng
// Dùng `git grep` (grep thường quá chậm trên OneDrive).
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const TWIN = join( HERE, '..', '..', '..', '..', '..' );
const args = process.argv.slice( 2 );
const check = ( args.find( ( a ) => a.startsWith( '--check=' ) ) || '' ).split( '=' )[ 1 ] || '';
const list = args.includes( '--list' );

const V = [ 'BizCity_Journal_[A-Za-z_]+', 'BizCity_Twin_Vault', 'BizCity_Brain_Vault_Store', 'BizCity_Vault_(Upload|Tags)', 'BizCity_Scope_Meter', 'BizCity_Brain_Access', 'BizCity_Brain_Resource_Link', 'BizCity_Twin_Sheet_[A-Za-z_]+', 'bizcity_journal_record' ];
// KG_Private_File_Store là kho tệp của Vault (D148-14) ⇒ không tính K.
const K = [ 'BizCity_KG_(?!Private_File_Store)[A-Za-z_]+', 'BizCity_KG\\b', 'BizCity_TwinSearch_Core', 'bizcity_kg_validate_citations_in_json', 'bizcity_kg_vector_bin_path', "bizcity_kg_(notebooks|passages|sources|workspaces)'" ];
const T = [ 'BizCity_TwinChat_[A-Za-z_]+', 'BIZCITY_TWINCHAT_[A-Z_]+' ];
const BASE = [ 'core/kg-hub', 'modules/twinchat', 'modules/twinkg', '**/docs/**', '**/tests/**', '**/_archived/**', 'zalo-hub', 'vendor', '**/node_modules/**', '**/dist/**', 'backups' ];
const ex = ( l ) => l.map( ( p ) => `:(exclude,glob)${ p }` );
// V: ba module lõi gọi nhau nội bộ không tính (core/skills = tên cũ của core/vault, giữ trong lúc chuyển).
const EXCLUDE_V = ex( [ ...BASE, 'core/vault', 'core/journal', 'core/sheet', 'core/skills' ] );
const EXCLUDE_KT = ex( BASE ); // K/T: quét cả ba module lõi — Vault / Journal / Sheet không được dính KG / TwinChat
const PORT_OK = /^core\/(vault|skills)\/includes\/vault\/class-notebook-port\.php:/; // chính cổng được chạm KG

function grep( pats, exclude, pcre, only ) {
	try {
		const out = execFileSync( 'git', [ 'grep', '-n', pcre ? '-P' : '-E', '-I', pats.join( '|' ), '--', only ? `${ only }/*.php` : '*.php', ...exclude ], { cwd: TWIN, encoding: 'utf8', maxBuffer: 64 << 20 } );
		return out.split( '\n' ).filter( Boolean );
	} catch ( e ) {
		if ( 1 === e.status ) return []; // không khớp
		throw e;
	}
}
const files = ( rows ) => new Set( rows.map( ( r ) => r.split( ':' )[ 0 ] ) );

const rv = grep( V, EXCLUDE_V ), rk = grep( K, EXCLUDE_KT, true ).filter( ( r ) => ! PORT_OK.test( r ) ), rt = grep( T, EXCLUDE_KT );
console.log( 'PHASE-0.148 census — ngoài kg-hub / twinchat / twinkg (V bỏ qua core/vault · core/journal · core/sheet; chỉ *.php, đã track git)' );
console.log( `  V Vault/Journal : ${ rv.length } dòng · ${ files( rv ).size } file   (W1: chỉ đổi đường nạp)` );
console.log( `  K KG            : ${ rk.length } dòng · ${ files( rk ).size } file   (W2: phải = 0)` );
console.log( `  T TwinChat      : ${ rt.length } dòng · ${ files( rt ).size } file   (W2: phải = 0)` );
if ( list ) {
	for ( const [ tag, rows ] of [ [ 'V', rv ], [ 'K', rk ], [ 'T', rt ] ] ) for ( const r of rows ) console.log( `${ tag } ${ r.slice( 0, 200 ) }` );
}

// [2026-11-12 09:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 vòng 2 (doc 60) — ba module: core/journal · core/sheet · core/vault (đổi tên từ core/skills).
// Vị trí đích từng file (file → thư mục, tính từ bizcity-twin-ai/).
const DEST = {
	'class-journal-index.php': 'core/journal/includes', 'class-journal-database.php': 'core/journal/includes', 'class-journal-brain-map.php': 'core/journal/includes',
	'journal-api.php': 'core/journal/includes', 'class-brain-access.php': 'core/journal/includes', 'class-brain-resource-link.php': 'core/journal/includes',
	'class-twin-sheet-api.php': 'core/sheet/includes',
	'class-twin-vault.php': 'core/vault/includes/vault', 'class-brain-vault-store.php': 'core/vault/includes/vault', 'class-vault-upload.php': 'core/vault/includes/vault',
	'class-vault-tags.php': 'core/vault/includes/vault', 'class-scope-meter.php': 'core/vault/includes/vault', 'class-kg-private-file-store.php': 'core/vault/includes/vault',
};
const OLD = ( f ) => [ 'core/kg-hub/includes', 'core/kg-hub/includes/twin-sheet' ].some( ( d ) => existsSync( join( TWIN, d, f ) ) );
const loc = Object.entries( DEST ).map( ( [ f, d ] ) => [ f, existsSync( join( TWIN, d, f ) ) ? 'moved' : OLD( f ) ? 'kg-hub' : 'missing' ] );
const moved = loc.filter( ( [ , w ] ) => 'moved' === w ).length;
console.log( `  Đã ở đích (core/journal · core/sheet · core/vault): ${ moved }/${ loc.length }` + ( moved < loc.length ? `  (còn ở kg-hub: ${ loc.filter( ( [ , w ] ) => 'kg-hub' === w ).map( ( [ f ] ) => f ).join( ', ' ) })` : '' ) );
const hasConst = ( rel, c ) => existsSync( join( TWIN, rel ) ) && readFileSync( join( TWIN, rel ), 'utf8' ).includes( c );
const boots = { journal: hasConst( 'core/journal/bootstrap.php', 'BIZCITY_JOURNAL_LOADED' ), sheet: hasConst( 'core/sheet/bootstrap.php', 'BIZCITY_SHEET_LOADED' ), vault: hasConst( 'core/vault/bootstrap.php', 'BIZCITY_VAULT_LOADED' ) };
const renamed = ! existsSync( join( TWIN, 'core/skills/bootstrap.php' ) ) && existsSync( join( TWIN, 'core/vault/bootstrap.php' ) );
const oldKg = existsSync( join( TWIN, 'core/kg-hub/bootstrap.php' ) ), oldTc = existsSync( join( TWIN, 'modules/twinchat/bootstrap.php' ) );
console.log( `  bootstrap: journal ${ boots.journal ? '✔' : '·' } · sheet ${ boots.sheet ? '✔' : '·' } · vault ${ boots.vault ? '✔' : '·' } · core/skills ⇒ core/vault ${ renamed ? 'đã đổi' : 'chưa' } · bản cũ trong lõi: kg-hub ${ oldKg ? 'còn' : 'đã gỡ' }, twinchat ${ oldTc ? 'còn' : 'đã gỡ' }` );
// D148-19 — phụ thuộc một chiều: journal không gọi sheet / vault; sheet không gọi vault.
const dirty = ( dir, pats ) => existsSync( join( TWIN, dir ) ) ? grep( pats, [], false, dir ) : [];
const jUp = dirty( 'core/journal', [ 'BizCity_Twin_Vault', 'BizCity_Brain_Vault_Store', 'BizCity_Vault_(Upload|Tags)', 'BizCity_Scope_Meter', 'BizCity_Twin_Sheet_[A-Za-z_]+' ] );
const sUp = dirty( 'core/sheet', [ 'BizCity_Twin_Vault', 'BizCity_Brain_Vault_Store', 'BizCity_Vault_(Upload|Tags)' ] );
console.log( `  D148-19 một chiều: journal → sheet/vault ${ jUp.length } chỗ · sheet → vault ${ sUp.length } chỗ` );

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { if ( cond ) pass++; else { fail++; console.error( 'FAIL:', msg ); } };
if ( [ 'W1', 'W2', 'W4' ].includes( check ) ) {
	ok( renamed, 'W1a: core/skills đã đổi tên core/vault' );
	ok( boots.journal && boots.sheet && boots.vault, 'W1: ba bootstrap define BIZCITY_{JOURNAL,SHEET,VAULT}_LOADED' );
	ok( moved === loc.length, 'W1: mọi file ở đích (doc 60 §2)' );
	ok( 0 === jUp.length && 0 === sUp.length, 'W1: D148-19 phụ thuộc một chiều (chạy --list)' );
}
if ( [ 'W2', 'W4' ].includes( check ) ) {
	ok( 0 === rk.length, `W2: còn ${ rk.length } chỗ gọi thẳng KG (chạy --list)` );
	ok( 0 === rt.length, `W2: còn ${ rt.length } chỗ gọi thẳng TwinChat (chạy --list)` );
}
if ( 'W4' === check ) {
	ok( ! oldKg, 'W4: core/kg-hub đã gỡ khỏi lõi' );
	ok( ! oldTc, 'W4: modules/twinchat đã gỡ khỏi lõi' );
}
console.log( `[claude-handoff] PHASE-0.148-split-census.selfcheck check=${ check || 'report' } pass=${ pass } fail=${ fail }` );
process.exit( fail ? 1 : 0 );
