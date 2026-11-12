#!/usr/bin/env node
// [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — census tách Notebook (chỉ đọc, chạy trên máy dev, R-DIAG-LOCAL).
// [2026-11-12 08:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 vòng 1 — Vault = core/skills (doc 50): đích dời là core/skills/includes/*;
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
const EXCLUDE_V = ex( [ ...BASE, 'core/skills' ] ); // V: core/skills là nhà của Vault — gọi nội bộ không tính
const EXCLUDE_KT = ex( BASE );                     // K/T: quét cả core/skills
const PORT_OK = /^core\/skills\/includes\/port\//;  // chính cổng được chạm KG

function grep( pats, exclude, pcre ) {
	try {
		const out = execFileSync( 'git', [ 'grep', '-n', pcre ? '-P' : '-E', '-I', pats.join( '|' ), '--', '*.php', ...exclude ], { cwd: TWIN, encoding: 'utf8', maxBuffer: 64 << 20 } );
		return out.split( '\n' ).filter( Boolean );
	} catch ( e ) {
		if ( 1 === e.status ) return []; // không khớp
		throw e;
	}
}
const files = ( rows ) => new Set( rows.map( ( r ) => r.split( ':' )[ 0 ] ) );

const rv = grep( V, EXCLUDE_V ), rk = grep( K, EXCLUDE_KT, true ).filter( ( r ) => ! PORT_OK.test( r ) ), rt = grep( T, EXCLUDE_KT );
console.log( 'PHASE-0.148 census — ngoài kg-hub / twinchat / twinkg (V bỏ qua core/skills = Vault; chỉ *.php, đã track git)' );
console.log( `  V Vault/Journal : ${ rv.length } dòng · ${ files( rv ).size } file   (W1: chỉ đổi đường nạp)` );
console.log( `  K KG            : ${ rk.length } dòng · ${ files( rk ).size } file   (W2: phải = 0)` );
console.log( `  T TwinChat      : ${ rt.length } dòng · ${ files( rt ).size } file   (W2: phải = 0)` );
if ( list ) {
	for ( const [ tag, rows ] of [ [ 'V', rv ], [ 'K', rk ], [ 'T', rt ] ] ) for ( const r of rows ) console.log( `${ tag } ${ r.slice( 0, 200 ) }` );
}

// Vị trí file Vault / Journal: đích = core/skills/includes/{vault,journal,access}/ (doc 50 D148-2 v2).
const MOVED = [ 'class-twin-vault.php', 'class-brain-vault-store.php', 'class-vault-upload.php', 'class-vault-tags.php', 'class-scope-meter.php', 'class-kg-private-file-store.php', 'class-brain-access.php', 'class-brain-resource-link.php', 'class-journal-index.php', 'class-journal-database.php', 'class-journal-brain-map.php', 'journal-api.php' ];
const VAULT_DIRS = [ 'core/skills/includes/vault', 'core/skills/includes/journal', 'core/skills/includes/access' ];
const where = ( f ) => VAULT_DIRS.some( ( d ) => existsSync( join( TWIN, d, f ) ) ) ? 'skills' : existsSync( join( TWIN, 'core/kg-hub/includes', f ) ) ? 'kg-hub' : 'missing';
const loc = MOVED.map( ( f ) => [ f, where( f ) ] );
const inVault = loc.filter( ( [ , w ] ) => 'skills' === w ).length;
console.log( `  Vault/Journal ở core/skills/includes/*: ${ inVault }/${ MOVED.length }` + ( inVault < MOVED.length ? `  (còn ở kg-hub: ${ loc.filter( ( [ , w ] ) => 'kg-hub' === w ).map( ( [ f ] ) => f ).join( ', ' ) })` : '' ) );
const bootPath = join( TWIN, 'core/skills/bootstrap.php' );
const vaultBoot = existsSync( bootPath ) && /BIZCITY_VAULT_LOADED/.test( readFileSync( bootPath, 'utf8' ) );
const oldKg = existsSync( join( TWIN, 'core/kg-hub/bootstrap.php' ) ), oldTc = existsSync( join( TWIN, 'modules/twinchat/bootstrap.php' ) );
console.log( `  core/skills/bootstrap.php nạp Vault (BIZCITY_VAULT_LOADED): ${ vaultBoot ? 'có' : 'chưa' } · bản cũ trong lõi: kg-hub ${ oldKg ? 'còn' : 'đã gỡ' }, twinchat ${ oldTc ? 'còn' : 'đã gỡ' }` );

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { if ( cond ) pass++; else { fail++; console.error( 'FAIL:', msg ); } };
if ( [ 'W1', 'W2', 'W4' ].includes( check ) ) {
	ok( vaultBoot, 'W1: core/skills/bootstrap.php define BIZCITY_VAULT_LOADED' );
	ok( inVault === MOVED.length, 'W1: mọi file Vault/Journal ở core/skills/includes/*' );
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
