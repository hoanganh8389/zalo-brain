/**
 * SetupGate — one dialog for "this needs the assistant (cell) but the four steps are not done" (R-SETUP-4, PHASE-0.83 S83-G1, doc 20).
 *
 * A host (Scheduler, Kho tri thức, Cài đặt…) calls it ONLY when the person presses an action that needs the cell:
 *     BizSetupGate.ensure({ reason: 'học cả website' }).then(function (ok) { if (ok) { …do it… } });
 * Ready ⇒ resolves true at once, no dialog. Not ready ⇒ the dialog shows the four steps (state from ONE owner, GET bizcity-channel/v1/zalo-connection/status?scope=mine),
 * the first step that is not done with its reason and what to do, and "Thiết lập ngay" mounts the shared quicksetup bundle inside the dialog. Resolves true when the
 * person presses "Tiếp tục" (all four Đạt), false when they press "Để sau" / ✕ / Esc. Viewing data never calls it.
 *
 * Plain JS, no build step, no dependency: the host only needs window.BIZCITY_SETUP_GATE (printed by BizCity_Setup_Gate). Text from the server is written with textContent.
 * R-GW-8: when the status cannot be read the gate does not lie — it says so and offers "Thử lại" and "Vẫn tiếp tục".
 *
 * [2026-11-23 05:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.83 S83-G1 — new file. Mockup: docs/PHASE-0.83-FOUR-STEP-SETUP/mockups/PHASE-0.83-setup-gate-mockup.html
 */
( function ( root ) {
	'use strict';

	var STEPS = [
		[ 'Kết nối tài khoản BizCity', 'Khoá 1API của website' ],
		[ 'Kết nối máy chủ Zalo', 'Zalo Hub của BizCity' ],
		[ 'Đăng nhập số Zalo', 'Quét QR bằng Zalo trên điện thoại' ],
		[ 'Chọn Agent Guru trả lời', 'Bộ não trả lời khách' ]
	];
	var RANK = { fail: 3, warn: 2, ok: 1, skip: 0 };
	var READY_TTL = 120000; // a "ready" answer is remembered 2 minutes (a not-ready one never is)
	var POLL_MS = 10000;

	/** the layer with the worst status among `ids` ({status:'ok'} when none) */
	function worst( layers, ids ) {
		var pick = null;
		( layers || [] ).forEach( function ( l ) {
			if ( ids.indexOf( l.id ) < 0 ) { return; }
			if ( ! pick || ( RANK[ l.status ] || 0 ) > ( RANK[ pick.status ] || 0 ) ) { pick = l; }
		} );
		return pick || { status: 'ok' };
	}

	function layerOf( layers, id ) {
		var out = null;
		( layers || [] ).forEach( function ( l ) { if ( l.id === id ) { out = l; } } );
		return out;
	}

	/**
	 * report (bizcity-zalo-connection/1.0, scope=mine) ⇒ { steps:[{n,title,what,state:'ok'|'bad'|'lock',message,hint,action}], done, first, ready }
	 * ① L0–L4 · ② L5–L6 (site layers; only `fail` blocks, a warning does not) · ③ a number of the person with a live Zalo session ·
	 * ④ that number has its Agent Guru / bot config (L9 not `fail`). Steps after the first one not done are `lock`. Pure.
	 */
	function stepsOf( report ) {
		var layers = ( report && report.layers ) || [];
		var accounts = ( report && report.accounts ) || [];
		var raw = [];
		var s1 = worst( layers, [ 'L0', 'L1', 'L2', 'L3', 'L4' ] );
		raw.push( s1.status === 'fail' ? { bad: true, l: s1 } : { bad: false } );
		var s2 = worst( layers, [ 'L5', 'L6' ] );
		raw.push( s2.status === 'fail' ? { bad: true, l: s2 } : { bad: false } );
		var live = accounts.filter( function ( a ) { return a && a.session === 'connected'; } );
		if ( live.length ) {
			raw.push( { bad: false } );
			var guru = live.some( function ( a ) { var l9 = layerOf( a.layers, 'L9' ); return ! l9 || l9.status !== 'fail'; } );
			var l9bad = layerOf( live[ 0 ].layers, 'L9' );
			raw.push( guru ? { bad: false } : { bad: true, l: l9bad || { message: 'Số Zalo chưa gắn Agent Guru.', hint: 'Chọn Agent Guru để trợ lý trả lời.', action: 'setup' } } );
		} else {
			var a0 = accounts[ 0 ];
			var l7 = a0 ? layerOf( a0.layers, 'L7' ) : null;
			raw.push( { bad: true, l: l7 || { message: 'Bạn chưa có số Zalo nào đăng nhập.', hint: 'Quét QR bằng Zalo trên điện thoại của số đó.', action: 'setup' } } );
			raw.push( { bad: false } );
		}
		var first = -1;
		var steps = raw.map( function ( r, i ) {
			var state = 'ok';
			if ( first >= 0 ) { state = 'lock'; } else if ( r.bad ) { state = 'bad'; first = i; }
			return {
				n: i + 1, title: STEPS[ i ][ 0 ], what: STEPS[ i ][ 1 ], state: state,
				message: r.l ? String( r.l.message || '' ) : '', hint: r.l ? String( r.l.hint || '' ) : '', action: r.l ? String( r.l.action || '' ) : ''
			};
		} );
		return { steps: steps, done: steps.filter( function ( s ) { return s.state === 'ok'; } ).length, first: first, ready: first < 0 };
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined && text !== null ) { n.textContent = text; }
		return n;
	}

	var cfg = function () { return root.BIZCITY_SETUP_GATE || {}; };
	var readyAt = 0;
	var dialog = null; // the open dialog (one at a time)

	function fetchReport( force ) {
		var c = cfg();
		if ( ! c.rest ) { return Promise.reject( new Error( 'no_config' ) ); }
		return fetch( c.rest + 'zalo-connection/status?scope=mine' + ( force ? '&force=1' : '' ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': c.nonce || '' }
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'http_' + r.status ); }
			return r.json();
		} );
	}

	/* ---------- embedded quicksetup (the same bundle every host uses) ---------- */
	var bundleLoading = null;
	function loadBundle() {
		var q = cfg().quicksetup || {};
		if ( root.BIZCITY_CG_QUICKSETUP ) { root.BIZCITY_CG_QUICKSETUP.mount(); return Promise.resolve(); }
		if ( bundleLoading ) { return bundleLoading; }
		if ( ! q.jsUrl ) { return Promise.reject( new Error( 'no_bundle' ) ); }
		root.BIZCITY_CG_BOOT = root.BIZCITY_CG_BOOT || q.boot;
		bundleLoading = new Promise( function ( resolve, reject ) {
			if ( q.cssUrl ) {
				var css = document.createElement( 'link' );
				css.rel = 'stylesheet';
				css.href = q.cssUrl;
				document.head.appendChild( css );
			}
			var js = document.createElement( 'script' );
			js.src = q.jsUrl;
			js.onload = function () { resolve(); }; // the bundle mounts itself on #bizcity-channel-gateway-root
			js.onerror = function () { bundleLoading = null; reject( new Error( 'load_failed' ) ); };
			document.body.appendChild( js );
		} );
		return bundleLoading;
	}

	/* ---------- the dialog ---------- */
	function close( result ) {
		if ( ! dialog ) { return; }
		var d = dialog;
		dialog = null;
		clearInterval( d.timer );
		if ( root.BIZCITY_CG_QUICKSETUP && d.embedded ) { try { root.BIZCITY_CG_QUICKSETUP.unmount(); } catch ( e ) { /* ignore */ } }
		document.removeEventListener( 'keydown', d.onKey, true );
		if ( d.scrim.parentNode ) { d.scrim.parentNode.removeChild( d.scrim ); }
		if ( d.prevFocus && d.prevFocus.focus ) { try { d.prevFocus.focus(); } catch ( e ) { /* ignore */ } }
		d.resolve( !! result );
	}

	function render( d ) {
		var c = cfg();
		var box = d.box;
		while ( box.firstChild ) { box.removeChild( box.firstChild ); }
		var view = d.view; // { err } | stepsOf result
		var head = el( 'header', 'bzsg-head' );
		var t = el( 'div' );
		var title = el( 'h3', 'bzsg-title', view.err ? 'Chưa kiểm tra được kết nối' : ( view.ready ? 'Đã sẵn sàng — tiếp tục thôi' : 'Cần kết nối để trợ lý làm việc này' ) );
		title.id = 'bzsg-title';
		t.appendChild( title );
		t.appendChild( el( 'p', 'bzsg-sub', view.err
			? 'Không tải được trạng thái từ website.'
			: ( d.reason ? 'Thao tác “' + d.reason + '” cần trợ lý (cell) nhận việc. ' : 'Thao tác này cần trợ lý (cell) nhận việc. ' ) + 'Làm lần lượt 4 bước, mỗi bước tự kiểm tra.' ) );
		head.appendChild( t );
		var x = el( 'button', 'bzsg-x', '×' );
		x.type = 'button';
		x.setAttribute( 'aria-label', 'Đóng' );
		x.addEventListener( 'click', function () { close( false ); } );
		head.appendChild( x );
		box.appendChild( head );

		var body = el( 'div', 'bzsg-body' );
		if ( view.err ) {
			var w = el( 'div', 'bzsg-why' );
			w.appendChild( el( 'b', '', 'Không đọc được trạng thái kết nối. ' ) );
			w.appendChild( document.createTextNode( 'Kiểm tra mạng của website rồi bấm Thử lại. ' ) );
			w.appendChild( el( 'small', '', '(mã: status_unreachable)' ) );
			body.appendChild( w );
		} else {
			var prog = el( 'div', 'bzsg-prog' );
			var bar = el( 'div', 'bzsg-bar' );
			var fill = el( 'i' );
			fill.style.width = ( view.done * 25 ) + '%';
			bar.appendChild( fill );
			prog.appendChild( bar );
			prog.appendChild( el( 'b', '', view.done + '/4 bước xong' ) );
			body.appendChild( prog );
			view.steps.forEach( function ( s ) {
				var row = el( 'div', 'bzsg-st bzsg-' + s.state + ( d.busy && s.n - 1 === view.first ? ' bzsg-run' : '' ) );
				row.appendChild( el( 'span', 'bzsg-n', s.state === 'ok' ? '✓' : String( s.n ) ) );
				var mid = el( 'div' );
				mid.appendChild( el( 'b', '', 'Bước ' + s.n + ' · ' + s.title ) );
				mid.appendChild( el( 'small', '', s.what ) );
				if ( s.state === 'bad' && ( s.message || s.hint ) ) {
					var why = el( 'div', 'bzsg-why' );
					why.setAttribute( 'role', 'alert' );
					why.textContent = ( s.message + ( s.hint ? ' ' + s.hint : '' ) ).trim();
					mid.appendChild( why );
				}
				row.appendChild( mid );
				var pill = s.state === 'ok' ? [ 'bzsg-pill bzsg-pill-ok', 'Đạt' ] : s.state === 'bad' ? ( d.busy ? [ 'bzsg-pill bzsg-pill-run', 'Đang kiểm tra…' ] : [ 'bzsg-pill bzsg-pill-bad', 'Chưa đạt' ] ) : [ 'bzsg-pill', 'Chờ bước trước' ];
				row.appendChild( el( 'span', pill[ 0 ], pill[ 1 ] ) );
				body.appendChild( row );
			} );
			if ( d.embedded ) {
				// the SAME node on every redraw: the quicksetup React root lives in it
				if ( ! d.hold ) {
					d.hold = el( 'div', 'bzsg-embed' );
					d.hold.id = 'bizcity-channel-gateway-root';
				}
				body.appendChild( d.hold );
			}
		}
		box.appendChild( body );

		var foot = el( 'footer', 'bzsg-foot' );
		var later = el( 'button', 'bzsg-btn bzsg-sec', 'Để sau' );
		later.type = 'button';
		later.addEventListener( 'click', function () { close( false ); } );
		foot.appendChild( later );
		if ( ! view.err ) {
			var re = el( 'button', 'bzsg-btn bzsg-sec', d.busy ? 'Đang kiểm tra…' : 'Kiểm tra lại' );
			re.type = 'button';
			re.disabled = !! d.busy;
			re.addEventListener( 'click', function () { recheck( d, true ); } );
			foot.appendChild( re );
		}
		var primary = null;
		if ( view.err ) {
			var again = el( 'button', 'bzsg-btn bzsg-sec', 'Vẫn tiếp tục' );
			again.type = 'button';
			again.addEventListener( 'click', function () { close( true ); } ); // R-GW-8: not knowing is not "blocked"
			foot.appendChild( again );
			primary = el( 'button', 'bzsg-btn', 'Thử lại' );
			primary.addEventListener( 'click', function () { recheck( d, true ); } );
		} else if ( view.ready ) {
			primary = el( 'button', 'bzsg-btn', 'Tiếp tục' );
			primary.addEventListener( 'click', function () { readyAt = Date.now(); close( true ); } );
		} else if ( view.first <= 1 && ! c.canManage ) {
			// ① ② belong to the website administrator (R-SETUP-4 R-S4-20)
			primary = el( 'button', 'bzsg-btn', 'Chờ quản trị viên' );
			primary.disabled = true;
		} else if ( ! d.embedded ) {
			primary = el( 'button', 'bzsg-btn', 'Thiết lập ngay' );
			primary.addEventListener( 'click', function () { startSetup( d ); } );
		}
		if ( primary ) {
			primary.type = 'button';
			foot.appendChild( primary );
		}
		box.appendChild( foot );
		if ( ! d.focused ) { d.focused = true; box.focus(); }
	}

	function startSetup( d ) {
		d.embedded = true;
		render( d );
		loadBundle().catch( function () {
			d.embedded = false;
			var a = cfg().adminUrl;
			var w = d.box.querySelector( '.bzsg-body' );
			if ( w ) {
				var m = el( 'div', 'bzsg-why', 'Không tải được màn hình kết nối. ' );
				if ( a ) {
					var link = el( 'a', '', 'Mở Channel Gateway' );
					link.href = a;
					link.target = '_top';
					m.appendChild( link );
				}
				w.appendChild( m );
			}
		} );
		// while the shared setup runs inside, keep reading the state: all four Đạt ⇒ the dialog turns into "Tiếp tục"
		clearInterval( d.timer );
		d.timer = setInterval( function () { recheck( d, false, true ); }, POLL_MS );
	}

	function recheck( d, force, quiet ) {
		if ( d.busy ) { return; }
		if ( ! quiet ) { d.busy = true; render( d ); }
		fetchReport( force ).then( function ( rep ) {
			d.busy = false;
			var v = stepsOf( rep );
			if ( v.ready && d.embedded && root.BIZCITY_CG_QUICKSETUP ) { try { root.BIZCITY_CG_QUICKSETUP.unmount(); } catch ( e ) { /* ignore */ } d.embedded = false; clearInterval( d.timer ); }
			if ( quiet && ! v.ready && d.view && ! d.view.err && JSON.stringify( v.steps.map( function ( s ) { return s.state; } ) ) === JSON.stringify( d.view.steps.map( function ( s ) { return s.state; } ) ) ) { return; } // nothing moved: do not redraw over the embedded setup
			d.view = v;
			if ( dialog === d ) { render( d ); }
		} ).catch( function () {
			d.busy = false;
			if ( quiet ) { return; }
			d.view = { err: true };
			if ( dialog === d ) { render( d ); }
		} );
	}

	/** open the dialog for a given view (a report already read, or { err:true }) */
	function open( view, opts ) {
		if ( dialog ) { close( false ); }
		return new Promise( function ( resolve ) {
			var scrim = el( 'div', 'bzsg-scrim' );
			var box = el( 'div', 'bzsg-dlg' );
			box.setAttribute( 'role', 'dialog' );
			box.setAttribute( 'aria-modal', 'true' );
			box.setAttribute( 'aria-labelledby', 'bzsg-title' );
			box.tabIndex = -1;
			scrim.appendChild( box );
			var d = { scrim: scrim, box: box, view: view, reason: ( opts && opts.reason ) || '', resolve: resolve, prevFocus: document.activeElement, busy: false, embedded: false, timer: 0 };
			d.onKey = function ( e ) {
				if ( 'Escape' === e.key ) { e.stopPropagation(); close( false ); return; }
				if ( 'Tab' === e.key ) { // keep the focus inside the dialog
					var f = box.querySelectorAll( 'button:not([disabled]),a[href],input,select,textarea' );
					if ( ! f.length ) { return; }
					var first = f[ 0 ];
					var last = f[ f.length - 1 ];
					if ( e.shiftKey && ( document.activeElement === first || document.activeElement === box ) ) { e.preventDefault(); last.focus(); }
					else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
				}
			};
			document.addEventListener( 'keydown', d.onKey, true );
			scrim.addEventListener( 'mousedown', function ( e ) { if ( e.target === scrim ) { close( false ); } } );
			document.body.appendChild( scrim );
			dialog = d;
			render( d );
		} );
	}

	/**
	 * @param {{reason?:string, force?:boolean}} opts reason = the verb phrase of the action ("học cả website", "tạo nhắc việc")
	 * @returns {Promise<boolean>} true = go on (ready, or the person pressed Tiếp tục / Vẫn tiếp tục); false = not now
	 */
	function ensure( opts ) {
		opts = opts || {};
		if ( ! opts.force && Date.now() - readyAt < READY_TTL ) { return Promise.resolve( true ); }
		return fetchReport( !! opts.force ).then( function ( rep ) {
			var v = stepsOf( rep );
			if ( v.ready ) { readyAt = Date.now(); return true; }
			return open( v, opts );
		}, function () {
			return open( { err: true }, opts );
		} );
	}

	root.BizSetupGate = { ensure: ensure, stepsOf: stepsOf, show: function ( report, opts ) { return open( stepsOf( report ), opts ); }, close: function () { close( false ); }, isOpen: function () { return !! dialog; } };
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = { stepsOf: stepsOf }; }
}( typeof window !== 'undefined' ? window : this ) );
