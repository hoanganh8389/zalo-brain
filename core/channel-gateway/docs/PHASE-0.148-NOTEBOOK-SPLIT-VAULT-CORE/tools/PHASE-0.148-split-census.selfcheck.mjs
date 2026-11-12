#!/usr/bin/env node
// [2026-11-12 08:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W0 — census tách Notebook (chỉ đọc, chạy trên máy dev, R-DIAG-LOCAL).
// Đếm chỗ NGOÀI kg-hub / twinchat / twinkg / core/vault gọi thẳng:
//   V = lớp Vault / Journal (chỉ cần đổi đường nạp ở W1)   K = lớp KG (phải qua BizCity_Notebook_Port ở W2)   T = TwinChat (W2)
// Và kiểm vị trí file Vault / Journal (W1), sự vắng mặt của bản cũ (W4).
// Chạy (từ bizcity-twin-ai/):
//   node core/channel-gateway/docs/PHASE-0.148-NOTEBOOK-SPLIT-VAULT-CORE/tools/PHASE-0.148-split-census.selfcheck.mjs            báo cáo, luôn exit 0
//   … --check=W1 | --check=W2 | --check=W4                                                                                   đỏ nếu đợt chưa đạt
//   … --list                                                                                                                in từng file:dòng
// Dùng `git grep` (grep thường quá chậm trên OneDrive).
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const TWIN = join( HERE, '..', '..', '..', '..', '..' );
const args = process.argv.slice( 2 );
const check = ( args.find( ( a ) => a.startsWith( '--check=' ) ) || '' ).split( '=' )[ 1 ] || '';
const list = args.includes( '--list' );

const V = [ 'BizCity_Journal_[A-Za-z_]+', 'BizCity_Twin_Vault', 'BizCity_Brain_Vault_Store', 'BizCity_Vault_(Upload|Tags)', 'BizCity_Scope_Meter', 'BizCity_Brain_Access', 'BizCity_Brain_Resource_Link', 'BizCity_Twin_Sheet_[A-Za-z_]+', 'bizcity_journal_record' ];
const K = [ 'BizCity_KG_[A-Za-z_]+', 'BizCity_KG\\b', 'BizCity_TwinSearch_Core', 'bizcity_kg_validate_citations_in_json', 'bizcity_kg_vector_bin_path', "bizcity_kg_(notebooks|passages|sources|workspaces)'" ];
const T = [ 'BizCity_TwinChat_[A-Za-z_]+', 'BIZCITY_TWINCHAT_[A-Z_]+' ];
// Chỗ được phép chạm KG/T sau W2: chính cổng + bộ dời (cũ) + tài liệu/test.
const EXCLUDE = [ 'core/kg-hub', 'modules/twinchat', 'modules/twinkg', 'core/vault', '**/docs/**', '**/tests/**', '**/_archived/**', 'zalo-hub', 'vendor', '**/node_modules/**', '**/dist/**', 'backups' ].map( ( p ) => `:(exclude,glob)${ p }` );
const PORT_OK = /core\/vault\/includes\/port\//;

function grep( pats ) {
	try {
		const out = execFileSync( 'git', [ 'grep', '-n', '-E', '-I', pats.join( '|' ), '--', '*.php', ...EXCLUDE ], { cwd: TWIN, encoding: 'utf8', maxBuffer: 64 << 20 } );
		return out.split( '\n' ).filter( Boolean );
	} catch ( e ) {
		if ( 1 === e.status ) return []; // không khớp
		throw e;
	}
}
const files = ( rows ) => new Set( rows.map( ( r ) => r.split( ':' )[ 0 ] ) );

const rv = grep( V ), rk = grep( K ).filter( ( r ) => ! PORT_OK.test( r ) ), rt = grep( T );
console.log( 'PHASE-0.148 census — ngoài kg-hub / twinchat / twinkg / core/vault (chỉ *.php, đã track git)' );
console.log( `  V Vault/Journal : ${ rv.length } dòng · ${ files( rv ).size } file   (W1: chỉ đổi đường nạp)` );
console.log( `  K KG            : ${ rk.length } dòng · ${ files( rk ).size } file   (W2: phải = 0)` );
console.log( `  T TwinChat      : ${ rt.length } dòng · ${ files( rt ).size } file   (W2: phải = 0)` );
if ( list ) {
	for ( const [ tag, rows ] of [ [ 'V', rv ], [ 'K', rk ], [ 'T', rt ] ] ) for ( const r of rows ) console.log( `${ tag } ${ r.slice( 0, 200 ) }` );
}

// Vị trí file Vault / Journal.
const MOVED = [ 'class-twin-vault.php', 'class-brain-vault-store.php', 'class-vault-upload.php', 'class-vault-tags.php', 'class-scope-meter.php', 'class-brain-access.php', 'class-brain-resource-link.php', 'class-journal-index.php', 'class-journal-database.php', 'class-journal-brain-map.php', 'journal-api.php' ];
const VAULT_DIRS = [ 'core/vault/includes', 'core/vault/includes/journal', 'core/vault/includes/vault', 'core/vault/includes/access' ];
const where = ( f ) => VAULT_DIRS.some( ( d ) => existsSync( join( TWIN, d, f ) ) ) ? 'vault' : existsSync( join( TWIN, 'core/kg-hub/includes', f ) ) ? 'kg-hub' : 'missing';
const loc = MOVED.map( ( f ) => [ f, where( f ) ] );
const inVault = loc.filter( ( [ , w ] ) => 'vault' === w ).length;
console.log( `  Vault/Journal ở core/vault: ${ inVault }/${ MOVED.length }` + ( inVault < MOVED.length ? `  (còn ở kg-hub: ${ loc.filter( ( [ , w ] ) => 'kg-hub' === w ).map( ( [ f ] ) => f ).join( ', ' ) })` : '' ) );
const vaultBoot = existsSync( join( TWIN, 'core/vault/bootstrap.php' ) );
const oldKg = existsSync( join( TWIN, 'core/kg-hub/bootstrap.php' ) ), oldTc = existsSync( join( TWIN, 'modules/twinchat/bootstrap.php' ) );
console.log( `  core/vault/bootstrap.php: ${ vaultBoot ? 'có' : 'chưa' } · bản cũ trong lõi: kg-hub ${ oldKg ? 'còn' : 'đã gỡ' }, twinchat ${ oldTc ? 'còn' : 'đã gỡ' }` );

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { if ( cond ) pass++; else { fail++; console.error( 'FAIL:', msg ); } };
if ( [ 'W1', 'W2', 'W4' ].includes( check ) ) {
	ok( vaultBoot, 'W1: core/vault/bootstrap.php' );
	ok( inVault === MOVED.length, 'W1: mọi file Vault/Journal ở core/vault' );
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
