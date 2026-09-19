( function ( $ ) {
	'use strict';

	if ( typeof window.cmAdmin === 'undefined' ) {
		return;
	}

	function request( action, data ) {
		return $.post( cmAdmin.ajaxUrl, $.extend( {
			action: action,
			nonce: cmAdmin.nonce
		}, data || {} ) );
	}

	function messageFrom( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return ( cmAdmin.l10n && cmAdmin.l10n.error ) || 'An error occurred.';
	}

	function complete( requestObject, successMessage ) {
		requestObject.done( function ( response ) {
			if ( response && response.success ) {
				if ( successMessage ) {
					showAdminNotice( successMessage, 'success' );
				}
				window.setTimeout( function () { window.location.reload(); }, successMessage ? 550 : 0 );
				return;
			}
			showAdminNotice( messageFrom( response ), 'error' );
		} ).fail( function ( xhr ) {
			showAdminNotice( messageFrom( xhr.responseJSON ), 'error' );
		} );
	}

	function showAdminNotice( message, type ) {
		var $existing = $( '#cm-admin-notice' );
		var timeoutId = 0;
		function dismiss() {
			window.clearTimeout( timeoutId );
			$existing.remove();
		}
		$existing.remove();
		$existing = $( '<div>', { id: 'cm-admin-notice', 'class': 'cm-admin-notice is-' + ( type || 'info' ), role: type === 'error' ? 'alert' : 'status' } ).append(
			$( '<span>', { text: message } ),
			$( '<button>', { type: 'button', 'class': 'button-link', 'aria-label': 'Dismiss message' } ).append( $( '<span>', { 'class': 'dashicons dashicons-no-alt' } ) )
		);
		$existing.on( 'click', 'button', dismiss );
		$( 'body' ).append( $existing );
		timeoutId = window.setTimeout( dismiss, 7000 );
	}

	function openActionModal( options, onConfirm ) {
		var previousFocus = document.activeElement;
		var headingId = 'cm-action-dialog-title-' + Date.now().toString( 36 );
		var $modal = $( '<div>', { 'class': 'cm-action-modal' } );
		var $dialog = $( '<div>', {
			'class': 'cm-action-dialog',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': headingId,
			tabindex: '-1'
		} );
		var $form = $( '<form>', { 'class': 'cm-action-form' } );
		var $error = $( '<p>', { 'class': 'cm-action-error', role: 'alert', hidden: true } );

		function close() {
			$modal.remove();
			if ( previousFocus && typeof previousFocus.focus === 'function' ) {
				previousFocus.focus();
			}
		}

		$dialog.append( $( '<div>', { 'class': 'cm-action-heading' } ).append(
			$( '<h2>', { id: headingId, text: options.title } ),
			$( '<button>', { type: 'button', 'class': 'button-link cm-close-action-modal', 'aria-label': 'Close dialog' } ).append( $( '<span>', { 'class': 'dashicons dashicons-no-alt' } ) )
		) );
		if ( options.description ) {
			$form.append( $( '<p>', { 'class': 'cm-action-description', text: options.description } ) );
		}
		( options.fields || [] ).forEach( function ( field, index ) {
			var fieldId = 'cm-action-field-' + Date.now().toString( 36 ) + '-' + index;
			var $field = $( '<div>', { 'class': 'cm-action-field' } );
			$field.append( $( '<label>', { for: fieldId, text: field.label } ) );
			var attributes = { id: fieldId, name: field.name };
			if ( field.placeholder ) {
				attributes.placeholder = field.placeholder;
			}
			if ( Object.prototype.hasOwnProperty.call( field, 'value' ) ) {
				attributes.value = field.value;
			}
			if ( field.required ) {
				attributes.required = 'required';
			}
			if ( field.min ) {
				attributes.min = field.min;
			}
			var $control;
			if ( field.type === 'textarea' ) {
				$control = $( '<textarea>', attributes );
				if ( Object.prototype.hasOwnProperty.call( field, 'value' ) ) {
					$control.val( field.value );
				}
			} else if ( field.type === 'select' ) {
				$control = $( '<select>', attributes );
				( field.options || [] ).forEach( function ( choice ) {
					$control.append( $( '<option>', { value: choice.value, text: choice.label } ).prop( 'selected', String( choice.value ) === String( field.value || '' ) ) );
				} );
			} else {
				attributes.type = field.type || 'text';
				$control = $( '<input>', attributes );
			}
			$field.append( $control );
			if ( field.help ) {
				$field.append( $( '<p>', { 'class': 'description', text: field.help } ) );
			}
			$form.append( $field );
		} );
		$form.append( $error );
		$form.append( $( '<div>', { 'class': 'cm-action-footer' } ).append(
			$( '<button>', { type: 'button', 'class': 'button cm-cancel-action-modal', text: options.cancelLabel || 'Cancel' } ),
			$( '<button>', { type: 'submit', 'class': 'button button-primary' + ( options.destructive ? ' cm-action-destructive' : '' ), text: options.confirmLabel || 'Continue' } )
		) );
		$dialog.append( $form );
		$modal.append( $dialog );
		$( 'body' ).append( $modal );

		$modal.on( 'click', function ( event ) {
			if ( event.target === this ) {
				close();
			}
		} );
		$modal.on( 'click', '.cm-close-action-modal, .cm-cancel-action-modal', close );
		$dialog.on( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				close();
			}
		} );
		$form.on( 'submit', function ( event ) {
			event.preventDefault();
			var values = {};
			var firstInvalid = null;
			( options.fields || [] ).forEach( function ( field ) {
				var $control = $form.find( '[name="' + field.name + '"]' );
				var value = $control.val();
				values[ field.name ] = value;
				if ( field.required && ! String( value || '' ).trim() && ! firstInvalid ) {
					firstInvalid = $control;
				}
			} );
			if ( firstInvalid ) {
				$error.text( 'Please complete the required field.' ).prop( 'hidden', false );
				firstInvalid.trigger( 'focus' );
				return;
			}
			close();
			onConfirm( values );
		} );
		window.setTimeout( function () {
			var $firstControl = $form.find( 'input, textarea, select' ).first();
			( $firstControl.length ? $firstControl : $dialog ).trigger( 'focus' );
		}, 0 );
	}

	function openConfirmationModal( options, onConfirm ) {
		openActionModal( $.extend( { confirmLabel: 'Continue', fields: [] }, options ), function () {
			onConfirm();
		} );
	}

	function openVerificationPreview( url ) {
		var previousFocus = document.activeElement;
		var headingId = 'cm-verification-preview-title-' + Date.now().toString( 36 );
		var $modal = $( '<div>', { 'class': 'cm-verification-preview-modal' } );
		var $dialog = $( '<section>', {
			'class': 'cm-verification-preview-dialog',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': headingId,
			tabindex: '-1'
		} );
		var $frame = $( '<iframe>', {
			'class': 'cm-verification-preview-frame',
			title: 'Public certificate verification page',
			src: url
		} );

		function close() {
			$frame.attr( 'src', 'about:blank' );
			$modal.remove();
			if ( previousFocus && typeof previousFocus.focus === 'function' ) {
				previousFocus.focus();
			}
		}

		$dialog.append( $( '<header>', { 'class': 'cm-verification-preview-heading' } ).append(
			$( '<div>' ).append(
				$( '<h2>', { id: headingId, text: 'Verification page' } ),
				$( '<p>', { text: 'This is the live public view shown when this certificate’s QR code is scanned.' } )
			),
			$( '<button>', { type: 'button', 'class': 'button-link cm-close-verification-preview', 'aria-label': 'Close verification page preview' } ).append( $( '<span>', { 'class': 'dashicons dashicons-no-alt' } ) )
		) );
		$dialog.append( $frame );
		$modal.append( $dialog );
		$( 'body' ).append( $modal );
		$modal.on( 'click', function ( event ) {
			if ( event.target === this ) {
				close();
			}
		} );
		$modal.on( 'click', '.cm-close-verification-preview', close );
		$dialog.on( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				close();
			}
		} );
		window.setTimeout( function () { $dialog.trigger( 'focus' ); }, 0 );
	}

	var designer = {
		templateId: 0,
		elements: [],
		selectedId: null,
		orientation: 'landscape',
		background: { color: '#ffffff', image_id: 0, image_url: '', opacity: 1 },
		webhookIds: [],
		guides: { horizontal: null, vertical: null },
		preview: false,
		previewData: {},
		dirty: false
	};
	var assetLibrary = { target: 'background', page: 1, query: '', items: [] };
	var minimumQrSize = 24;
	var badgedQrScale = 0.6;
	var minimumBadgedQrHeight = minimumQrSize / badgedQrScale;
	var variableImageOptions = [];
	var qrBadges = ( window.cmAdmin && cmAdmin.qrBadges ) || {};

	function isBadgedQr( element ) {
		return element.type === 'qr' && element.qr_presentation === 'badge';
	}

	function qrBadge( element, qrSize ) {
		var badge = qrBadges[ element.qr_badge ] || qrBadges[ 'heritage-green' ] || { ratio: 1, url: '' };
		return {
			ratio: Number( badge.ratio ) || 1,
			url: badge.url,
			label: badge.label || 'Verification badge'
		};
	}

	function qrBadgeGap( qrSize ) {
		return Math.max( 1.2, qrSize * 0.04 );
	}

	function badgedQrCodeSize( badgeHeight ) {
		return Math.max( minimumQrSize, Number( badgeHeight ) * badgedQrScale );
	}

	function badgedQrGroupWidth( element, badgeHeight ) {
		var height = Math.max( minimumBadgedQrHeight, Number( badgeHeight ) || minimumBadgedQrHeight );
		var qrSize = badgedQrCodeSize( height );
		var badge = qrBadge( element, height );
		return qrSize + qrBadgeGap( qrSize ) + ( height * Number( badge.ratio || 1 ) );
	}

	function setBadgedQrSize( element, badgeHeight ) {
		var height = Math.max( minimumBadgedQrHeight, Number( badgeHeight ) || minimumBadgedQrHeight );
		element.height = height;
		element.width = badgedQrGroupWidth( element, height );
	}

	function qrSizeFromBadgedWidth( element, width ) {
		var availableWidth = Math.max( 0, Number( width ) || 0 );
		var height = Math.max( minimumBadgedQrHeight, availableWidth / ( badgedQrScale + 1.04 ) );
		for ( var attempt = 0; attempt < 3; attempt++ ) {
			var badge = qrBadge( element, height );
			height = Math.max( minimumBadgedQrHeight, availableWidth / ( badgedQrScale + Number( badge.ratio || 1 ) + 0.04 ) );
		}
		return height;
	}

	function maxBadgedQrHeight( element, availableWidth, availableHeight ) {
		return Math.min( Number( availableHeight ), qrSizeFromBadgedWidth( element, availableWidth ) );
	}

	function pageSize() {
		return designer.orientation === 'portrait' ? { width: 210, height: 297 } : { width: 297, height: 210 };
	}

	function constrainElement( element, size ) {
		var width = Number( element.width );
		var height = Number( element.height );
		var x = Number( element.x );
		var y = Number( element.y );
		var rotation = Number( element.rotation );
		element.width = Math.min( size.width, Math.max( 2, Number.isFinite( width ) ? width : 50 ) );
		element.height = Math.min( size.height, Math.max( 1, Number.isFinite( height ) ? height : 15 ) );
		x = Number.isFinite( x ) ? x : 0;
		y = Number.isFinite( y ) ? y : 0;
		if ( element.type === 'qr' ) {
			if ( isBadgedQr( element ) ) {
				var availableBadgeHeight = maxBadgedQrHeight( element, size.width - x, size.height - y );
				setBadgedQrSize( element, Math.max( minimumBadgedQrHeight, Math.min( element.height, availableBadgeHeight ) ) );
			} else {
				var squareSize = Math.max( minimumQrSize, Math.min( element.width, element.height ) );
				x += ( element.width - squareSize ) / 2;
				y += ( element.height - squareSize ) / 2;
				element.width = squareSize;
				element.height = squareSize;
			}
		} else if ( element.type === 'image' && element.square ) {
			var imageSquareSize = Math.max( 2, Math.min( element.width, element.height ) );
			element.width = imageSquareSize;
			element.height = imageSquareSize;
		}
		element.rotation = Math.min( 180, Math.max( -180, Number.isFinite( rotation ) ? rotation : 0 ) );
		var centreX = x + element.width / 2;
		var centreY = y + element.height / 2;
		var radians = element.rotation * Math.PI / 180;
		var boundsWidth = Math.abs( element.width * Math.cos( radians ) ) + Math.abs( element.height * Math.sin( radians ) );
		var boundsHeight = Math.abs( element.width * Math.sin( radians ) ) + Math.abs( element.height * Math.cos( radians ) );
		if ( isRotatable( element.type ) ) {
			var visibleWidth = Math.min( 4, boundsWidth );
			var visibleHeight = Math.min( 4, boundsHeight );
			centreX = Math.min( size.width - visibleWidth + boundsWidth / 2, Math.max( visibleWidth - boundsWidth / 2, centreX ) );
			centreY = Math.min( size.height - visibleHeight + boundsHeight / 2, Math.max( visibleHeight - boundsHeight / 2, centreY ) );
			element.x = centreX - element.width / 2;
			element.y = centreY - element.height / 2;
			return element;
		}
		if ( boundsWidth > size.width || boundsHeight > size.height ) {
			var scale = Math.min( size.width / boundsWidth, size.height / boundsHeight );
			element.width *= scale;
			element.height *= scale;
			boundsWidth *= scale;
			boundsHeight *= scale;
		}
		centreX = Math.min( size.width - boundsWidth / 2, Math.max( boundsWidth / 2, centreX ) );
		centreY = Math.min( size.height - boundsHeight / 2, Math.max( boundsHeight / 2, centreY ) );
		element.x = centreX - element.width / 2;
		element.y = centreY - element.height / 2;
		return element;
	}

	function newId() {
		return 'el_' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 7 );
	}

	function isShape( type ) {
		return [ 'rectangle', 'rounded_rectangle', 'ellipse', 'circle', 'triangle', 'diamond', 'star', 'hexagon', 'ribbon' ].indexOf( type ) !== -1;
	}

	function isRotatable( type ) {
		return type === 'image' || type === 'line' || isShape( type );
	}

	function elementBoundsAt( element, x, y ) {
		var width = Number( element.width ) || 0;
		var height = Number( element.height ) || 0;
		var radians = ( Number( element.rotation ) || 0 ) * Math.PI / 180;
		var boundsWidth = Math.abs( width * Math.cos( radians ) ) + Math.abs( height * Math.sin( radians ) );
		var boundsHeight = Math.abs( width * Math.sin( radians ) ) + Math.abs( height * Math.cos( radians ) );
		var centreX = x + width / 2;
		var centreY = y + height / 2;
		return {
			left: centreX - boundsWidth / 2,
			centreX: centreX,
			right: centreX + boundsWidth / 2,
			top: centreY - boundsHeight / 2,
			centreY: centreY,
			bottom: centreY + boundsHeight / 2
		};
	}

	function snapElementPosition( element, x, y, size, toleranceX, toleranceY ) {
		var bounds = elementBoundsAt( element, x, y );
		var xOffsets = [ bounds.left - x, bounds.centreX - x, bounds.right - x ];
		var yOffsets = [ bounds.top - y, bounds.centreY - y, bounds.bottom - y ];
		var xTargets = [ 0, size.width / 2, size.width ];
		var yTargets = [ 0, size.height / 2, size.height ];
		designer.elements.forEach( function ( other ) {
			if ( other.id === element.id ) {
				return;
			}
			var otherBounds = elementBoundsAt( other, Number( other.x ) || 0, Number( other.y ) || 0 );
			xTargets.push( otherBounds.left, otherBounds.centreX, otherBounds.right );
			yTargets.push( otherBounds.top, otherBounds.centreY, otherBounds.bottom );
		} );
		xTargets = xTargets.filter( function ( target ) { return target >= 0 && target <= size.width; } );
		yTargets = yTargets.filter( function ( target ) { return target >= 0 && target <= size.height; } );
		var bestX = { distance: toleranceX + 1, position: x, guide: null };
		var bestY = { distance: toleranceY + 1, position: y, guide: null };
		xTargets.forEach( function ( target ) {
			xOffsets.forEach( function ( offset ) {
				var candidate = target - offset;
				var distance = Math.abs( candidate - x );
				if ( distance <= toleranceX && distance < bestX.distance ) {
					bestX = { distance: distance, position: candidate, guide: target };
				}
			} );
		} );
		yTargets.forEach( function ( target ) {
			yOffsets.forEach( function ( offset ) {
				var candidate = target - offset;
				var distance = Math.abs( candidate - y );
				if ( distance <= toleranceY && distance < bestY.distance ) {
					bestY = { distance: distance, position: candidate, guide: target };
				}
			} );
		} );
		return { x: bestX.position, y: bestY.position, vertical: bestX.guide, horizontal: bestY.guide };
	}

	function shapeMarkup( type, color ) {
		var fill = /^#[0-9a-f]{6}$/i.test( color || '' ) ? color : '#e8eef7';
		var shapes = {
			rectangle: '<rect width="100" height="100" />',
			rounded_rectangle: '<rect x="1" y="1" width="98" height="98" rx="12" ry="12" />',
			ellipse: '<ellipse cx="50" cy="50" rx="49" ry="49" />',
			circle: '<ellipse cx="50" cy="50" rx="49" ry="49" />',
			triangle: '<polygon points="50,1 99,99 1,99" />',
			diamond: '<polygon points="50,1 99,50 50,99 1,50" />',
			star: '<polygon points="50,1 61,36 98,36 68,57 79,94 50,72 21,94 32,57 2,36 39,36" />',
			hexagon: '<polygon points="25,1 75,1 99,50 75,99 25,99 1,50" />',
			ribbon: '<polygon points="1,18 20,18 20,5 80,5 80,18 99,18 88,50 99,82 80,82 80,95 20,95 20,82 1,82 12,50" />'
		};
		return '<svg class="cm-shape-preview" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" fill="' + fill + '">' + ( shapes[ type ] || shapes.rectangle ) + '</svg>';
	}

	function defaultElement( type ) {
		var base = {
			id: newId(), type: type, x: 70, y: 75, width: 155, height: 18,
			color: '#1d2327', background_color: '#e8eef7', font_family: 'Arial',
			font_size: 22, font_weight: '400', text_align: 'center', text: '', qr_presentation: 'plain', qr_badge: 'heritage-green', qr_badge_side: 'right',
			value: '{{verification_url}}', image_id: 0, image_url: '', image_variable: '', square: false, locked: false, opacity: 1, rotation: 0
		};

		if ( type === 'text' ) {
			base.text = 'Editable text';
		} else if ( type === 'image' ) {
			base.x = 108;
			base.y = 55;
			base.width = 80;
			base.height = 50;
		} else if ( type === 'qr' ) {
			base.x = 247;
			base.y = 160;
			base.width = 30;
			base.height = 30;
		} else if ( isShape( type ) ) {
			base.x = 35;
			base.y = 45;
			base.width = 70;
			base.height = 35;
			if ( type === 'ellipse' || type === 'circle' || type === 'star' ) {
				base.width = 40;
				base.height = 40;
			}
		} else if ( type === 'line' ) {
			base.x = 85;
			base.y = 150;
			base.width = 127;
			base.height = 1;
			base.background_color = '#1d2327';
		}
		return base;
	}

	function variableElement( key, type ) {
		if ( type === 'image' ) {
			var imageElement = defaultElement( 'image' );
			imageElement.image_variable = key;
			return imageElement;
		}
		var element = defaultElement( 'text' );
		element.text = '{{' + key + '}}';
		if ( key === 'learning_outcomes' ) {
			element.x = 38;
			element.width = 221;
			element.height = 36;
			element.font_size = 11;
		}
		return element;
	}

	function starterElements() {
		return [
			$.extend( defaultElement( 'text' ), { x: 20, y: 25, width: 257, height: 22, text: 'Certificate of Completion', font_size: 34, font_weight: '700' } ),
			$.extend( defaultElement( 'text' ), { x: 55, y: 61, width: 187, height: 12, text: 'This certificate is proudly presented to', font_size: 14 } ),
			$.extend( defaultElement( 'text' ), { x: 35, y: 82, width: 227, height: 24, text: '{{recipient_name}}', font_size: 30, font_weight: '700' } ),
			$.extend( defaultElement( 'line' ), { x: 78, y: 110, width: 141 } ),
			$.extend( defaultElement( 'text' ), { x: 50, y: 121, width: 197, height: 13, text: 'for successfully completing', font_size: 14 } ),
			$.extend( defaultElement( 'text' ), { x: 40, y: 141, width: 217, height: 20, text: '{{course_name}}', font_size: 23, font_weight: '600' } ),
			$.extend( defaultElement( 'text' ), { x: 25, y: 177, width: 95, height: 10, text: 'Issued: {{issue_date}}', font_size: 11, text_align: 'left' } ),
			$.extend( defaultElement( 'qr' ), { x: 247, y: 160, width: 30, height: 30 } )
		];
	}

	function selectedElement() {
		return designer.elements.find( function ( element ) { return element.id === designer.selectedId; } ) || null;
	}

	function safeJson( value, fallback ) {
		try {
			var parsed = JSON.parse( value || '' );
			return parsed && typeof parsed === 'object' ? parsed : fallback;
		} catch ( error ) {
			return fallback;
		}
	}

	function randomFrom( values ) {
		return values[ Math.floor( Math.random() * values.length ) ];
	}

	function previewDate( date ) {
		return date.toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' } );
	}

	function createPreviewData() {
		var names = [ 'Alex Morgan', 'Priya Shah', 'Jordan Williams', 'Samira Khan', 'Daniel Evans', 'Sofia Martinez' ];
		var courses = [ 'Leadership Essentials', 'Workplace Safety', 'Advanced Project Management', 'Customer Service Excellence', 'Data Protection Fundamentals' ];
		var issuers = [ 'Dr Taylor Bennett', 'Morgan Clarke', 'Jamie Richardson' ];
		var recipient = randomFrom( names );
		var issued = new Date();
		var expires = new Date( issued.getTime() );
		expires.setFullYear( expires.getFullYear() + 1 );
		var emailName = recipient.toLowerCase().replace( /[^a-z]+/g, '.' ).replace( /^\.|\.$/g, '' );
		return {
			recipient_name: recipient,
			recipient_email: emailName + '@example.com',
			certificate_number: 'CERT-' + issued.getFullYear() + '-' + String( Math.floor( 1000 + Math.random() * 9000 ) ),
			certificate_name: 'Certificate of Completion',
			course_name: randomFrom( courses ),
			learning_outcomes: 'Understand the core principles\nApply the learning confidently\nDemonstrate practical competence',
			issue_date: previewDate( issued ),
			completion_date: previewDate( issued ),
			expiry_date: previewDate( expires ),
			issuer_name: randomFrom( issuers ),
			issuer_title: 'Training Director',
			site_name: 'Example Training Academy',
			site_url: 'https://example.com',
			verification_url: 'https://example.com/verify/CERT-SAMPLE',
			certificate_download_url: 'https://example.com/certificates/download',
			template_name: $.trim( $( '#cm-template-name' ).val() ) || 'Certificate of Completion',
			current_year: String( issued.getFullYear() )
		};
	}

	function sampleValue( key ) {
		if ( Object.prototype.hasOwnProperty.call( designer.previewData, key ) ) {
			return designer.previewData[ key ];
		}
		if ( key.indexOf( 'email' ) !== -1 ) {
			return 'sample@example.com';
		}
		if ( key.indexOf( 'date' ) !== -1 ) {
			return previewDate( new Date() );
		}
		if ( key.indexOf( 'outcome' ) !== -1 || key.indexOf( 'objective' ) !== -1 ) {
			return 'Demonstrate knowledge and practical understanding';
		}
		if ( key.indexOf( 'number' ) !== -1 || key.slice( -3 ) === '_id' ) {
			return 'SAMPLE-1024';
		}
		var label = key.replace( /_/g, ' ' ).replace( /\b\w/g, function ( letter ) { return letter.toUpperCase(); } );
		return 'Sample ' + label;
	}

	function previewText( value ) {
		var isLearningOutcomes = /learning_outcomes/i.test( value || '' );
		if ( ! designer.preview ) {
			return value || '';
		}
		return String( value || '' ).replace( /{{\s*([a-z0-9_-]+)\s*}}/gi, function ( match, key ) {
			var output = sampleValue( key.toLowerCase() );
			if ( isLearningOutcomes && key.toLowerCase() === 'learning_outcomes' ) {
				return output.split( /\r\n|\r|\n/ ).map( function ( line ) {
					return line.trim() ? String.fromCharCode( 8226 ) + ' ' + line.replace( /^[\-\*]\s*/, '' ).trim() : '';
				} ).filter( function ( line ) { return line; } ).join( '\n' );
			}
			return output;
		} );
	}

	function setPreview( enabled ) {
		designer.preview = !! enabled;
		designer.previewData = designer.preview ? createPreviewData() : {};
		renderDesigner();
	}

	function elementLabel( element ) {
		if ( element.type === 'text' ) {
			return element.text || 'Text';
		}
		if ( element.type === 'image' && element.image_variable ) {
			return 'Image variable: ' + element.image_variable;
		}
		return { image: 'Media', qr: 'QR code', rectangle: 'Rectangle', rounded_rectangle: 'Rounded box', ellipse: 'Circle / oval', circle: 'Circle / oval', triangle: 'Triangle', diamond: 'Diamond', star: 'Star', hexagon: 'Hexagon', ribbon: 'Ribbon', line: 'Line' }[ element.type ] || element.type;
	}

	function renderDesigner() {
		var size = pageSize();
		var $canvas = $( '#cm-certificate-canvas' );
		$( '.cm-designer' ).toggleClass( 'is-preview', designer.preview );
		$( '.cm-toggle-preview' ).toggleClass( 'is-active', designer.preview ).attr( 'aria-pressed', designer.preview ? 'true' : 'false' );
		$( '.cm-preview-button-label' ).text( designer.preview ? 'Exit preview' : 'Preview' );
		$( '.cm-editing-help' ).prop( 'hidden', designer.preview );
		$( '.cm-preview-help' ).prop( 'hidden', ! designer.preview );
		$( '#cm-template-name, .cm-save-template' ).prop( 'disabled', designer.preview );
		$canvas.toggleClass( 'is-portrait', designer.orientation === 'portrait' );
		$canvas.toggleClass( 'is-preview', designer.preview );
		$canvas.css( { backgroundColor: designer.background.color, backgroundImage: 'none' } );
		$canvas.empty();
		if ( designer.background.image_url ) {
			$canvas.append( $( '<span class="cm-canvas-background" aria-hidden="true"></span>' ).css( {
				backgroundImage: 'url("' + designer.background.image_url.replace( /"/g, '' ) + '")',
				opacity: designer.background.opacity
			} ) );
		}

		designer.elements.forEach( function ( element ) {
			var $element = $( '<div class="cm-canvas-element" tabindex="0"><div class="cm-element-content"></div><span class="cm-element-size"></span><span class="cm-element-lock dashicons dashicons-lock"></span><span class="cm-resize-handle"></span></div>' );
			var $content = $element.find( '.cm-element-content' );
			$element.attr( 'data-id', element.id ).attr( 'data-type', element.type );
			$element.toggleClass( 'has-media', element.type === 'image' && !! element.image_url );
			$element.toggleClass( 'is-locked', !! element.locked );
			$element.toggleClass( 'is-learning-outcomes', element.type === 'text' && /{{\s*learning_outcomes\s*}}/i.test( element.text || '' ) );
			$element.toggleClass( 'is-selected', element.id === designer.selectedId );
			$element.css( {
				left: ( element.x / size.width * 100 ) + '%',
				top: ( element.y / size.height * 100 ) + '%',
				width: ( element.width / size.width * 100 ) + '%',
				height: ( element.height / size.height * 100 ) + '%',
				color: element.color,
				fontFamily: element.font_family,
				fontSize: Math.max( 8, element.font_size * 0.68 ) + 'px',
				fontWeight: element.font_weight,
				textAlign: element.text_align,
				opacity: element.type === 'image' ? Math.max( 0, Math.min( 1, Number( element.opacity ) ) ) : 1,
				transform: 'rotate(' + ( Number( element.rotation ) || 0 ) + 'deg)',
				transformOrigin: 'center center'
			} );
			$element.find( '.cm-element-size' ).text( Number( element.width ).toFixed( 1 ) + ' × ' + Number( element.height ).toFixed( 1 ) + ' mm' + ( Math.abs( Number( element.width ) - Number( element.height ) ) < 0.05 ? ' • square' : '' ) );

			if ( element.type === 'text' ) {
				$content.text( previewText( element.text ) );
			} else if ( element.type === 'image' ) {
				if ( element.image_url ) {
					$content.append( $( '<img>' ).attr( 'src', element.image_url ) );
				} else if ( element.image_variable ) {
					$content.html( '<span class="dashicons dashicons-format-image"></span><small></small>' ).find( 'small' ).text( 'Image variable: ' + element.image_variable );
				} else {
					$content.html( '<span class="dashicons dashicons-format-image"></span><small>Choose media</small>' );
				}
			} else if ( element.type === 'qr' ) {
				if ( isBadgedQr( element ) ) {
					var badge = qrBadge( element );
					var $group = $( '<span class="cm-qr-badge-group"></span>' );
					var $qr = $( '<span class="cm-qr-preview"></span>' );
					var $badge = $( '<img class="cm-qr-badge-preview" alt="">' ).attr( 'src', badge.url );
					$group.toggleClass( 'is-badge-left', element.qr_badge_side === 'left' );
					if ( element.qr_badge_side === 'left' ) {
						$group.append( $badge, $qr );
					} else {
						$group.append( $qr, $badge );
					}
					$content.empty().append( $group ).attr( 'title', previewText( element.value ) );
				} else {
					$content.html( '<span class="cm-qr-preview"></span>' ).attr( 'title', previewText( element.value ) );
				}
			} else if ( isShape( element.type ) ) {
				$content.html( shapeMarkup( element.type, element.background_color ) );
			} else {
				$content.css( 'background-color', element.color );
			}
			$canvas.append( $element );
		} );

		if ( designer.guides.vertical !== null ) {
			$canvas.append( $( '<span class="cm-centre-guide cm-centre-guide-vertical" aria-hidden="true"></span>' ).css( 'left', ( designer.guides.vertical / size.width * 100 ) + '%' ) );
		}
		if ( designer.guides.horizontal !== null ) {
			$canvas.append( $( '<span class="cm-centre-guide cm-centre-guide-horizontal" aria-hidden="true"></span>' ).css( 'top', ( designer.guides.horizontal / size.height * 100 ) + '%' ) );
		}

		$( '#cm-template-orientation' ).val( designer.orientation );
		$( '#cm-page-color' ).val( designer.background.color || '#ffffff' );
		$( '#cm-background-opacity' ).val( Math.round( designer.background.opacity * 100 ) );
		$( '#cm-background-opacity-value' ).text( Math.round( designer.background.opacity * 100 ) + '%' );
		$( '.cm-clear-background' ).prop( 'hidden', ! designer.background.image_url );
		renderInspector();
		renderLayers();
	}

	function renderInspector() {
		var element = selectedElement();
		$( '.cm-empty-inspector' ).prop( 'hidden', !! element );
		$( '.cm-element-fields' ).prop( 'hidden', ! element );
		if ( ! element ) {
			return;
		}

		$( '.cm-field-text' ).toggle( element.type === 'text' );
		$( '.cm-field-font' ).toggle( element.type === 'text' );
		$( '.cm-font-grid' ).toggle( element.type === 'text' );
		$( '.cm-field-qr' ).toggle( element.type === 'qr' );
		$( '.cm-field-qr-badge' ).toggle( isBadgedQr( element ) );
		$( '.cm-qr-size-note' ).toggle( element.type === 'qr' );
		if ( isBadgedQr( element ) ) {
			$( '.cm-qr-badge-variant span' ).text( qrBadge( element, element.height ).label + ' (' + Number( element.height ).toFixed( 1 ) + ' mm badge / ' + badgedQrCodeSize( element.height ).toFixed( 1 ) + ' mm QR)' );
		}
		$( '.cm-field-image' ).toggle( element.type === 'image' );
		$( '.cm-square-control' ).toggle( element.type === 'image' );
		$( '.cm-media-opacity' ).toggle( element.type === 'image' );
		$( '.cm-field-fill' ).toggle( isShape( element.type ) );
		$( '.cm-field-rotation' ).toggle( isRotatable( element.type ) );
		$( '.cm-element-fields [data-property]' ).each( function () {
			var property = $( this ).data( 'property' );
			if ( $( this ).is( ':checkbox' ) ) {
				$( this ).prop( 'checked', !! element[ property ] );
			} else if ( property === 'opacity' ) {
				$( this ).val( Math.round( Math.max( 0, Math.min( 1, Number( element.opacity ) ) ) * 100 ) );
			} else {
				$( this ).val( element[ property ] );
			}
		} );
		$( '#cm-element-opacity-value' ).text( Math.round( Math.max( 0, Math.min( 1, Number( element.opacity ) ) ) * 100 ) + '%' );
		$( '.cm-element-fields [data-property]' ).not( '[data-property="locked"]' ).prop( 'disabled', !! element.locked );
		$( '.cm-change-media' ).prop( 'disabled', !! element.locked );
		$( '.cm-align-element, .cm-rotation-preset' ).prop( 'disabled', !! element.locked );
		$( '#cm-rotation-value' ).text( Math.round( Number( element.rotation ) || 0 ) + '\u00b0' );
	}

	function renderLayers() {
		var $layers = $( '.cm-layers' ).empty();
		designer.elements.slice().reverse().forEach( function ( element, index ) {
			var $button = $( '<button type="button" class="cm-layer"></button>' );
			$button.attr( 'data-id', element.id ).append( $( '<span class="cm-layer-order"></span>' ).text( index + 1 ), $( '<span class="cm-layer-label"></span>' ).text( elementLabel( element ) ) );
			$button.toggleClass( 'is-selected', element.id === designer.selectedId );
			$layers.append( $button );
		} );
	}

	function markDirty() {
		designer.dirty = true;
		$( '.cm-save-state' ).text( 'Unsaved changes' );
	}

	function openDesigner( data ) {
		designer.templateId = Number( data.id || 0 );
		designer.orientation = data.orientation === 'portrait' ? 'portrait' : 'landscape';
		designer.elements = data.elements ? safeJson( data.elements, [] ) : starterElements();
		designer.elements = Array.isArray( designer.elements ) ? designer.elements.map( function ( element ) {
			return constrainElement( $.extend( defaultElement( element.type || 'text' ), element, { id: element.id || newId() } ), pageSize() );
		} ) : [];
		if ( ! designer.elements.length ) {
			designer.elements = starterElements().map( function ( element ) { return constrainElement( element, pageSize() ); } );
		}
		designer.background = data.background_config ? safeJson( data.background_config, designer.background ) : { color: '#ffffff', image_id: 0, image_url: '', opacity: 1 };
		designer.background.opacity = Number.isFinite( Number( designer.background.opacity ) ) ? Math.max( 0, Math.min( 1, Number( designer.background.opacity ) ) ) : 1;
		var selectedWebhookIds = data.webhook_ids ? safeJson( data.webhook_ids, [] ) : [];
		designer.webhookIds = Array.isArray( selectedWebhookIds ) ? selectedWebhookIds.map( Number ) : [];
		$( '.cm-template-webhook' ).each( function () { $( this ).prop( 'checked', designer.webhookIds.indexOf( Number( $( this ).val() ) ) !== -1 ); } );
		designer.selectedId = designer.elements.length ? designer.elements[ 0 ].id : null;
		designer.guides = { horizontal: false, vertical: false };
		designer.preview = false;
		designer.previewData = {};
		designer.dirty = false;
		$( '#cm-template-name' ).val( data.title || 'Untitled certificate' );
		$( '.cm-page-heading, .cm-templates-list' ).prop( 'hidden', true );
		$( '.cm-designer' ).prop( 'hidden', false );
		$( '.cm-save-state' ).text( designer.templateId ? 'Published template' : 'New template' );
		renderDesigner();
		window.scrollTo( 0, 0 );
	}

	function addElement( element ) {
		constrainElement( element, pageSize() );
		designer.elements.push( element );
		designer.selectedId = element.id;
		markDirty();
		renderDesigner();
	}

	$( '.cm-tool[data-type], .cm-variable-chip' ).attr( 'draggable', 'true' );

	$( document ).on( 'dragstart', '.cm-tool[data-type], .cm-variable-chip', function ( event ) {
		var payload = $( this ).hasClass( 'cm-variable-chip' ) ? 'variable:' + $( this ).data( 'key' ) + ':' + ( $( this ).data( 'type' ) || 'text' ) : 'element:' + $( this ).data( 'type' );
		event.originalEvent.dataTransfer.setData( 'text/plain', payload );
		if ( $( this ).closest( '.cm-catalog-modal' ).length ) {
			window.setTimeout( function () { $( '.cm-catalog-modal' ).prop( 'hidden', true ); }, 0 );
		}
	} );

	$( document ).on( 'dragover', '#cm-certificate-canvas', function ( event ) {
		event.preventDefault();
	} );

	$( document ).on( 'drop', '#cm-certificate-canvas', function ( event ) {
		event.preventDefault();
		var original = event.originalEvent;
		var payload = original.dataTransfer.getData( 'text/plain' );
		var parts = payload.split( ':' );
		var rect = this.getBoundingClientRect();
		var size = pageSize();
		var element;
		if ( parts[ 0 ] === 'variable' ) {
			element = variableElement( parts[ 1 ], parts[ 2 ] || 'text' );
		} else if ( parts[ 0 ] === 'element' && parts[ 1 ] !== 'image' ) {
			element = defaultElement( parts[ 1 ] );
		} else if ( parts[ 1 ] === 'image' ) {
			chooseMedia( function ( attachment ) {
				var imageElement = $.extend( defaultElement( 'image' ), { image_id: attachment.id, image_url: attachment.url } );
				imageElement.x = ( original.clientX - rect.left ) / rect.width * size.width - imageElement.width / 2;
				imageElement.y = ( original.clientY - rect.top ) / rect.height * size.height - imageElement.height / 2;
				addElement( imageElement );
			} );
			return;
		}
		if ( element ) {
			element.x = Math.max( 0, ( original.clientX - rect.left ) / rect.width * size.width - element.width / 2 );
			element.y = Math.max( 0, ( original.clientY - rect.top ) / rect.height * size.height - element.height / 2 );
			addElement( element );
		}
	} );

	function chooseMedia( callback ) {
		if ( ! window.wp || ! wp.media ) {
			showAdminNotice( 'The WordPress media library is unavailable.', 'error' );
			return;
		}
		var frame = wp.media( { title: 'Choose certificate media', button: { text: 'Use this media' }, multiple: false, library: { type: 'image' } } );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			callback( attachment );
		} );
		frame.open();
	}

	function renderVariableImageOptions() {
		var $list = $( '.cm-variable-image-options-list' ).empty();
		if ( ! variableImageOptions.length ) {
			$list.append( $( '<span class="cm-image-option-empty"></span>' ).text( 'No restrictions set.' ) );
			return;
		}
		variableImageOptions.forEach( function ( attachment ) {
			var $option = $( '<span class="cm-variable-image-option"><img alt=""><span></span><button type="button" aria-label="Remove approved image">×</button></span>' );
			$option.attr( 'data-id', attachment.id );
			$option.find( 'img' ).attr( 'src', attachment.url ).attr( 'alt', attachment.title || '' );
			$option.find( 'span' ).text( attachment.title || attachment.filename || 'Image ' + attachment.id );
			$list.append( $option );
		} );
	}

	function chooseVariableImageOptions( selectedIds, callback ) {
		if ( ! window.wp || ! wp.media ) {
			showAdminNotice( 'The WordPress media library is unavailable.', 'error' );
			return;
		}
		var frame = wp.media( { title: 'Choose approved certificate images', button: { text: 'Use approved images' }, multiple: true, library: { type: 'image' } } );
		frame.on( 'open', function () {
			var selection = frame.state().get( 'selection' );
			selectedIds.forEach( function ( id ) { selection.add( wp.media.attachment( id ) ); } );
		} );
		frame.on( 'select', function () {
			callback( frame.state().get( 'selection' ).toJSON().map( function ( attachment ) {
				return { id: Number( attachment.id ), title: attachment.title || '', filename: attachment.filename || '', url: attachment.url || '' };
			} ) );
		} );
		frame.open();
	}

	function useAsset( attachment ) {
		if ( assetLibrary.target === 'background' ) {
			designer.background.image_id = Number( attachment.id || 0 );
			designer.background.image_url = attachment.url;
			markDirty();
			renderDesigner();
		} else {
			addElement( $.extend( defaultElement( 'image' ), {
				image_id: Number( attachment.id || 0 ),
				image_url: attachment.url,
				width: 40,
				height: 40
			} ) );
		}
		$( '.cm-asset-modal' ).prop( 'hidden', true );
	}

	function renderAssetItems( items, append ) {
		var $results = $( '.cm-asset-results' );
		if ( ! append ) {
			$results.empty();
		}
		items.forEach( function ( item ) {
			var $card = $( '<article class="cm-asset-card"><div class="cm-asset-preview"><img alt=""></div><div class="cm-asset-card-body"><strong></strong><small></small><div class="cm-asset-card-actions"><a target="_blank" rel="noopener noreferrer"></a><button type="button" class="button button-primary cm-use-asset"></button></div></div></article>' );
			$card.find( 'img' ).attr( 'src', item.preview_url ).attr( 'alt', item.tags || '' );
			$card.find( 'strong' ).text( item.tags || 'Pixabay image' );
			$card.find( 'small' ).text( 'By ' + ( item.user || 'Pixabay contributor' ) );
			$card.find( 'a' ).attr( 'href', item.page_url ).text( 'View on Pixabay' );
			$card.find( '.cm-use-asset' ).text( 'Import & use' ).data( 'asset', item );
			$results.append( $card );
		} );
		if ( ! items.length && ! append ) {
			$results.append( $( '<p class="cm-empty-assets"></p>' ).text( 'No images were found.' ) );
		}
	}

	function searchPixabay( append ) {
		var $status = $( '.cm-asset-status' ).text( 'Searching Pixabay...' );
		$( '.cm-pixabay-search button, .cm-pixabay-more' ).prop( 'disabled', true );
		request( 'cm_search_pixabay', {
			query: assetLibrary.query,
			page: assetLibrary.page,
			target: assetLibrary.target
		} ).done( function ( response ) {
			if ( ! response.success ) {
				$status.text( messageFrom( response ) );
				return;
			}
			assetLibrary.items = append ? assetLibrary.items.concat( response.data.items ) : response.data.items;
			renderAssetItems( response.data.items, append );
			$( '.cm-pixabay-more' ).prop( 'hidden', ! response.data.has_more );
			$status.text( response.data.total + ' Pixabay results' );
		} ).fail( function ( xhr ) {
			$status.text( messageFrom( xhr.responseJSON ) );
		} ).always( function () {
			$( '.cm-pixabay-search button, .cm-pixabay-more' ).prop( 'disabled', false );
		} );
	}

	$( document ).on( 'click', '.cm-open-assets', function () {
		assetLibrary.target = $( this ).data( 'target' );
		assetLibrary.page = 1;
		assetLibrary.items = [];
		$( '.cm-asset-modal' ).prop( 'hidden', false );
		$( '.cm-pixabay-search' ).prop( 'hidden', false );
		$( '.cm-pixabay-more' ).prop( 'hidden', true );
		$( '.cm-asset-results' ).empty();
		$( '.cm-asset-status' ).text( '' );
		$( '#cm-asset-title' ).text( 'Search Pixabay' );
		$( '.cm-asset-subtitle' ).text( 'Images are imported into this site before use; the template does not hotlink to Pixabay.' );
		$( '.cm-asset-notice' ).empty().append( document.createTextNode( 'Use images in a certificate design and follow the ' ) ).append( $( '<a target="_blank" rel="noopener noreferrer">Pixabay Content License</a>' ).attr( 'href', 'https://pixabay.com/service/license-summary/' ) ).append( document.createTextNode( '. Search results are provided by Pixabay.' ) );
		if ( ! cmAdmin.pixabayConfigured ) {
			$( '.cm-asset-results' ).append( $( '<p class="cm-empty-assets"></p>' ).append( document.createTextNode( 'A Pixabay API key is required. ' ) ).append( $( '<a>Open Certificate Manager Settings</a>' ).attr( 'href', cmAdmin.settingsUrl ) ) );
		} else {
			assetLibrary.query = assetLibrary.target === 'background' ? 'certificate background' : 'certificate badge';
			$( '#cm-pixabay-query' ).val( assetLibrary.query );
			searchPixabay( false );
		}
	} );

	$( document ).on( 'submit', '.cm-pixabay-search', function ( event ) {
		event.preventDefault();
		assetLibrary.query = $.trim( $( '#cm-pixabay-query' ).val() );
		assetLibrary.page = 1;
		assetLibrary.items = [];
		if ( assetLibrary.query ) {
			searchPixabay( false );
		}
	} );

	$( document ).on( 'click', '.cm-pixabay-more', function () {
		assetLibrary.page += 1;
		searchPixabay( true );
	} );

	$( document ).on( 'click', '.cm-use-asset', function () {
		var item = $( this ).data( 'asset' );
		var $button = $( this );
		$button.prop( 'disabled', true ).text( 'Importing...' );
		$( '.cm-asset-status' ).text( 'Copying the image into WordPress Media...' );
		request( 'cm_import_pixabay_image', {
			image_url: item.download_url,
			page_url: item.page_url,
			author: item.user,
			pixabay_id: item.id
		} ).done( function ( response ) {
			if ( response.success ) {
				useAsset( response.data );
			} else {
				$( '.cm-asset-status' ).text( messageFrom( response ) );
				$button.prop( 'disabled', false ).text( 'Import & use' );
			}
		} ).fail( function ( xhr ) {
			$( '.cm-asset-status' ).text( messageFrom( xhr.responseJSON ) );
			$button.prop( 'disabled', false ).text( 'Import & use' );
		} );
	} );

	$( document ).on( 'click', '.cm-close-assets', function () {
		$( '.cm-asset-modal' ).prop( 'hidden', true );
	} );

	$( document ).on( 'click', '.cm-open-catalog', function () {
		var catalog = $( this ).data( 'catalog' );
		$( '#cm-catalog-title' ).text( catalog === 'shapes' ? 'Shapes' : 'Variables' );
		$( '.cm-catalog-section' ).prop( 'hidden', true );
		$( catalog === 'shapes' ? '.cm-shapes-catalog' : '.cm-variables-catalog' ).prop( 'hidden', false );
		$( '.cm-catalog-modal' ).prop( 'hidden', false );
		if ( catalog === 'variables' ) {
			$( '#cm-variable-search' ).val( '' );
			$( '.cm-variables-catalog .cm-variable-chip' ).show();
			window.setTimeout( function () { $( '#cm-variable-search' ).trigger( 'focus' ); }, 0 );
		}
	} );

	$( document ).on( 'input', '#cm-variable-search', function () {
		var query = $.trim( $( this ).val() ).toLowerCase();
		$( '.cm-variables-catalog .cm-variable-chip' ).each( function () {
			$( this ).toggle( ! query || $( this ).text().toLowerCase().indexOf( query ) !== -1 || String( $( this ).data( 'key' ) ).toLowerCase().indexOf( query ) !== -1 );
		} );
	} );

	$( document ).on( 'click', '.cm-close-catalog', function () {
		$( '.cm-catalog-modal' ).prop( 'hidden', true );
	} );

	$( document ).on( 'click', '.cm-asset-modal, .cm-catalog-modal', function ( event ) {
		if ( event.target === this ) {
			$( this ).prop( 'hidden', true );
		}
	} );

	$( document ).on( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			$( '.cm-asset-modal, .cm-catalog-modal' ).prop( 'hidden', true );
		}
	} );

	$( document ).on( 'click', '.cm-add-template', function () {
		openDesigner( { title: 'Certificate of Completion', orientation: 'landscape' } );
	} );

	$( document ).on( 'click', '.cm-edit-template', function () {
		var id = $( this ).closest( '.cm-template-item' ).data( 'template-id' );
		request( 'cm_get_template', { template_id: id } ).done( function ( response ) {
			if ( response.success ) {
				openDesigner( response.data );
			} else {
				showAdminNotice( messageFrom( response ), 'error' );
			}
		} ).fail( function ( xhr ) { showAdminNotice( messageFrom( xhr.responseJSON ), 'error' ); } );
	} );

	$( document ).on( 'click', '.cm-preview-template', function () {
		var id = $( this ).closest( '.cm-template-item' ).data( 'template-id' );
		request( 'cm_get_template', { template_id: id } ).done( function ( response ) {
			if ( response.success ) {
				openDesigner( response.data );
				setPreview( true );
			} else {
				showAdminNotice( messageFrom( response ), 'error' );
			}
		} ).fail( function ( xhr ) { showAdminNotice( messageFrom( xhr.responseJSON ), 'error' ); } );
	} );

	$( document ).on( 'click', '.cm-toggle-preview', function () {
		setPreview( ! designer.preview );
	} );

	$( document ).on( 'click', '.cm-close-designer', function () {
		function closeDesigner() {
			$( '.cm-designer' ).prop( 'hidden', true );
			$( '.cm-page-heading, .cm-templates-list' ).prop( 'hidden', false );
		}
		if ( designer.dirty ) {
			openConfirmationModal( {
				title: 'Discard unsaved changes?',
				description: 'Any edits made since the last save will be lost.',
				confirmLabel: 'Discard changes',
				destructive: true
			}, closeDesigner );
			return;
		}
		closeDesigner();
	} );

	$( document ).on( 'click', '.cm-tool[data-type]', function () {
		var type = $( this ).data( 'type' );
		if ( type === 'image' ) {
			chooseMedia( function ( attachment ) {
				addElement( $.extend( defaultElement( 'image' ), { image_id: attachment.id, image_url: attachment.url } ) );
			} );
			return;
		}
		addElement( defaultElement( type ) );
		$( '.cm-catalog-modal' ).prop( 'hidden', true );
	} );

	$( document ).on( 'click', '.cm-variable-chip', function () {
		var element = variableElement( $( this ).data( 'key' ), $( this ).data( 'type' ) || 'text' );
		addElement( element );
		$( '.cm-catalog-modal' ).prop( 'hidden', true );
	} );

	$( document ).on( 'click', '.cm-canvas-element, .cm-layer', function ( event ) {
		event.stopPropagation();
		designer.selectedId = $( this ).data( 'id' );
		renderDesigner();
	} );

	$( document ).on( 'click', '#cm-certificate-canvas', function () {
		designer.selectedId = null;
		renderDesigner();
	} );

	$( document ).on( 'input change', '.cm-element-fields [data-property]', function () {
		var element = selectedElement();
		if ( ! element ) {
			return;
		}
		var property = $( this ).data( 'property' );
		var value = $( this ).is( ':checkbox' ) ? $( this ).is( ':checked' ) : $( this ).val();
		if ( element.locked && property !== 'locked' ) {
			return;
		}
		if ( [ 'x', 'y', 'width', 'height', 'font_size', 'rotation' ].indexOf( property ) !== -1 ) {
			value = Number( value );
		}
		if ( property === 'opacity' ) {
			value = Math.max( 0, Math.min( 1, Number( value ) / 100 ) );
		}
		if ( element.type === 'qr' && property === 'qr_presentation' ) {
			element.qr_presentation = value === 'badge' ? 'badge' : 'plain';
			if ( isBadgedQr( element ) ) {
				var plainQrSize = Math.max( minimumQrSize, Math.min( Number( element.width ), Number( element.height ) ) );
				setBadgedQrSize( element, plainQrSize / badgedQrScale );
			} else {
				var currentQrSize = badgedQrCodeSize( element.height );
				element.width = currentQrSize;
				element.height = currentQrSize;
			}
		} else if ( element.type === 'qr' && isBadgedQr( element ) && ( property === 'width' || property === 'height' || property === 'qr_badge' ) ) {
			if ( property === 'qr_badge' ) {
				element.qr_badge = value;
				setBadgedQrSize( element, element.height );
			} else {
				setBadgedQrSize( element, property === 'width' ? qrSizeFromBadgedWidth( element, value ) : value );
			}
		} else if ( element.type === 'qr' && ( property === 'width' || property === 'height' ) ) {
			element.width = value;
			element.height = value;
		} else if ( element.type === 'image' && element.square && ( property === 'width' || property === 'height' ) ) {
			element.width = value;
			element.height = value;
		} else if ( property === 'square' && value ) {
			var squareSize = Math.max( 2, Math.min( Number( element.width ), Number( element.height ) ) );
			element.width = squareSize;
			element.height = squareSize;
			element.square = true;
		} else {
			element[ property ] = value;
		}
		if ( [ 'x', 'y', 'width', 'height', 'rotation', 'square', 'qr_presentation', 'qr_badge' ].indexOf( property ) !== -1 ) {
			constrainElement( element, pageSize() );
		}
		markDirty();
		renderDesigner();
	} );

	$( document ).on( 'click', '.cm-rotation-preset', function () {
		var element = selectedElement();
		if ( ! element || element.locked || ! isRotatable( element.type ) ) {
			return;
		}
		element.rotation = Number( $( this ).data( 'rotation' ) ) || 0;
		constrainElement( element, pageSize() );
		markDirty();
		renderDesigner();
	} );

	$( document ).on( 'click', '.cm-delete-element', function () {
		designer.elements = designer.elements.filter( function ( element ) { return element.id !== designer.selectedId; } );
		designer.selectedId = null;
		markDirty();
		renderDesigner();
	} );

	$( document ).on( 'click', '.cm-align-element', function () {
		var element = selectedElement();
		if ( ! element ) {
			return;
		}
		if ( element.locked ) {
			return;
		}
		var size = pageSize();
		var alignment = $( this ).data( 'align' );
		if ( alignment === 'horizontal' || alignment === 'both' ) {
			element.x = ( size.width - element.width ) / 2;
		}
		if ( alignment === 'vertical' || alignment === 'both' ) {
			element.y = ( size.height - element.height ) / 2;
		}
		constrainElement( element, size );
		designer.guides.vertical = alignment === 'horizontal' || alignment === 'both' ? size.width / 2 : null;
		designer.guides.horizontal = alignment === 'vertical' || alignment === 'both' ? size.height / 2 : null;
		markDirty();
		renderDesigner();
		window.setTimeout( function () {
			designer.guides.horizontal = null;
			designer.guides.vertical = null;
			renderDesigner();
		}, 650 );
	} );

	$( document ).on( 'click', '.cm-change-media', function () {
		chooseMedia( function ( attachment ) {
			var element = selectedElement();
			if ( element ) {
				element.image_id = attachment.id;
				element.image_url = attachment.url;
				element.image_variable = '';
				markDirty();
				renderDesigner();
			}
		} );
	} );

	$( document ).on( 'change', '#cm-template-orientation', function () {
		var nextOrientation = $( this ).val() === 'portrait' ? 'portrait' : 'landscape';
		if ( nextOrientation === designer.orientation ) {
			return;
		}
		var previousSize = pageSize();
		var nextSize = nextOrientation === 'portrait' ? { width: 210, height: 297 } : { width: 297, height: 210 };
		var scaleX = nextSize.width / previousSize.width;
		var scaleY = nextSize.height / previousSize.height;
		designer.elements.forEach( function ( element ) {
			element.x *= scaleX;
			element.y *= scaleY;
			element.width *= scaleX;
			element.height *= scaleY;
			constrainElement( element, nextSize );
		} );
		designer.orientation = nextOrientation;
		markDirty();
		renderDesigner();
	} );

	$( document ).on( 'change', '.cm-template-webhook', function () {
		designer.webhookIds = $( '.cm-template-webhook:checked' ).map( function () { return Number( $( this ).val() ); } ).get();
		markDirty();
	} );

	$( document ).on( 'input', '#cm-page-color', function () {
		designer.background.color = $( this ).val();
		markDirty();
		renderDesigner();
	} );

	$( document ).on( 'input', '#cm-background-opacity', function () {
		designer.background.opacity = Number( $( this ).val() ) / 100;
		markDirty();
		renderDesigner();
	} );

	$( document ).on( 'click', '.cm-background-media', function () {
		chooseMedia( function ( attachment ) {
			designer.background.image_id = attachment.id;
			designer.background.image_url = attachment.url;
			markDirty();
			renderDesigner();
		} );
	} );

	$( document ).on( 'click', '.cm-clear-background', function () {
		designer.background.image_id = 0;
		designer.background.image_url = '';
		markDirty();
		renderDesigner();
	} );

	$( document ).on( 'pointerdown', '.cm-canvas-element', function ( event ) {
		if ( designer.preview ) {
			return;
		}
		if ( $( event.target ).hasClass( 'cm-resize-handle' ) ) {
			return;
		}
		event.preventDefault();
		var id = $( this ).data( 'id' );
		var element = designer.elements.find( function ( item ) { return item.id === id; } );
		if ( ! element ) {
			return;
		}
		designer.selectedId = id;
		if ( element.locked ) {
			renderDesigner();
			return;
		}
		var canvasRect = document.getElementById( 'cm-certificate-canvas' ).getBoundingClientRect();
		var size = pageSize();
		var start = { x: event.clientX, y: event.clientY, left: element.x, top: element.y };

		function move( moveEvent ) {
			var rawX = start.left + ( moveEvent.clientX - start.x ) / canvasRect.width * size.width;
			var rawY = start.top + ( moveEvent.clientY - start.y ) / canvasRect.height * size.height;
			var nextX = isRotatable( element.type ) ? rawX : Math.max( 0, Math.min( size.width - element.width, rawX ) );
			var nextY = isRotatable( element.type ) ? rawY : Math.max( 0, Math.min( size.height - element.height, rawY ) );
			var toleranceX = 6 / canvasRect.width * size.width;
			var toleranceY = 6 / canvasRect.height * size.height;
			var snapped = snapElementPosition( element, nextX, nextY, size, toleranceX, toleranceY );
			designer.guides.vertical = snapped.vertical;
			designer.guides.horizontal = snapped.horizontal;
			element.x = snapped.x;
			element.y = snapped.y;
			constrainElement( element, size );
			markDirty();
			renderDesigner();
		}
		function up() {
			$( document ).off( 'pointermove.cmDesigner', move ).off( 'pointerup.cmDesigner', up );
			designer.guides.horizontal = null;
			designer.guides.vertical = null;
			renderDesigner();
		}
		$( document ).on( 'pointermove.cmDesigner', move ).on( 'pointerup.cmDesigner', up );
	} );

	$( document ).on( 'pointerdown', '.cm-resize-handle', function ( event ) {
		if ( designer.preview ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		var id = $( this ).closest( '.cm-canvas-element' ).data( 'id' );
		var element = designer.elements.find( function ( item ) { return item.id === id; } );
		if ( ! element || element.locked ) {
			return;
		}
		var canvasRect = document.getElementById( 'cm-certificate-canvas' ).getBoundingClientRect();
		var size = pageSize();
		var start = { x: event.clientX, y: event.clientY, width: element.width, height: element.height };
		designer.selectedId = id;

		function resize( moveEvent ) {
			var nextWidth = start.width + ( moveEvent.clientX - start.x ) / canvasRect.width * size.width;
			var nextHeight = start.height + ( moveEvent.clientY - start.y ) / canvasRect.height * size.height;
			if ( element.type === 'qr' || ( element.type === 'image' && element.square ) ) {
				var widthChange = nextWidth - start.width;
				var heightChange = nextHeight - start.height;
				var nextSize = Math.abs( widthChange ) >= Math.abs( heightChange ) ? nextWidth : nextHeight;
				if ( isBadgedQr( element ) ) {
					var widthScale = nextWidth / start.width;
					var heightScale = nextHeight / start.height;
					nextSize = Math.abs( widthScale - 1 ) >= Math.abs( heightScale - 1 ) ? start.height * widthScale : nextHeight;
				}
				if ( element.type === 'qr' && isBadgedQr( element ) ) {
					nextSize = Math.max( minimumBadgedQrHeight, Math.min( maxBadgedQrHeight( element, size.width - element.x, size.height - element.y ), nextSize ) );
				} else if ( element.type === 'qr' ) {
					nextSize = Math.max( minimumQrSize, Math.min( size.width - element.x, size.height - element.y, nextSize ) );
				} else {
					nextSize = Math.max( 2, nextSize );
				}
				if ( isBadgedQr( element ) ) {
					setBadgedQrSize( element, nextSize );
				} else {
					element.width = nextSize;
					element.height = nextSize;
				}
			} else {
				element.width = isRotatable( element.type ) ? Math.max( 2, nextWidth ) : Math.max( 2, Math.min( size.width - element.x, nextWidth ) );
				element.height = isRotatable( element.type ) ? Math.max( 1, nextHeight ) : Math.max( 1, Math.min( size.height - element.y, nextHeight ) );
			}
			constrainElement( element, size );
			markDirty();
			renderDesigner();
		}
		function up() {
			$( document ).off( 'pointermove.cmDesignerResize', resize ).off( 'pointerup.cmDesignerResize', up );
		}
		$( document ).on( 'pointermove.cmDesignerResize', resize ).on( 'pointerup.cmDesignerResize', up );
	} );

	$( document ).on( 'keydown', function ( event ) {
		if ( ( event.key === 'Delete' || event.key === 'Backspace' ) && designer.selectedId && ! designer.preview && ! $( event.target ).is( ':input, [contenteditable="true"]' ) ) {
			event.preventDefault();
			$( '.cm-delete-element' ).trigger( 'click' );
		}
	} );

	function usedVariables() {
		var keys = [];
		designer.elements.forEach( function ( element ) {
			var content = ( element.text || '' ) + ' ' + ( element.value || '' );
			var match;
			var pattern = /{{\s*([a-z0-9_-]+)\s*}}/gi;
			while ( ( match = pattern.exec( content ) ) !== null ) {
				if ( keys.indexOf( match[ 1 ] ) === -1 ) {
					keys.push( match[ 1 ] );
				}
			}
			if ( element.image_variable && keys.indexOf( element.image_variable ) === -1 ) {
				keys.push( element.image_variable );
			}
		} );
		return keys;
	}

	$( document ).on( 'click', '.cm-save-template', function () {
		var name = $.trim( $( '#cm-template-name' ).val() );
		if ( ! name ) {
			showAdminNotice( 'Enter a template name.', 'error' );
			$( '#cm-template-name' ).trigger( 'focus' );
			return;
		}
		var $button = $( this ).prop( 'disabled', true );
		$( '.cm-save-state' ).text( 'Saving…' );
		request( 'cm_save_template', {
			template_id: designer.templateId,
			name: name,
			description: '',
			content: JSON.stringify( designer.elements ),
			background: JSON.stringify( designer.background ),
			variables: JSON.stringify( usedVariables() ),
			webhook_ids: JSON.stringify( designer.webhookIds ),
			orientation: designer.orientation
		} ).done( function ( response ) {
			if ( response.success ) {
				designer.templateId = Number( response.data.id );
				designer.dirty = false;
				$( '.cm-save-state' ).text( cmAdmin.l10n.saveSuccess );
			} else {
				$( '.cm-save-state' ).text( messageFrom( response ) );
			}
		} ).fail( function ( xhr ) {
			$( '.cm-save-state' ).text( messageFrom( xhr.responseJSON ) );
		} ).always( function () { $button.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '.cm-duplicate-template', function () {
		complete( request( 'cm_duplicate_template', { template_id: $( this ).closest( '.cm-template-item' ).data( 'template-id' ) } ), cmAdmin.l10n.duplicateSuccess );
	} );

	$( document ).on( 'click', '.cm-set-default-template', function () {
		complete( request( 'cm_set_default_template', { template_id: $( this ).closest( '.cm-template-item' ).data( 'template-id' ) } ), cmAdmin.l10n.defaultSuccess );
	} );

	$( document ).on( 'click', '.cm-delete-template', function () {
		var templateId = $( this ).closest( '.cm-template-item' ).data( 'template-id' );
		openConfirmationModal( {
			title: 'Delete template?',
			description: 'This removes the template and cannot be undone.',
			confirmLabel: 'Delete template',
			destructive: true
		}, function () {
			complete( request( 'cm_delete_template', { template_id: templateId } ), cmAdmin.l10n.deleteSuccess );
		} );
	} );

	$( document ).on( 'click', '.cm-open-issue-form, .cm-close-issue-form', function () {
		$( '.cm-issue-panel' ).prop( 'hidden', ! $( '.cm-issue-panel' ).prop( 'hidden' ) );
	} );

	function updateCsvTemplateDownload() {
		var templateId = String( $( '#cm-import-template' ).val() || '' );
		var $download = $( '.cm-download-template-csv' );
		var enabled = !! templateId && !! cmAdmin.csvTemplateUrl;
		$download.toggleClass( 'disabled', ! enabled ).attr( 'aria-disabled', enabled ? 'false' : 'true' );
		$download.attr( 'href', enabled ? cmAdmin.csvTemplateUrl + '&template_id=' + encodeURIComponent( templateId ) : '#' );
		$( '.cm-csv-template-fields' ).each( function () {
			$( this ).prop( 'hidden', String( $( this ).data( 'template-id' ) ) !== templateId );
		} );
	}

	$( document ).on( 'click', '[data-issue-mode]', function () {
		var mode = $( this ).data( 'issue-mode' );
		$( '[data-issue-mode]' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		$( this ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
		$( '[data-issue-mode-panel]' ).each( function () {
			$( this ).prop( 'hidden', $( this ).data( 'issue-mode-panel' ) !== mode );
		} );
	} );

	$( document ).on( 'change', '#cm-import-template', updateCsvTemplateDownload );
	$( document ).on( 'click', '.cm-download-template-csv[aria-disabled="true"]', function ( event ) {
		event.preventDefault();
		showAdminNotice( 'Choose a template before downloading its CSV layout.', 'error' );
	} );

	$( document ).on( 'change', '#cm-issue-template', function () {
		var id = String( $( this ).val() );
		$( '.cm-template-variable-fields' ).each( function () {
			var active = String( $( this ).data( 'template-id' ) ) === id;
			$( this ).prop( 'hidden', ! active ).find( ':input' ).prop( 'disabled', ! active );
		} );
	} );

	$( '.cm-template-variable-fields :input' ).prop( 'disabled', true );
	$( '#cm-issue-template' ).trigger( 'change' );
	updateCsvTemplateDownload();

	$( document ).on( 'change', '[name="expiry_enabled"]', function () {
		$( '.cm-expiry-duration' ).prop( 'hidden', ! this.checked );
	} );

	$( document ).on( 'submit', '#cm-issue-certificate-form', function ( event ) {
		event.preventDefault();
		var $form = $( this );
		var $button = $form.find( '[type="submit"]' ).prop( 'disabled', true );
		$( '.cm-issue-status' ).removeClass( 'is-error is-success' ).text( 'Issuing…' );
		$.post( cmAdmin.ajaxUrl, $form.serialize() + '&action=cm_issue_certificate&nonce=' + encodeURIComponent( cmAdmin.nonce ) ).done( function ( response ) {
			if ( response.success ) {
				$( '.cm-issue-status' ).addClass( 'is-success' ).text( 'Issued ' + response.data.certificate_number + ' successfully.' );
				window.setTimeout( function () { window.location.reload(); }, 900 );
			} else {
				$( '.cm-issue-status' ).addClass( 'is-error' ).text( messageFrom( response ) );
				$button.prop( 'disabled', false );
			}
		} ).fail( function ( xhr ) {
			$( '.cm-issue-status' ).addClass( 'is-error' ).text( messageFrom( xhr.responseJSON ) );
			$button.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'submit', '#cm-import-certificates-form', function ( event ) {
		event.preventDefault();
		var $form = $( this );
		var $button = $form.find( '[type="submit"]' ).prop( 'disabled', true );
		var $status = $( '.cm-csv-import-status' ).removeClass( 'is-error is-success' ).text( 'Importing and issuing certificates…' );
		var formData = new window.FormData( this );
		formData.append( 'action', 'cm_import_certificates_csv' );
		formData.append( 'nonce', cmAdmin.nonce );
		$.ajax( { url: cmAdmin.ajaxUrl, method: 'POST', data: formData, processData: false, contentType: false } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$status.addClass( 'is-error' ).text( messageFrom( response ) );
				$button.prop( 'disabled', false );
				return;
			}
			var data = response.data || {};
			var summary = 'Issued ' + Number( data.issued || 0 ) + ' of ' + Number( data.total || 0 ) + ' certificate' + ( Number( data.total || 0 ) === 1 ? '' : 's' ) + '.';
			if ( Number( data.failed || 0 ) || data.partial ) {
				if ( Number( data.failed || 0 ) ) {
					summary += ' ' + Number( data.failed ) + ' row' + ( Number( data.failed ) === 1 ? '' : 's' ) + ' need attention.';
				}
				if ( data.errors && data.errors.length ) {
					summary += ' ' + data.errors.join( ' ' );
				}
				$status.addClass( 'is-error' ).text( summary );
				$button.prop( 'disabled', false );
				return;
			}
			$status.addClass( 'is-success' ).text( summary );
			window.setTimeout( function () { window.location.reload(); }, 1100 );
		} ).fail( function ( xhr ) {
			$status.addClass( 'is-error' ).text( messageFrom( xhr.responseJSON ) );
			$button.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'keydown', '#cm-issue-certificate-form', function ( event ) {
		if ( event.key !== 'Enter' ) {
			return;
		}
		event.stopPropagation();
		if ( $( event.target ).is( 'textarea' ) ) {
			return;
		}
		event.preventDefault();
	} );

	$( document ).on( 'click', '.cm-select-variable-media', function () {
		var $field = $( this ).siblings( '.cm-image-variable-input' );
		var $selection = $( this ).siblings( '.cm-image-variable-selection' );
		chooseMedia( function ( attachment ) {
			$field.val( attachment.id );
			$selection.text( attachment.filename || attachment.title || 'Image selected' );
		} );
	} );

	$( document ).on( 'click', '.cm-add-variable', function () {
		var name = $.trim( $( '#cm-new-variable-name' ).val() );
		var key = $.trim( $( '#cm-new-variable-key' ).val() );
		var fieldType = $( '#cm-new-variable-type' ).val() || 'text';
		var $status = $( '.cm-variable-create-status' ).removeClass( 'is-error' ).text( '' );
		if ( ! name || ! key ) {
			$status.addClass( 'is-error' ).text( 'Enter both a label and a key.' );
			$( ! name ? '#cm-new-variable-name' : '#cm-new-variable-key' ).trigger( 'focus' );
			return;
		}
		complete( request( 'cm_save_variable', { name: name, key: key, field_type: fieldType, field_options: JSON.stringify( fieldType === 'image' ? variableImageOptions.map( function ( attachment ) { return attachment.id; } ) : [] ), value: '' } ), cmAdmin.l10n.saveSuccess );
	} );

	$( document ).on( 'change', '#cm-new-variable-type', function () {
		var isImage = $( this ).val() === 'image';
		$( '#cm-image-variable-options' ).prop( 'hidden', ! isImage );
		if ( isImage ) {
			renderVariableImageOptions();
		}
	} );

	$( document ).on( 'click', '.cm-select-variable-image-options', function () {
		chooseVariableImageOptions( variableImageOptions.map( function ( attachment ) { return attachment.id; } ), function ( attachments ) {
			variableImageOptions = attachments;
			renderVariableImageOptions();
		} );
	} );

	$( document ).on( 'click', '.cm-variable-image-option button', function () {
		var id = Number( $( this ).closest( '.cm-variable-image-option' ).data( 'id' ) );
		variableImageOptions = variableImageOptions.filter( function ( attachment ) { return attachment.id !== id; } );
		renderVariableImageOptions();
	} );

	$( document ).on( 'input', '#cm-new-variable-name', function () {
		var $key = $( '#cm-new-variable-key' );
		if ( ! $key.val() || $key.data( 'generated-key' ) ) {
			$key.val( $.trim( $( this ).val() ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_|_$/g, '' ) ).data( 'generated-key', true );
		}
	} );

	$( document ).on( 'input', '#cm-new-variable-key', function () {
		$( this ).data( 'generated-key', false );
	} );

	$( document ).on( 'click', '.cm-configure-variable-images', function () {
		var variableId = $( this ).closest( '.cm-variable-item' ).data( 'variable-id' );
		request( 'cm_get_variable', { variable_id: variableId } ).done( function ( response ) {
			if ( ! response.success ) {
				showAdminNotice( messageFrom( response ), 'error' );
				return;
			}
			var selectedIds = safeJson( response.data.field_options, [] ).map( Number ).filter( function ( id ) { return id > 0; } );
			chooseVariableImageOptions( selectedIds, function ( attachments ) {
				complete( request( 'cm_save_variable', {
					variable_id: response.data.id,
					name: response.data.label,
					key: response.data.variable_key,
					field_type: 'image',
					field_options: JSON.stringify( attachments.map( function ( attachment ) { return attachment.id; } ) ),
					value: response.data.default_value || ''
				} ), cmAdmin.l10n.saveSuccess );
			} );
		} );
	} );

	$( document ).on( 'click', '.cm-edit-variable', function () {
		var item = $( this ).closest( '.cm-variable-item' );
		request( 'cm_get_variable', { variable_id: item.data( 'variable-id' ) } ).done( function ( response ) {
			if ( ! response.success ) {
				showAdminNotice( messageFrom( response ), 'error' );
				return;
			}
			openActionModal( {
				title: 'Edit variable',
				description: 'Choose how issuers will enter this certificate value.',
				confirmLabel: 'Save variable',
				fields: [
					{ name: 'name', label: 'Label', required: true, value: response.data.label || '' },
					{ name: 'key', label: 'Key', required: true, value: response.data.variable_key || '' },
					{ name: 'field_type', label: 'Input type', type: 'select', value: response.data.field_type || 'text', options: [ { value: 'text', label: 'Single-line text' }, { value: 'textarea', label: 'Multi-line text' }, { value: 'email', label: 'Email address' }, { value: 'number', label: 'Number' }, { value: 'date', label: 'Date' }, { value: 'url', label: 'URL' }, { value: 'image', label: 'Image' } ] },
					{ name: 'value', label: 'Default value', value: response.data.default_value || '' }
				]
			}, function ( values ) {
				complete( request( 'cm_save_variable', { variable_id: response.data.id, name: values.name, key: values.key, field_type: values.field_type, field_options: values.field_type === 'image' ? ( response.data.field_options || '[]' ) : '[]', value: values.field_type === 'image' ? '' : values.value } ), cmAdmin.l10n.saveSuccess );
			} );
		} );
	} );

	$( document ).on( 'click', '.cm-delete-variable', function () {
		var variableId = $( this ).closest( '.cm-variable-item' ).data( 'variable-id' );
		openConfirmationModal( {
			title: 'Delete variable?',
			description: 'This removes the variable and cannot be undone.',
			confirmLabel: 'Delete variable',
			destructive: true
		}, function () {
			complete( request( 'cm_delete_variable', { variable_id: variableId } ), cmAdmin.l10n.deleteSuccess );
		} );
	} );

	$( document ).on( 'click', '.cm-revoke-certificate', function () {
		var certificateId = $( this ).data( 'id' );
		openActionModal( {
			title: 'Revoke certificate',
			description: 'Revoking a certificate changes its public verification status. Add a reason so the audit trail is clear.',
			confirmLabel: 'Revoke certificate',
			destructive: true,
			fields: [ { name: 'reason', label: 'Reason for revocation', type: 'textarea', placeholder: 'e.g. Issued in error' } ]
		}, function ( values ) {
			complete( request( 'cm_revoke_certificate', { certificate_id: certificateId, reason: values.reason } ), cmAdmin.l10n.revokeSuccess );
		} );
	} );

	$( document ).on( 'click', '.cm-view-verification-page', function () {
		var url = String( $( this ).data( 'verification-url' ) || '' );
		if ( url ) {
			openVerificationPreview( url );
		}
	} );

	$( document ).on( 'click', '.cm-replace-certificate', function () {
		var certificateId = $( this ).data( 'id' );
		openActionModal( {
			title: 'Replace certificate',
			description: 'Enter the internal record ID of the certificate that replaces this one. The current certificate will be marked as replaced.',
			confirmLabel: 'Mark as replaced',
			fields: [ { name: 'replacement_id', label: 'Replacement certificate record ID', type: 'number', min: '1', required: true, placeholder: 'e.g. 123' } ]
		}, function ( values ) {
			complete( request( 'cm_replace_certificate', { certificate_id: certificateId, replacement_id: values.replacement_id } ), cmAdmin.l10n.replaceSuccess );
		} );
	} );

	$( document ).on( 'click', '.cm-trigger-certificate-webhook', function () {
		var certificateId = $( this ).data( 'id' );
		request( 'cm_get_certificate_webhook_options', { certificate_id: certificateId } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showAdminNotice( messageFrom( response ), 'error' );
				return;
			}
			var linked = response.data.linked || [];
			var choices = linked.length ? linked : ( response.data.available || [] );
			if ( ! choices.length ) {
				showAdminNotice( 'No active certificate-issued webhooks are configured. Add one from Certificates > Webhooks first.', 'error' );
				return;
			}
			openActionModal( {
				title: 'Trigger webhook',
				description: linked.length ? 'Choose the linked webhook to trigger for this certificate.' : 'No webhook is linked to this certificate. Choose one to link and trigger.',
				confirmLabel: 'Trigger webhook',
				fields: [ {
					name: 'webhook_id',
					label: 'Webhook',
					type: 'select',
					required: true,
					options: choices.map( function ( webhook ) { return { value: webhook.id, label: webhook.name }; } )
				} ]
			}, function ( values ) {
				complete( request( 'cm_trigger_certificate_webhook', { certificate_id: certificateId, webhook_id: values.webhook_id } ), 'Webhook triggered. Delivery status is available in the logs.' );
			} );
		} ).fail( function ( xhr ) {
			showAdminNotice( messageFrom( xhr.responseJSON ), 'error' );
		} );
	} );

	$( document ).on( 'click', '.cm-clear-logs', function () {
		var type = $( this ).data( 'type' );
		openConfirmationModal( {
			title: 'Clear logs?',
			description: 'This permanently removes the selected log entries.',
			confirmLabel: 'Clear logs',
			destructive: true
		}, function () {
			complete( request( 'cm_clear_logs', { type: type } ), cmAdmin.l10n.clearSuccess );
		} );
	} );

	$( document ).on( 'submit', '.cm-purge-form', function ( event ) {
		var form = this;
		if ( $( form ).data( 'confirmed' ) ) {
			return;
		}
		event.preventDefault();
		if ( $( form ).find( '[name="confirmation"]' ).val().trim().toUpperCase() !== $( form ).data( 'confirmation' ) ) {
			showAdminNotice( 'Enter the requested confirmation phrase exactly before continuing.', 'error' );
			return;
		}
		openConfirmationModal( {
			title: 'Permanently delete ' + $( form ).data( 'label' ) + '?',
			description: 'This final action cannot be undone. Audit and operational logs will be retained.',
			confirmLabel: 'Delete permanently',
			destructive: true
		}, function () {
			$( form ).data( 'confirmed', true );
			form.submit();
		} );
	} );
} )( jQuery );
