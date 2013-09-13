jQuery(document).ready(function($) {

	$(document).on( 'heartbeat-send.bbp-mods-viewing', function( e, data ) {
		var topic_id = bbp_mods_viewing.topic_id,
			send = {};

		if ( ! topic_id )
			return;

		send['topic_id'] = topic_id;

		data['bbp-mods-viewing'] = send;

		console.log( 'sent' );

	});

	$('.bbp-topic-lock-close').click(function(e) {
		e.preventDefault();
		$('#topic-lock-dialog').remove();
	})
});