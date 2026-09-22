/**
 * OptiForge Image Optimizer — Admin JavaScript with Offset & Time-Budgeted Loop Fixes.
 */

/* global OFIO, jQuery */
( function ( $, OFIO ) {
	'use strict';

	if ( typeof OFIO === 'undefined' ) {
		return;
	}

	var bulk = {
		running    : false,
		paused     : false,
		stopped    : false,
		isRestore  : false,
		forceAll   : false,
		offset     : 0,
		total      : 0,
		processed  : 0,
		savedBytes : 0,
	};

	var $btnStart      = $( '#ofio-btn-start' );
	var $btnPause      = $( '#ofio-btn-pause' );
	var $btnResume     = $( '#ofio-btn-resume' );
	var $btnStop       = $( '#ofio-btn-stop' );
	var $btnBulkRst    = $( '#ofio-btn-bulk-restore' );
	var $forceCheck    = $( '#ofio-force-reoptimize' );
	var $progressWrap  = $( '#ofio-progress-wrap' );
	var $progressBar   = $( '#ofio-progress-bar' );
	var $progressText  = $( '#ofio-progress-text' );
	var $statsInline   = $( '#ofio-stats-inline' );
	var $statProcessed = $( '#ofio-stat-processed' );
	var $statRemaining = $( '#ofio-stat-remaining' );
	var $statSaved     = $( '#ofio-stat-saved' );
	var $log           = $( '#ofio-log' );

	function formatBytes( bytes ) {
		if ( bytes < 1024 )       { return bytes + ' B'; }
		if ( bytes < 1048576 )    { return ( bytes / 1024 ).toFixed( 1 ) + ' KB'; }
		if ( bytes < 1073741824 ) { return ( bytes / 1048576 ).toFixed( 2 ) + ' MB'; }
		return ( bytes / 1073741824 ).toFixed( 3 ) + ' GB';
	}

	function updateProgress() {
		if ( bulk.total === 0 ) { return; }
		var pct = Math.min( 100, Math.round( ( bulk.processed / bulk.total ) * 100 ) );
		$progressBar.css( 'width', pct + '%' );
		$progressText.text( pct + '%' );
		$statProcessed.text( bulk.processed );
		$statRemaining.text( Math.max( 0, bulk.total - bulk.processed ) );
		$statSaved.text( formatBytes( bulk.savedBytes ) );
	}

	function addLog( type, filename, detail ) {
		var $entry = $( '<div>' ).addClass( 'ofio-log-entry ofio-log-entry--' + type );
		$entry.append( $( '<span>' ).addClass( 'ofio-log-icon' ) );
		$entry.append( $( '<span>' ).addClass( 'ofio-log-filename' ).text( filename || '' ) );
		if ( detail ) {
			$entry.append( $( '<span>' ).addClass( 'ofio-log-detail' ).text( detail ) );
		}
		$log.append( $entry );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	}

	function startBulk() {
		if ( bulk.running ) { return; }

		bulk.running    = true;
		bulk.paused     = false;
		bulk.stopped    = false;
		bulk.isRestore  = false;
		bulk.forceAll   = $forceCheck.is( ':checked' );
		bulk.offset     = 0;
		bulk.total      = 0;
		bulk.processed  = 0;
		bulk.savedBytes = 0;

		$log.empty();
		$progressBar.css( 'width', '0%' );
		$progressText.text( '0%' );
		$progressWrap.show();
		$statsInline.show();
		$log.show();
		$btnStart.hide();
		$btnPause.show();
		$btnStop.show();

		addLog( 'info', OFIO.i18n.fetching, '' );

		$.post( OFIO.ajax_url, {
			action    : 'ofio_get_unoptimized_images',
			security  : OFIO.nonce,
			force_all : bulk.forceAll ? 1 : 0,
		} ).done( function ( response ) {
			if ( ! response.success ) {
				addLog( 'error', response.data.message || OFIO.i18n.error, '' );
				bulk.running = false;
				$btnStart.show();
				$btnPause.hide();
				return;
			}

			bulk.total = response.data.unoptimized || 0;
			if ( bulk.total === 0 ) {
				addLog( 'info', OFIO.i18n.no_images, '' );
				bulk.running = false;
				$btnStart.show();
				$btnPause.hide();
				return;
			}

			addLog( 'info', OFIO.i18n.starting, bulk.total + ' images queue' );
			updateProgress();
			processNextBatch();
		} );
	}

	function processNextBatch() {
		if ( bulk.stopped || bulk.paused ) { return; }

		$.ajax( {
			url     : OFIO.ajax_url,
			method  : 'POST',
			timeout : 120000,
			data    : {
				action    : 'ofio_process_image_batch',
				security  : OFIO.nonce,
				force_all : bulk.forceAll ? 1 : 0,
				offset    : bulk.offset,
			},
		} ).done( function ( response ) {
			if ( response.success ) {
				var items = response.data.processed || [];
				$.each( items, function ( i, item ) {
					bulk.processed++;
					bulk.savedBytes += ( item.saved_bytes || 0 );
					addLog( item.success ? 'success' : 'error', item.filename || ( 'ID #' + item.id ), item.message || '' );
				} );

				// Only increment offset in force-all mode!
				if ( bulk.forceAll ) {
					bulk.offset += ( response.data.count_done || items.length );
				}

				updateProgress();

				if ( response.data.remaining === 0 || items.length === 0 ) {
					$progressBar.css( 'width', '100%' );
					$progressText.text( '100%' );
					addLog( 'info', OFIO.i18n.complete, formatBytes( bulk.savedBytes ) + ' saved' );
					bulk.running = false;
					$btnStart.show();
					$btnPause.hide();
					return;
				}
				setTimeout( processNextBatch, 250 );
			}
		} );
	}

	$btnBulkRst.on( 'click', function () {
		if ( ! window.confirm( OFIO.i18n.confirm_bulk_rst ) ) { return; }

		bulk.running    = true;
		bulk.paused     = false;
		bulk.stopped    = false;
		bulk.isRestore  = true;
		bulk.total      = 0;
		bulk.processed  = 0;
		bulk.savedBytes = 0;

		$log.empty().show();
		$progressBar.css( 'width', '0%' );
		$progressText.text( '0%' );
		$progressWrap.show();
		$statsInline.show();
		$btnStart.hide();
		$btnPause.hide();
		$btnStop.show();

		addLog( 'info', 'Fetching restore queue…', '' );

		$.post( OFIO.ajax_url, {
			action   : 'ofio_get_restore_queue',
			security : OFIO.nonce,
		} ).done( function ( res ) {
			if ( ! res.success || res.data.total_restorable === 0 ) {
				addLog( 'info', 'No backed up images found.', '' );
				bulk.running = false;
				$btnStart.show();
				$btnStop.hide();
				return;
			}

			bulk.total = res.data.total_restorable;
			updateProgress();
			processNextRestoreBatch();
		} );
	} );

	function processNextRestoreBatch() {
		if ( bulk.stopped ) { return; }

		$.post( OFIO.ajax_url, {
			action   : 'ofio_process_restore_batch',
			security : OFIO.nonce,
		} ).done( function ( res ) {
			if ( res.success ) {
				var items = res.data.processed || [];
				$.each( items, function ( i, item ) {
					bulk.processed++;
					addLog( item.success ? 'success' : 'error', 'Attachment #' + item.id, item.message );
				} );

				updateProgress();

				if ( res.data.remaining === 0 || items.length === 0 ) {
					$progressBar.css( 'width', '100%' );
					$progressText.text( '100%' );
					addLog( 'info', 'Bulk restore completed successfully!', '' );
					bulk.running = false;
					$btnStart.show();
					$btnStop.hide();
					return;
				}
				setTimeout( processNextRestoreBatch, 300 );
			}
		} );
	}

	$btnStart.on( 'click', startBulk );

	$btnPause.on( 'click', function () {
		bulk.paused = true;
		$btnPause.hide();
		$btnResume.show();
	} );

	$btnResume.on( 'click', function () {
		bulk.paused = false;
		$btnResume.hide();
		$btnPause.show();
		processNextBatch();
	} );

	$btnStop.on( 'click', function () {
		if ( ! window.confirm( OFIO.i18n.confirm_stop ) ) { return; }
		bulk.stopped = true;
		bulk.running = false;
		$btnStart.show();
		$btnPause.hide();
		$btnResume.hide();
		$btnStop.hide();
	} );

	$( '#ofio-purge-backups' ).on( 'click', function () {
		if ( ! window.confirm( OFIO.i18n.confirm_purge ) ) { return; }
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Purging…' );

		$.post( OFIO.ajax_url, {
			action   : 'ofio_purge_backups',
			security : OFIO.nonce,
		}, function ( response ) {
			if ( response.success ) {
				window.alert( response.data.message );
				window.location.reload();
			} else {
				window.alert( 'Failed to purge backups.' );
				$btn.prop( 'disabled', false ).text( 'Delete All Backups' );
			}
		} );
	} );

	// Media Library Live Inline Optimization (NO PAGE RELOAD)
	$( document ).on( 'click', '.ofio-reoptimize', function ( e ) {
		e.preventDefault();
		var $btn  = $( this );
		var id    = parseInt( $btn.data( 'id' ), 10 );
		var nonce = String( $btn.data( 'nonce' ) );
		var $cell = $( '#ofio-media-cell-' + id );

		$cell.html( '<span class="ofio-spinner"></span> <small style="color:#2271b1;font-weight:600;">Optimizing…</small>' );

		$.post( OFIO.ajax_url, {
			action        : 'ofio_reoptimize_image',
			security      : nonce,
			attachment_id : id,
		} ).done( function ( response ) {
			if ( response.success && response.data.html ) {
				$cell.html( response.data.html );
			} else {
				$cell.html( '<span class="ofio-badge ofio-badge--error">Failed</span>' );
			}
		} ).fail( function () {
			$cell.html( '<span class="ofio-badge ofio-badge--error">Error</span>' );
		} );
	} );

	$( document ).on( 'click', '.ofio-restore', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( OFIO.i18n.confirm_restore ) ) { return; }
		var $btn  = $( this );
		var id    = parseInt( $btn.data( 'id' ), 10 );
		var nonce = String( $btn.data( 'nonce' ) );
		var $cell = $( '#ofio-media-cell-' + id );

		$cell.html( '<span class="ofio-spinner"></span> <small style="color:#d63638;font-weight:600;">Restoring…</small>' );

		$.post( OFIO.ajax_url, {
			action        : 'ofio_restore_image',
			security      : nonce,
			attachment_id : id,
		} ).done( function ( response ) {
			if ( response.success && response.data.html ) {
				$cell.html( response.data.html );
			} else {
				$cell.html( '<span class="ofio-badge ofio-badge--error">Failed</span>' );
			}
		} ).fail( function () {
			$cell.html( '<span class="ofio-badge ofio-badge--error">Error</span>' );
		} );
	} );

} )( jQuery, window.OFIO || {} );