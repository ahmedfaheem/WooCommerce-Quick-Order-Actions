/**
 * WooCommerce Quick Order Actions - orders list inline editing.
 * Vanilla JS, no dependencies. Loaded only on the orders list screen.
 */
( function () {
	'use strict';

	var cfg = window.wcqoaData;
	var table = document.querySelector( 'table.wp-list-table' );
	if ( ! cfg || ! table ) {
		return;
	}

	var i18n = cfg.i18n;
	var FIELDS = [ 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];
	var DASH = '\u2013';
	var statesCache = {};
	var busy = {};
	var pop = null;
	var activeBtn = null;
	var toastTimer = null;

	/* ---------- helpers ---------- */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				node.appendChild( c );
			}
		} );
		return node;
	}

	function field( labelText, control ) {
		return el( 'label', { 'class': 'wcqoa-field' }, [ el( 'span', { 'class': 'wcqoa-label', text: labelText } ), control ] );
	}

	function isRtl() {
		return document.documentElement.dir === 'rtl' || document.body.classList.contains( 'rtl' );
	}

	function request( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			fd.append( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: fd } )
			.then( function ( r ) {
				return r.json();
			} )
			.catch( function () {
				return { success: false, data: { message: i18n.error } };
			} );
	}

	function normalizeStates( s ) {
		return s && ! Array.isArray( s ) && typeof s === 'object' ? s : {};
	}

	function toast( message, isError ) {
		var old = document.querySelector( '.wcqoa-toast' );
		if ( old ) {
			old.remove();
		}
		clearTimeout( toastTimer );
		var t = el( 'div', { 'class': 'wcqoa-toast' + ( isError ? ' is-error' : '' ), role: 'status', 'aria-live': 'polite', text: message } );
		document.body.appendChild( t );
		toastTimer = setTimeout( function () {
			t.remove();
		}, 3500 );
	}

	/* ---------- icons ---------- */

	var ICON_MAP = [
		{ type: 'status', cell: 'td.column-order_status', label: i18n.changeStatus },
		{ type: 'note', cell: 'td.column-order_number', label: i18n.addNote },
		{ type: 'address', group: 'billing', cell: 'td.column-billing_address', label: i18n.editAddress + ' (' + i18n.billing + ')' },
		{ type: 'address', group: 'shipping', cell: 'td.column-shipping_address', label: i18n.editAddress + ' (' + i18n.shipping + ')' },
		{ type: 'phone', cell: 'td.column-wcqoa_phone', label: i18n.editPhone }
	];

	function rowOrderId( row ) {
		var m = /^(?:order|post)-(\d+)$/.exec( row.id || '' );
		return m ? m[ 1 ] : null;
	}

	var notesKey;

	/** Column key of the list-table "notes" column (configured, or detected from its header). */
	function getNotesKey() {
		if ( notesKey !== undefined ) {
			return notesKey;
		}
		notesKey = cfg.notesColumn || null;
		if ( notesKey ) {
			return notesKey;
		}
		var ths = Array.prototype.slice.call( table.querySelectorAll( 'thead th[id]' ) ).filter( function ( th ) {
			return th.id.indexOf( 'wcqoa' ) !== 0;
		} );
		var found = ths.filter( function ( th ) {
			return /note|comment|message/i.test( th.id );
		} )[ 0 ] || ths.filter( function ( th ) {
			return /note|ملاحظ/i.test( th.textContent );
		} )[ 0 ];
		notesKey = found ? found.id : null;
		return notesKey;
	}

	function notesCell( row ) {
		var key = getNotesKey();
		return key ? row.querySelector( 'td.column-' + key ) : null;
	}

	function setNoteCell( row, note ) {
		var cell = row ? notesCell( row ) : null;
		if ( ! cell ) {
			return;
		}
		Array.prototype.slice.call( cell.childNodes ).forEach( function ( n ) {
			if ( ! ( n.nodeType === 1 && n.classList.contains( 'wcqoa-icon' ) ) ) {
				cell.removeChild( n );
			}
		} );
		if ( note ) {
			cell.insertBefore( el( 'span', { 'class': 'wcqoa-note-text', text: note } ), cell.firstChild );
		}
	}

	function addIcons( row ) {
		var id = rowOrderId( row );
		if ( ! id ) {
			return;
		}
		ICON_MAP.forEach( function ( def ) {
			var cell = ( def.type === 'note' && notesCell( row ) ) || row.querySelector( def.cell );
			if ( ! cell || cell.querySelector( '.wcqoa-icon[data-type="' + def.type + '"]' ) ) {
				return;
			}
			var btn = el( 'button', {
				type: 'button',
				'class': 'wcqoa-icon',
				'data-type': def.type,
				'data-group': def.group || '',
				'data-order': id,
				'aria-label': def.label,
				title: def.label,
				'aria-haspopup': 'dialog',
				'aria-expanded': 'false'
			}, [ el( 'span', { 'class': def.type === 'note' ? 'dashicons dashicons-admin-comments' : 'dashicons dashicons-edit', 'aria-hidden': 'true' } ) ] );
			cell.appendChild( btn );
		} );
	}

	/* ---------- cell updates ---------- */

	function updateStatusCell( row, data ) {
		var mark = row ? row.querySelector( 'td.column-order_status mark.order-status' ) : null;
		if ( ! mark ) {
			return;
		}
		var cls = mark.className.replace( /(^|\s)status-\S+/g, '$1' ).trim();
		mark.className = cls + ' status-' + data.status;
		var span = mark.querySelector( 'span' );
		( span || mark ).textContent = data.label;
	}

	function setAddressCell( cell, text, mapUrl ) {
		if ( ! cell ) {
			return;
		}
		var a = cell.querySelector( 'a' );
		if ( a && text !== DASH ) {
			a.textContent = text;
			if ( mapUrl ) {
				a.href = mapUrl;
			}
			return;
		}
		Array.prototype.slice.call( cell.childNodes ).forEach( function ( n ) {
			if ( n.nodeType === 1 && ( n.classList.contains( 'wcqoa-icon' ) || n.classList.contains( 'description' ) ) ) {
				return;
			}
			cell.removeChild( n );
		} );
		cell.insertBefore( document.createTextNode( text ), cell.firstChild );
	}

	/* ---------- popover ---------- */

	function closePopover( restoreFocus ) {
		if ( ! pop ) {
			return;
		}
		pop.remove();
		pop = null;
		document.removeEventListener( 'mousedown', onOutside, true );
		document.removeEventListener( 'keydown', onKey, true );
		window.removeEventListener( 'resize', reposition );
		if ( activeBtn ) {
			activeBtn.setAttribute( 'aria-expanded', 'false' );
			if ( restoreFocus ) {
				activeBtn.focus();
			}
		}
		activeBtn = null;
	}

	function onOutside( e ) {
		if ( pop && ! pop.contains( e.target ) && ! e.target.closest( '.wcqoa-icon' ) ) {
			closePopover( false );
		}
	}

	function onKey( e ) {
		if ( e.key === 'Escape' ) {
			closePopover( true );
		}
	}

	function reposition() {
		if ( ! pop || ! activeBtn ) {
			return;
		}
		if ( window.matchMedia( '(max-width: 600px)' ).matches ) {
			pop.style.left = '';
			pop.style.top = '';
			return;
		}
		var r = activeBtn.getBoundingClientRect();
		var pw = pop.offsetWidth;
		var ph = pop.offsetHeight;
		var vw = document.documentElement.clientWidth;
		var left = isRtl() ? r.right - pw : r.left;
		left = Math.max( 8, Math.min( left, vw - pw - 8 ) );
		var top = r.bottom + 6;
		if ( top + ph > window.innerHeight && r.top - ph - 6 > 0 ) {
			top = r.top - ph - 6;
		}
		pop.style.left = left + window.pageXOffset + 'px';
		pop.style.top = top + window.pageYOffset + 'px';
	}

	/* ---------- builders ----------
	 * Each returns { title, body (Node), focus (Node), payload() => {action,data}|null|false, done(data) }
	 * payload() returning false aborts (error already shown), null means nothing to save.
	 */

	function buildStatus( ctx ) {
		var select = el( 'select', { 'class': 'wcqoa-input' } );
		var mark = ctx.row ? ctx.row.querySelector( 'td.column-order_status mark.order-status' ) : null;
		var m = mark ? /(?:^|\s)status-(\S+)/.exec( mark.className ) : null;
		var current = ctx.status || ( m ? m[ 1 ] : '' );
		if ( current && ! cfg.statuses[ current ] ) {
			// Current status is hidden from the list (e.g. HOLD): show it as a disabled placeholder.
			var label = mark ? mark.textContent.trim() : current;
			var ph = el( 'option', { value: '', text: label } );
			ph.disabled = true;
			ph.selected = true;
			select.appendChild( ph );
		}
		Object.keys( cfg.statuses ).forEach( function ( slug ) {
			var o = el( 'option', { value: slug, text: cfg.statuses[ slug ] } );
			if ( slug === current ) {
				o.selected = true;
			}
			select.appendChild( o );
		} );
		return {
			title: i18n.changeStatus,
			body: field( i18n.status, select ),
			focus: select,
			payload: function () {
				if ( ! select.value ) {
					return null;
				}
				return { action: 'wcqoa_update_status', data: { status: select.value } };
			},
			done: function ( data ) {
				updateStatusCell( ctx.row, data );
			}
		};
	}

	function buildNote( ctx ) {
		var ta = el( 'textarea', { 'class': 'wcqoa-input', rows: '4', maxlength: '5000' } );
		ta.disabled = true;
		return {
			title: i18n.addNote,
			body: field( i18n.note, ta ),
			focus: null,
			disabled: true,
			load: function () {
				request( 'wcqoa_get_note', { order_id: ctx.orderId } ).then( function ( res ) {
					if ( ! ta.isConnected ) {
						return;
					}
					if ( res && res.success ) {
						ta.value = res.data.note || '';
						ta.disabled = false;
						ctx.enable();
						ta.focus();
					} else {
						ctx.error( res && res.data && res.data.message ? res.data.message : i18n.error );
					}
				} );
			},
			payload: function () {
				return { action: 'wcqoa_update_note', data: { note: ta.value } };
			},
			done: function ( data ) {
				setNoteCell( ctx.row, data.note || '' );
			}
		};
	}

	function setPhoneCell( row, phone, phone2 ) {
		var cell = row ? row.querySelector( 'td.column-wcqoa_phone' ) : null;
		if ( ! cell ) {
			return;
		}
		var p = cell.querySelector( '.wcqoa-phone-value' );
		if ( p ) {
			p.textContent = phone || DASH;
		}
		var p2 = cell.querySelector( '.wcqoa-phone2-value' );
		if ( phone2 ) {
			if ( ! p2 ) {
				p2 = el( 'span', { 'class': 'wcqoa-phone2-value' } );
				cell.insertBefore( p2, p ? p.nextSibling : cell.firstChild );
			}
			p2.textContent = phone2;
		} else if ( p2 ) {
			p2.remove();
		}
	}

	function isValidPhone( v ) {
		var digits = v.replace( /\D/g, '' ).length;
		return /^\+?[0-9\s\-.()\/]+$/.test( v ) && digits >= 6 && digits <= 15;
	}

	function buildPhone( ctx ) {
		var input = el( 'input', { type: 'tel', 'class': 'wcqoa-input', inputmode: 'tel', autocomplete: 'off', maxlength: '30' } );
		var input2 = el( 'input', { type: 'tel', 'class': 'wcqoa-input', inputmode: 'tel', autocomplete: 'off', maxlength: '30' } );
		input.disabled = true;
		input2.disabled = true;
		return {
			title: i18n.editPhone,
			body: el( 'div', {}, [ field( i18n.phone, input ), field( i18n.phone2, input2 ) ] ),
			focus: null,
			disabled: true,
			load: function () {
				request( 'wcqoa_get_phone', { order_id: ctx.orderId } ).then( function ( res ) {
					if ( ! input.isConnected ) {
						return;
					}
					if ( res && res.success ) {
						input.value = res.data.phone || '';
						input2.value = res.data.phone2 || '';
						input.disabled = false;
						input2.disabled = false;
						ctx.enable();
						input.focus();
					} else {
						ctx.error( res && res.data && res.data.message ? res.data.message : i18n.error );
					}
				} );
			},
			payload: function () {
				var v = input.value.trim();
				var v2 = input2.value.trim();
				if ( ! isValidPhone( v ) || ( v2 && ! isValidPhone( v2 ) ) ) {
					ctx.error( i18n.phoneInvalid );
					return false;
				}
				return { action: 'wcqoa_update_phone', data: { phone: v, phone2: v2 } };
			},
			done: function ( data ) {
				ctx.phone = data.phone || '';
				setPhoneCell( ctx.row, data.phone, data.phone2 );
			}
		};
	}

	function buildAddress( ctx ) {
		var wrap = el( 'div', { 'class': 'wcqoa-address' }, [ el( 'p', { 'class': 'wcqoa-loading', text: i18n.loading } ) ] );
		var inputs = {};
		var stateBox = el( 'div' );

		function fillState( country, value ) {
			var render = function ( states ) {
				var ctl;
				var keys = Object.keys( states );
				if ( keys.length ) {
					ctl = el( 'select', { 'class': 'wcqoa-input' } );
					ctl.appendChild( el( 'option', { value: '', text: i18n.selectOption } ) );
					keys.forEach( function ( code ) {
						var o = el( 'option', { value: code, text: states[ code ] } );
						if ( code === value ) {
							o.selected = true;
						}
						ctl.appendChild( o );
					} );
				} else {
					ctl = el( 'input', { type: 'text', 'class': 'wcqoa-input', value: value || '' } );
				}
				stateBox.textContent = '';
				stateBox.appendChild( ctl );
				inputs.state = ctl;
				reposition();
			};
			if ( ! country ) {
				render( {} );
			} else if ( statesCache[ country ] ) {
				render( statesCache[ country ] );
			} else {
				request( 'wcqoa_get_states', { country: country } ).then( function ( res ) {
					statesCache[ country ] = normalizeStates( res && res.success ? res.data.states : {} );
					render( statesCache[ country ] );
				} );
			}
		}

		function buildFields( values ) {
			var countrySel = el( 'select', { 'class': 'wcqoa-input' } );
			countrySel.appendChild( el( 'option', { value: '', text: i18n.selectOption } ) );
			Object.keys( cfg.countries ).forEach( function ( code ) {
				var o = el( 'option', { value: code, text: cfg.countries[ code ] } );
				if ( code === values.country ) {
					o.selected = true;
				}
				countrySel.appendChild( o );
			} );
			inputs.country = countrySel;
			inputs.address_1 = el( 'input', { type: 'text', 'class': 'wcqoa-input', value: values.address_1 } );
			inputs.address_2 = el( 'input', { type: 'text', 'class': 'wcqoa-input', value: values.address_2 } );
			inputs.city = el( 'input', { type: 'text', 'class': 'wcqoa-input', value: values.city } );
			inputs.postcode = el( 'input', { type: 'text', 'class': 'wcqoa-input', value: values.postcode } );
			countrySel.addEventListener( 'change', function () {
				fillState( countrySel.value, '' );
			} );
			fillState( values.country, values.state );

			return el( 'div', { 'class': 'wcqoa-group' }, [
				el( 'p', { 'class': 'wcqoa-hint', text: i18n.addressHint } ),
				field( i18n.address1, inputs.address_1 ),
				field( i18n.address2, inputs.address_2 ),
				el( 'div', { 'class': 'wcqoa-row' }, [ field( i18n.city, inputs.city ), field( i18n.postcode, inputs.postcode ) ] ),
				el( 'div', { 'class': 'wcqoa-row' }, [ field( i18n.country, countrySel ), field( i18n.state, stateBox ) ] )
			] );
		}

		return {
			title: i18n.editAddress,
			body: wrap,
			focus: null,
			disabled: true,
			payload: function () {
				var data = {};
				FIELDS.forEach( function ( f ) {
					data[ 'address[' + f + ']' ] = inputs[ f ].value;
				} );
				return { action: 'wcqoa_update_address', data: data };
			},
			done: function ( data ) {
				if ( ctx.row ) {
					setAddressCell( ctx.row.querySelector( 'td.column-billing_address' ), data.billing.text );
					setAddressCell( ctx.row.querySelector( 'td.column-shipping_address' ), data.shipping.text, data.shipping.map_url );
				}
			},
			load: function () {
				request( 'wcqoa_get_address', { order_id: ctx.orderId } ).then( function ( res ) {
					if ( ! wrap.isConnected ) {
						return;
					}
					wrap.textContent = '';
					if ( ! res || ! res.success ) {
						ctx.error( res && res.data && res.data.message ? res.data.message : i18n.error );
						return;
					}
					var states = res.data.states || {};
					Object.keys( states ).forEach( function ( c ) {
						statesCache[ c ] = normalizeStates( states[ c ] );
					} );
					wrap.appendChild( buildFields( res.data.address ) );
					inputs.address_1.focus();
					ctx.enable();
					reposition();
				} );
			}
		};
	}

	/* ---------- shared form ---------- */

	var BUILDERS = { status: buildStatus, note: buildNote, phone: buildPhone, address: buildAddress };

	// Last saved values per order, re-applied whenever the preview modal is reopened
	// (guards against WooCommerce serving cached preview data).
	var saved = {};

	function remember( orderId, type, data ) {
		( saved[ orderId ] = saved[ orderId ] || {} )[ type ] = data;
	}

	function applySaved( modal, orderId ) {
		var s = saved[ orderId ];
		if ( ! s || ! modal ) {
			return;
		}
		if ( s.status ) {
			updateMark( modal.querySelector( 'mark.order-status' ), s.status );
		}
		if ( s.phone ) {
			setPreviewPhone( modal, s.phone.phone || '' );
		}
		if ( s.address ) {
			setPreviewAddress( modal, 0, s.address.billing.html );
			setPreviewAddress( modal, 1, s.address.shipping.html );
		}
		if ( s.note ) {
			setPreviewNote( modal, s.note.note || '' );
		}
	}

	/**
	 * Build a form for an action type. ctx is mutated (error/enable/group, plus status/phone state).
	 * opts: { onDone(data), onCancel() }
	 */
	function createForm( type, ctx, opts ) {
		var saveBtn = el( 'button', { type: 'submit', 'class': 'button button-primary wcqoa-save', text: i18n.save } );
		var cancelBtn = el( 'button', { type: 'button', 'class': 'button wcqoa-cancel', text: i18n.cancel } );
		var spinner = el( 'span', { 'class': 'wcqoa-spinner', 'aria-hidden': 'true' } );
		var msg = el( 'div', { 'class': 'wcqoa-msg', role: 'alert' } );

		ctx.error = function ( text ) {
			msg.textContent = text;
			msg.classList.add( 'is-error' );
			reposition();
		};
		ctx.enable = function () {
			saveBtn.disabled = false;
		};

		var b = BUILDERS[ type ]( ctx );
		var form = el( 'form', { 'class': 'wcqoa-form', novalidate: 'novalidate' }, [
			b.body,
			el( 'div', { 'class': 'wcqoa-actions' }, [ spinner, cancelBtn, saveBtn ] ),
			msg
		] );
		if ( b.disabled ) {
			saveBtn.disabled = true;
		}

		cancelBtn.addEventListener( 'click', function () {
			opts.onCancel();
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( saveBtn.disabled ) {
				return;
			}
			msg.textContent = '';
			msg.classList.remove( 'is-error' );
			var p = b.payload();
			if ( p === false ) {
				return;
			}
			if ( p === null ) {
				opts.onCancel();
				toast( i18n.nothingToSave );
				return;
			}
			var key = p.action + ':' + ctx.orderId;
			if ( busy[ key ] ) {
				return; // Prevent duplicate requests.
			}
			busy[ key ] = true;
			saveBtn.disabled = true;
			cancelBtn.disabled = true;
			saveBtn.textContent = i18n.saving;
			spinner.classList.add( 'is-active' );
			p.data.order_id = ctx.orderId;

			request( p.action, p.data ).then( function ( res ) {
				delete busy[ key ];
				var text = res && res.data && res.data.message ? res.data.message : i18n.error;
				if ( res && res.success ) {
					b.done( res.data );
					remember( ctx.orderId, type, res.data );
					opts.onDone( res.data );
					if ( opts.keepOpen && form.isConnected ) {
						spinner.classList.remove( 'is-active' );
						saveBtn.disabled = false;
						cancelBtn.disabled = false;
						saveBtn.textContent = i18n.save;
						msg.classList.remove( 'is-error' );
						msg.textContent = text;
						setTimeout( function () {
							if ( ! msg.classList.contains( 'is-error' ) && msg.textContent === text ) {
								msg.textContent = '';
							}
						}, 4000 );
					} else {
						toast( text );
					}
					return;
				}
				if ( ! form.isConnected ) {
					toast( text, true );
					return;
				}
				spinner.classList.remove( 'is-active' );
				saveBtn.disabled = false;
				cancelBtn.disabled = false;
				saveBtn.textContent = i18n.save;
				ctx.error( text );
			} );
		} );

		if ( b.load ) {
			b.load();
		}
		return { form: form, focus: b.focus, title: b.title };
	}

	/* ---------- popover (list icons) ---------- */

	function openPopover( btn ) {
		var ctx = {
			row: btn.closest( 'tr' ),
			orderId: btn.getAttribute( 'data-order' ),
			group: btn.getAttribute( 'data-group' )
		};
		var f = createForm( btn.getAttribute( 'data-type' ), ctx, {
			onDone: function () {
				closePopover( true );
			},
			onCancel: function () {
				closePopover( true );
			}
		} );

		pop = el( 'div', { 'class': 'wcqoa-popover', role: 'dialog', 'aria-label': f.title }, [
			el( 'div', { 'class': 'wcqoa-title', text: f.title } ),
			f.form
		] );
		activeBtn = btn;
		btn.setAttribute( 'aria-expanded', 'true' );
		document.body.appendChild( pop );
		reposition();
		document.addEventListener( 'mousedown', onOutside, true );
		document.addEventListener( 'keydown', onKey, true );
		window.addEventListener( 'resize', reposition );
		if ( f.focus ) {
			f.focus.focus();
		}
	}

	/* ---------- order preview modal panel ---------- */

	function updateMark( mark, data ) {
		if ( ! mark ) {
			return;
		}
		var cls = mark.className.replace( /(^|\s)status-\S+/g, '$1' ).trim();
		mark.className = cls + ' status-' + data.status;
		( mark.querySelector( 'span' ) || mark ).textContent = data.label;
	}

	function setPreviewAddress( modal, index, html ) {
		var block = modal ? modal.querySelectorAll( '.wc-order-preview-address' )[ index ] : null;
		var h = block ? block.querySelector( 'h2' ) : null;
		if ( ! h ) {
			return;
		}
		var n = h.nextSibling;
		while ( n && ! ( n.nodeType === 1 && n.tagName === 'STRONG' ) ) {
			var next = n.nextSibling;
			block.removeChild( n );
			n = next;
		}
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = html && html.trim() ? html : DASH;
		block.insertBefore( tpl.content, n );
	}

	function setPreviewPhone( modal, phone ) {
		if ( ! modal ) {
			return;
		}
		// Billing and shipping phone are saved together, so refresh every phone link.
		Array.prototype.forEach.call( modal.querySelectorAll( '.wc-order-preview-address a[href^="tel:"]' ), function ( a ) {
			a.textContent = phone;
			a.setAttribute( 'href', 'tel:' + phone.replace( /[^\d+]/g, '' ) );
		} );
	}

	function setPreviewNote( modal, note ) {
		if ( ! modal ) {
			return;
		}
		var box = modal.querySelector( '.wc-order-preview-note' );
		if ( box ) {
			Array.prototype.slice.call( box.childNodes ).forEach( function ( n ) {
				if ( ! ( n.nodeType === 1 && n.tagName === 'STRONG' ) ) {
					box.removeChild( n );
				}
			} );
			if ( note ) {
				box.appendChild( document.createTextNode( note ) );
			} else {
				box.remove();
			}
			return;
		}
		if ( ! note ) {
			return;
		}
		box = el( 'div', { 'class': 'wc-order-preview-note' }, [ el( 'strong', { text: i18n.noteTab } ) ] );
		box.appendChild( document.createTextNode( note ) );
		var host = modal.querySelector( '.wc-order-preview-addresses' ) || modal.querySelector( 'article' );
		if ( host ) {
			host.appendChild( box );
		}
	}

	function initPreview( root ) {
		if ( root.getAttribute( 'data-ready' ) ) {
			return;
		}
		root.setAttribute( 'data-ready', '1' );

		var orderId = root.getAttribute( 'data-order-id' );
		var ctx = {
			orderId: orderId,
			row: table.querySelector( '#order-' + orderId + ', #post-' + orderId ),
			status: root.getAttribute( 'data-status' ) || '',
			phone: root.getAttribute( 'data-phone' ) || ''
		};
		var modal = root.closest( '.wc-backbone-modal' );
		if ( saved[ orderId ] && saved[ orderId ].status ) {
			ctx.status = saved[ orderId ].status.status;
		}
		applySaved( modal, orderId );
		var tabs = el( 'div', { 'class': 'wcqoa-tabs', role: 'tablist' } );
		var pane = el( 'div', { 'class': 'wcqoa-pane' } );
		var current = null;
		var buttons = {};

		function show( type ) {
			pane.textContent = '';
			current = type;
			Object.keys( buttons ).forEach( function ( k ) {
				buttons[ k ].classList.toggle( 'is-active', k === type );
				buttons[ k ].setAttribute( 'aria-selected', k === type ? 'true' : 'false' );
			} );
			if ( ! type ) {
				return;
			}
			ctx.group = 'billing';
			var f = createForm( type, ctx, {
				keepOpen: true,
				onDone: function ( data ) {
					if ( type === 'status' ) {
						ctx.status = data.status;
						updateMark( modal && modal.querySelector( 'mark.order-status' ), data );
					} else if ( type === 'phone' ) {
						ctx.phone = data.phone || '';
						setPreviewPhone( modal, ctx.phone );
					} else if ( type === 'address' ) {
						setPreviewAddress( modal, 0, data.billing.html );
						setPreviewAddress( modal, 1, data.shipping.html );
					} else if ( type === 'note' ) {
						setPreviewNote( modal, data.note || '' );
					}
				},
				onCancel: function () {
					show( null );
				}
			} );
			pane.appendChild( f.form );
			if ( f.focus ) {
				f.focus.focus();
			}
		}

		[ [ 'status', i18n.status ], [ 'note', i18n.noteTab ], [ 'address', i18n.address ], [ 'phone', i18n.phone ] ].forEach( function ( t ) {
			var b = el( 'button', { type: 'button', 'class': 'wcqoa-tab', role: 'tab', text: t[ 1 ] } );
			b.addEventListener( 'click', function () {
				show( current === t[ 0 ] ? null : t[ 0 ] );
			} );
			buttons[ t[ 0 ] ] = b;
			tabs.appendChild( b );
		} );

		// Keep the modal's arrow-key navigation and shortcuts away from our fields.
		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) {
				e.stopPropagation();
			}
		} );

		root.appendChild( el( 'div', { 'class': 'wcqoa-title', text: i18n.quickActions } ) );
		root.appendChild( tabs );
		root.appendChild( pane );
	}

	function scanPreview( node ) {
		if ( node.nodeType !== 1 ) {
			return;
		}
		var roots = node.matches( '.wcqoa-preview' ) ? [ node ] : node.querySelectorAll( '.wcqoa-preview' );
		Array.prototype.forEach.call( roots, initPreview );
	}

	// The preview modal is injected into <body> by WooCommerce; watch direct children only.
	new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( m ) {
			Array.prototype.forEach.call( m.addedNodes, scanPreview );
		} );
	} ).observe( document.body, { childList: true } );

	/* ---------- init ---------- */

	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.wcqoa-icon' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var same = activeBtn === btn;
		closePopover( false );
		if ( ! same ) {
			openPopover( btn );
		}
	} );

	Array.prototype.forEach.call( table.querySelectorAll( 'tbody tr' ), addIcons );
}() );
