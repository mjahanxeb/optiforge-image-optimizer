/**
 * OptiForge Image Optimizer — Admin JavaScript
 *
 * Implements:
 * 1. True Background Processing: browser-independent execution via background queue.
 * 2. Instant Startup (Zero UI Blocking): immediately launches background job without synchronous queries.
 * 3. Real-time Telemetry Polling: lightweight transient-powered polling every 1.5s.
 * 4. Automatic Session Reconnection: immediately hooks into active/paused queues on page load.
 * 5. Single Image Actions in Media Library list table and Media Modal.
 */

/* global OFIO, jQuery */
( function ( $, OFIO ) {
	'use strict';

	if ( typeof OFIO === 'undefined' ) {
		return;
	}

	var pollTimer       = null;
	var pollInFlight    = false;
	var loggedItemKeys  = {};
	var isRunning       = false;

	var $btnStart       = $( '#ofio-btn-start' );
	var $btnPause       = $( '#ofio-btn-pause' );
	var $btnResume      = $( '#ofio-btn-resume' );
	var $btnStop        = $( '#ofio-btn-stop' );
	var $btnBulkRst     = $( '#ofio-btn-bulk-restore' );
	var $forceCheck     = $( '#ofio-force-reoptimize' );
	var $progressWrap   = $( '#ofio-progress-wrap' );
	var $progressBar    = $( '#ofio-progress-bar' );
	var $progressText   = $( '#ofio-progress-text' );
	var $statsInline    = $( '#ofio-stats-inline' );
	var $statProcessed  = $( '#ofio-stat-processed' );
	var $statRemaining  = $( '#ofio-stat-remaining' );
	var $statSaved      = $( '#ofio-stat-saved' );
	var $activeFileWrap = $( '#ofio-active-file-wrap' );
	var $log            = $( '#ofio-log' );

	function formatBytes( bytes ) {
		bytes = parseInt( bytes, 10 ) || 0;
		if ( bytes < 1024 )       { return bytes + ' B'; }
		if ( bytes < 1048576 )    { return ( bytes / 1024 ).toFixed( 1 ) + ' KB'; }
		if ( bytes < 1073741824 ) { return ( bytes / 1048576 ).toFixed( 2 ) + ' MB'; }
		return ( bytes / 1073741824 ).toFixed( 3 ) + ' GB';
	}

	function updateDashboardCards( stats ) {
		if ( ! stats ) { return; }
		var savedMb   = ( parseFloat( stats.saved_mb ) || 0 ).toFixed( 2 );
		var pct       = ( parseFloat( stats.pct_saved ) || 0 ).toFixed( 1 );
		var optImages = parseInt( stats.optimized_images, 10 ) || 0;
		var totImages = parseInt( stats.total_images, 10 ) || 0;

		$( '#ofio-stat-saved-mb' ).text( savedMb + ' MB' );
		$( '#ofio-stat-pct-saved' ).text( pct + '%' );
		$( '#ofio-stat-optimized-images' ).text( optImages );
		if ( totImages > 0 ) {
			$( '#ofio-stat-total-images' ).text( totImages );
		}

		if ( optImages === 0 ) {
			$btnBulkRst.prop( 'disabled', true );
		} else {
			$btnBulkRst.prop( 'disabled', false );
		}
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

	function startPolling() {
		stopPolling();
		pollInFlight = false;
		pollQueueStatus();
		pollTimer = setInterval( pollQueueStatus, 1500 );
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function pollQueueStatus() {
		if ( pollInFlight ) { return; }
		pollInFlight = true;

		$.post( OFIO.ajax_url, {
			action   : 'ofio_get_bulk_status',
			security : OFIO.nonce,
		} ).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				return;
			}

			var data       = response.data;
			var state      = data.state || {};
			var recentLog  = data.recent_log || [];
			var stats      = data.stats || null;

			if ( stats ) {
				updateDashboardCards( stats );
			}

			// Render new log entries.
			if ( recentLog.length ) {
				// Reverse to display oldest first.
				var reversed = recentLog.slice().reverse();
				$.each( reversed, function ( i, item ) {
					var key = item.id + '_' + ( item.time || 0 ) + '_' + ( item.saved_bytes || 0 );
					if ( ! loggedItemKeys[ key ] ) {
						loggedItemKeys[ key ] = true;
						var type = item.success ? 'success' : 'error';
						var detail = item.message || ( item.saved_bytes ? 'Saved ' + formatBytes( item.saved_bytes ) : '' );
						addLog( type, item.filename, detail );
					}
				} );
			}

			// Update progress bar and UI based on state.
			var total     = parseInt( state.total, 10 ) || 0;
			var processed = parseInt( state.processed, 10 ) || 0;
			var remaining = parseInt( state.remaining, 10 ) || 0;
			var saved     = parseInt( state.saved_bytes, 10 ) || 0;

			$statProcessed.text( processed );
			$statRemaining.text( remaining );
			$statSaved.text( formatBytes( saved ) );

			if ( state.current_file ) {
				$activeFileWrap.text( state.current_file );
			}

			if ( state.step === 'indexing' ) {
				$progressBar.css( 'width', '10%' );
				$progressText.text( '…' );
				$progressWrap.show();
				$statsInline.show();
			} else if ( total > 0 ) {
				var pct = Math.min( 100, Math.round( ( processed / total ) * 100 ) );
				$progressBar.css( 'width', pct + '%' );
				$progressText.text( pct + '%' );
				$progressWrap.show();
				$statsInline.show();
			}

			// Handle queue states.
			if ( state.status === 'running' ) {
				isRunning = true;
				$btnStart.hide();
				$btnBulkRst.prop( 'disabled', true ).hide();
				$btnResume.hide();
				$btnPause.show();
				$btnStop.show();
				$log.show();
			} else if ( state.status === 'paused' ) {
				isRunning = false;
				$btnStart.hide();
				$btnBulkRst.prop( 'disabled', true ).hide();
				$btnPause.hide();
				$btnResume.show();
				$btnStop.show();
			} else if ( state.status === 'completed' ) {
				isRunning = false;
				stopPolling();
				$progressBar.css( 'width', '100%' );
				$progressText.text( '100%' );
				$btnStart.show();
				$btnBulkRst.prop( 'disabled', false ).show();
				$btnPause.hide();
				$btnResume.hide();
				$btnStop.hide();
				$activeFileWrap.text( '' );
				addLog( 'info', ( OFIO.i18n && OFIO.i18n.complete ) ? OFIO.i18n.complete : 'Process complete!', formatBytes( saved ) + ' saved' );
			} else if ( state.status === 'stopped' || state.status === 'idle' ) {
				isRunning = false;
				stopPolling();
				$btnStart.show();
				$btnBulkRst.prop( 'disabled', false ).show();
				$btnPause.hide();
				$btnResume.hide();
				$btnStop.hide();
				$activeFileWrap.text( '' );
			}
		} ).always( function () {
			pollInFlight = false;
		} );
	}

	// -------------------------------------------------------------------------
	// Instant Start Bulk Optimization
	// -------------------------------------------------------------------------
	function startBulk() {
		if ( isRunning ) { return; }

		var forceAll = $forceCheck.is( ':checked' );

		// INSTANT UI FEEDBACK (< 1ms).
		isRunning = true;
		loggedItemKeys = {};
		$log.empty().show();
		$progressBar.css( 'width', '5%' );
		$progressText.text( '0%' );
		$progressWrap.show();
		$statsInline.show();
		$btnStart.hide();
		$btnBulkRst.prop( 'disabled', true ).hide();
		$btnResume.hide();
		$btnPause.show();
		$btnStop.show();
		$statProcessed.text( '0' );
		$statRemaining.text( '…' );
		$statSaved.text( '0 KB' );
		$activeFileWrap.text( 'Initializing background queue…' );

		addLog( 'info', ( OFIO.i18n && OFIO.i18n.starting ) ? OFIO.i18n.starting : 'Starting background optimization…', 'Browser-independent queue active' );

		$.post( OFIO.ajax_url, {
			action    : 'ofio_start_bulk_queue',
			security  : OFIO.nonce,
			force_all : forceAll ? 1 : 0,
		} ).done( function ( response ) {
			if ( response && response.success ) {
				startPolling();
			} else {
				addLog( 'error', ( response && response.data && response.data.message ) ? response.data.message : 'Error starting queue.', '' );
				isRunning = false;
				$btnStart.show();
				$btnBulkRst.prop( 'disabled', false ).show();
				$btnPause.hide();
				$btnStop.hide();
			}
		} ).fail( function ( jqXHR ) {
			addLog( 'error', 'Server Error (' + jqXHR.status + ')', 'Could not dispatch background queue.' );
			isRunning = false;
			$btnStart.show();
			$btnBulkRst.prop( 'disabled', false ).show();
			$btnPause.hide();
			$btnStop.hide();
		} );
	}

	// -------------------------------------------------------------------------
	// Pause / Resume / Stop Controls
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '#ofio-btn-pause', function () {
		$btnPause.prop( 'disabled', true );
		$.post( OFIO.ajax_url, {
			action   : 'ofio_pause_bulk_queue',
			security : OFIO.nonce,
		} ).done( function () {
			$btnPause.prop( 'disabled', false ).hide();
			$btnResume.show();
			addLog( 'info', ( OFIO.i18n && OFIO.i18n.paused ) ? OFIO.i18n.paused : 'Paused.', '' );
		} );
	} );

	$( document ).on( 'click', '#ofio-btn-resume', function () {
		$btnResume.prop( 'disabled', true );
		$.post( OFIO.ajax_url, {
			action   : 'ofio_resume_bulk_queue',
			security : OFIO.nonce,
		} ).done( function () {
			$btnResume.prop( 'disabled', false ).hide();
			$btnPause.show();
			addLog( 'info', ( OFIO.i18n && OFIO.i18n.resuming ) ? OFIO.i18n.resuming : 'Resuming…', '' );
			startPolling();
		} );
	} );

	$( document ).on( 'click', '#ofio-btn-stop', function () {
		if ( ! window.confirm( ( OFIO.i18n && OFIO.i18n.confirm_stop ) ? OFIO.i18n.confirm_stop : 'Stop background processing?' ) ) {
			return;
		}

		$btnStop.prop( 'disabled', true );
		$.post( OFIO.ajax_url, {
			action   : 'ofio_stop_bulk_queue',
			security : OFIO.nonce,
		} ).done( function () {
			$btnStop.prop( 'disabled', false ).hide();
			$btnPause.hide();
			$btnResume.hide();
			$btnStart.show();
			$btnBulkRst.prop( 'disabled', false ).show();
			stopPolling();
			addLog( 'info', ( OFIO.i18n && OFIO.i18n.stopped ) ? OFIO.i18n.stopped : 'Stopped by user.', '' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Instant Bulk Restore
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '#ofio-btn-bulk-restore', function () {
		if ( isRunning ) { return; }
		if ( ! window.confirm( ( OFIO.i18n && OFIO.i18n.confirm_bulk_rst ) ? OFIO.i18n.confirm_bulk_rst : 'Restore ALL backed up images back to original in the background? This will revert all optimizations.' ) ) {
			return;
		}

		isRunning = true;
		loggedItemKeys = {};
		$log.empty().show();
		$progressBar.css( 'width', '5%' );
		$progressText.text( '0%' );
		$progressWrap.show();
		$statsInline.show();
		$btnStart.hide();
		$btnBulkRst.prop( 'disabled', true ).hide();
		$btnPause.hide();
		$btnResume.hide();
		$btnStop.show();

		addLog( 'info', 'Starting background bulk restoration…', '' );

		$.post( OFIO.ajax_url, {
			action   : 'ofio_start_restore_queue',
			security : OFIO.nonce,
		} ).done( function ( response ) {
			if ( response && response.success ) {
				startPolling();
			} else {
				addLog( 'error', ( response && response.data && response.data.message ) ? response.data.message : 'Error starting restore queue.', '' );
				isRunning = false;
				$btnStart.show();
				$btnBulkRst.prop( 'disabled', false ).show();
				$btnStop.hide();
			}
		} ).fail( function ( jqXHR ) {
			addLog( 'error', 'Server Error (' + jqXHR.status + ')', 'Could not dispatch restore queue.' );
			isRunning = false;
			$btnStart.show();
			$btnBulkRst.prop( 'disabled', false ).show();
			$btnStop.hide();
		} );
	} );

	$( document ).on( 'click', '#ofio-btn-start', startBulk );

	// Purge all backups.
	$( document ).on( 'click', '#ofio-purge-backups', function () {
		if ( ! window.confirm( ( OFIO.i18n && OFIO.i18n.confirm_purge ) ? OFIO.i18n.confirm_purge : 'Are you sure you want to delete all backups?' ) ) {
			return;
		}
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

	// Media Library Single Actions.
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
				if ( response.data.stats ) {
					updateDashboardCards( response.data.stats );
				}
			} else {
				$cell.html( '<span class="ofio-badge ofio-badge--error">Failed</span>' );
			}
		} ).fail( function () {
			$cell.html( '<span class="ofio-badge ofio-badge--error">Error</span>' );
		} );
	} );

	$( document ).on( 'click', '.ofio-restore', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( ( OFIO.i18n && OFIO.i18n.confirm_restore ) ? OFIO.i18n.confirm_restore : 'Restore original image?' ) ) {
			return;
		}
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
				if ( response.data.stats ) {
					updateDashboardCards( response.data.stats );
				}
			} else {
				$cell.html( '<span class="ofio-badge ofio-badge--error">Failed</span>' );
			}
		} ).fail( function () {
			$cell.html( '<span class="ofio-badge ofio-badge--error">Error</span>' );
		} );
	} );

	// Media Modal "Optimize Now" button handler.
	$( document ).on( 'click', '.ofio-modal-optimize', function ( e ) {
		e.preventDefault();
		var $btn    = $( this );
		var id      = parseInt( $btn.data( 'id' ), 10 );
		var nonce   = String( $btn.data( 'nonce' ) );
		var $status = $( '#ofio-modal-status-' + id );

		$btn.prop( 'disabled', true );
		if ( $status.length ) {
			$status.css( 'color', '#2271b1' ).text( 'Optimizing…' );
		}

		$.post( OFIO.ajax_url, {
			action        : 'ofio_modal_optimize',
			security      : nonce,
			attachment_id : id,
		} ).done( function ( response ) {
			$btn.prop( 'disabled', false );
			if ( response.success ) {
				if ( $status.length ) {
					$status.css( 'color', '#00a32a' ).text( response.data.message || 'Done!' );
				}
				var $cell = $( '#ofio-media-cell-' + id );
				if ( $cell.length && response.data.html ) {
					$cell.html( response.data.html );
				}
				if ( response.data.stats ) {
					updateDashboardCards( response.data.stats );
				}
			} else {
				if ( $status.length ) {
					$status.css( 'color', '#d63638' ).text(
						( response.data && response.data.message ) ? response.data.message : 'Optimization failed.'
					);
				}
			}
		} ).fail( function ( jqXHR ) {
			$btn.prop( 'disabled', false );
			if ( $status.length ) {
				$status.css( 'color', '#d63638' ).text( 'Server error (' + jqXHR.status + ').' );
			}
		} );
	} );

	// On Page Load: Check if background queue is already running or paused.
	$( function () {
		if ( $( '#ofio-btn-start' ).length ) {
			$.post( OFIO.ajax_url, {
				action   : 'ofio_get_bulk_status',
				security : OFIO.nonce,
			} ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.state ) {
					var state = response.data.state;
					if ( state.status === 'running' || state.status === 'paused' ) {
						startPolling();
					}
				}
			} );
		}
	} );

} )( jQuery, window.OFIO || {} );