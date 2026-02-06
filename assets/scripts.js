jQuery( function( $ ) {

	// unblock the button when we have values of all the fields
	$( '#store_url, #consumer_key, #consumer_secret' ).keyup( function() {
		const button = $( '#psfw_add_new_store' )
		if( '' !== $( '#store_url' ).val() && '' !== $( '#consumer_key' ).val() && '' !== $( '#consumer_secret' ).val() ) {
			button.prop( 'disabled', false )
		} else {
			button.prop( 'disabled', true )
		}
	} );

	// adding a new store easily
	$( '#psfw_add_new_store' ).click( function( e ) {

		e.preventDefault();

		const button = $(this);
		const url = $( '#store_url' );
		const cKey = $( '#consumer_key' );
		const cSecret = $( '#consumer_secret' );
		const noticeContainer = $( '#psfw-stores-notices' );
		const storesContainer = $( '.psfw-stores-table' ).find( 'tbody' );

		$.ajax( {
			type : 'POST',
			url : ajaxurl,
			data : {
				url : url.val(),
				consumer_key : cKey.val(),
				consumer_secret : cSecret.val(),
				action : 'psfw_addstore',
				_wpnonce : psfw_settings.nonce,
			},
			beforeSend : function( xhr ){
				button.prop( 'disabled', true );
				noticeContainer.empty();
			},
			success : function( data ) {
				if( true === data.success ) {
					noticeContainer.html( '<div class="notice notice-success"><p>' + data.data.message + '</p></div>' );
					if( storesContainer.find( '.psfw-store' ).length > 0 ) {
						storesContainer.append( data.data.tr );
					} else {
						storesContainer.html( data.data.tr );
					}
					url.val( '' );
					cKey.val( '' );
					cSecret.val( '' );
					window.onbeforeunload = '';
				} else {
					// allow to make changes
					button.prop( 'disabled', false );
					noticeContainer.html( '<div class="notice notice-error"><p>' + data.data[0].message + '</p></div>' );
				}
			}
		} );

	} );


	$( 'body' ).on( 'click', '.psfw-remove-store', function( e ) {

		e.preventDefault();
		if( true !== confirm( psfw_settings.deleteStoreConfirmText ) ) {
			return;
		}
		$(this).prop( 'disabled', true );

		const url = $(this).parent().prev().text();
		const tr = $(this).parent().parent();
		const storesContainer = $( '.psfw-stores-table' ).find( 'tbody' );

		$.ajax( {
			type : 'POST',
			url : ajaxurl,
			data : {
				url : url,
				action : 'psfw_removestore',
				_wpnonce : psfw_settings.nonce,
			},
			success : function( data ) {
				if( storesContainer.find( '.psfw-store' ).length == 1 ) {
					storesContainer.html( '<td colspan="3">' + data.data.message + '</td>' );
				} else {
					tr.remove();
				}
			}
		} );

	} );

} );
